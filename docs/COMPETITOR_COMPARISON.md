# AIMD vs Competitors Comparison

Analysis date: 2025-12-23

## Summary

AIMD (AI Mess Detector) significantly outperforms competitors in performance (7-40x faster), while providing a comparable set of metrics and additional capabilities (parallel processing, Git integration, baseline).

---

## 1. Performance Benchmarks

### 1.1 Test Environments

| Codebase | Files | Description |
|----------|-------|-------------|
| **Small** | 275 | AI Mess Detector (src/) |
| **Large** | 9,953 | Production backend |

### 1.2 Results (Large codebase, ~10k files)

| Tool | Time (sec) | Relative to AIMD |
|------|-----------|------------------|
| **AIMD** | **5.8** | 1x |
| phpmetrics | 44 | 7.5x slower |
| pdepend | 176 | 30x slower |
| phpmd | 225 | 39x slower |

### 1.3 Results (Small codebase, 275 files)

| Tool | Time (sec) | Relative to AIMD |
|------|-----------|------------------|
| **AIMD** | **0.52** | 1x |
| pdepend | 0.86 | 1.7x slower |
| phpmetrics | 0.97 | 1.9x slower |
| phpmd | 1.29 | 2.5x slower |

### 1.4 Performance Conclusions

1. **AIMD is 7-40x faster** than competitors on large projects
2. The advantage grows with codebase size
3. Sequential mode is faster than parallel on medium projects (overhead)
4. All tests were run with caches cleared

---

## 2. Metrics Comparison

### 2.1 Full Metrics Table

| Metric | AIMD | pdepend | phpmetrics | phpmd |
|--------|------|---------|------------|-------|
| **Complexity** |
| CCN (Cyclomatic) | ✅ | ✅ | ✅ | ✅ |
| CCN2 (Extended) | ❌ | ✅ | ❌ | ❌ |
| NPath | ✅ | ✅ | ❌ | ✅ |
| Cognitive | ✅ | ❌ | ❌ | ❌ |
| **Halstead** |
| Volume | ✅ | ✅ | ✅ | ❌ |
| Difficulty | ✅ | ✅ | ✅ | ❌ |
| Effort | ✅ | ✅ | ✅ | ❌ |
| Bugs | ✅ | ✅ | ✅ | ❌ |
| Time | ✅ | ✅ | ❌ | ❌ |
| **Maintainability** |
| MI (Index) | ✅ | ✅ | ✅ | ❌ |
| MI without comments | ❌ | ❌ | ✅ | ❌ |
| Comment Weight | ❌ | ❌ | ✅ | ❌ |
| **Size** |
| LOC | ✅ | ✅ | ✅ | ❌ |
| LLOC | ✅ | ✅ | ✅ | ❌ |
| CLOC | ✅ | ✅ | ✅ | ❌ |
| ELOC | ❌ | ✅ | ❌ | ❌ |
| NCLOC | ❌ | ✅ | ❌ | ❌ |
| Class Count | ✅ | ✅ | ❌ | ❌ |
| Method Count | ✅ | ✅ | ✅ | ✅ |
| **Coupling** |
| CBO | ✅ | ✅ | ❌ | ✅ |
| Ca (Afferent) | ✅ | ✅ | ✅ | ❌ |
| Ce (Efferent) | ✅ | ✅ | ✅ | ❌ |
| Instability | ✅ | ❌ | ✅ | ❌ |
| Abstractness | ✅ | ❌ | ❌ | ❌ |
| Distance | ✅ | ❌ | ❌ | ❌ |
| RFC | ✅ | ❌ | ❌ | ❌ |
| **Cohesion** |
| LCOM | ✅ | ❌ | ✅ | ❌ |
| TCC | ✅ | ❌ | ❌ | ❌ |
| LCC | ✅ | ❌ | ❌ | ❌ |
| WMC | ✅ | ✅ | ❌ | ✅ |
| WOC | ✅ | ❌ | ❌ | ❌ |
| **Inheritance** |
| DIT | ✅ | ✅ | ✅ | ✅ |
| NOC | ✅ | ✅ | ❌ | ✅ |
| **Other** |
| Code Rank | ❌ | ✅ | ❌ | ❌ |
| PageRank | ❌ | ❌ | ✅ | ❌ |
| Kan Defects | ❌ | ❌ | ✅ | ❌ |
| System Complexity | ❌ | ❌ | ✅ | ❌ |

### 2.2 Unique AIMD Metrics

1. **Cognitive Complexity** — more accurate assessment of code comprehension difficulty
2. **TCC/LCC** (Tight/Loose Class Cohesion) — class cohesion assessment
3. **RFC** (Response for Class) — number of callable methods
4. **WOC** (Weight of Class) — weighted class assessment
5. **Distance from Main Sequence** — balance of abstractness and stability

### 2.3 Metrics Missing from AIMD (require implementation)

1. **CCN2** (Extended Cyclomatic Complexity) — accounts for more constructs
2. **ELOC** (Executable Lines of Code) — used in pdepend for MI
3. **Code Rank / PageRank** — class importance ranking
4. **MI without comments** — MI without considering comments
5. **Kan Defects** — alternative defect estimation
6. **System Complexity** — relative system complexity

---

## 3. Formula Comparison

### 3.1 Maintainability Index (MI)

**Formula (identical):**
```
MI = 171 - 5.2*ln(V) - 0.23*CCN - 16.2*ln(LOC)
MI_normalized = max(0, min(100, MI * 100 / 171))
```

**Differences:**
| Parameter | AIMD | pdepend |
|-----------|------|---------|
| Complexity | CCN | CCN2 |
| LOC | Estimate from Volume | ELOC (actual) |

**Discrepancy:** ~1-5 MI units (insignificant for practical use)

### 3.2 Cyclomatic Complexity

**Formula (identical):**
```
CCN = 1 + number of branching points
```

pdepend also computes CCN2 (Extended), which additionally accounts for:
- Ternary operators
- Null coalescing (??)
- && and || operators in conditions

---

## 4. Feature Comparison

| Feature | AIMD | phpmd | phpmetrics | pdepend |
|---------|------|-------|------------|---------|
| Parallel processing | ✅ | ❌ | ❌ | ❌ |
| Baseline (ignore known issues) | ✅ | ✅ | ❌ | ❌ |
| Git integration | ✅ | ❌ | ✅ | ❌ |
| @aimd-ignore tags | ✅ | ❌ | ❌ | ❌ |
| SARIF output | ✅ | ✅ | ❌ | ❌ |
| GitLab Code Quality | ✅ | ❌ | ❌ | ❌ |
| Checkstyle output | ✅ | ✅ | ❌ | ❌ |
| HTML reports | ❌ | ✅ | ✅ | ❌ |
| Graph visualization | ✅ (DOT) | ❌ | ✅ (HTML) | ✅ |
| AST caching | ✅ | ✅ | ❌ | ❌ |
| Analysis rules | ✅ | ✅ | ❌ | ❌ |
| Custom rules | Planned | ✅ | ❌ | ❌ |

---

## 5. Development Recommendations

### 5.1 High Priority

1. **Add ELOC** — for accurate MI calculation matching pdepend
2. **Add CCN2** — extended cyclomatic complexity
3. **HTML reports** — visual representation of results

### 5.2 Medium Priority

4. **MI without comments** — additional MI variant
5. **Code Rank** — class importance ranking
6. **Custom rules** — ability to add custom checks

### 5.3 Low Priority

7. **Kan Defects** — alternative estimation
8. **System Complexity** — relative complexity
9. **PageRank** — class popularity

---

## 6. Conclusion

**AIMD has competitive advantages:**
- 7-40x faster than competitors
- Unique metrics (Cognitive Complexity, TCC/LCC, RFC)
- Git integration and baseline out of the box
- Modern stack (PHP 8.4, amphp)

**Areas for improvement:**
- MI accuracy (use ELOC instead of estimate)
- Add CCN2 for pdepend compatibility
- HTML reports for visualization

---

## Appendix: Benchmark Scripts

Scripts are located in:
- `scripts/benchmark-comparison.sh` — performance benchmark
- `scripts/compare-metrics.py` — metric value comparison

Benchmark results:
- `benchmark-results/benchmark_small_*.csv`
- `benchmark-results/benchmark_large_*.csv`
