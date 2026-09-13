#!/usr/bin/env python3
"""A deterministic PHPUnit stand-in for phpunit-aggregate runner tests."""

from __future__ import annotations

import json
import os
import signal
import subprocess
import sys
import time
from pathlib import Path


configuration = json.loads(os.environ["FAKE_PHPUNIT_CONFIGURATION"])
arguments = sys.argv[1:]
suite = next((argument.split("=", 1)[1] for argument in arguments if argument.startswith("--testsuite=")), None)

if "--list-tests" in arguments:
    if configuration.get("malformed_listing"):
        print("PHPUnit 12.0.0")
        print("Available tests:")
        print("unexpected output")
        raise SystemExit(0)
    if configuration.get("list_exit"):
        print("listing failed", file=sys.stderr)
        raise SystemExit(configuration["list_exit"])
    identifiers = configuration["aggregate"] if suite is None else configuration["suites"][suite]
    print("PHPUnit 12.0.0")
    print()
    print("Available tests:")
    for identifier in identifiers:
        print(f" - {identifier}")
    raise SystemExit(0)

behavior = configuration.get("run", {}).get(suite, {})
record_directory = os.environ.get("FAKE_PHPUNIT_RECORD_DIRECTORY")
if record_directory is not None:
    cache_directory = next(
        argument.split("=", 1)[1]
        for argument in arguments
        if argument.startswith("--cache-directory=")
    )
    Path(record_directory, f"{suite}.json").write_text(
        json.dumps({"suite": suite, "cache_directory": cache_directory}),
        encoding="utf-8",
    )
if behavior.get("spawn_term_ignoring_child"):
    child = subprocess.Popen(
        [
            sys.executable,
            "-c",
            "import signal, time; signal.signal(signal.SIGTERM, signal.SIG_IGN); time.sleep(30)",
        ],
    )
    if record_directory is not None:
        Path(record_directory, f"{suite}.child-pid").write_text(str(child.pid), encoding="utf-8")
time.sleep(behavior.get("sleep", 0))
print(behavior.get("stdout", f"{suite} stdout"))
print(behavior.get("stderr", f"{suite} stderr"), file=sys.stderr)
raise SystemExit(behavior.get("exit", 0))
