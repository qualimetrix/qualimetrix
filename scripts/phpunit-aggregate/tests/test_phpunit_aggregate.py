#!/usr/bin/env python3
"""Focused contract tests for the parallel PHPUnit aggregate runner."""

from __future__ import annotations

import json
import importlib.util
import os
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest import mock


TEST_ROOT = Path(__file__).parent
PROJECT_ROOT = TEST_ROOT.parents[2]
RUNNER = PROJECT_ROOT / "scripts/phpunit-aggregate.py"
FAKE_PHPUNIT = TEST_ROOT / "Fixtures/fake_phpunit.py"
SUITES = ("Unit", "Integration", "Functional", "Infrastructure", "Tooling", "Governance")


def load_runner_module():
    specification = importlib.util.spec_from_file_location("qmx_phpunit_aggregate", RUNNER)
    assert specification is not None and specification.loader is not None
    module = importlib.util.module_from_spec(specification)
    sys.modules[specification.name] = module
    specification.loader.exec_module(module)
    return module


def complete_configuration() -> dict[str, object]:
    suites = {
        suite: [f"Example\\{suite}Test::itRuns"]
        for suite in SUITES
    }
    return {
        "aggregate": [identifier for identifiers in suites.values() for identifier in identifiers],
        "suites": suites,
    }


class PhpunitAggregateTest(unittest.TestCase):
    def run_runner(
        self,
        configuration: dict[str, object],
        *arguments: str,
        record_directory: Path | None = None,
        profile: bool = False,
    ) -> subprocess.CompletedProcess[str]:
        with tempfile.TemporaryDirectory(prefix="qmx-phpunit-aggregate-test-") as directory:
            configuration_path = Path(directory) / "configuration.json"
            configuration_path.write_text(json.dumps(configuration), encoding="utf-8")
            environment = os.environ.copy()
            environment["FAKE_PHPUNIT_CONFIGURATION"] = configuration_path.read_text(encoding="utf-8")
            environment.pop("QMX_PHPUNIT_PROFILE", None)
            if profile:
                environment["QMX_PHPUNIT_PROFILE"] = "1"
            if record_directory is not None:
                environment["FAKE_PHPUNIT_RECORD_DIRECTORY"] = str(record_directory)
            return subprocess.run(
                [
                    sys.executable,
                    str(RUNNER),
                    f"--phpunit={FAKE_PHPUNIT}",
                    "--heartbeat=0.01",
                    *arguments,
                ],
                cwd=PROJECT_ROOT,
                env=environment,
                capture_output=True,
                text=True,
                check=False,
                timeout=10,
            )

    def test_refuses_union_mismatch_before_any_suite_runs(self):
        configuration = complete_configuration()
        configuration["aggregate"] = ["Example\\UnitTest::itRuns"]

        completed = self.run_runner(configuration)

        self.assertEqual(2, completed.returncode)
        self.assertIn("PHPUnit suite partition mismatch", completed.stderr)
        self.assertIn("unexpected=Example\\FunctionalTest::itRuns", completed.stderr)
        self.assertNotIn("stdout", completed.stdout)

    def test_refuses_duplicate_identifiers_across_suites(self):
        configuration = complete_configuration()
        suites = configuration["suites"]
        assert isinstance(suites, dict)
        suites["Functional"] = ["Example\\UnitTest::itRuns"]
        configuration["aggregate"] = [
            "Example\\UnitTest::itRuns",
            "Example\\IntegrationTest::itRuns",
            "Example\\InfrastructureTest::itRuns",
            "Example\\GovernanceTest::itRuns",
        ]

        completed = self.run_runner(configuration)

        self.assertEqual(2, completed.returncode)
        self.assertIn("overlaps=Example\\UnitTest::itRuns (Unit, Functional)", completed.stderr)

    def test_refuses_an_unparseable_phpunit_listing_before_any_suite_runs(self):
        configuration = complete_configuration()
        configuration["malformed_listing"] = True

        completed = self.run_runner(configuration)

        self.assertEqual(2, completed.returncode)
        self.assertIn("cannot parse test list", completed.stderr)
        self.assertNotIn("stdout", completed.stdout)

    def test_propagates_a_nonzero_shard_exit_after_publishing_every_suite(self):
        configuration = complete_configuration()
        configuration["run"] = {
            "Unit": {"stdout": "unit output", "stderr": "unit diagnostics"},
            "Integration": {"stdout": "integration output", "exit": 7},
            "Functional": {"stdout": "functional output"},
            "Infrastructure": {"stdout": "infrastructure output"},
            "Governance": {"stdout": "governance output"},
        }

        completed = self.run_runner(configuration)

        self.assertEqual(1, completed.returncode)
        self.assertIn("===== PHPUnit suite: Integration (exit 7) =====", completed.stdout)
        self.assertIn("unit diagnostics", completed.stdout)
        self.assertIn("infrastructure output", completed.stdout)

    def test_publishes_captured_output_in_phpunit_suite_order_not_completion_order(self):
        configuration = complete_configuration()
        configuration["run"] = {
            "Unit": {"stdout": "unit", "sleep": 0.12},
            "Integration": {"stdout": "integration", "sleep": 0.08},
            "Functional": {"stdout": "functional", "sleep": 0.04},
            "Infrastructure": {"stdout": "infrastructure", "sleep": 0.01},
            "Governance": {"stdout": "governance", "sleep": 0.02},
        }

        completed = self.run_runner(configuration, f"--jobs={len(SUITES)}", "--timeout=2")

        self.assertEqual(0, completed.returncode, completed.stderr)
        positions = [completed.stdout.index(f"===== PHPUnit suite: {suite}") for suite in SUITES]
        self.assertEqual(positions, sorted(positions))
        self.assertLess(completed.stdout.index("unit\n"), completed.stdout.index("infrastructure\n"))

    def test_gives_each_shard_a_distinct_cache_that_is_removed_after_publication(self):
        configuration = complete_configuration()
        with tempfile.TemporaryDirectory(prefix="qmx-phpunit-cache-record-") as directory:
            record_directory = Path(directory)
            completed = self.run_runner(configuration, record_directory=record_directory)
            records = [
                json.loads((record_directory / f"{suite}.json").read_text(encoding="utf-8"))
                for suite in SUITES
            ]

        self.assertEqual(0, completed.returncode, completed.stderr)
        cache_directories = [record["cache_directory"] for record in records]
        self.assertEqual(len(SUITES), len(set(cache_directories)))
        self.assertTrue(all(not Path(cache_directory).exists() for cache_directory in cache_directories))
        self.assertTrue(all(not any(arg.startswith("--log-junit=") for arg in record["arguments"]) for record in records))

    def test_profile_keeps_suite_selection_and_reports_measured_cases(self):
        configuration = complete_configuration()
        configuration["run"] = {"Tooling": {"case_time": 2.5}}
        with tempfile.TemporaryDirectory(prefix="qmx-phpunit-profile-record-") as directory:
            record_directory = Path(directory)
            completed = self.run_runner(configuration, record_directory=record_directory, profile=True)
            records = [json.loads((record_directory / f"{suite}.json").read_text(encoding="utf-8")) for suite in SUITES]

        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("discovery wall time:", completed.stdout)
        self.assertIn("shard phase wall time:", completed.stdout)
        self.assertIn("2.500s Tooling Example\\ToolingTest::itRuns", completed.stdout)
        self.assertTrue(all(any(arg.startswith("--log-junit=") for arg in record["arguments"]) for record in records))
        self.assertTrue(all(not Path(record["cache_directory"]).exists() for record in records))

    def test_profile_refuses_missing_junit_only_after_success(self):
        configuration = complete_configuration()
        configuration["run"] = {"Tooling": {"junit": "missing"}}

        successful = self.run_runner(configuration, profile=True)
        self.assertEqual(2, successful.returncode)
        self.assertIn("enabled PHPUnit profile is incomplete", successful.stderr)
        self.assertIn("partial measurement", successful.stdout)

        configuration["run"]["Tooling"]["exit"] = 7
        failed = self.run_runner(configuration, profile=True)
        self.assertEqual(1, failed.returncode)
        self.assertIn("partial measurement", failed.stdout)
        self.assertNotIn("enabled PHPUnit profile is incomplete", failed.stderr)

    def test_terminates_overdue_shards_and_cleans_up_before_returning(self):
        configuration = complete_configuration()
        configuration["run"] = {suite: {"sleep": 30} for suite in SUITES}

        completed = self.run_runner(configuration, "--timeout=5", f"--jobs={len(SUITES)}")

        self.assertEqual(1, completed.returncode)
        self.assertIn("===== PHPUnit suite: Unit (exit 124) =====", completed.stdout)
        self.assertIn("still running:", completed.stderr)

    def test_timeout_kills_a_term_ignoring_child_in_the_shard_process_group(self):
        configuration = complete_configuration()
        configuration["run"] = {
            "Unit": {"sleep": 30, "spawn_term_ignoring_child": True},
            "Integration": {"sleep": 30},
            "Functional": {"sleep": 30},
            "Infrastructure": {"sleep": 30},
            "Governance": {"sleep": 30},
        }
        with tempfile.TemporaryDirectory(prefix="qmx-phpunit-child-record-") as directory:
            record_directory = Path(directory)
            completed = self.run_runner(
                configuration,
                "--timeout=2",
                f"--jobs={len(SUITES)}",
                record_directory=record_directory,
            )
            child_pid = int((record_directory / "Unit.child-pid").read_text(encoding="utf-8"))

        self.assertEqual(1, completed.returncode, completed.stderr)
        deadline = time.monotonic() + 1
        while process_exists(child_pid) and time.monotonic() < deadline:
            time.sleep(0.02)
        self.assertFalse(process_exists(child_pid), f"timeout left child process {child_pid} alive")

    def test_printed_commands_are_built_by_the_function_that_starts_a_shard(self):
        """A printed command a reader trusts has to be the command that runs.

        Spying on shard_command proves both paths go through it, and comparing
        the printed argv with what Popen was handed proves nothing was added on
        the way out.
        """
        runner = load_runner_module()
        original = runner.shard_command
        callers: list[str] = []

        def spy(phpunit, suite, cache_directory):
            callers.append(suite)
            return original(phpunit, suite, cache_directory)

        phpunit = Path("vendor/bin/phpunit")
        with tempfile.TemporaryDirectory(prefix="qmx-shard-command-") as directory:
            cache_root = Path(directory)
            shard = runner.Shard("Unit", cache_root / "Unit.stdout", cache_root / "Unit.stderr")
            with mock.patch.object(runner, "shard_command", side_effect=spy):
                printed = runner.printable_commands(phpunit, cache_root)
                with mock.patch.object(runner.subprocess, "Popen") as popen:
                    runner.start_shard(phpunit, shard, cache_root)
            runner.close_shard_handles(shard)

        self.assertEqual([*SUITES, "Unit"], callers)
        self.assertEqual(printed["Unit"], popen.call_args.args[0])

    def test_print_commands_writes_json_for_every_suite_and_runs_nothing(self):
        with tempfile.TemporaryDirectory(prefix="qmx-print-commands-") as directory:
            completed = subprocess.run(
                [
                    sys.executable,
                    str(RUNNER),
                    f"--phpunit={FAKE_PHPUNIT}",
                    "--print-commands",
                    f"--cache-root={directory}",
                ],
                cwd=PROJECT_ROOT,
                capture_output=True,
                text=True,
                check=False,
                timeout=10,
            )

        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertEqual("", completed.stderr)
        printed = json.loads(completed.stdout)
        self.assertEqual(str(FAKE_PHPUNIT), printed["phpunit"])
        self.assertEqual(list(SUITES), list(printed["commands"]))
        for suite, command in printed["commands"].items():
            self.assertEqual(str(FAKE_PHPUNIT), command[0])
            self.assertIn(f"--testsuite={suite}", command)

    def test_print_commands_in_profile_mode_names_the_junit_each_shard_would_write(self):
        with tempfile.TemporaryDirectory(prefix="qmx-print-profile-") as directory:
            environment = os.environ.copy()
            environment["QMX_PHPUNIT_PROFILE"] = "1"
            completed = subprocess.run(
                [sys.executable, str(RUNNER), f"--phpunit={FAKE_PHPUNIT}",
                 "--print-commands", f"--cache-root={directory}"],
                cwd=PROJECT_ROOT, env=environment, capture_output=True, text=True, check=False, timeout=10,
            )

            self.assertEqual(0, completed.returncode, completed.stderr)
            printed = json.loads(completed.stdout)
            for suite, command in printed["commands"].items():
                self.assertIn(f"--log-junit={directory}/{suite}/junit.xml", command)

    def test_both_command_builders_select_from_one_argument_tuple(self):
        """The partition proof and the shard must not select differently.

        The runner builds two commands: one lists tests to prove the suites
        partition the aggregate, the other runs a shard. A selecting argument
        added to only one of them would prove coverage over a set nobody runs,
        or run a set nobody proved. Stripping the one argument each adds leaves
        two identical lists, which is the property.
        """
        runner = load_runner_module()
        phpunit = Path("vendor/bin/phpunit")
        cache_directory = Path("/tmp/qmx-cache/Unit")

        listing = [
            argument
            for argument in runner.list_command(phpunit, "Unit")
            if argument != "--list-tests"
        ]
        shard = [
            argument
            for argument in runner.shard_command(phpunit, "Unit", cache_directory)
            if not argument.startswith("--cache-directory=")
        ]

        self.assertEqual(listing, shard)

    def test_phpunit_excludes_each_dedicated_group_and_keeps_ordinary_cases(self):
        runner = load_runner_module()
        with tempfile.TemporaryDirectory(prefix="qmx-group-selection-") as directory:
            fixture = Path(directory) / "GroupSelectionTest.php"
            fixture.write_text("""<?php
namespace AggregateFixture;
use PHPUnit\\Framework\\Attributes\\{Group, Test};
use PHPUnit\\Framework\\TestCase;
final class GroupSelectionTest extends TestCase {
    #[Test] public function itRunsOrdinaryCases(): void {}
    #[Test, Group('benchmark')] public function itRunsBenchmarks(): void {}
    #[Test, Group('live-freshness')] public function itRunsLiveFreshness(): void {}
    #[Test, Group('finding-gate-e2e')] public function itRunsGateCapture(): void {}
}
""", encoding="utf-8")
            completed = subprocess.run(
                [*runner.list_command(PROJECT_ROOT / "vendor/bin/phpunit", None), str(fixture)],
                cwd=PROJECT_ROOT, capture_output=True, text=True, check=False, timeout=10,
            )
        self.assertEqual(0, completed.returncode, completed.stderr)
        cases = [line.strip() for line in completed.stdout.splitlines() if line.startswith(" - ")]
        self.assertEqual(["- AggregateFixture\\GroupSelectionTest::itRunsOrdinaryCases"], cases)

    def test_every_command_names_the_configuration_instead_of_searching_for_it(self):
        """A local phpunit.xml outranks phpunit.xml.dist in PHPUnit's search.

        That file is git-ignored, so leaving the choice to PHPUnit would let a
        developer's tree run one configuration while CI ran another, both green.
        """
        runner = load_runner_module()
        phpunit = Path("vendor/bin/phpunit")
        expected = f"--configuration={PROJECT_ROOT / 'phpunit.xml.dist'}"

        self.assertIn(expected, runner.list_command(phpunit, None))
        self.assertIn(expected, runner.list_command(phpunit, "Unit"))
        for suite, command in runner.printable_commands(phpunit, Path("/tmp/qmx-cache")).items():
            self.assertIn(expected, command, suite)

    def test_print_commands_names_the_configuration_it_passes(self):
        with tempfile.TemporaryDirectory(prefix="qmx-print-configuration-") as directory:
            completed = subprocess.run(
                [
                    sys.executable,
                    str(RUNNER),
                    f"--phpunit={FAKE_PHPUNIT}",
                    "--print-commands",
                    f"--cache-root={directory}",
                ],
                cwd=PROJECT_ROOT,
                capture_output=True,
                text=True,
                check=False,
                timeout=10,
            )

        self.assertEqual(0, completed.returncode, completed.stderr)
        printed = json.loads(completed.stdout)
        self.assertEqual(str(PROJECT_ROOT / "phpunit.xml.dist"), printed["configuration"])
        for suite, command in printed["commands"].items():
            self.assertIn(f"--configuration={printed['configuration']}", command, suite)

    def test_print_commands_refuses_without_a_cache_root(self):
        completed = subprocess.run(
            [sys.executable, str(RUNNER), f"--phpunit={FAKE_PHPUNIT}", "--print-commands"],
            cwd=PROJECT_ROOT,
            capture_output=True,
            text=True,
            check=False,
            timeout=10,
        )

        self.assertEqual(2, completed.returncode)
        self.assertIn("--print-commands needs --cache-root", completed.stderr)

    def test_attempts_to_terminate_every_shard_when_one_cleanup_fails(self):
        runner = load_runner_module()
        shards = [
            runner.Shard(suite, Path(f"{suite}.stdout"), Path(f"{suite}.stderr"))
            for suite in SUITES
        ]
        calls = []

        def terminate(shard):
            calls.append(shard.suite)
            if shard.suite == "Unit":
                raise runner.RunnerRefusal("simulated termination failure")

        with mock.patch.object(runner, "terminate_shard", side_effect=terminate):
            with self.assertRaisesRegex(runner.RunnerRefusal, "simulated termination failure"):
                runner.terminate_shards(shards)

        self.assertEqual(list(SUITES), calls)
        self.assertTrue(all(shard.exit_code == runner.TIMEOUT_EXIT for shard in shards))


def process_exists(process_id: int) -> bool:
    try:
        os.kill(process_id, 0)
    except ProcessLookupError:
        return False
    return True


if __name__ == "__main__":
    unittest.main()
