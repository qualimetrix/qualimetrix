#!/usr/bin/env python3
"""
Cross-tool metric validation: AIMD vs pdepend vs phpmetrics.

Runs all three tools on benchmark projects and compares metric values
at method-level and class-level granularity.

Usage:
    python3 scripts/cross-tool-comparison.py [--projects=monolog,php-parser,...] [--json=output.json]

Projects (default: monolog, php-parser, symfony-console, doctrine-orm):
    monolog          — small, clean OOP
    php-parser       — medium, complex algorithms
    symfony-console  — medium, framework component
    doctrine-orm     — large, complex domain

Requirements:
    pdepend 2.16.2+  — ~/.composer/vendor/bin/pdepend
    phpmetrics 2.9.1 — ~/.composer/vendor/bin/phpmetrics
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
from dataclasses import dataclass, field
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

# Metric comparison definitions
# (aimd_key, pdepend_attr, phpmetrics_key, level, description)
METHOD_METRICS = [
    ("ccn", ["ccn", "ccn2"], None, "Cyclomatic Complexity"),
    ("npath", ["npath"], None, "NPath Complexity"),
    ("halstead.volume", ["hv"], None, "Halstead Volume"),
    ("halstead.difficulty", ["hd"], None, "Halstead Difficulty"),
    ("halstead.effort", ["he"], None, "Halstead Effort"),
    ("halstead.bugs", ["hb"], None, "Halstead Bugs"),
    ("mi", ["mi"], None, "Maintainability Index"),
]

CLASS_METRICS = [
    ("wmc", ["wmc"], "wmc", "Weighted Methods per Class"),
    ("dit", ["dit"], None, "Depth of Inheritance Tree"),
    ("noc", ["nocc"], None, "Number of Children"),
    ("cbo", ["cbo"], None, "Coupling Between Objects"),
    ("ca", ["ca"], "afferentCoupling", "Afferent Coupling"),
    ("ce", ["ce"], "efferentCoupling", "Efferent Coupling"),
    ("loc", ["loc"], "loc", "Lines of Code"),
    ("lloc", ["lloc"], "lloc", "Logical Lines of Code"),
    ("instability", [], "instability", "Instability"),
    ("classRank", [], "pageRank", "ClassRank / PageRank"),
    ("lcom", [], "lcom", "LCOM"),
    ("mi", [], "mi", "Maintainability Index (class-level)"),
]


def safe_float(val: Any) -> Optional[float]:
    """Convert value to float, returning None for non-numeric."""
    if val is None:
        return None
    try:
        f = float(val)
        if math.isnan(f) or math.isinf(f):
            return None
        return f
    except (ValueError, TypeError):
        return None


# --- Tool runners ---

def run_aimd(project_path: Path) -> dict:
    """Run AIMD and return {classes: {name: metrics}, methods: {name: metrics}}."""
    cmd = [
        "php", "-d", "memory_limit=2G",
        str(PROJECT_ROOT / "bin/aimd"), "check", str(project_path),
        "--format=metrics", "--workers=1",
        "--disable-rule=duplication.code-duplication",
        "--disable-rule=architecture.circular-dependency",
    ]

    result = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
    stdout = result.stdout.strip()

    if not stdout:
        raise RuntimeError(f"AIMD produced no output. stderr: {result.stderr[:500]}")

    data = json.loads(stdout)
    classes = {}
    methods = {}

    for symbol in data.get("symbols", []):
        sym_type = symbol.get("type")
        name = symbol.get("name", "")
        metrics = symbol.get("metrics", {})

        if sym_type == "class":
            short_name = name.rsplit("\\", 1)[-1] if "\\" in name else name
            classes[short_name] = metrics
        elif sym_type == "method":
            # Format: Namespace\Class::method
            if "::" in name:
                fqcn, method_name = name.rsplit("::", 1)
                class_short = fqcn.rsplit("\\", 1)[-1] if "\\" in fqcn else fqcn
                key = f"{class_short}::{method_name}"
                methods[key] = metrics
            else:
                # Functions
                methods[name] = metrics

    return {"classes": classes, "methods": methods}


def run_pdepend(project_path: Path) -> dict:
    """Run pdepend and return {classes: {name: metrics}, methods: {name: metrics}}."""
    with tempfile.NamedTemporaryFile(suffix=".xml", delete=False) as f:
        summary_file = f.name

    cmd = [
        str(COMPOSER_BIN / "pdepend"),
        f"--summary-xml={summary_file}",
        str(project_path),
    ]

    try:
        subprocess.run(
            cmd, capture_output=True, text=True, timeout=600,
            env={**os.environ, "PHP_CS_FIXER_IGNORE_ENV": "1"},
        )

        tree = ET.parse(summary_file)
        root = tree.getroot()

        classes = {}
        methods = {}

        for pkg in root.findall(".//package"):
            for cls in pkg.findall("class"):
                class_name = cls.get("name")
                if not class_name:
                    continue
                classes[class_name] = dict(cls.attrib)

                for method in cls.findall("method"):
                    method_name = method.get("name")
                    if method_name:
                        key = f"{class_name}::{method_name}"
                        methods[key] = dict(method.attrib)

            # Also check interfaces and traits
            for tag in ("interface", "trait"):
                for cls in pkg.findall(tag):
                    class_name = cls.get("name")
                    if not class_name:
                        continue
                    classes[class_name] = dict(cls.attrib)
                    for method in cls.findall("method"):
                        method_name = method.get("name")
                        if method_name:
                            key = f"{class_name}::{method_name}"
                            methods[key] = dict(method.attrib)

        return {"classes": classes, "methods": methods}
    finally:
        if os.path.exists(summary_file):
            os.unlink(summary_file)


def run_phpmetrics(project_path: Path) -> dict:
    """Run phpmetrics and return {classes: {name: metrics}}.

    phpmetrics 2.9.x outputs a flat JSON dict where keys are FQN class names
    and values are metric dicts (with _type=Hal\\Metric\\ClassMetric for classes).
    """
    with tempfile.NamedTemporaryFile(suffix=".json", delete=False) as f:
        json_file = f.name

    cmd = [
        str(COMPOSER_BIN / "phpmetrics"),
        f"--report-json={json_file}",
        str(project_path),
    ]

    try:
        subprocess.run(cmd, capture_output=True, text=True, timeout=600)

        with open(json_file) as f:
            data = json.load(f)

        classes = {}

        # phpmetrics 2.9.x: flat dict with FQN keys
        if isinstance(data, dict):
            for fqn, entry in data.items():
                if not isinstance(entry, dict):
                    continue
                # Skip namespace entries (shorter dicts) and non-class entries
                entry_type = entry.get("_type", "")
                if "ClassMetric" not in entry_type:
                    continue
                name = entry.get("name", fqn)
                short_name = name.rsplit("\\", 1)[-1] if "\\" in name else name
                if short_name:
                    classes[short_name] = entry

        return {"classes": classes}
    finally:
        if os.path.exists(json_file):
            os.unlink(json_file)


# --- Comparison engine ---

@dataclass
class Divergence:
    symbol: str
    metric: str
    aimd_value: float
    other_tool: str
    other_key: str
    other_value: float
    pct_diff: float  # percentage difference relative to max(abs(a),abs(b))


@dataclass
class MetricComparison:
    metric_name: str
    description: str
    level: str  # "method" or "class"
    tool_pair: str  # e.g. "AIMD vs pdepend(ccn2)"
    total_compared: int = 0
    exact_match: int = 0  # delta < 1%
    close_match: int = 0  # delta 1-10%
    divergent: int = 0    # delta > 10%
    top_divergences: list = field(default_factory=list)

    def add(self, symbol: str, aimd_val: float, other_val: float,
            other_tool: str, other_key: str):
        self.total_compared += 1

        # Percentage diff relative to max absolute value
        max_abs = max(abs(aimd_val), abs(other_val))
        if max_abs < 0.001:
            # Both essentially zero
            self.exact_match += 1
            return

        pct_diff = abs(aimd_val - other_val) / max_abs * 100

        if pct_diff < 1:
            self.exact_match += 1
        elif pct_diff < 10:
            self.close_match += 1
        else:
            self.divergent += 1

        if pct_diff >= 5:
            self.top_divergences.append(Divergence(
                symbol=symbol,
                metric=self.metric_name,
                aimd_value=aimd_val,
                other_tool=other_tool,
                other_key=other_key,
                other_value=other_val,
                pct_diff=round(pct_diff, 1),
            ))

    def sort_divergences(self, limit: int = 20):
        self.top_divergences.sort(key=lambda d: d.pct_diff, reverse=True)
        self.top_divergences = self.top_divergences[:limit]


def compare_method_metrics(
    aimd: dict, pdepend: dict, project_id: str,
) -> list[MetricComparison]:
    """Compare method-level metrics between AIMD and pdepend."""
    results = []

    aimd_methods = aimd.get("methods", {})
    pdepend_methods = pdepend.get("methods", {})

    # Find common method keys
    common_keys = set(aimd_methods.keys()) & set(pdepend_methods.keys())

    for aimd_key, pdepend_attrs, _, description in METHOD_METRICS:
        for pd_attr in pdepend_attrs:
            comp = MetricComparison(
                metric_name=aimd_key,
                description=description,
                level="method",
                tool_pair=f"AIMD vs pdepend({pd_attr})",
            )

            for method_key in sorted(common_keys):
                aimd_val = safe_float(aimd_methods[method_key].get(aimd_key))
                pd_val = safe_float(pdepend_methods[method_key].get(pd_attr))

                if aimd_val is not None and pd_val is not None:
                    comp.add(
                        f"{project_id}::{method_key}",
                        aimd_val, pd_val,
                        "pdepend", pd_attr,
                    )

            comp.sort_divergences()
            if comp.total_compared > 0:
                results.append(comp)

    return results


def compare_class_metrics(
    aimd: dict, pdepend: dict, phpmetrics: dict, project_id: str,
) -> list[MetricComparison]:
    """Compare class-level metrics between AIMD, pdepend, and phpmetrics."""
    results = []

    aimd_classes = aimd.get("classes", {})
    pdepend_classes = pdepend.get("classes", {})
    phpmetrics_classes = phpmetrics.get("classes", {})

    for aimd_key, pdepend_attrs, pm_key, description in CLASS_METRICS:
        # AIMD vs pdepend
        common_pd = set(aimd_classes.keys()) & set(pdepend_classes.keys())
        for pd_attr in pdepend_attrs:
            comp = MetricComparison(
                metric_name=aimd_key,
                description=description,
                level="class",
                tool_pair=f"AIMD vs pdepend({pd_attr})",
            )

            for cls_key in sorted(common_pd):
                aimd_val = safe_float(aimd_classes[cls_key].get(aimd_key))
                pd_val = safe_float(pdepend_classes[cls_key].get(pd_attr))

                if aimd_val is not None and pd_val is not None:
                    comp.add(
                        f"{project_id}::{cls_key}",
                        aimd_val, pd_val,
                        "pdepend", pd_attr,
                    )

            comp.sort_divergences()
            if comp.total_compared > 0:
                results.append(comp)

        # AIMD vs phpmetrics
        if pm_key:
            common_pm = set(aimd_classes.keys()) & set(phpmetrics_classes.keys())
            comp = MetricComparison(
                metric_name=aimd_key,
                description=description,
                level="class",
                tool_pair=f"AIMD vs phpmetrics({pm_key})",
            )

            for cls_key in sorted(common_pm):
                aimd_val = safe_float(aimd_classes[cls_key].get(aimd_key))
                pm_val = safe_float(phpmetrics_classes[cls_key].get(pm_key))

                if aimd_val is not None and pm_val is not None:
                    comp.add(
                        f"{project_id}::{cls_key}",
                        aimd_val, pm_val,
                        "phpmetrics", pm_key,
                    )

            comp.sort_divergences()
            if comp.total_compared > 0:
                results.append(comp)

    return results


# --- Reporting ---

def print_report(all_comparisons: list[MetricComparison]) -> None:
    """Print human-readable comparison report."""
    # Group by (metric_name, tool_pair)
    grouped: dict[str, list[MetricComparison]] = defaultdict(list)
    for comp in all_comparisons:
        key = f"{comp.metric_name}|{comp.tool_pair}"
        grouped[key].append(comp)

    for key in sorted(grouped.keys()):
        comps = grouped[key]
        metric_name = comps[0].metric_name
        tool_pair = comps[0].tool_pair
        level = comps[0].level
        description = comps[0].description

        # Aggregate across projects
        total = sum(c.total_compared for c in comps)
        exact = sum(c.exact_match for c in comps)
        close = sum(c.close_match for c in comps)
        divergent = sum(c.divergent for c in comps)

        if total == 0:
            continue

        exact_pct = exact / total * 100
        close_pct = close / total * 100
        div_pct = divergent / total * 100

        # Status indicator
        if div_pct > 20:
            status = "!!!"
        elif div_pct > 5:
            status = "!!"
        elif div_pct > 0:
            status = "!"
        else:
            status = "OK"

        print(f"\n{'='*80}")
        print(f"[{status}] {description} ({metric_name}, {level}-level)")
        print(f"    {tool_pair}")
        print(f"{'='*80}")
        print(f"  Total compared: {total}")
        print(f"  Exact match (±1%):  {exact:>5d} ({exact_pct:5.1f}%)")
        print(f"  Close match (±10%): {close:>5d} ({close_pct:5.1f}%)")
        print(f"  Divergent (>10%):   {divergent:>5d} ({div_pct:5.1f}%)")

        # Collect all divergences across projects
        all_divs = []
        for comp in comps:
            all_divs.extend(comp.top_divergences)
        all_divs.sort(key=lambda d: d.pct_diff, reverse=True)

        if all_divs:
            print(f"\n  Top divergences:")
            for d in all_divs[:15]:
                sign = "+" if d.aimd_value > d.other_value else "-"
                print(
                    f"    {d.symbol}: "
                    f"AIMD={fmt_val(d.aimd_value)}, "
                    f"{d.other_tool}({d.other_key})={fmt_val(d.other_value)} "
                    f"({sign}{d.pct_diff}%)"
                )


def fmt_val(v: float) -> str:
    """Format a metric value for display."""
    if abs(v) >= 1000:
        return f"{v:,.0f}"
    elif abs(v) >= 10:
        return f"{v:.1f}"
    elif abs(v) >= 1:
        return f"{v:.2f}"
    else:
        return f"{v:.4f}"


def print_summary_table(all_comparisons: list[MetricComparison]) -> None:
    """Print summary table of all metrics."""
    # Group by (metric_name, tool_pair)
    grouped: dict[str, list[MetricComparison]] = defaultdict(list)
    for comp in all_comparisons:
        key = f"{comp.metric_name}|{comp.tool_pair}"
        grouped[key].append(comp)

    print(f"\n{'='*80}")
    print("SUMMARY TABLE")
    print(f"{'='*80}")
    print(f"{'Metric':<22} {'Tool pair':<30} {'Total':>6} {'Exact':>7} {'Close':>7} {'Divg':>7} {'Divg%':>6}")
    print("-" * 86)

    for key in sorted(grouped.keys()):
        comps = grouped[key]
        metric_name = comps[0].metric_name
        tool_pair = comps[0].tool_pair

        total = sum(c.total_compared for c in comps)
        exact = sum(c.exact_match for c in comps)
        close = sum(c.close_match for c in comps)
        divergent = sum(c.divergent for c in comps)

        if total == 0:
            continue

        div_pct = divergent / total * 100

        # Marker for high divergence
        marker = " !!!" if div_pct > 20 else " !!" if div_pct > 5 else ""

        print(
            f"{metric_name:<22} {tool_pair:<30} {total:>6} "
            f"{exact:>7} {close:>7} {divergent:>7} {div_pct:>5.1f}%{marker}"
        )


def build_json_report(
    all_comparisons: list[MetricComparison],
    project_stats: dict,
) -> dict:
    """Build JSON report for further analysis."""
    metrics = []
    for comp in all_comparisons:
        divs = [
            {
                "symbol": d.symbol,
                "aimd": d.aimd_value,
                "other_tool": d.other_tool,
                "other_key": d.other_key,
                "other_value": d.other_value,
                "pct_diff": d.pct_diff,
            }
            for d in comp.top_divergences
        ]
        metrics.append({
            "metric": comp.metric_name,
            "description": comp.description,
            "level": comp.level,
            "tool_pair": comp.tool_pair,
            "total_compared": comp.total_compared,
            "exact_match": comp.exact_match,
            "close_match": comp.close_match,
            "divergent": comp.divergent,
            "exact_pct": round(comp.exact_match / comp.total_compared * 100, 1) if comp.total_compared else 0,
            "divergent_pct": round(comp.divergent / comp.total_compared * 100, 1) if comp.total_compared else 0,
            "top_divergences": divs,
        })

    return {
        "version": "1.0",
        "projects": project_stats,
        "comparisons": metrics,
    }


# --- Main ---

def main():
    parser = argparse.ArgumentParser(description="Cross-tool metric validation")
    parser.add_argument(
        "--projects", type=str, default=None,
        help="Comma-separated project IDs (default: all 4)",
    )
    parser.add_argument(
        "--json", type=str, default=None,
        help="Save JSON report to file",
    )
    parser.add_argument(
        "--top-n", type=int, default=15,
        help="Number of top divergences to show per metric (default: 15)",
    )

    args = parser.parse_args()

    project_ids = list(PROJECTS.keys())
    if args.projects:
        project_ids = [p.strip() for p in args.projects.split(",")]
        for pid in project_ids:
            if pid not in PROJECTS:
                print(f"Unknown project: {pid}. Available: {', '.join(PROJECTS.keys())}")
                sys.exit(1)

    # Verify tools exist
    for tool in ["pdepend", "phpmetrics"]:
        tool_path = COMPOSER_BIN / tool
        if not tool_path.exists():
            print(f"Tool not found: {tool_path}")
            sys.exit(1)

    all_comparisons: list[MetricComparison] = []
    project_stats = {}

    for pid in project_ids:
        project_path = PROJECTS[pid]
        if not project_path.exists():
            print(f"\nSKIP: {pid} (path not found: {project_path})")
            continue

        print(f"\n{'#'*80}")
        print(f"# Project: {pid}")
        print(f"# Path: {project_path}")
        print(f"{'#'*80}")

        # Run tools
        print(f"\n  Running AIMD...", end="", flush=True)
        try:
            aimd_data = run_aimd(project_path)
            print(f" OK ({len(aimd_data['classes'])} classes, {len(aimd_data['methods'])} methods)")
        except Exception as e:
            print(f" FAILED: {e}")
            continue

        print(f"  Running pdepend...", end="", flush=True)
        try:
            pdepend_data = run_pdepend(project_path)
            print(f" OK ({len(pdepend_data['classes'])} classes, {len(pdepend_data['methods'])} methods)")
        except Exception as e:
            print(f" FAILED: {e}")
            pdepend_data = {"classes": {}, "methods": {}}

        print(f"  Running phpmetrics...", end="", flush=True)
        try:
            phpmetrics_data = run_phpmetrics(project_path)
            print(f" OK ({len(phpmetrics_data['classes'])} classes)")
        except Exception as e:
            print(f" FAILED: {e}")
            phpmetrics_data = {"classes": {}}

        # Symbol matching stats
        aimd_classes = set(aimd_data["classes"].keys())
        pd_classes = set(pdepend_data["classes"].keys())
        pm_classes = set(phpmetrics_data["classes"].keys())

        aimd_methods = set(aimd_data["methods"].keys())
        pd_methods = set(pdepend_data["methods"].keys())

        print(f"\n  Symbol matching:")
        print(f"    Classes — AIMD: {len(aimd_classes)}, pdepend: {len(pd_classes)}, "
              f"phpmetrics: {len(pm_classes)}")
        print(f"    Classes matched — AIMD∩pd: {len(aimd_classes & pd_classes)}, "
              f"AIMD∩pm: {len(aimd_classes & pm_classes)}")
        print(f"    Methods — AIMD: {len(aimd_methods)}, pdepend: {len(pd_methods)}")
        print(f"    Methods matched — AIMD∩pd: {len(aimd_methods & pd_methods)}")

        project_stats[pid] = {
            "aimd_classes": len(aimd_classes),
            "pdepend_classes": len(pd_classes),
            "phpmetrics_classes": len(pm_classes),
            "matched_classes_pd": len(aimd_classes & pd_classes),
            "matched_classes_pm": len(aimd_classes & pm_classes),
            "aimd_methods": len(aimd_methods),
            "pdepend_methods": len(pd_methods),
            "matched_methods_pd": len(aimd_methods & pd_methods),
        }

        # Compare method-level
        method_comps = compare_method_metrics(aimd_data, pdepend_data, pid)
        all_comparisons.extend(method_comps)

        # Compare class-level
        class_comps = compare_class_metrics(
            aimd_data, pdepend_data, phpmetrics_data, pid,
        )
        all_comparisons.extend(class_comps)

    # Print reports
    print_summary_table(all_comparisons)
    print_report(all_comparisons)

    # Save JSON if requested
    if args.json:
        report = build_json_report(all_comparisons, project_stats)
        json_path = Path(args.json)
        json_path.write_text(json.dumps(report, indent=2, ensure_ascii=False))
        print(f"\nJSON report saved to: {json_path}")

    # Print overall assessment
    print(f"\n{'='*80}")
    print("OVERALL ASSESSMENT")
    print(f"{'='*80}")

    # Group and aggregate
    grouped: dict[str, list[MetricComparison]] = defaultdict(list)
    for comp in all_comparisons:
        key = f"{comp.metric_name}|{comp.tool_pair}"
        grouped[key].append(comp)

    issues = []
    good = []
    for key in sorted(grouped.keys()):
        comps = grouped[key]
        total = sum(c.total_compared for c in comps)
        divergent = sum(c.divergent for c in comps)
        if total == 0:
            continue
        div_pct = divergent / total * 100
        name = f"{comps[0].metric_name} [{comps[0].tool_pair}]"
        if div_pct > 5:
            issues.append((name, div_pct, total, divergent))
        else:
            good.append((name, div_pct, total))

    if good:
        print("\n  GOOD (divergence <= 5%):")
        for name, pct, total in good:
            print(f"    {name}: {pct:.1f}% divergent ({total} compared)")

    if issues:
        print("\n  NEEDS INVESTIGATION (divergence > 5%):")
        for name, pct, total, div_count in sorted(issues, key=lambda x: -x[1]):
            print(f"    {name}: {pct:.1f}% divergent ({div_count}/{total})")


if __name__ == "__main__":
    main()
