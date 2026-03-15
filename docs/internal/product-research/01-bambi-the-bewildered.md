# Bambi the Bewildered: First-Time User Experience Report

**Date:** 2026-03-15
**Persona:** Junior PHP developer, never used AI Mess Detector before
**Projects tested:** Monolog (121 files), Symfony Console (132 files), Doctrine ORM (453 files)
**All runs:** `--no-cache --workers=0 --disable-rule=architecture.circular-dependency --disable-rule=duplication.code-duplication`

---

## Executive Summary

The default summary output is genuinely impressive for a first encounter. Health bars with percentages and plain-English labels ("Needs attention", "Excellent") are immediately comprehensible. The 10-second comprehension target is met for the high-level health picture. However, several issues undermine confidence: the rating labels feel inconsistent (71% = "Excellent" but 67% = "Good"?), nonexistent paths produce fake health scores instead of an error, the `--detail` violations use jargon that a newcomer would struggle with (WMC, CBO, TCC, LCOM, NPath), and the "Typing" health dimension appears/disappears without explanation.

---

## First Impressions

### Run 1: Monolog (small project)

```
AI Mess Detector — 121 files analyzed, 0.8s

Health █████████████████████░░░░░░░░░ 71.1% Excellent

  Complexity      █████████████████░░░░░░░░░░░░░ 55.7% Good
  Cohesion        ████████████████████████░░░░░░ 78.8% Excellent
  Coupling        ██████████████████░░░░░░░░░░░░ 61.4% Good
  Typing          █████████████████████████████░ 96.1% Good
  Maintainability ██████████████████████░░░░░░░░ 73.4% Good

494 violations (160 errors, 334 warnings) | Tech debt: 23d 3h 15min

Hints: --detail to see all violations | --format=html -o report.html for full report
```

**My reaction as Bambi:** "Oh wow, this is pretty! I can see health bars, percentages, labels. I get it immediately — my code is 71% healthy, that's... wait, it says 'Excellent'? 71% on a school exam is a C. And there are 494 violations and 160 ERRORS. How is that 'Excellent'?"

I also notice: no "worst offenders" section here. The Doctrine run showed worst offenders, but Monolog didn't. Why? I don't know what threshold triggers it.

### Run 2: Symfony Console (medium project)

```
Health ████████████████████░░░░░░░░░░ 67.6% Good

  Complexity      ████████████░░░░░░░░░░░░░░░░░░ 39.3% Needs attention
                   ↳ Cyclomatic (avg): 23.3 (target: below 4) — too many code paths per method
                   ↳ Cognitive (avg): 20.5 (target: below 5) — deeply nested, hard to follow

Worst classes
  49.2 Symfony\Component\Console\Application (37 violations) — high coupling, low cohesion
```

**My reaction:** "Great, now I can see WHAT needs attention: Complexity is at 39.3%. And the hints tell me what the numbers mean. 'Cyclomatic (avg): 23.3 (target: below 4)' — wait, 23.3 vs target 4? That seems absurdly off. Is Symfony really 6x over the target? That makes me question whether the target is reasonable or if the tool is miscalibrated."

The worst class with its score (49.2) and a brief reason ("high coupling, low cohesion") is helpful. I know where to look first.

### Run 3: Doctrine ORM (large project)

```
Worst namespaces
  45.2 Doctrine\ORM\Query (16 classes, 196 violations) — high coupling, high complexity

Worst classes
  45.8 Doctrine\ORM\Mapping\ClassMetadataFactory (20 violations) — high coupling, low cohesion

Hints: --namespace='Doctrine\ORM\Query' to drill down | --format=html -o report.html for full report
```

**My reaction:** "This is the best output. I see the worst namespace AND classes, and the hint tells me exactly what to type next (`--namespace='Doctrine\ORM\Query'`). Love it."

---

## Findings

### 1. CRITICAL: Nonexistent path produces fake health scores instead of an error

Running `bin/aimd check nonexistent/path` produces:

```
AI Mess Detector — 0 files analyzed, 0.0s

Health ██████████████████░░░░░░░░░░░░ 60% Good

  Cohesion        ███████████████░░░░░░░░░░░░░░░ 50% Needs attention
  Coupling        ██████████████████████████████ 100% Excellent
  Maintainability ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ 0% Poor

1 violation (1 error) | Tech debt: 15min
```

**Expected:** An error message like "Error: path 'nonexistent/path' does not exist". Instead I get a "60% Good" health report for literally nothing. This is deeply misleading. A newcomer would think their code was analyzed. The "0 files analyzed" is easy to miss.

The "100% Excellent coupling" for zero files is absurd — there is nothing to couple, so of course it's perfect.

### 2. HIGH: Rating labels feel inconsistent and too generous

| Score | Label     |
| ----- | --------- |
| 71.1% | Excellent |
| 73.4% | Excellent |
| 67.6% | Good      |
| 96.1% | Good      |

71% = "Excellent" but 96% = "Good"? These seem like they might be on different scales per dimension, but as a newcomer I have no way to know that. The word "Excellent" for a 71% score feels inflated and undermines trust. If I showed this report to my tech lead and said "our code health is Excellent at 71%!", they would not be impressed.

### 3. HIGH: Abbreviations and jargon in violation messages without explanation

In `--detail` mode, I see:

- `WMC: 115 (max 80) — total method complexity is high` — What is WMC? The hint says "total method complexity" but that's the definition, not what to DO about it.
- `CBO: 49 (max 20) — depends on too many classes` — CBO? I have to Google this.
- `God Class detected (3/4 criteria): high WMC (115 >= 47), low TCC (0.12 < 0.33), large size (699 >= 300 LOC)` — Three abbreviations in one message (WMC, TCC, LOC). I can guess LOC but the rest is opaque.
- `NPath complexity: 3456 (max 1000)` — What is NPath? How is it different from cyclomatic complexity?
- `LCOM` — appears in options, never explained.
- `Instability: 1.00 (max 0.95) — package is highly unstable` — "package is highly unstable" sounds scary. Does this mean my code will crash? No, it means it has too many outgoing dependencies. But a newcomer would panic.

The sub-hints (the `—` descriptions) are helpful but too terse for a newcomer. Something like "depends on 49 other classes (recommended: max 20). Reduce by extracting interfaces or splitting the class" would be actionable.

### 4. HIGH: "Typing" dimension appears and disappears

- Monolog, Symfony, Doctrine: "Typing" dimension shown
- Nonexistent path: "Typing" dimension missing, only Cohesion/Coupling/Maintainability shown
- No "Complexity" dimension for nonexistent path either

As a newcomer, I don't understand why some dimensions appear and others don't. If there's a threshold for showing a dimension, it should be documented or the dimension should always appear.

### 5. MEDIUM: `--namespace` filter shows global health scores, not filtered ones

When I run `--namespace='Symfony\Component\Console\Command'`, the health bars still show the entire project's scores (Complexity 39.3%, same as unfiltered). Only the violation count changes (578 -> 49). This is confusing — I expected to see the health of just that namespace.

The header says `[namespace: Symfony\Component\Console\Command]` which suggests filtering, but the health scores contradict this.

### 6. MEDIUM: No-path auto-detect runs analysis on entire project, OOMs

Running `bin/aimd check --no-cache --workers=0` with no path auto-detects from `composer.json` and tries to analyze the entire project including `benchmarks/vendor/`, which causes an OOM crash. The error message is a raw PHP fatal error, not a user-friendly message.

**Expected:** Either a friendly error explaining what happened, or auto-detection should respect `exclude` patterns from config.

### 7. MEDIUM: Health score for sub-items (e.g., `health.complexity = 22.5`) is confusing in violation output

In `--detail` mode:

```
ERROR
  Monolog\Handler\Slack: health.complexity = 22.5 (error threshold: below 25.0)  [health.complexity]
```

Wait — 22.5 is BELOW 25.0, and that's an ERROR? Is "below 25.0" the threshold meaning "values below 25 are errors"? That's inverted from how every other metric works (where exceeding a max is bad). The scale is: higher = better for health scores, so a LOW health score is bad. But the phrasing "error threshold: below 25.0" is ambiguous — does it mean "error if below 25" or "error threshold IS below 25"?

### 8. MEDIUM: Tech debt numbers are not actionable

"Tech debt: 65d 35min" — 65 days? For a library maintained by a team of experts? This feels wildly inflated and makes me, a junior dev, feel hopeless. There is no guidance on what "tech debt" means in practice or whether this number is normal for a project of this size.

A per-violation or per-file breakdown would help. Something like "Top 3 debt contributors: UnitOfWork.php (5d), Query.php (4d), ..."

### 9. MEDIUM: `--detail` output is overwhelming for large projects

Running `--detail` on Doctrine ORM would produce 1153 violations. Even the first 80 lines showed mostly `[project]`-level health violations before getting to actual file-level issues. The project-level health violations (33 of them!) are noise for someone who just wants to see "what's wrong with my code."

### 10. LOW: The `--help` output lists too many rule-specific options

The help text has ~80 options, most of which are rule-specific threshold tweaks (`--god-class-wmc-threshold`, `--lcom-min-methods`). A newcomer will scroll past and miss the important ones (`--detail`, `--format`, `--namespace`). These advanced options should be hidden behind a `--help-rules` or similar.

### 11. LOW: "text-verbose" formatter in error message but not documented as preferred

The invalid format error says: `Available formatters: checkstyle, gitlab, github, html, json, metrics-json, sarif, summary, text, text-verbose`

But `text-verbose` is deprecated (per the codebase). Showing deprecated formatters in the available list confuses newcomers.

### 12. LOW: Exit code semantics unclear

The tool exits with code 2 when violations are found. For a newcomer integrating into CI, there's no documentation in `--help` about what exit codes mean (0 = clean, 1 = tool error, 2 = violations found?).

---

## What Works Well

1. **Health bar visualization is excellent.** The Unicode progress bars with percentages and color-coded labels are immediately readable. This is the tool's strongest UX feature.

2. **Sub-dimension hints on low scores.** When Complexity is "Needs attention", the tool shows `↳ Cyclomatic (avg): 23.3 (target: below 4) — too many code paths per method`. This is exactly what a newcomer needs — context on WHY the score is low.

3. **Worst offenders section is genuinely useful.** Showing the worst namespace and top 3 classes with brief reasons gives a clear starting point. The score number (45.2, 48.6) helps prioritize.

4. **Contextual hints are smart.** The tool adapts hints based on output: when worst namespaces are shown, it suggests `--namespace='...'` to drill down. When no offenders exist, it suggests `--detail`. This is excellent progressive disclosure.

5. **Violation messages have actionable suffixes.** "consider introducing a parameter object", "use exceptions instead" — these tell you what to DO, not just what's wrong.

6. **The `--format=invalid` error is clear and helpful.** It lists all available formatters. Good.

7. **Speed is impressive.** 453 files in 3.6 seconds (single-threaded!) is fast enough that there's no friction to re-running.

8. **The `--namespace` drill-down concept is great.** Filtering violations to a specific namespace is exactly what you need when investigating a "worst offender."

---

## Recommendations (Prioritized)

### P0 (Must fix)

1. **Error on nonexistent paths.** Show a clear error message instead of fake health scores. This is a trust-breaking bug for new users.

2. **Fix rating label thresholds.** Either recalibrate so that 71% is not "Excellent", or add context (e.g., "Excellent for a project of this size"). The current labels erode trust.

### P1 (Should fix)

3. **Expand abbreviations on first occurrence.** First time CBO appears, show "CBO (Coupling Between Objects): 49 (max 20)". Subsequent occurrences can use the abbreviation alone.

4. **Fix health threshold phrasing.** Change "error threshold: below 25.0" to "error: score 22.5 is below minimum 25.0" or similar unambiguous phrasing.

5. **Filter health scores when `--namespace` is used.** Show health scores for the filtered namespace, not the entire project.

6. **Sort `--detail` violations: files first, project-level last.** Newcomers care about "what file do I fix?" not "my project health.complexity is 20.9."

### P2 (Nice to have)

7. **Add "top debt contributors" to summary.** Show the top 3 files by debt, e.g., "Top debt: UnitOfWork.php (5d), Query.php (4d), EntityManager.php (3d)."

8. **Hide rule-specific options in `--help`.** Show only the top 15-20 options. Add `bin/aimd check --help-rules` for threshold tuning.

9. **Remove `text-verbose` from available formatters list** (it's deprecated).

10. **Add a brief glossary link in output.** When abbreviations appear, suggest "Run `bin/aimd glossary` for metric definitions" or link to docs.

11. **Document exit codes in `--help`.** Add a line: "Exit codes: 0 = no violations, 1 = tool error, 2 = violations found."

12. **Contextualize tech debt.** Show debt per 1000 LOC or as a ratio, so it scales with project size.
