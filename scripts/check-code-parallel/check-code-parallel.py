#!/usr/bin/env python3
"""Run PHPStan beside documentation, artifact and self-analysis checks.

Each child writes to regular files, so verbose output cannot block either
process. Output is published in command order after both have completed.
"""

from __future__ import annotations

import os
import signal
import subprocess
import sys
import tempfile
import time
from dataclasses import dataclass
from pathlib import Path
from typing import BinaryIO, Sequence


ROOT = Path(__file__).resolve().parents[2]
COMMANDS = (
    ("phpstan", ("composer", "phpstan")),
    ("check:remaining", ("composer", "check:remaining")),
)
SHUTDOWN_GRACE_SECONDS = 30.0
POLL_SECONDS = 0.05
REFUSAL_EXIT = 2


@dataclass
class Child:
    name: str
    command: Sequence[str]
    stdout_path: Path
    stderr_path: Path
    process: subprocess.Popen[bytes] | None = None
    exit_code: int | None = None


def signal_group(process: subprocess.Popen[bytes], signum: int) -> None:
    try:
        os.killpg(process.pid, signum)
    except ProcessLookupError:
        pass


def group_alive(process: subprocess.Popen[bytes]) -> bool:
    # Reap an exited session leader before asking the OS about its group.
    # A remaining descendant still keeps that group addressable.
    process.poll()
    try:
        os.killpg(process.pid, 0)
    except ProcessLookupError:
        return False
    return True


def stop_groups(children: Sequence[Child], grace_seconds: float) -> str | None:
    """Attempt cleanup of every group and report the first OS refusal."""
    errors: list[str] = []
    reported: set[tuple[str, int, int | None]] = set()

    def remember(error: OSError, process: subprocess.Popen[bytes], action: str) -> None:
        key = (action, process.pid, error.errno)
        if key not in reported:
            reported.add(key)
            errors.append(f"{action} process group {process.pid}: {error}")

    for child in children:
        if child.process is not None:
            try:
                signal_group(child.process, signal.SIGTERM)
            except OSError as error:
                remember(error, child.process, "cannot TERM")

    deadline = time.monotonic() + grace_seconds
    while time.monotonic() < deadline:
        active = False
        for child in children:
            if child.process is None:
                continue
            try:
                active = group_alive(child.process) or active
            except OSError as error:
                remember(error, child.process, "cannot inspect")
                active = True
        if not active:
            break
        time.sleep(POLL_SECONDS)

    # A leader may have exited while a descendant ignores TERM. Address the
    # original groups even in that case, then reap every direct child.
    for child in children:
        if child.process is None:
            continue
        try:
            active = group_alive(child.process)
        except OSError as error:
            remember(error, child.process, "cannot inspect")
            active = True
        if active:
            try:
                signal_group(child.process, signal.SIGKILL)
            except OSError as error:
                remember(error, child.process, "cannot KILL")
    for child in children:
        if child.process is not None:
            try:
                child.exit_code = child.process.wait(timeout=2)
            except subprocess.TimeoutExpired:
                errors.append(f"process group {child.process.pid} leader survived SIGKILL")
    return errors[0] if errors else None


def publish_file(source: Path, destination: BinaryIO) -> None:
    with source.open("rb") as captured:
        while chunk := captured.read(1024 * 1024):
            destination.write(chunk)
    destination.flush()


def publish(children: Sequence[Child], stdout: BinaryIO, stderr: BinaryIO) -> None:
    for child in children:
        stdout.write(f"===== {child.name} stdout =====\n".encode())
        stdout.flush()
        publish_file(child.stdout_path, stdout)
        stderr.write(f"===== {child.name} stderr =====\n".encode())
        stderr.flush()
        publish_file(child.stderr_path, stderr)
        stderr.write(f"[{child.name}] exit {child.exit_code}\n".encode())
        stderr.flush()


def run_checks(
    commands: Sequence[tuple[str, Sequence[str]]] = COMMANDS,
    *,
    cwd: Path = ROOT,
    stdout: BinaryIO | None = None,
    stderr: BinaryIO | None = None,
    shutdown_grace_seconds: float = SHUTDOWN_GRACE_SECONDS,
) -> int:
    """Wait for every command; a failure does not cancel its peers."""
    if stdout is None:
        stdout = sys.stdout.buffer
    if stderr is None:
        stderr = sys.stderr.buffer
    if os.name != "posix":
        stderr.write(b"check-code-parallel: isolated process groups require POSIX\n")
        stderr.flush()
        return REFUSAL_EXIT

    interrupted: int | None = None

    def remember_signal(signum: int, _frame: object) -> None:
        nonlocal interrupted
        if interrupted is None:
            interrupted = signum

    previous_int = signal.signal(signal.SIGINT, remember_signal)
    previous_term = signal.signal(signal.SIGTERM, remember_signal)
    try:
        with tempfile.TemporaryDirectory(prefix="qmx-check-code-parallel-") as temporary:
            cache_root = Path(temporary)
            children = [
                Child(name, tuple(command), cache_root / f"{index}.stdout", cache_root / f"{index}.stderr")
                for index, (name, command) in enumerate(commands)
            ]
            launch_error: OSError | None = None
            cleanup_error: str | None = None
            cleanup_attempted = False
            try:
                for child in children:
                    if interrupted is not None:
                        break
                    with child.stdout_path.open("wb") as child_stdout, child.stderr_path.open("wb") as child_stderr:
                        try:
                            child.process = subprocess.Popen(
                                child.command,
                                cwd=cwd,
                                stdin=subprocess.DEVNULL,
                                stdout=child_stdout,
                                stderr=child_stderr,
                                start_new_session=True,
                            )
                        except OSError as error:
                            launch_error = error
                            break

                if launch_error is not None or interrupted is not None:
                    cleanup_attempted = True
                    cleanup_error = stop_groups(children, shutdown_grace_seconds)
                else:
                    while any(child.process is not None and child.process.poll() is None for child in children):
                        if interrupted is not None:
                            break
                        time.sleep(POLL_SECONDS)
                    if interrupted is not None:
                        cleanup_attempted = True
                        cleanup_error = stop_groups(children, shutdown_grace_seconds)
                    else:
                        for child in children:
                            if child.process is not None and child.exit_code is None:
                                child.exit_code = child.process.wait()
            finally:
                # An unexpected Python exception must not orphan the checks.
                if not cleanup_attempted and any(child.process is not None and child.exit_code is None for child in children):
                    cleanup_error = stop_groups(children, shutdown_grace_seconds) or cleanup_error

            publish([child for child in children if child.process is not None], stdout, stderr)
            if launch_error is not None:
                stderr.write(f"check-code-parallel: launch failed: {launch_error}\n".encode())
                stderr.flush()
            if cleanup_error is not None:
                stderr.write(f"check-code-parallel: cleanup refusal: {cleanup_error}\n".encode())
                stderr.flush()
                return REFUSAL_EXIT
            if launch_error is not None:
                return REFUSAL_EXIT
            if interrupted is not None:
                stderr.write(f"check-code-parallel: interrupted by {signal.Signals(interrupted).name}\n".encode())
                stderr.flush()
                return 128 + interrupted
            for child in children:
                if child.exit_code != 0:
                    assert child.exit_code is not None
                    return child.exit_code if child.exit_code > 0 else 128 - child.exit_code
            return 0
    finally:
        signal.signal(signal.SIGINT, previous_int)
        signal.signal(signal.SIGTERM, previous_term)


def main() -> int:
    return run_checks()


if __name__ == "__main__":
    raise SystemExit(main())
