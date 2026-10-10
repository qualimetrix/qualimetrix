"""Small process-level regressions for the code-check coordinator."""

from __future__ import annotations

import errno
import importlib.util
import io
import json
import os
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "check-code-parallel.py"
SPEC = importlib.util.spec_from_file_location("check_code_parallel", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class CheckCodeParallelTest(unittest.TestCase):
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
                "if name not in ('phpstan', 'test:aggregate'): sys.exit(17)",
                "other = 'test:aggregate' if name == 'phpstan' else 'phpstan'",
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
            self.assertEqual(
                b'===== phpstan stdout =====\nphpstan stdout\n'
                b'===== test:aggregate stdout =====\ntest:aggregate stdout\n',
                completed.stdout,
            )
            self.assertEqual(
                b'===== phpstan stderr =====\nphpstan stderr\n[phpstan] exit 4\n'
                b'===== test:aggregate stderr =====\ntest:aggregate stderr\n[test:aggregate] exit 0\n',
                completed.stderr,
            )
            expected_context = str(SCRIPT.parents[2]) + '\n' + 'inherited'
            self.assertEqual(expected_context, (root / 'phpstan.context').read_text())
            self.assertEqual(expected_context, (root / 'test:aggregate.context').read_text())

    def test_group_refusal_still_attempts_cleanup_of_the_peer(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            children = [
                MODULE.Child(name, [sys.executable], root / f"{name}.out", root / f"{name}.err")
                for name in ("phpstan", "test:aggregate")
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
                [(name, [sys.executable, "-c", program, str(path)]) for name, path in zip(("phpstan", "test:aggregate"), paths)],
                cwd=root,
                stdout=io.BytesIO(),
                stderr=io.BytesIO(),
            )
            self.assertEqual(0, result)
            self.assertEqual([str(original)] * 2, [path.read_text() for path in paths])

    def test_both_commands_complete_and_publish_full_output_in_fixed_order(self) -> None:
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
                "sys.stdout.write(sys.argv[3] * 200000)",
                "sys.stderr.write(sys.argv[4] * 200000)",
                "sys.exit(int(sys.argv[5]))",
            ))
            commands = [
                ("phpstan", [sys.executable, "-c", program, str(first), str(second), "A", "a", "4"]),
                ("test:aggregate", [sys.executable, "-c", program, str(second), str(first), "B", "b", "7"]),
            ]
            for first_exit, second_exit, expected in ((4, 0, 4), (0, 7, 7), (4, 7, 4)):
                commands[0][1][-1] = str(first_exit)
                commands[1][1][-1] = str(second_exit)
                first.unlink(missing_ok=True)
                second.unlink(missing_ok=True)
                stdout = io.BytesIO()
                stderr = io.BytesIO()
                result = MODULE.run_checks(commands, cwd=root, stdout=stdout, stderr=stderr)
                self.assertEqual(expected, result)
                self.assertEqual(
                    b"===== phpstan stdout =====\n" + b"A" * 200000
                    + b"===== test:aggregate stdout =====\n" + b"B" * 200000,
                    stdout.getvalue(),
                )
                self.assertEqual(
                    b"===== phpstan stderr =====\n" + b"a" * 200000 + f"[phpstan] exit {first_exit}\n".encode()
                    + b"===== test:aggregate stderr =====\n" + b"b" * 200000
                    + f"[test:aggregate] exit {second_exit}\n".encode(),
                    stderr.getvalue(),
                )

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
                            ("test:aggregate", [str(root / "missing-executable")]),
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
                commands = [(name, [sys.executable, "-c", program, str(pid)]) for name, pid in zip(("phpstan", "test:aggregate"), pid_files)]
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
                    self.assertIn(b"===== test:aggregate stdout =====", stdout)
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
                ("test:aggregate", [sys.executable, "-c", "import time; time.sleep(30)"]),
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


def start_harness(root: Path, commands: list[tuple[str, list[str]]]) -> subprocess.Popen[bytes]:
    program = (
        "import importlib.util,json,sys; "
        "spec=importlib.util.spec_from_file_location('check_code_parallel',sys.argv[1]); "
        "module=importlib.util.module_from_spec(spec); sys.modules[spec.name]=module; "
        "spec.loader.exec_module(module); "
        "raise SystemExit(module.run_checks(json.loads(sys.argv[2]),cwd=__import__('pathlib').Path(sys.argv[3]),shutdown_grace_seconds=.2))"
    )
    return subprocess.Popen(
        [sys.executable, "-c", program, str(SCRIPT), json.dumps(commands), str(root)],
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
