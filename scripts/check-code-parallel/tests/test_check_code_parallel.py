"""Small process-level regressions for the code-check coordinator."""

from __future__ import annotations

import errno
import importlib.util
import io
import json
import os
import select
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch


SCRIPT = Path(os.environ.get(
    "QMX_CHECK_CODE_PARALLEL_SCRIPT",
    Path(__file__).resolve().parents[1] / "check-code-parallel.py",
))
SPEC = importlib.util.spec_from_file_location("check_code_parallel", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class CheckCodeParallelTest(unittest.TestCase):
    def test_review_broken_stdout_during_interrupt_cannot_abort_group_cleanup(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            pid_files = [root / "fast.pid", root / "slow.pid"]
            reap_witness = root / "reap.witness"
            fast = "import os,sys,time; open(sys.argv[1],'w').write(str(os.getpid())); time.sleep(30)"
            slow = (
                "import os,signal,sys,time; signal.signal(signal.SIGTERM,signal.SIG_IGN); "
                "open(sys.argv[1],'w').write(str(os.getpid())); time.sleep(30)"
            )
            commands = [
                ("fast", [sys.executable, "-c", fast, str(pid_files[0])]),
                ("slow", [sys.executable, "-c", slow, str(pid_files[1])]),
            ]
            child = start_harness(
                root,
                commands,
                shutdown_grace_seconds=.2,
                reap_witness=reap_witness,
            )
            try:
                wait_for_files(pid_files)
                assert child.stdout is not None
                child.stdout.close()
                child.send_signal(signal.SIGINT)
                child.wait(timeout=3)
                assert child.stderr is not None
                stderr = child.stderr.read()
                if b"cleanup refusal" in stderr:
                    self.assertEqual(2, child.returncode, stderr.decode(errors="replace"))
                else:
                    self.assertEqual(130, child.returncode, stderr.decode(errors="replace"))
                self.assertEqual("ECHILD", reap_witness.read_text())
                for pid_file in pid_files:
                    self.assertFalse(process_is_running(int(pid_file.read_text())))
            finally:
                if child.poll() is None:
                    child.kill()
                    child.wait()
                if child.stdout is not None and not child.stdout.closed:
                    child.stdout.close()
                if child.stderr is not None:
                    child.stderr.close()
                for pid_file in pid_files:
                    if pid_file.exists():
                        kill_group_if_running(int(pid_file.read_text()))

    def test_review_normal_run_waits_for_late_descendant_output(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            group_file = root / "group.pid"
            ready_file = root / "descendant.ready"
            written_file = root / "descendant.written"
            descendant_pid_file = root / "descendant.pid"
            descendant = (
                "import os,sys,time; open(sys.argv[1],'w').write('ready'); "
                "open(sys.argv[3],'w').write(str(os.getpid())); "
                "time.sleep(.25); print('late stdout',flush=True); "
                "print('late stderr',file=sys.stderr,flush=True); "
                "open(sys.argv[2],'w').write('written')"
            )
            leader = (
                "import os,subprocess,sys,time; open(sys.argv[1],'w').write(str(os.getpid())); "
                "subprocess.Popen([sys.executable,'-c',sys.argv[2],sys.argv[3],sys.argv[4],sys.argv[5]]); "
                "deadline=time.monotonic()+2; "
                "exec(\"while not os.path.exists(sys.argv[3]):\\n if time.monotonic()>deadline: raise SystemExit(9)\\n time.sleep(.01)\")"
            )
            stdout = io.BytesIO()
            stderr = io.BytesIO()
            try:
                result = MODULE.run_checks(
                    [("with-descendant", [sys.executable, "-c", leader, str(group_file), descendant, str(ready_file), str(written_file), str(descendant_pid_file)])],
                    cwd=root,
                    stdout=stdout,
                    stderr=stderr,
                )
                wait_for_files([written_file])
                self.assertEqual(0, result)
                self.assertIn(b"late stdout", stdout.getvalue())
                self.assertIn(b"late stderr", stderr.getvalue())
                self.assertFalse(process_is_running(int(descendant_pid_file.read_text())))
            finally:
                if group_file.exists():
                    kill_group_if_running(int(group_file.read_text()))

    def test_review_publication_errors_preserve_command_exit_priority(self) -> None:
        class RefusingStream(io.BytesIO):
            def __init__(self, error: OSError) -> None:
                super().__init__()
                self.error = error

            def write(self, _data: object) -> int:
                raise self.error

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for stdout, stderr in (
                (RefusingStream(BrokenPipeError("synthetic closed stdout")), io.BytesIO()),
                (io.BytesIO(), RefusingStream(OSError("synthetic stderr refusal"))),
            ):
                with self.subTest(stdout=type(stdout).__name__, stderr=type(stderr).__name__):
                    result = MODULE.run_checks(
                        [
                            ("first", [sys.executable, "-c", "raise SystemExit(7)"]),
                            ("second", [sys.executable, "-c", "raise SystemExit(9)"]),
                        ],
                        cwd=root,
                        stdout=stdout,
                        stderr=stderr,
                    )
                    self.assertEqual(7, result)
                    if isinstance(stderr, io.BytesIO) and not isinstance(stderr, RefusingStream):
                        self.assertIn(b"cannot publish first stdout", stderr.getvalue())

    def test_review_closed_standard_fd_does_not_change_process_exit(self) -> None:
        program = (
            "import importlib.util,json,os,sys; "
            "spec=importlib.util.spec_from_file_location('check_code_parallel',sys.argv[1]); "
            "module=importlib.util.module_from_spec(spec); sys.modules[spec.name]=module; "
            "spec.loader.exec_module(module); "
            "os.close(int(sys.argv[2])); "
            "result=module.run_checks([('failing',(sys.executable,'-c','raise SystemExit(7)'))],cwd=__import__('pathlib').Path(sys.argv[3])); "
            "__import__('pathlib').Path(sys.argv[4]).write_text(json.dumps({'result':result})); "
            "raise SystemExit(result)"
        )
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for descriptor in (1, 2):
                with self.subTest(descriptor=descriptor):
                    marker = root / f"fd-{descriptor}.json"
                    completed = subprocess.run(
                        [sys.executable, "-c", program, str(SCRIPT), str(descriptor), str(root), str(marker)],
                        stdout=subprocess.PIPE,
                        stderr=subprocess.PIPE,
                        check=False,
                    )
                    self.assertEqual({"result": 7}, json.loads(marker.read_text()))
                    self.assertEqual(7, completed.returncode)

    def test_review_normal_group_inspection_refusal_is_bounded_and_reported(self) -> None:
        stdout = io.BytesIO()
        stderr = io.BytesIO()
        with tempfile.TemporaryDirectory() as temporary:
            with patch.object(MODULE, "group_alive", side_effect=PermissionError(errno.EPERM, "synthetic refusal")):
                result = MODULE.run_checks(
                    [("completed", [sys.executable, "-c", "pass"])],
                    cwd=Path(temporary),
                    stdout=stdout,
                    stderr=stderr,
                    shutdown_grace_seconds=.01,
                )

        self.assertEqual(2, result)
        self.assertIn(b"cleanup refusal", stderr.getvalue())
        self.assertIn(b"[Errno 1] synthetic refusal", stderr.getvalue())

    def test_review_normal_run_bounds_wait_for_a_descendant_left_in_the_group(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            pid_file = root / "lingering.json"
            descendant = "import time; print('descendant output',flush=True); time.sleep(30)"
            leader = (
                "import json,os,subprocess,sys,time; "
                "lingering=subprocess.Popen([sys.executable,'-c',sys.argv[2]]); "
                "open(sys.argv[1],'w').write(json.dumps([os.getpid(),lingering.pid])); "
                "time.sleep(.2); raise SystemExit(3)"
            )
            commands = [
                ("lingering", [sys.executable, "-c", leader, str(pid_file), descendant]),
                ("peer", [sys.executable, "-c", "pass"]),
            ]
            bound = MODULE.GROUP_EXIT_GRACE_SECONDS
            started = time.monotonic()
            child = start_harness(root, commands)
            try:
                try:
                    stdout, stderr = child.communicate(timeout=bound + 3)
                except subprocess.TimeoutExpired:
                    self.fail("the coordinator kept waiting for a group its finished leader left behind")
                elapsed = time.monotonic() - started
                self.assertEqual(3, child.returncode, stderr.decode(errors="replace"))
                self.assertLess(elapsed, bound + 1.5)
                self.assertIn(b"===== lingering stdout =====\ndescendant output\n", stdout)
                self.assertIn(b"[lingering] exit 3\n", stderr)
                self.assertIn(b"[peer] exit 0\n", stderr)
                self.assertIn(b"lingering process group is still running", stderr)
                self.assertTrue(
                    process_is_running(json.loads(pid_file.read_text())[1]),
                    "the normal path stopped a descendant it does not own the result of",
                )
            finally:
                if child.poll() is None:
                    child.kill()
                    child.wait()
                if pid_file.exists():
                    kill_group_if_running(json.loads(pid_file.read_text())[0])

    def test_review_signal_during_normal_readiness_defers_publication_until_cleanup(self) -> None:
        stdout = io.BytesIO()
        stderr = io.BytesIO()
        real_group_alive = MODULE.group_alive
        interrupted = False

        def interrupt_at_readiness(process: subprocess.Popen[bytes]) -> bool:
            nonlocal interrupted
            if not interrupted and process.poll() is not None:
                interrupted = True
                os.kill(os.getpid(), signal.SIGTERM)
                return False
            return real_group_alive(process)

        with tempfile.TemporaryDirectory() as temporary:
            with patch.object(MODULE, "group_alive", side_effect=interrupt_at_readiness):
                result = MODULE.run_checks(
                    [("completed", [sys.executable, "-c", "pass"])],
                    cwd=Path(temporary),
                    stdout=stdout,
                    stderr=stderr,
                    shutdown_grace_seconds=.05,
                )

        self.assertEqual(143, result, stderr.getvalue().decode(errors="replace"))
        self.assertEqual(b"===== completed stdout =====\n", stdout.getvalue())

    def test_default_cli_runs_both_composer_commands_concurrently(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            composer = root / "composer"
            composer.write_text("\n".join((
                f"#!{sys.executable}",
                "import os, sys, time",
                "from pathlib import Path",
                "root = Path(os.environ['QMX_FAKE_DIR'])",
                "name = sys.argv[1]",
                "if name not in ('phpstan', 'check:remaining'): sys.exit(17)",
                "other = 'check:remaining' if name == 'phpstan' else 'phpstan'",
                "(root / (name + '.started')).write_text('ready')",
                "deadline = time.monotonic() + 2",
                "while not (root / (other + '.started')).exists():",
                "    if time.monotonic() > deadline: sys.exit(19)",
                "    time.sleep(.01)",
                "(root / (name + '.context')).write_text(os.getcwd() + '\\n' + os.environ['QMX_FAKE_SENTINEL'])",
                "sys.stdout.write(name + ' stdout\\n')",
                "sys.stderr.write(name + ' stderr\\n')",
                "sys.exit(4 if name == 'phpstan' else 0)",
            )))
            composer.chmod(0o755)
            environment = os.environ.copy()
            environment['PATH'] = str(root) + os.pathsep + environment.get('PATH', '')
            environment['QMX_FAKE_DIR'] = str(root)
            environment['QMX_FAKE_SENTINEL'] = 'inherited'

            completed = subprocess.run(
                [sys.executable, str(SCRIPT)],
                cwd=root,
                env=environment,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                timeout=5,
                check=False,
            )

            self.assertEqual(4, completed.returncode, completed.stderr.decode(errors='replace'))
            stdout_blocks = (
                b'===== phpstan stdout =====\nphpstan stdout\n',
                b'===== check:remaining stdout =====\ncheck:remaining stdout\n',
            )
            stderr_blocks = (
                b'===== phpstan stderr =====\nphpstan stderr\n[phpstan] exit 4\n',
                b'===== check:remaining stderr =====\ncheck:remaining stderr\n[check:remaining] exit 0\n',
            )
            self.assertEqual(sum(map(len, stdout_blocks)), len(completed.stdout))
            self.assertEqual(sum(map(len, stderr_blocks)), len(completed.stderr))
            for block in stdout_blocks:
                self.assertIn(block, completed.stdout)
            for block in stderr_blocks:
                self.assertIn(block, completed.stderr)
            expected_context = str(SCRIPT.parents[2]) + '\n' + 'inherited'
            self.assertEqual(expected_context, (root / 'phpstan.context').read_text())
            self.assertEqual(expected_context, (root / 'check:remaining.context').read_text())

    def test_group_refusal_still_attempts_cleanup_of_the_peer(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            children = [
                MODULE.Child(name, [sys.executable], root / f"{name}.out", root / f"{name}.err")
                for name in ("phpstan", "check:remaining")
            ]
            marker = root / "peer-cleanup-complete"
            ready = root / "peer-ready"
            peer_program = "\n".join((
                "from pathlib import Path",
                "import signal, sys, time",
                "def finish(_signum, _frame):",
                "    time.sleep(.25)",
                "    Path(sys.argv[1]).write_text('complete')",
                "    raise SystemExit(0)",
                "signal.signal(signal.SIGTERM, finish)",
                "Path(sys.argv[2]).write_text('ready')",
                "while True: time.sleep(1)",
            ))
            for child, command in zip(children, (
                [sys.executable, "-c", "import time; time.sleep(30)"],
                [sys.executable, "-c", peer_program, str(marker), str(ready)],
            )):
                child.process = subprocess.Popen(command, stdin=subprocess.DEVNULL,
                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
            wait_for_files([ready])
            first_pid = children[0].process.pid
            real_group_alive = MODULE.group_alive

            def refuse_first_inspection(process: subprocess.Popen[bytes]) -> bool:
                if process.pid == first_pid:
                    raise PermissionError(errno.EPERM, "synthetic refusal")
                return real_group_alive(process)

            try:
                with patch.object(MODULE, "group_alive", side_effect=refuse_first_inspection):
                    refusal = MODULE.stop_groups(children, .6)
                self.assertIsNotNone(refusal)
                self.assertIn("cannot inspect process group", refusal)
                self.assertIn("[Errno 1] synthetic refusal", refusal)
                self.assertTrue(marker.exists(), "peer was KILLed before its TERM cleanup completed")
                self.assertEqual("complete", marker.read_text())
                self.assertTrue(all(child.process.poll() is not None for child in children))
            finally:
                for child in children:
                    kill_group_if_running(child.process.pid)
                    child.process.wait(timeout=2)

    def test_children_inherit_the_callers_umask(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            original = os.umask(0)
            os.umask(original)
            program = (
                "import os,sys; mask=os.umask(0); os.umask(mask); "
                "open(sys.argv[1],'w').write(str(mask))"
            )
            paths = [root / "first.mask", root / "second.mask"]
            result = MODULE.run_checks(
                [(name, [sys.executable, "-c", program, str(path)]) for name, path in zip(("phpstan", "check:remaining"), paths)],
                cwd=root,
                stdout=io.BytesIO(),
                stderr=io.BytesIO(),
            )
            self.assertEqual(0, result)
            self.assertEqual([str(original)] * 2, [path.read_text() for path in paths])

    def test_both_commands_complete_and_publish_full_output_in_completion_order(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            first = root / "first.started"
            second = root / "second.started"
            program = "\n".join((
                "from pathlib import Path",
                "import sys, time",
                "Path(sys.argv[1]).write_text('started')",
                "deadline = time.monotonic() + 2",
                "while not Path(sys.argv[2]).exists():",
                "    if time.monotonic() > deadline: sys.exit(9)",
                "    time.sleep(.01)",
                "time.sleep(float(sys.argv[6]))",
                "sys.stdout.write(sys.argv[3] * 200000)",
                "sys.stderr.write(sys.argv[4] * 200000)",
                "sys.exit(int(sys.argv[5]))",
            ))
            commands = [
                ("phpstan", [sys.executable, "-c", program, str(first), str(second), "A", "a", "4", ".15"]),
                ("check:remaining", [sys.executable, "-c", program, str(second), str(first), "B", "b", "7", "0"]),
            ]
            for first_exit, second_exit, expected in ((4, 0, 4), (0, 7, 7), (4, 7, 4)):
                commands[0][1][-2] = str(first_exit)
                commands[1][1][-2] = str(second_exit)
                first.unlink(missing_ok=True)
                second.unlink(missing_ok=True)
                stdout = io.BytesIO()
                stderr = io.BytesIO()
                result = MODULE.run_checks(commands, cwd=root, stdout=stdout, stderr=stderr)
                self.assertEqual(expected, result)
                self.assertEqual(
                    b"===== check:remaining stdout =====\n" + b"B" * 200000
                    + b"===== phpstan stdout =====\n" + b"A" * 200000,
                    stdout.getvalue(),
                )
                self.assertEqual(
                    b"===== check:remaining stderr =====\n" + b"b" * 200000
                    + f"[check:remaining] exit {second_exit}\n".encode()
                    + b"===== phpstan stderr =====\n" + b"a" * 200000 + f"[phpstan] exit {first_exit}\n".encode(),
                    stderr.getvalue(),
                )

    def test_interrupt_publishes_only_after_all_groups_stop(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            ready_files = [root / "fast.ready", root / "slow.ready"]
            slow_finished = root / "slow.finished"
            cleanup_ready = root / "slow.cleanup-ready"
            cleanup_release = root / "slow.cleanup-release"
            program = "\n".join((
                "from pathlib import Path",
                "import signal, sys, time",
                "def stop(_signum, _frame):",
                "    if sys.argv[5] != '-':",
                "        Path(sys.argv[5]).write_text('ready')",
                "        deadline = time.monotonic() + 2",
                "        while not Path(sys.argv[6]).exists():",
                "            if time.monotonic() > deadline: raise SystemExit(8)",
                "            time.sleep(.01)",
                "    time.sleep(float(sys.argv[2]))",
                "    if sys.argv[3] != '-': Path(sys.argv[3]).write_text('finished')",
                "    print(sys.argv[4], flush=True)",
                "    raise SystemExit(0)",
                "signal.signal(signal.SIGTERM, stop)",
                "Path(sys.argv[1]).write_text('ready')",
                "while True: time.sleep(1)",
            ))
            commands = [
                ("fast", [sys.executable, "-c", program, str(ready_files[0]), "0", "-", "fast output", "-", "-"]),
                ("slow", [sys.executable, "-c", program, str(ready_files[1]), "0", str(slow_finished), "slow output", str(cleanup_ready), str(cleanup_release)]),
            ]
            child = start_harness(root, commands, shutdown_grace_seconds=1.0)
            try:
                wait_for_files(ready_files)
                child.send_signal(signal.SIGTERM)
                wait_for_files([cleanup_ready])
                assert child.stdout is not None
                readable, _, _ = select.select([child.stdout], [], [], .3)
                self.assertEqual([], readable, "output was published while the slow group was still alive")
                cleanup_release.write_text("release")
                stdout, stderr = child.communicate(timeout=3)
                self.assertEqual(143, child.returncode, stderr.decode(errors="replace"))
                self.assertTrue(slow_finished.exists(), "publication preceded completion of all groups")
                self.assertEqual(
                    b"===== fast stdout =====\nfast output\n===== slow stdout =====\nslow output\n",
                    stdout,
                )
            finally:
                cleanup_release.write_text("release")
                if child.poll() is None:
                    child.kill()
                    child.wait()
                if child.stdout is not None:
                    child.stdout.close()
                if child.stderr is not None:
                    child.stderr.close()

    def test_interrupt_keeps_output_written_by_a_descendant_after_its_leader_exits(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            ready_file = root / "descendant.ready"
            written_file = root / "descendant.written"
            descendant = (
                "import signal,sys,time; "
                "signal.signal(signal.SIGTERM,signal.SIG_IGN); "
                "open(sys.argv[1],'w').write('ready'); "
                "time.sleep(.35); "
                "print('late stdout',flush=True); "
                "print('late stderr',file=sys.stderr,flush=True); "
                "open(sys.argv[2],'w').write('written')"
            )
            leader = (
                "import subprocess,sys,time; "
                "subprocess.Popen([sys.executable,'-c',sys.argv[1],sys.argv[2],sys.argv[3]]); "
                "time.sleep(30)"
            )
            commands = [
                ("with-descendant", [sys.executable, "-c", leader, descendant, str(ready_file), str(written_file)]),
            ]
            child = start_harness(root, commands, shutdown_grace_seconds=.8)
            try:
                wait_for_files([ready_file])
                child.send_signal(signal.SIGTERM)
                stdout, stderr = child.communicate(timeout=3)
                self.assertTrue(written_file.exists(), "descendant did not write during the grace period")
                self.assertIn(b"===== with-descendant stdout =====\nlate stdout\n", stdout)
                self.assertIn(b"===== with-descendant stderr =====\nlate stderr\n", stderr)
                if b"cleanup refusal" in stderr:
                    self.assertEqual(2, child.returncode, stderr.decode(errors="replace"))
                else:
                    self.assertEqual(143, child.returncode, stderr.decode(errors="replace"))
            finally:
                if child.poll() is None:
                    child.kill()
                    child.wait()
                if child.stdout is not None:
                    child.stdout.close()
                if child.stderr is not None:
                    child.stderr.close()

    def test_launch_failure_stops_a_started_peer(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            pid_file = root / "started.pid"
            program = "import os,signal,sys,time; signal.signal(signal.SIGTERM,signal.SIG_IGN); open(sys.argv[1],'w').write(str(os.getpid())); time.sleep(30)"
            stdout = io.BytesIO()
            stderr = io.BytesIO()
            original_popen = subprocess.Popen
            launched: list[subprocess.Popen[bytes]] = []

            def launch_after_first_is_ready(*args: object, **kwargs: object) -> subprocess.Popen[bytes]:
                process = original_popen(*args, **kwargs)
                launched.append(process)
                if len(launched) == 1:
                    wait_for_files([pid_file])
                    self.assertIsNone(process.poll())
                return process

            try:
                with patch.object(MODULE.subprocess, "Popen", side_effect=launch_after_first_is_ready):
                    result = MODULE.run_checks(
                        [
                            ("phpstan", [sys.executable, "-c", program, str(pid_file)]),
                            ("check:remaining", [str(root / "missing-executable")]),
                        ],
                        cwd=root,
                        stdout=stdout,
                        stderr=stderr,
                        shutdown_grace_seconds=0.1,
                    )
                self.assertEqual(1, len(launched))
                self.assertEqual(launched[0].pid, int(pid_file.read_text()))
                self.assertEqual(2, result)
                self.assertIn(b"launch failed", stderr.getvalue())
                self.assertFalse(process_is_running(launched[0].pid))
            finally:
                for process in launched:
                    kill_group_if_running(process.pid)
                    if process.poll() is None:
                        process.kill()
                    process.wait(timeout=2)

    def test_int_and_term_stop_both_owned_groups(self) -> None:
        for signum in (signal.SIGINT, signal.SIGTERM):
            with self.subTest(signum=signum), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                pid_files = [root / "first.pid", root / "second.pid"]
                program = "import os,sys,time; open(sys.argv[1],'w').write(str(os.getpid())); time.sleep(30)"
                commands = [(name, [sys.executable, "-c", program, str(pid)]) for name, pid in zip(("phpstan", "check:remaining"), pid_files)]
                child = start_harness(root, commands)
                try:
                    wait_for_files(pid_files)
                    child.send_signal(signum)
                    stdout, stderr = child.communicate(timeout=5)
                    if b"cleanup refusal" in stderr:
                        self.assertEqual(2, child.returncode, stderr.decode(errors="replace"))
                        self.assertIn(b"[Errno 1] Operation not permitted", stderr)
                    else:
                        self.assertEqual(128 + signum, child.returncode, stderr.decode(errors="replace"))
                        self.assertIn(f"interrupted by {signal.Signals(signum).name}".encode(), stderr)
                    self.assertIn(b"===== phpstan stdout =====", stdout)
                    self.assertIn(b"===== check:remaining stdout =====", stdout)
                    for pid_file in pid_files:
                        self.assertFalse(process_is_running(int(pid_file.read_text())))
                finally:
                    if child.poll() is None:
                        child.kill()
                        child.wait()
                    if child.stdout is not None:
                        child.stdout.close()
                    if child.stderr is not None:
                        child.stderr.close()
                    for pid_file in pid_files:
                        if pid_file.exists():
                            kill_group_if_running(int(pid_file.read_text()))

    def test_term_ignoring_grandchild_is_killed_with_its_group(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            pid_file = root / "descendants.json"
            ready_file = root / "grandchild.ready"
            program = (
                "import json,os,signal,subprocess,sys,time; "
                "signal.signal(signal.SIGTERM,signal.SIG_IGN); "
                "grandchild=subprocess.Popen([sys.executable,'-c',"
                "'import signal,sys,time; signal.signal(signal.SIGTERM,signal.SIG_IGN); open(sys.argv[1],\"w\").write(\"ready\"); time.sleep(30)',sys.argv[2]]); "
                "open(sys.argv[1],'w').write(json.dumps([os.getpid(),grandchild.pid])); "
                "time.sleep(30)"
            )
            commands = [
                ("phpstan", [sys.executable, "-c", program, str(pid_file), str(ready_file)]),
                ("check:remaining", [sys.executable, "-c", "import time; time.sleep(30)"]),
            ]
            child = start_harness(root, commands)
            try:
                wait_for_files([pid_file, ready_file])
                pids = json.loads(pid_file.read_text())
                child.send_signal(signal.SIGTERM)
                _, stderr = child.communicate(timeout=5)
                for pid in pids:
                    self.assertFalse(process_is_running(pid), f"left process {pid} alive")
                if b"cleanup refusal" in stderr:
                    self.assertEqual(2, child.returncode, stderr.decode(errors="replace"))
                    self.assertIn(b"[Errno 1] Operation not permitted", stderr)
                else:
                    self.assertEqual(143, child.returncode, stderr.decode(errors="replace"))
            finally:
                if child.poll() is None:
                    child.kill()
                    child.wait()
                if child.stdout is not None:
                    child.stdout.close()
                if child.stderr is not None:
                    child.stderr.close()
                if pid_file.exists():
                    kill_group_if_running(json.loads(pid_file.read_text())[0])


def start_harness(
    root: Path,
    commands: list[tuple[str, list[str]]],
    *,
    shutdown_grace_seconds: float = .2,
    reap_witness: Path | None = None,
) -> subprocess.Popen[bytes]:
    program = (
        "import importlib.util,json,os,sys; "
        "spec=importlib.util.spec_from_file_location('check_code_parallel',sys.argv[1]); "
        "module=importlib.util.module_from_spec(spec); sys.modules[spec.name]=module; "
        "spec.loader.exec_module(module); "
        "result=module.run_checks(json.loads(sys.argv[2]),cwd=__import__('pathlib').Path(sys.argv[3]),shutdown_grace_seconds=float(sys.argv[4])); "
        "witness=sys.argv[5]; "
        "outcome=''; "
        "exec(\"try:\\n pid,status=os.waitpid(-1,os.WNOHANG)\\n outcome=f'{pid}:{status}'\\nexcept ChildProcessError:\\n outcome='ECHILD'\") if witness != '-' else None; "
        "__import__('pathlib').Path(witness).write_text(outcome) if witness != '-' else None; "
        "raise SystemExit(result)"
    )
    return subprocess.Popen(
        [
            sys.executable,
            "-c",
            program,
            str(SCRIPT),
            json.dumps(commands),
            str(root),
            str(shutdown_grace_seconds),
            str(reap_witness) if reap_witness is not None else "-",
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )


def wait_for_files(paths: list[Path]) -> None:
    deadline = time.monotonic() + 3
    while not all(path.exists() for path in paths):
        if time.monotonic() >= deadline:
            raise AssertionError(f"children did not start: {paths}")
        time.sleep(.01)


def process_is_running(pid: int) -> bool:
    observed = subprocess.run(["ps", "-o", "stat=", "-p", str(pid)], capture_output=True, text=True, check=False)
    status = observed.stdout.strip()
    return bool(status) and not status.startswith("Z")


def kill_group_if_running(pgid: int) -> None:
    try:
        os.killpg(pgid, signal.SIGKILL)
    except (ProcessLookupError, PermissionError):
        pass


if __name__ == "__main__":
    unittest.main()
