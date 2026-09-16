#!/usr/bin/env python3
"""Run the configured PHPUnit aggregate as isolated, verified suite shards.

The runner deliberately proves that every configured suite in SUITES partitions
the same test IDs as the aggregate before it starts a shard. That prevents a
fast, green parallel run from silently omitting a directory or executing a test
twice. A suite declared in phpunit.xml.dist and missing from SUITES therefore
refuses the run rather than disappearing from it.

Usage:
    python3 scripts/phpunit-aggregate.py [--jobs=1..N] [--timeout=SECONDS]
    (N is len(SUITES); the default runs every shard concurrently.)

    python3 scripts/phpunit-aggregate.py --print-commands --cache-root=DIR
    prints, as JSON, the exact argv each suite shard would be started with.
    It runs nothing. A reader that has to know what `composer check` executes
    gets it from here rather than from a second reading of this file, and
    shard_command() is the single place both the print and the run come from.

The command names the configuration explicitly and retains the aggregate's
no-coverage, benchmark, and live-freshness exclusions. Suite output is captured per shard, then published only after the
run in the fixed PHPUnit-suite order.
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import signal
import subprocess
import sys
import tempfile
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Sequence


ROOT = Path(__file__).resolve().parent.parent
SUITES = ("Unit", "Integration", "Functional", "Infrastructure", "Governance")
# Named rather than left to PHPUnit's search, which takes a local phpunit.xml
# ahead of phpunit.xml.dist: that file is git-ignored, so a developer's tree
# would run a different configuration than CI while both reported success.
CONFIGURATION = ROOT / "phpunit.xml.dist"
COMMON_ARGUMENTS = (
    f"--configuration={CONFIGURATION}",
    "--no-coverage",
    "--exclude-group=benchmark",
    "--exclude-group=live-freshness",
)
REFUSAL_EXIT = 2
TIMEOUT_EXIT = 124


class RunnerRefusal(RuntimeError):
    """The runner cannot prove that parallel execution preserves aggregate coverage."""


@dataclass
class Shard:
    suite: str
    stdout_path: Path
    stderr_path: Path
    process: subprocess.Popen[bytes] | None = None
    stdout_handle: object | None = None
    stderr_handle: object | None = None
    process_group: int | None = None
    exit_code: int | None = None


def parse_positive_int(value: str) -> int:
    try:
        parsed = int(value)
    except ValueError as error:
        raise argparse.ArgumentTypeError("must be an integer") from error
    if not 1 <= parsed <= len(SUITES):
        raise argparse.ArgumentTypeError(f"must be between 1 and {len(SUITES)}")
    return parsed


def parse_positive_seconds(value: str) -> float:
    try:
        parsed = float(value)
    except ValueError as error:
        raise argparse.ArgumentTypeError("must be a number of seconds") from error
    if parsed <= 0:
        raise argparse.ArgumentTypeError("must be greater than zero")
    return parsed


def parse_arguments(arguments: Sequence[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--phpunit",
        type=Path,
        default=ROOT / "vendor/bin/phpunit",
        help="PHPUnit executable (default: %(default)s)",
    )
    parser.add_argument("--jobs", type=parse_positive_int, default=len(SUITES), help="Concurrent suite shards")
    parser.add_argument(
        "--print-commands",
        action="store_true",
        help="Print the per-suite commands as JSON and exit without running anything",
    )
    parser.add_argument(
        "--cache-root",
        type=Path,
        default=None,
        help="Cache root the printed commands point at (required with --print-commands)",
    )
    parser.add_argument(
        "--timeout",
        type=parse_positive_seconds,
        default=900.0,
        help="Deadline for discovery and all suite shards in seconds",
    )
    parser.add_argument(
        "--heartbeat",
        type=parse_positive_seconds,
        default=30.0,
        help="Progress heartbeat interval in seconds",
    )
    return parser.parse_args(arguments)


def list_command(phpunit: Path, suite: str | None) -> list[str]:
    command = [str(phpunit), "--list-tests", *COMMON_ARGUMENTS]
    if suite is not None:
        command.append(f"--testsuite={suite}")
    return command


def shard_command(phpunit: Path, suite: str, cache_directory: Path) -> list[str]:
    """The exact argv one suite shard is started with.

    Both the run and `--print-commands` come through here, so what a reader is
    told `composer check` executes cannot drift from what it executes.
    """
    return [
        str(phpunit),
        *COMMON_ARGUMENTS,
        f"--cache-directory={cache_directory}",
        f"--testsuite={suite}",
    ]


def printable_commands(phpunit: Path, cache_root: Path) -> dict[str, list[str]]:
    return {suite: shard_command(phpunit, suite, cache_root / suite) for suite in SUITES}


def run_listing(phpunit: Path, suite: str | None, timeout: float) -> list[str]:
    try:
        completed = subprocess.run(
            list_command(phpunit, suite),
            cwd=ROOT,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=timeout,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        label = "aggregate" if suite is None else f"suite {suite}"
        raise RunnerRefusal(f"cannot list tests for {label}: {error}") from error

    label = "aggregate" if suite is None else f"suite {suite}"
    if completed.returncode != 0:
        raise RunnerRefusal(f"cannot list tests for {label}: PHPUnit exited {completed.returncode}")
    if completed.stderr:
        raise RunnerRefusal(f"cannot list tests for {label}: PHPUnit wrote to stderr")

    try:
        stdout = completed.stdout.decode("utf-8", errors="strict")
    except UnicodeDecodeError as error:
        raise RunnerRefusal(f"cannot list tests for {label}: stdout is not UTF-8") from error
    return parse_test_ids(stdout, label)


def parse_test_ids(output: str, label: str) -> list[str]:
    """Parse PHPUnit 12's documented human-readable list without guessing around noise."""
    lines = output.splitlines()
    headers = [index for index, line in enumerate(lines) if line == "Available tests:"]
    if len(headers) != 1:
        raise RunnerRefusal(f"cannot parse test list for {label}: expected one Available tests header")

    identifiers: list[str] = []
    for line in lines[headers[0] + 1 :]:
        if not line.startswith(" - "):
            raise RunnerRefusal(f"cannot parse test list for {label}: unexpected line after header")
        identifier = line[3:]
        if not identifier:
            raise RunnerRefusal(f"cannot parse test list for {label}: empty test identifier")
        identifiers.append(identifier)

    if not identifiers:
        raise RunnerRefusal(f"cannot parse test list for {label}: no test identifiers")
    duplicates = duplicate_ids(identifiers)
    if duplicates:
        raise RunnerRefusal(
            f"cannot parse test list for {label}: duplicate test identifiers: {', '.join(duplicates)}"
        )
    return identifiers


def duplicate_ids(identifiers: Sequence[str]) -> list[str]:
    seen: set[str] = set()
    duplicates: set[str] = set()
    for identifier in identifiers:
        if identifier in seen:
            duplicates.add(identifier)
        seen.add(identifier)
    return sorted(duplicates)


def assert_partition(expected: Sequence[str], suite_ids: dict[str, Sequence[str]]) -> None:
    expected_duplicates = duplicate_ids(expected)
    if expected_duplicates:
        raise RunnerRefusal(f"aggregate list has duplicate test identifiers: {', '.join(expected_duplicates)}")

    owner_by_id: dict[str, str] = {}
    overlaps: list[str] = []
    for suite in SUITES:
        for identifier in suite_ids[suite]:
            previous_owner = owner_by_id.setdefault(identifier, suite)
            if previous_owner != suite:
                overlaps.append(f"{identifier} ({previous_owner}, {suite})")

    expected_set = set(expected)
    actual_set = set(owner_by_id)
    missing = sorted(expected_set - actual_set)
    unexpected = sorted(actual_set - expected_set)
    if overlaps or missing or unexpected:
        details: list[str] = []
        if overlaps:
            details.append("overlaps=" + "; ".join(sorted(overlaps)))
        if missing:
            details.append("missing=" + ", ".join(missing))
        if unexpected:
            details.append("unexpected=" + ", ".join(unexpected))
        raise RunnerRefusal("PHPUnit suite partition mismatch: " + " | ".join(details))


def discover_partition(phpunit: Path, deadline: float) -> None:
    expected = run_listing(phpunit, None, remaining_seconds(deadline))
    suites = {
        suite: run_listing(phpunit, suite, remaining_seconds(deadline))
        for suite in SUITES
    }
    assert_partition(expected, suites)


def remaining_seconds(deadline: float) -> float:
    remaining = deadline - time.monotonic()
    if remaining <= 0:
        raise RunnerRefusal("deadline expired before PHPUnit discovery completed")
    return remaining


def start_shard(phpunit: Path, shard: Shard, cache_root: Path) -> None:
    cache_directory = cache_root / shard.suite
    cache_directory.mkdir()
    command = shard_command(phpunit, shard.suite, cache_directory)
    shard.stdout_handle = shard.stdout_path.open("wb")
    shard.stderr_handle = shard.stderr_path.open("wb")
    try:
        shard.process = subprocess.Popen(
            command,
            cwd=ROOT,
            stdout=shard.stdout_handle,
            stderr=shard.stderr_handle,
            start_new_session=True,
        )
        shard.process_group = shard.process.pid
    except OSError:
        close_shard_handles(shard)
        raise


def close_shard_handles(shard: Shard) -> None:
    for handle_name in ("stdout_handle", "stderr_handle"):
        handle = getattr(shard, handle_name)
        if handle is not None:
            handle.close()
            setattr(shard, handle_name, None)


def terminate_shard(shard: Shard) -> None:
    if shard.process is None or shard.process_group is None:
        return
    process_group = shard.process_group
    signal_process_group(process_group, signal.SIGTERM)
    try:
        shard.process.wait(timeout=2)
    except subprocess.TimeoutExpired:
        # The session leader is still alive, so its process group cannot have
        # been recycled between the TERM grace period and this hard stop.
        signal_process_group(process_group, signal.SIGKILL)
        try:
            shard.process.wait(timeout=2)
        except subprocess.TimeoutExpired as error:
            raise RunnerRefusal(f"process group {process_group} leader survived SIGKILL") from error
        return

    # The leader may exit on TERM while a worker ignores it. Kill its former
    # group immediately: a vanished group raises ProcessLookupError, whereas a
    # surviving child still has the original group and receives SIGKILL.
    signal_process_group(process_group, signal.SIGKILL)


def terminate_shards(shards: Sequence[Shard]) -> None:
    first_error: RunnerRefusal | None = None
    for shard in shards:
        try:
            terminate_shard(shard)
        except RunnerRefusal as error:
            if first_error is None:
                first_error = error
        finally:
            if shard.exit_code is None:
                shard.exit_code = TIMEOUT_EXIT
            close_shard_handles(shard)

    if first_error is not None:
        raise first_error


def signal_process_group(process_group: int, signal_number: int) -> None:
    try:
        os.killpg(process_group, signal_number)
    except ProcessLookupError:
        pass
    except PermissionError as error:
        raise RunnerRefusal(f"cannot signal process group {process_group}") from error


def run_shards(phpunit: Path, jobs: int, deadline: float, heartbeat: float) -> list[Shard]:
    cache_root = Path(tempfile.mkdtemp(prefix="qmx-phpunit-aggregate-"))
    shards = [
        Shard(suite, cache_root / f"{suite}.stdout", cache_root / f"{suite}.stderr") for suite in SUITES
    ]
    pending = list(shards)
    running: list[Shard] = []
    # The first pulse confirms that the shard phase has actually started; later
    # pulses distinguish a long but live run from a runner stalled before launch.
    next_heartbeat = time.monotonic()

    try:
        try:
            while pending or running:
                if time.monotonic() >= deadline:
                    for shard in pending:
                        shard.stdout_path.write_text("", encoding="utf-8")
                        shard.stderr_path.write_text("not started: aggregate deadline expired\n", encoding="utf-8")
                        shard.exit_code = TIMEOUT_EXIT
                    break

                while pending and len(running) < jobs:
                    shard = pending.pop(0)
                    try:
                        start_shard(phpunit, shard, cache_root)
                    except OSError as error:
                        shard.stdout_path.write_text("", encoding="utf-8")
                        shard.stderr_path.write_text(f"could not start PHPUnit: {error}\n", encoding="utf-8")
                        shard.exit_code = REFUSAL_EXIT
                        continue
                    running.append(shard)

                for shard in list(running):
                    assert shard.process is not None
                    exit_code = shard.process.poll()
                    if exit_code is None:
                        continue
                    shard.exit_code = exit_code
                    close_shard_handles(shard)
                    running.remove(shard)

                now = time.monotonic()
                if running and now >= next_heartbeat:
                    names = ", ".join(shard.suite for shard in running)
                    print(f"[phpunit-aggregate] still running: {names}", file=sys.stderr, flush=True)
                    next_heartbeat = now + heartbeat
                if running:
                    time.sleep(min(0.05, max(0.0, deadline - time.monotonic())))
        finally:
            terminate_shards(running)

        publish_shards(shards)
        return shards
    finally:
        shutil.rmtree(cache_root, ignore_errors=True)


def publish_shards(shards: Sequence[Shard]) -> None:
    for shard in shards:
        assert shard.exit_code is not None
        print(f"===== PHPUnit suite: {shard.suite} (exit {shard.exit_code}) =====")
        print("--- stdout ---")
        sys.stdout.write(shard.stdout_path.read_text(encoding="utf-8", errors="replace"))
        if not shard.stdout_path.read_bytes().endswith(b"\n"):
            print()
        print("--- stderr ---")
        sys.stdout.write(shard.stderr_path.read_text(encoding="utf-8", errors="replace"))
        if not shard.stderr_path.read_bytes().endswith(b"\n"):
            print()


def main(arguments: Sequence[str] | None = None) -> int:
    args = parse_arguments(sys.argv[1:] if arguments is None else arguments)
    if args.print_commands:
        if args.cache_root is None:
            print("phpunit aggregate refusal: --print-commands needs --cache-root", file=sys.stderr)
            return REFUSAL_EXIT
        json.dump(
            {
                "phpunit": str(args.phpunit),
                "configuration": str(CONFIGURATION),
                "commands": printable_commands(args.phpunit, args.cache_root),
            },
            sys.stdout,
            indent=2,
        )
        sys.stdout.write("\n")
        return 0
    if os.name != "posix":
        print("phpunit aggregate refusal: isolated process groups require a POSIX platform", file=sys.stderr)
        return REFUSAL_EXIT
    deadline = time.monotonic() + args.timeout
    try:
        discover_partition(args.phpunit, deadline)
        shards = run_shards(args.phpunit, args.jobs, deadline, args.heartbeat)
    except RunnerRefusal as error:
        print(f"phpunit aggregate refusal: {error}", file=sys.stderr)
        return REFUSAL_EXIT

    return 0 if all(shard.exit_code == 0 for shard in shards) else 1


if __name__ == "__main__":
    raise SystemExit(main())
