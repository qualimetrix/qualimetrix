#!/usr/bin/env python3
"""Deterministic tests for cross-tool methodology; no live tools are invoked."""

import importlib.util
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch


SCRIPT = Path(__file__).parents[1] / "cross-tool-comparison.py"
FIXTURES = Path(__file__).parent / "fixtures"
SPEC = importlib.util.spec_from_file_location("cross_tool_comparison", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class CrossToolComparisonTest(unittest.TestCase):
    def setUp(self):
        self.qmx = MODULE.parse_qmx_json((FIXTURES / "qmx-current.json").read_text())
        self.pdepend = MODULE.parse_pdepend_xml(FIXTURES / "pdepend-fqn.xml")
        self.phpmetrics = MODULE.parse_phpmetrics_json(FIXTURES / "phpmetrics-fqn.json")

    def spec(self, qmx_key, tool, other_key):
        return next(
            item for item in MODULE.COMPARISON_SPECS
            if item.qmx_key == qmx_key and item.other_tool == tool and item.other_key == other_key
        )

    def test_same_short_names_keep_distinct_fqns(self):
        self.assertEqual(
            {"Vendor\\Alpha\\Handler", "Vendor\\Beta\\Handler"},
            set(self.qmx["classes"]),
        )
        self.assertEqual(set(self.qmx["classes"]), set(self.pdepend["classes"]))
        self.assertEqual(set(self.qmx["classes"]), set(self.phpmetrics["classes"]))

    def test_duplicate_fqn_is_a_hard_failure(self):
        with self.assertRaisesRegex(MODULE.ComparisonError, "duplicate class identity"):
            MODULE.parse_pdepend_xml(FIXTURES / "pdepend-collision.xml")

    def test_nonzero_competitor_process_is_a_hard_failure(self):
        completed = subprocess.CompletedProcess(["pdepend"], 1, stdout="", stderr="failed")
        with patch.object(MODULE.subprocess, "run", return_value=completed):
            with self.assertRaisesRegex(MODULE.ComparisonError, "exited with 1"):
                MODULE.execute(["pdepend"], "PDepend", {0})

    def test_missing_required_artifact_is_a_hard_failure(self):
        completed = subprocess.CompletedProcess(["pdepend"], 0, stdout="", stderr="")
        missing = FIXTURES / "does-not-exist.xml"
        with patch.object(MODULE.subprocess, "run", return_value=completed):
            with self.assertRaisesRegex(MODULE.ComparisonError, "required artifact"):
                MODULE.execute(["pdepend"], "PDepend", {0}, missing)

    def test_polluted_qmx_stdout_is_a_hard_failure(self):
        with self.assertRaisesRegex(MODULE.ComparisonError, "not one JSON document"):
            MODULE.parse_qmx_json((FIXTURES / "qmx-polluted.txt").read_text())

    def test_incomplete_qmx_coverage_is_a_hard_failure(self):
        with self.assertRaisesRegex(MODULE.ComparisonError, "missing or incomplete analysis coverage"):
            MODULE.parse_qmx_json((FIXTURES / "qmx-incomplete-coverage.json").read_text())

    def test_missing_qmx_coverage_is_a_hard_failure(self):
        with self.assertRaisesRegex(MODULE.ComparisonError, "no coverage contract"):
            MODULE.parse_qmx_json((FIXTURES / "qmx-missing-coverage.json").read_text())

    def test_malformed_qmx_coverage_is_a_hard_failure(self):
        with self.assertRaisesRegex(MODULE.ComparisonError, "missing or incomplete analysis coverage"):
            MODULE.parse_qmx_json((FIXTURES / "qmx-malformed-coverage.json").read_text())

    def test_exit_four_uses_the_canonical_coverage_contract(self):
        incomplete = (FIXTURES / "qmx-incomplete-coverage.json").read_text()
        completed = subprocess.CompletedProcess(["qmx"], 4, stdout=incomplete, stderr="")
        with patch.object(MODULE, "execute", return_value=completed):
            with self.assertRaisesRegex(MODULE.ComparisonError, "incomplete analysis coverage"):
                MODULE.run_qmx(FIXTURES)

    def test_current_class_keys_compare_and_stale_keys_remain_visible_as_missing(self):
        current = MODULE.compare_spec(
            self.spec("classLoc", "pdepend", "loc"), self.qmx, self.pdepend, "fixture", 5
        )
        self.assertEqual(2, current.paired_values)
        self.assertEqual(2, current.exact_match)

        stale_qmx = MODULE.parse_qmx_json((FIXTURES / "qmx-stale-keys.json").read_text())
        stale = MODULE.compare_spec(
            self.spec("classLoc", "pdepend", "loc"), stale_qmx, self.pdepend, "fixture", 5
        )
        self.assertEqual(0, stale.paired_values)
        self.assertEqual(1, stale.missing_qmx_values)
        self.assertEqual(0, stale.qmx_values)

    def test_method_statement_count_is_used_for_the_contextual_lloc_row(self):
        comparison = MODULE.compare_spec(
            self.spec("methodStatementCount", "pdepend", "lloc"),
            self.qmx,
            self.pdepend,
            "fixture",
            5,
        )
        self.assertEqual(MODULE.CONTEXTUAL, comparison.spec.classification)
        self.assertEqual(1, comparison.paired_values)
        self.assertEqual(1, comparison.exact_match)

    def test_missing_metric_and_zero_counts_are_reported(self):
        qmx = {
            "classes": {"A": {"noc": 0}, "B": {}},
            "methods": {},
            "collisions": {"classes": 0, "methods": 0},
        }
        other = {
            "classes": {"A": {"nocc": 0}, "B": {"nocc": 1}, "C": {"nocc": 2}},
            "methods": {},
            "collisions": {"classes": 0, "methods": 0},
        }
        comparison = MODULE.compare_spec(self.spec("noc", "pdepend", "nocc"), qmx, other, "fixture", 5)
        self.assertEqual(2, comparison.symbol_intersection)
        self.assertEqual(1, comparison.other_only_symbols)
        self.assertEqual(1, comparison.missing_qmx_values)
        self.assertEqual(1, comparison.paired_values)
        self.assertEqual(1, comparison.both_zero_pairs)

    def test_near_zero_values_are_not_exact(self):
        comparison = MODULE.MetricComparison(
            spec=self.spec("noc", "pdepend", "nocc"), project="fixture"
        )
        comparison.add_pair("A", 0.0001, 0.0002)
        self.assertEqual(0, comparison.exact_match)
        self.assertEqual(1, comparison.divergent)

    def test_top_n_limits_project_aggregate_and_json(self):
        comparison = MODULE.MetricComparison(
            spec=self.spec("noc", "pdepend", "nocc"), project="fixture"
        )
        for index in range(5):
            comparison.add_pair(f"symbol-{index}", index + 1, 0)
        comparison.trim(2)
        self.assertEqual(2, len(comparison.top_divergences))
        aggregate = MODULE.aggregate_comparisons([comparison], 1)
        noc = next(item for item in aggregate if item.spec == comparison.spec)
        self.assertEqual(1, len(noc.top_divergences))
        report = MODULE.build_json_report([comparison], {}, 1)
        json_noc = next(item for item in report["aggregate"] if item["metric"] == "noc")
        self.assertEqual(1, len(json_noc["top_divergences"]))

    def test_only_comparable_rows_receive_agreement_verdicts(self):
        comparable = MODULE.MetricComparison(self.spec("noc", "pdepend", "nocc"), "fixture")
        comparable.add_pair("A", 0, 0)
        contextual = MODULE.MetricComparison(self.spec("ccn", "pdepend", "ccn2"), "fixture")
        contextual.add_pair("A::run", 1, 1)
        unsupported = MODULE.MetricComparison(self.spec("cognitive", "pdepend", None), "fixture")
        self.assertEqual("agreement", MODULE.agreement_verdict(comparable))
        self.assertEqual("contextual", MODULE.agreement_verdict(contextual))
        self.assertEqual("unsupported", MODULE.agreement_verdict(unsupported))


if __name__ == "__main__":
    unittest.main()
