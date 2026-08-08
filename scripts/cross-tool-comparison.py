#!/usr/bin/env python3
"""Fail-closed, FQN-aware cross-tool metric comparison.

The live run is intentionally separate from the deterministic fixture suite::

    composer test:cross-tool
    python3 scripts/cross-tool-comparison.py --json=/tmp/qmx-comparison.json

The comparison is evidence, not an oracle.  Every metric pair is classified as
``comparable``, ``contextual`` (similar label, different contract), or
``unsupported``.  Agreement verdicts are emitted only for comparable pairs.
"""

import argparse
import json
import math
import os
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from collections import defaultdict
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any, Optional


PROJECT_ROOT = Path(__file__).parent.parent
COMPOSER_BIN = Path.home() / ".composer/vendor/bin"
BENCHMARK_VENDOR = PROJECT_ROOT / "benchmarks/vendor"

PROJECTS = {
    "monolog": BENCHMARK_VENDOR / "monolog/monolog/src",
    "php-parser": BENCHMARK_VENDOR / "nikic/php-parser/lib",
    "symfony-console": BENCHMARK_VENDOR / "symfony/console",
    "doctrine-orm": BENCHMARK_VENDOR / "doctrine/orm/src",
}

COMPARABLE = "comparable"
CONTEXTUAL = "contextual"
UNSUPPORTED = "unsupported"


class ComparisonError(RuntimeError):
    """The run cannot produce trustworthy comparison evidence."""


@dataclass(frozen=True)
class ComparisonSpec:
    qmx_key: str
    other_tool: str
    other_key: Optional[str]
    level: str
    description: str
    classification: str
    rationale: str

    @property
    def tool_pair(self) -> str:
        key = self.other_key if self.other_key is not None else "unsupported"
        return f"Qualimetrix vs {self.other_tool}({key})"


# This table is deliberately explicit. A new row requires a decision about
# semantic equivalence; sharing a familiar metric name is not enough.
COMPARISON_SPECS = [
    ComparisonSpec("ccn", "pdepend", "ccn", "method", "Cyclomatic Complexity", CONTEXTUAL,
                   "PDepend CCN and Qualimetrix CCN2+ count different decisions."),
    ComparisonSpec("ccn", "pdepend", "ccn2", "method", "Cyclomatic Complexity", CONTEXTUAL,
                   "Qualimetrix extends CCN2 for modern PHP constructs."),
    ComparisonSpec("npath", "pdepend", "npath", "method", "NPath Complexity", CONTEXTUAL,
                   "Expression and match semantics differ between implementations."),
    ComparisonSpec("halstead.volume", "pdepend", "hv", "method", "Halstead Volume", CONTEXTUAL,
                   "The operator vocabularies intentionally differ."),
    ComparisonSpec("halstead.difficulty", "pdepend", "hd", "method", "Halstead Difficulty", CONTEXTUAL,
                   "The operator vocabularies intentionally differ."),
    ComparisonSpec("halstead.effort", "pdepend", "he", "method", "Halstead Effort", CONTEXTUAL,
                   "The operator vocabularies intentionally differ."),
    ComparisonSpec("halstead.bugs", "pdepend", "hb", "method", "Halstead Bugs", CONTEXTUAL,
                   "The operator vocabularies intentionally differ."),
    ComparisonSpec("mi", "pdepend", "mi", "method", "Maintainability Index", CONTEXTUAL,
                   "Formula inputs and normalization are not identical."),
    ComparisonSpec("methodStatementCount", "pdepend", "lloc", "method", "Method statements / LLOC", CONTEXTUAL,
                   "Statement count is not the same contract as logical lines of code."),
    ComparisonSpec("cognitive", "pdepend", None, "method", "Cognitive Complexity", UNSUPPORTED,
                   "PDepend does not report a corresponding method metric."),
    ComparisonSpec("wmc", "pdepend", "wmc", "class", "Weighted Methods per Class", CONTEXTUAL,
                   "The CCN variant propagates into WMC."),
    ComparisonSpec("wmc", "phpmetrics", "wmc", "class", "Weighted Methods per Class", CONTEXTUAL,
                   "The CCN variant propagates into WMC."),
    ComparisonSpec("dit", "pdepend", "dit", "class", "Depth of Inheritance Tree", CONTEXTUAL,
                   "External-runtime inheritance boundaries differ."),
    ComparisonSpec("noc", "pdepend", "nocc", "class", "Number of Children", COMPARABLE,
                   "Both count direct project children."),
    ComparisonSpec("cbo", "pdepend", "cbo", "class", "Coupling Between Objects", CONTEXTUAL,
                   "Dependency-type coverage differs substantially."),
    ComparisonSpec("ca", "pdepend", "ca", "class", "Afferent Coupling", CONTEXTUAL,
                   "PDepend omits some declaration kinds and references."),
    ComparisonSpec("ce", "pdepend", "ce", "class", "Efferent Coupling", CONTEXTUAL,
                   "Dependency-type coverage differs substantially."),
    ComparisonSpec("ca", "phpmetrics", "afferentCoupling", "class", "Afferent Coupling", CONTEXTUAL,
                   "The dependency graphs have different edge contracts."),
    ComparisonSpec("ce", "phpmetrics", "efferentCoupling", "class", "Efferent Coupling", CONTEXTUAL,
                   "The dependency graphs have different edge contracts."),
    ComparisonSpec("classLoc", "pdepend", "loc", "class", "Class physical LOC", CONTEXTUAL,
                   "Equality is limited to non-attributed named classes because PDepend excludes "
                   "class attributes from its source span while Qualimetrix includes them."),
    ComparisonSpec("classLoc", "phpmetrics", "loc", "class", "Class physical LOC", CONTEXTUAL,
                   "phpmetrics counts lines from a pretty-printed AST rather than the original "
                   "physical source span."),
    ComparisonSpec("instability", "phpmetrics", "instability", "class", "Instability", CONTEXTUAL,
                   "Different Ca/Ce graphs feed the ratio."),
    ComparisonSpec("classRank", "phpmetrics", "pageRank", "class", "ClassRank / PageRank", CONTEXTUAL,
                   "The algorithms and dependency graphs differ."),
    ComparisonSpec("lcom", "phpmetrics", "lcom", "class", "LCOM", CONTEXTUAL,
                   "Qualimetrix uses LCOM4; phpmetrics uses another LCOM variant."),
    ComparisonSpec("mi.avg", "phpmetrics", "mi", "class", "Average Maintainability Index", CONTEXTUAL,
                   "Class aggregation, formula inputs, and normalization differ."),
    ComparisonSpec("mi.min", "phpmetrics", None, "class", "Minimum method MI", UNSUPPORTED,
                   "phpmetrics has no class field for minimum method MI."),
]


def safe_float(value: Any) -> Optional[float]:
    if value is None:
        return None
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return number if math.isfinite(number) else None


def canonical_fqn(name: str) -> str:
    return name.strip().lstrip("\\")


def insert_unique(target: dict, key: str, value: dict, tool: str, level: str) -> None:
    if not key:
        raise ComparisonError(f"{tool} emitted an empty {level} identity")
    if key in target:
        raise ComparisonError(f"{tool} emitted duplicate {level} identity: {key}")
    target[key] = value


def require_complete_qmx_coverage(data: dict) -> None:
    """Enforce the MetricsJsonFormatter top-level coverage contract."""
    if "coverage" not in data:
        raise ComparisonError("Qualimetrix artifact has no coverage contract")
    coverage = data["coverage"]
    if not isinstance(coverage, dict) or coverage.get("complete") is not True:
        raise ComparisonError("Qualimetrix reports missing or incomplete analysis coverage")


def parse_qmx_artifact(raw: str) -> tuple[dict, dict]:
    """Parse and index the complete MetricsJsonFormatter stdout document."""
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as error:
        raise ComparisonError(f"Qualimetrix stdout is not one JSON document: {error}") from error
    if not isinstance(data, dict) or not isinstance(data.get("symbols"), list):
        raise ComparisonError("Qualimetrix artifact must contain a symbols array")
    summary = data.get("summary")
    if not isinstance(summary, dict) or not isinstance(summary.get("filesAnalyzed"), int):
        raise ComparisonError("Qualimetrix artifact has no complete summary")
    require_complete_qmx_coverage(data)

    classes: dict[str, dict] = {}
    methods: dict[str, dict] = {}
    for symbol in data["symbols"]:
        if not isinstance(symbol, dict) or not isinstance(symbol.get("metrics"), dict):
            raise ComparisonError("Qualimetrix emitted a malformed symbol")
        name = canonical_fqn(str(symbol.get("name", "")))
        if symbol.get("type") == "class":
            insert_unique(classes, name, symbol["metrics"], "Qualimetrix", "class")
        elif symbol.get("type") == "method":
            insert_unique(methods, name, symbol["metrics"], "Qualimetrix", "method")
    if not classes and not methods:
        raise ComparisonError("Qualimetrix artifact contains no class or method metrics")
    indexed = {"classes": classes, "methods": methods, "collisions": {"classes": 0, "methods": 0}}
    return data, indexed


def parse_qmx_json(raw: str) -> dict:
    """Parse a complete Qualimetrix artifact and return its symbol index."""
    _, indexed = parse_qmx_artifact(raw)
    return indexed


def pdepend_class_fqn(package_name: str, class_name: str) -> str:
    class_name = canonical_fqn(class_name)
    package_name = canonical_fqn(package_name)
    if "\\" in class_name or not package_name or package_name in {"default", "+global"}:
        return class_name
    return f"{package_name}\\{class_name}"


def parse_pdepend_xml(path: Path) -> dict:
    try:
        root = ET.parse(path).getroot()
    except (ET.ParseError, OSError) as error:
        raise ComparisonError(f"PDepend artifact is not valid XML: {error}") from error
    classes: dict[str, dict] = {}
    methods: dict[str, dict] = {}
    for package in root.findall(".//package"):
        package_name = package.get("name", "")
        for tag in ("class", "interface", "trait"):
            for cls in package.findall(tag):
                class_name = cls.get("name")
                if not class_name:
                    raise ComparisonError("PDepend emitted a class without a name")
                fqn = pdepend_class_fqn(package_name, class_name)
                insert_unique(classes, fqn, dict(cls.attrib), "PDepend", "class")
                for method in cls.findall("method"):
                    method_name = method.get("name")
                    if not method_name:
                        raise ComparisonError(f"PDepend emitted an unnamed method in {fqn}")
                    insert_unique(methods, f"{fqn}::{method_name}", dict(method.attrib), "PDepend", "method")
    if not classes:
        raise ComparisonError("PDepend artifact contains no class-like symbols")
    return {"classes": classes, "methods": methods, "collisions": {"classes": 0, "methods": 0}}


def parse_phpmetrics_json(path: Path) -> dict:
    try:
        data = json.loads(path.read_text())
    except (json.JSONDecodeError, OSError) as error:
        raise ComparisonError(f"phpmetrics artifact is not valid JSON: {error}") from error
    if not isinstance(data, dict):
        raise ComparisonError("phpmetrics artifact must be a JSON object")
    classes: dict[str, dict] = {}
    for artifact_key, entry in data.items():
        if not isinstance(entry, dict) or "ClassMetric" not in str(entry.get("_type", "")):
            continue
        fqn = canonical_fqn(str(entry.get("name") or artifact_key))
        insert_unique(classes, fqn, entry, "phpmetrics", "class")
    if not classes:
        raise ComparisonError("phpmetrics artifact contains no class metrics")
    return {"classes": classes, "methods": {}, "collisions": {"classes": 0, "methods": 0}}


def execute(cmd: list[str], tool: str, valid_codes: set[int], artifact: Optional[Path] = None) -> subprocess.CompletedProcess:
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=600,
                                env={**os.environ, "PHP_CS_FIXER_IGNORE_ENV": "1"})
    except (OSError, subprocess.TimeoutExpired) as error:
        raise ComparisonError(f"{tool} process failed: {error}") from error
    if result.returncode not in valid_codes:
        raise ComparisonError(
            f"{tool} exited with {result.returncode}; stderr: {result.stderr.strip()[:500]}"
        )
    if artifact is not None and (not artifact.is_file() or artifact.stat().st_size == 0):
        raise ComparisonError(f"{tool} did not produce the required artifact: {artifact}")
    return result


def run_qmx(project_path: Path) -> dict:
    cmd = ["php", "-d", "memory_limit=2G", str(PROJECT_ROOT / "bin/qmx"), "check", str(project_path),
           "--format=metrics", "--workers=1", "--disable-rule=duplication.code-duplication",
           "--disable-rule=architecture.circular-dependency"]
    result = execute(cmd, "Qualimetrix", {0, 1, 2, 4})
    document, data = parse_qmx_artifact(result.stdout)
    if result.returncode == 4:
        # Exit 4 normally means incomplete analysis. Keep the artifact usable
        # only when the canonical structured contract explicitly says otherwise.
        require_complete_qmx_coverage(document)
    return data


def run_pdepend(project_path: Path) -> dict:
    with tempfile.NamedTemporaryFile(suffix=".xml", delete=False) as handle:
        artifact = Path(handle.name)
    try:
        execute([str(COMPOSER_BIN / "pdepend"), f"--summary-xml={artifact}", str(project_path)],
                "PDepend", {0}, artifact)
        return parse_pdepend_xml(artifact)
    finally:
        artifact.unlink(missing_ok=True)


def run_phpmetrics(project_path: Path) -> dict:
    with tempfile.NamedTemporaryFile(suffix=".json", delete=False) as handle:
        artifact = Path(handle.name)
    try:
        execute([str(COMPOSER_BIN / "phpmetrics"), f"--report-json={artifact}", str(project_path)],
                "phpmetrics", {0}, artifact)
        return parse_phpmetrics_json(artifact)
    finally:
        artifact.unlink(missing_ok=True)


@dataclass
class Divergence:
    symbol: str
    qmx_value: float
    other_value: float
    pct_diff: float


@dataclass
class MetricComparison:
    spec: ComparisonSpec
    project: str
    qmx_symbols: int = 0
    other_symbols: int = 0
    symbol_intersection: int = 0
    qmx_only_symbols: int = 0
    other_only_symbols: int = 0
    qmx_values: int = 0
    other_values: int = 0
    paired_values: int = 0
    missing_qmx_values: int = 0
    missing_other_values: int = 0
    qmx_zero_values: int = 0
    other_zero_values: int = 0
    both_zero_pairs: int = 0
    exact_match: int = 0
    close_match: int = 0
    divergent: int = 0
    collisions: int = 0
    top_divergences: list[Divergence] = field(default_factory=list)

    def add_pair(self, symbol: str, qmx_value: float, other_value: float) -> None:
        self.paired_values += 1
        self.both_zero_pairs += int(qmx_value == 0 and other_value == 0)
        if qmx_value == other_value:
            self.exact_match += 1
            return
        scale = max(abs(qmx_value), abs(other_value))
        pct_diff = 100.0 if scale == 0 else abs(qmx_value - other_value) / scale * 100
        if pct_diff < 10:
            self.close_match += 1
        else:
            self.divergent += 1
        self.top_divergences.append(Divergence(symbol, qmx_value, other_value, round(pct_diff, 1)))

    def trim(self, top_n: int) -> None:
        self.top_divergences.sort(key=lambda item: (-item.pct_diff, item.symbol))
        self.top_divergences = self.top_divergences[:top_n]


def compare_spec(spec: ComparisonSpec, qmx: dict, other: dict, project: str, top_n: int) -> MetricComparison:
    comparison = MetricComparison(spec=spec, project=project)
    level_key = "classes" if spec.level == "class" else "methods"
    qmx_symbols = qmx[level_key]
    other_symbols = other[level_key]
    comparison.qmx_symbols = len(qmx_symbols)
    comparison.other_symbols = len(other_symbols)
    comparison.collisions = qmx["collisions"][level_key] + other["collisions"][level_key]
    if spec.other_key is None:
        qmx_numeric_values = [safe_float(metrics.get(spec.qmx_key)) for metrics in qmx_symbols.values()]
        comparison.qmx_values = sum(value is not None for value in qmx_numeric_values)
        comparison.qmx_zero_values = sum(value == 0 for value in qmx_numeric_values if value is not None)
        return comparison

    intersection = set(qmx_symbols) & set(other_symbols)
    comparison.symbol_intersection = len(intersection)
    comparison.qmx_only_symbols = len(set(qmx_symbols) - set(other_symbols))
    comparison.other_only_symbols = len(set(other_symbols) - set(qmx_symbols))
    qmx_numeric_values = [safe_float(metrics.get(spec.qmx_key)) for metrics in qmx_symbols.values()]
    other_numeric_values = [safe_float(metrics.get(spec.other_key)) for metrics in other_symbols.values()]
    comparison.qmx_values = sum(value is not None for value in qmx_numeric_values)
    comparison.other_values = sum(value is not None for value in other_numeric_values)
    comparison.qmx_zero_values = sum(value == 0 for value in qmx_numeric_values if value is not None)
    comparison.other_zero_values = sum(value == 0 for value in other_numeric_values if value is not None)
    for symbol in sorted(intersection):
        qmx_value = safe_float(qmx_symbols[symbol].get(spec.qmx_key))
        other_value = safe_float(other_symbols[symbol].get(spec.other_key))
        if qmx_value is None:
            comparison.missing_qmx_values += 1
        if other_value is None:
            comparison.missing_other_values += 1
        if qmx_value is not None and other_value is not None:
            comparison.add_pair(f"{project}::{symbol}", qmx_value, other_value)
    comparison.trim(top_n)
    return comparison


def compare_project(project: str, qmx: dict, pdepend: dict, phpmetrics: dict, top_n: int) -> list[MetricComparison]:
    tools = {"pdepend": pdepend, "phpmetrics": phpmetrics}
    return [compare_spec(spec, qmx, tools[spec.other_tool], project, top_n) for spec in COMPARISON_SPECS]


def aggregate_comparisons(comparisons: list[MetricComparison], top_n: int) -> list[MetricComparison]:
    grouped: dict[ComparisonSpec, list[MetricComparison]] = defaultdict(list)
    for comparison in comparisons:
        grouped[comparison.spec].append(comparison)
    totals = []
    numeric_fields = [field_name for field_name in MetricComparison.__dataclass_fields__
                      if field_name not in {"spec", "project", "top_divergences"}]
    for spec in COMPARISON_SPECS:
        aggregate = MetricComparison(spec=spec, project="all")
        for item in grouped.get(spec, []):
            for field_name in numeric_fields:
                setattr(aggregate, field_name, getattr(aggregate, field_name) + getattr(item, field_name))
            aggregate.top_divergences.extend(item.top_divergences)
        aggregate.trim(top_n)
        totals.append(aggregate)
    return totals


def agreement_verdict(comparison: MetricComparison) -> str:
    if comparison.spec.classification != COMPARABLE:
        return comparison.spec.classification
    if comparison.paired_values == 0:
        return "insufficient-data"
    divergence_pct = comparison.divergent / comparison.paired_values * 100
    return "agreement" if divergence_pct <= 5 else "investigate"


def comparison_to_dict(comparison: MetricComparison) -> dict:
    data = {name: getattr(comparison, name) for name in MetricComparison.__dataclass_fields__
            if name not in {"spec", "top_divergences"}}
    data.update({
        "metric": comparison.spec.qmx_key,
        "description": comparison.spec.description,
        "level": comparison.spec.level,
        "tool_pair": comparison.spec.tool_pair,
        "classification": comparison.spec.classification,
        "rationale": comparison.spec.rationale,
        "verdict": agreement_verdict(comparison),
        "top_divergences": [asdict(item) for item in comparison.top_divergences],
    })
    return data


def build_json_report(comparisons: list[MetricComparison], project_stats: dict, top_n: int) -> dict:
    return {
        "version": "2.0",
        "top_n": top_n,
        "projects": project_stats,
        "comparisons": [comparison_to_dict(item) for item in comparisons],
        "aggregate": [comparison_to_dict(item) for item in aggregate_comparisons(comparisons, top_n)],
    }


def print_summary(comparisons: list[MetricComparison], top_n: int) -> None:
    print("\nMETRIC CONTRACT AND COVERAGE SUMMARY")
    print(f"{'Metric':<22} {'Pair':<34} {'Class':<11} {'Symbols':>9} {'Values':>9} {'Verdict':<17}")
    print("-" * 108)
    for item in aggregate_comparisons(comparisons, top_n):
        symbol_coverage = f"{item.symbol_intersection}/{item.qmx_symbols}"
        value_coverage = f"{item.paired_values}/{item.symbol_intersection}"
        print(f"{item.spec.qmx_key:<22} {item.spec.tool_pair:<34} {item.spec.classification:<11} "
              f"{symbol_coverage:>9} {value_coverage:>9} {agreement_verdict(item):<17}")
        print(f"  unmatched qmx/other={item.qmx_only_symbols}/{item.other_only_symbols}; "
              f"missing values qmx/other={item.missing_qmx_values}/{item.missing_other_values}; "
              f"zeros qmx/other/both={item.qmx_zero_values}/{item.other_zero_values}/{item.both_zero_pairs}; "
              f"collisions={item.collisions}")
        if item.spec.classification != COMPARABLE:
            print(f"  context: {item.spec.rationale}")
        for divergence in item.top_divergences:
            print(f"  divergence {divergence.symbol}: qmx={divergence.qmx_value:g}, "
                  f"other={divergence.other_value:g}, delta={divergence.pct_diff:.1f}%")


def main() -> int:
    parser = argparse.ArgumentParser(description="Cross-tool metric validation")
    parser.add_argument("--projects", default=None, help="Comma-separated benchmark project IDs")
    parser.add_argument("--json", default=None, help="Write the structured report to this path")
    parser.add_argument("--top-n", type=int, default=15, help="Divergences retained per project and aggregate")
    args = parser.parse_args()
    if args.top_n < 0:
        parser.error("--top-n must be non-negative")

    project_ids = list(PROJECTS)
    if args.projects:
        project_ids = [project.strip() for project in args.projects.split(",") if project.strip()]
    unknown = sorted(set(project_ids) - set(PROJECTS))
    if unknown:
        raise ComparisonError(f"Unknown project(s): {', '.join(unknown)}")
    for tool in ("pdepend", "phpmetrics"):
        if not (COMPOSER_BIN / tool).is_file():
            raise ComparisonError(f"Required tool not found: {COMPOSER_BIN / tool}")

    comparisons: list[MetricComparison] = []
    project_stats = {}
    for project in project_ids:
        path = PROJECTS[project]
        if not path.is_dir():
            raise ComparisonError(f"Required benchmark project is absent: {project} ({path})")
        print(f"Running {project}...", flush=True)
        qmx = run_qmx(path)
        pdepend = run_pdepend(path)
        phpmetrics = run_phpmetrics(path)
        project_comparisons = compare_project(project, qmx, pdepend, phpmetrics, args.top_n)
        comparisons.extend(project_comparisons)
        project_stats[project] = {
            "qmx_classes": len(qmx["classes"]),
            "pdepend_classes": len(pdepend["classes"]),
            "phpmetrics_classes": len(phpmetrics["classes"]),
            "qmx_methods": len(qmx["methods"]),
            "pdepend_methods": len(pdepend["methods"]),
        }

    print_summary(comparisons, args.top_n)
    if args.json:
        Path(args.json).write_text(json.dumps(build_json_report(comparisons, project_stats, args.top_n), indent=2) + "\n")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except ComparisonError as error:
        print(f"Cross-tool comparison failed: {error}", file=sys.stderr)
        sys.exit(1)
