#!/usr/bin/env python3
"""
Compare metric values between AIMD, pdepend, and phpmetrics
on a single PHP file or class.
"""

import subprocess
import json
import xml.etree.ElementTree as ET
import sys
import os
from pathlib import Path
from typing import Dict, Any, Optional
import tempfile

# Paths
PROJECT_ROOT = Path(__file__).parent.parent
COMPOSER_BIN = Path.home() / ".composer/vendor/bin"

def run_aimd(file_path: Path) -> Dict[str, Any]:
    """Run AIMD and extract metrics."""
    cmd = [str(PROJECT_ROOT / "bin/aimd"), "analyze", str(file_path), "--format=json"]
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
        # AIMD outputs to stderr on violations
        output = result.stdout or result.stderr
        data = json.loads(output)
        return data
    except Exception as e:
        return {"error": str(e)}

def run_pdepend(file_path: Path) -> Dict[str, Any]:
    """Run pdepend and extract metrics."""
    with tempfile.NamedTemporaryFile(suffix='.xml', delete=False) as f:
        summary_file = f.name

    cmd = [
        str(COMPOSER_BIN / "pdepend"),
        f"--summary-xml={summary_file}",
        str(file_path)
    ]

    try:
        subprocess.run(cmd, capture_output=True, text=True, timeout=60)
        tree = ET.parse(summary_file)
        root = tree.getroot()

        metrics = {}

        # File-level metrics
        metrics['file'] = dict(root.attrib)

        # Class-level metrics
        for cls in root.findall('.//class'):
            class_name = cls.get('name')
            metrics[f'class:{class_name}'] = dict(cls.attrib)

            # Method-level metrics
            for method in cls.findall('method'):
                method_name = method.get('name')
                metrics[f'method:{class_name}::{method_name}'] = dict(method.attrib)

        os.unlink(summary_file)
        return metrics
    except Exception as e:
        if os.path.exists(summary_file):
            os.unlink(summary_file)
        return {"error": str(e)}

def run_phpmetrics(file_path: Path) -> Dict[str, Any]:
    """Run phpmetrics and extract metrics."""
    with tempfile.NamedTemporaryFile(suffix='.json', delete=False) as f:
        json_file = f.name

    cmd = [
        str(COMPOSER_BIN / "phpmetrics"),
        f"--report-json={json_file}",
        str(file_path)
    ]

    try:
        subprocess.run(cmd, capture_output=True, text=True, timeout=60)
        with open(json_file) as f:
            data = json.load(f)
        os.unlink(json_file)
        return data
    except Exception as e:
        if os.path.exists(json_file):
            os.unlink(json_file)
        return {"error": str(e)}

def compare_file(file_path: Path) -> None:
    """Compare metrics for a single file."""
    print(f"\n{'='*70}")
    print(f"Comparing metrics for: {file_path.name}")
    print(f"{'='*70}\n")

    # Run all tools
    print("Running AIMD...")
    aimd_result = run_aimd(file_path)

    print("Running pdepend...")
    pdepend_result = run_pdepend(file_path)

    print("Running phpmetrics...")
    phpmetrics_result = run_phpmetrics(file_path)

    # Extract and compare metrics
    print("\n" + "="*70)
    print("PDEPEND RAW METRICS (class level)")
    print("="*70)
    for key, value in pdepend_result.items():
        if key.startswith('class:'):
            print(f"\n{key}:")
            if isinstance(value, dict):
                for k, v in sorted(value.items()):
                    print(f"  {k}: {v}")

    print("\n" + "="*70)
    print("PDEPEND RAW METRICS (method level)")
    print("="*70)
    for key, value in pdepend_result.items():
        if key.startswith('method:'):
            print(f"\n{key}:")
            if isinstance(value, dict):
                # Only show key metrics
                for k in ['ccn', 'ccn2', 'npath', 'loc', 'lloc', 'mi', 'hv', 'hd', 'he', 'hb']:
                    if k in value:
                        print(f"  {k}: {value[k]}")

    print("\n" + "="*70)
    print("PHPMETRICS RAW METRICS")
    print("="*70)
    if 'classes' in phpmetrics_result:
        for cls in phpmetrics_result.get('classes', []):
            print(f"\nClass: {cls.get('name', 'unknown')}")
            for k in ['ccn', 'ccnMethodMax', 'lcom', 'mi', 'mIwoC', 'loc', 'lloc',
                     'instability', 'afferentCoupling', 'efferentCoupling',
                     'volume', 'difficulty', 'effort', 'bugs']:
                if k in cls:
                    print(f"  {k}: {cls[k]}")

            # Methods
            for method in cls.get('methods', []):
                print(f"\n  Method: {method.get('name', 'unknown')}")
                for k in ['ccn', 'npath']:
                    if k in method:
                        print(f"    {k}: {method[k]}")

    print("\n" + "="*70)
    print("AIMD VIOLATIONS")
    print("="*70)
    if 'files' in aimd_result:
        for file_data in aimd_result.get('files', []):
            for violation in file_data.get('violations', []):
                print(f"  {violation.get('rule')}: {violation.get('symbol')} = {violation.get('metricValue')}")
                print(f"    {violation.get('description')}")
    elif 'error' in aimd_result:
        print(f"  Error: {aimd_result['error']}")

    # Comparison table
    print("\n" + "="*70)
    print("METRIC COMPARISON TABLE")
    print("="*70)
    print(f"{'Metric':<25} {'AIMD':<15} {'pdepend':<15} {'phpmetrics':<15}")
    print("-"*70)

    # Extract specific metrics for comparison
    # This is a simplified comparison - real implementation would need more work

    # Get first class from each tool
    pdepend_class = None
    for k, v in pdepend_result.items():
        if k.startswith('class:'):
            pdepend_class = v
            break

    phpmetrics_class = None
    if 'classes' in phpmetrics_result and phpmetrics_result['classes']:
        phpmetrics_class = phpmetrics_result['classes'][0]

    comparisons = [
        ('CCN (class)', '-', pdepend_class.get('wmc') if pdepend_class else '-',
         phpmetrics_class.get('ccn') if phpmetrics_class else '-'),
        ('LOC', '-', pdepend_class.get('loc') if pdepend_class else '-',
         phpmetrics_class.get('loc') if phpmetrics_class else '-'),
        ('LLOC', '-', pdepend_class.get('lloc') if pdepend_class else '-',
         phpmetrics_class.get('lloc') if phpmetrics_class else '-'),
        ('LCOM', '-', '-',
         phpmetrics_class.get('lcom') if phpmetrics_class else '-'),
        ('Instability', '-', '-',
         phpmetrics_class.get('instability') if phpmetrics_class else '-'),
        ('MI', '-', '-',
         phpmetrics_class.get('mi') if phpmetrics_class else '-'),
    ]

    for row in comparisons:
        print(f"{row[0]:<25} {str(row[1]):<15} {str(row[2]):<15} {str(row[3]):<15}")

def main():
    if len(sys.argv) < 2:
        print("Usage: python3 compare-metrics.py <php-file>")
        print("\nExample:")
        print("  python3 compare-metrics.py src/Core/Metric/MetricBag.php")
        sys.exit(1)

    file_path = Path(sys.argv[1])
    if not file_path.exists():
        # Try relative to project root
        file_path = PROJECT_ROOT / sys.argv[1]

    if not file_path.exists():
        print(f"File not found: {sys.argv[1]}")
        sys.exit(1)

    compare_file(file_path)

if __name__ == "__main__":
    main()
