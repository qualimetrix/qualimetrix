# 01 — Captain Obvious: Output Clarity Audit

**Persona:** Mid-level PHP developer, first-time AIMD user
**Projects:** Doctrine ORM (`benchmarks/vendor/doctrine/orm/src`), Guzzle (`benchmarks/vendor/guzzlehttp/guzzle/src`)
**Focus:** Can I understand what the tool tells me without reading docs?

## Summary

AIMD's default summary output is largely self-explanatory for the things that matter most — violation messages are actionable and the health bar is intuitive. However, there are two serious trust-breaking issues: the `--class` drill-down shows **project-level** health (not class health), which silently misleads; and the "worst namespaces" score (e.g., `44.7`) contradicts the header health score (`78.8%`) for the **same namespace** without any explanation. Several metric abbreviations (WMC, MI, TCC, CBO) and per-dimension label thresholds are unexplained, creating confusion spikes on first read.

## Projects Overview

| Project      | Files | Violations               | Overall Health   |
| ------------ | ----- | ------------------------ | ---------------- |
| Doctrine ORM | 453   | 1200 (363 err, 837 warn) | 66.6% Acceptable |
| Guzzle       | 41    | 218 (61 err, 157 warn)   | 70.5% Strong     |

## Findings

### CRITICAL

**C1 — `--class` drill-down shows project health, not class health**

When a user runs `bin/aimd check src/ --class='Doctrine\ORM\Query\SqlWalker'`, the header reads:
```
Health ████████████████████░░░░░░░░░░ 66.6% Acceptable
```
This is identical to the project-level health. The class `SqlWalker` is described in the violations section as having `CBO: 115`, `WMC: 397`, God Class, and 14 errors — yet the header health score does not reflect that class's condition at all. A user who runs `--class` to understand how bad a specific class is will walk away with a completely wrong impression. The violation list is scoped to the class, but the health dashboard is not. There is no label or warning saying "project-level score".

The `--namespace` drill-down does correctly show a recalculated health for that namespace (78.8% for `Doctrine\ORM\Query`), so the inconsistency makes `--class` feel broken by comparison.

---

**C2 — Namespace drill-down shows two contradictory health scores for the same namespace**

In `--namespace='Doctrine\ORM\Query'` output:
```
Health [namespace: Doctrine\ORM\Query] ████████████████████████░░░░░░ 78.8% Strong

Worst namespaces
  44.7 Doctrine\ORM\Query (16 classes, 196 violations) — high coupling, high complexity
```

The same namespace has health `78.8% Strong` in the header and `44.7` (implicitly "Weak" based on JSON) in the list — a difference of 34 points with no explanation. A new user's first reaction: "Is 78.8% or 44.7 the real score? These can't both be right." In the JSON, the header score comes from a recalculated project-filtered health while `44.7` is the namespace's own aggregate health score. This distinction is invisible in CLI output.

---

### HIGH

**H1 — Label thresholds differ silently per dimension, making scores incomparable**

Guzzle output:
```
Health █████████████████████░░░░░░░░░ 70.5% Strong
  Typing          ████████████████████████░░░░░░ 78.8% Weak
```

`70.5%` is "Strong" but `78.8%` is "Weak" — a higher number gets a worse label. A new user naturally assumes the same scale applies across the board. In reality each dimension has independent thresholds (confirmed in JSON: `overall warn>50`, `typing warn>80`). No tooltip, footnote, or hint explains this. It looks like a bug.

---

**H2 — `computed.health` violations have no `humanMessage` and no actionable guidance**

In JSON output:
```json
{
  "rule": "computed.health",
  "message": "GuzzleHttp\\Handler: health.complexity = 23.8 (error threshold: below 25.0)",
  "humanMessage": null
}
```

In CLI `--detail` output, these appear as:
```
ERROR
  GuzzleHttp\Handler: health.complexity = 23.8 (error threshold: below 25.0)  [health.complexity]
```

The user sees a number that exceeded a threshold, but: (a) there is no human-readable explanation of what `health.complexity` is, (b) there is no suggestion of what to fix, and (c) `humanMessage` is `null` — the only rule category where this is true. These violations pile up in the `[project]` section at the top where they have no file context, making them look like tool errors rather than real findings.

---

**H3 — `Typing` dimension shows no sub-breakdown when Weak**

Doctrine's Complexity (46.5% Weak) shows:
```
  Complexity      ██████████████░░░░░░░░░░░░░░░░ 46.5% Weak
                   ↳ Cyclomatic (avg): 16.9 (target: below 4) — too many code paths per method
                   ↳ Cognitive (avg): 15.3 (target: below 5) — deeply nested, hard to follow
```

Guzzle's Typing (78.8% Weak) shows:
```
  Typing          ████████████████████████░░░░░░ 78.8% Weak
```

No `↳` lines. The user has no idea whether it's missing property types, parameter types, or return types, nor which files are responsible. The JSON confirms `decomposition: []` for this dimension — the signal is present but the diagnosis is absent.

---

**H4 — `WMC` and `MI` abbreviations used without expansion**

In violation messages:
```
WMC: 397 (max 80) — total method complexity is high
MI: 32.0 (min 40.0) — code is hard to change safely
```

`WMC` (Weighted Methods per Class) and `MI` (Maintainability Index) appear only in their abbreviated form. The trailing description helps partially — "total method complexity is high" explains *what it means*, not *what it is*. First-time users who search for "WMC" may not connect it to AIMD's output. Contrast with `LCOM4: 8 (max 5) — class has 8 unrelated method groups` which is self-explanatory.

---

### MEDIUM

**M1 — `Boolean argument detected` doesn't name the argument**

```
WARN :30
  Boolean argument detected - consider splitting methods or using enums  [code-smell.boolean-argument]
```

The line number is given but not the parameter name. A developer looking at line 30 of a long file with multiple boolean parameters doesn't know which one triggered the rule. The message should name the argument, e.g., "Boolean argument `$strict` — consider splitting methods or using enums".

---

**M2 — Namespace-level violations appear without file context in the `[project]` section**

```
Violations
[project] (9 violations)
  ERROR
    GuzzleHttp\Handler: health.complexity = 23.8 (error threshold: below 25.0)  [health.complexity]
  WARN
    GuzzleHttp\Cookie: health.complexity = 27.5 (warning threshold: below 50.0)  [health.complexity]
```

The label `[project]` is opaque — it's not a filename, not a class, not a namespace. These violations have no symbol context (empty field between severity and message). This makes them visually indistinguishable from a tool error. "project-level findings" or grouping by namespace would help.

---

**M3 — `Instability` metric direction is ambiguous**

```
Instability: 0.83 (max 0.80) — package is highly unstable
```

Is 0 or 1 the bad end? A new user might think 0 = "zero stability" = bad, but in reality 1 = "fully unstable". The description "highly unstable" tells you the current state but not the direction. Contrast with `ClassRank` which says "coupling hotspot, many depend on this" — that's directionally clear.

---

**M4 — Namespace drill-down hints don't suggest the logical next step**

After seeing worst classes in namespace output:
```
Worst classes
  56.7 Doctrine\ORM\Query\SqlWalker (39 violations) — high coupling, low cohesion
  ...
Hints: --format=html -o report.html for full report
```

The hint doesn't offer `--class='Doctrine\ORM\Query\SqlWalker'` to drill deeper. The default output smartly suggests `--namespace='Doctrine\ORM\Query'`, but the namespace output breaks the chain. The user has to manually figure out the `--class` flag.

---

**M5 — `size.class-count` violation reported per-file instead of once per namespace**

In the namespace drill-down, the violation "Classes: 62 (max 25) — too many classes in namespace" is reported once (attached to a specific file), which is slightly odd — the violation is about the namespace, not the file. However, this is a minor display issue rather than a major confusion point.

---

**M6 — `Cyclomatic complexity: 10 (max 10)` reads as not-a-violation**

```
WARN :229  Client::configureDefaults
  Cyclomatic complexity: 10 (max 10) — too many code paths  [complexity.cyclomatic.method]
```

"10 (max 10)" looks like the method is exactly at the limit, not over it. The user reads "max 10" as "the limit is 10" and "value is 10" — so why is it a warning? A clearer phrasing would distinguish "at the limit" from "exceeds the limit", e.g., "(limit: 10, at threshold)".

---

**M7 — `ClassRank` name is counterintuitive**

```
ClassRank: 0.0807 (max 0.0500) — coupling hotspot, many depend on this
```

"ClassRank" evokes Google PageRank — a positive term for a popular/important class. In AIMD, high ClassRank is a warning. The description "coupling hotspot" explains it, but the name itself is misleading for first-time users.

---

### LOW

**L1 — Tech debt breakdown not shown in default project-level output**

The top-level summary shows total debt (`Tech debt: 67d 3h 20min`) but not the per-rule breakdown. Drill-down to namespace or class reveals the breakdown table. A developer who only runs the default command never sees *where* the debt is concentrated. Adding even a top-3 list would help prioritization.

---

**L2 — `health.*` in `[project]` section: format differs from other violations**

Most violations look like:
```
WARN :50  SqlWalker
  WMC: 397 (max 80) — ...
```

But `health.*` violations look like:
```
ERROR
  Doctrine\ORM\Query: health.complexity = 20.8 (error threshold: below 25.0)
```

The symbol field is empty, severity has no line number, and the message contains a raw metric key (`health.complexity`) instead of a human name. The inconsistent format makes them stand out as anomalies.

---

**L3 — "N/A" appears nowhere in this output, but it's in the UX docs as a feature**

Not observed in either project. No clarity issue here, just noting it wasn't triggered.

---

**L4 — `+131 more` in worst classes is a dead end**

```
Worst classes
  56.7 Doctrine\ORM\Query\SqlWalker (39 violations) — high coupling, low cohesion
  57.8 Doctrine\ORM\Query\AST\Node (6 violations) — high coupling, low cohesion
  64.6 Doctrine\ORM\Query\Expr (6 violations) — low cohesion
  +131 more
```

The `+131 more` has no call to action. The user doesn't know if they can see the full list or how. Adding a hint like `(use --format=html for full list)` would close the loop.

---

## What Works Well

- **Violation messages are actionable.** Most messages follow the pattern "Metric: value (threshold) — plain-English consequence", e.g., "Cyclomatic complexity: 26 (max 20) — too many code paths". No documentation needed.
- **God Class detection message is excellent.** `God Class detected (3/4 criteria): high WMC (397 >= 47), low TCC (0.10 < 0.33), large size (2294 >= 300 LOC)` — shows exactly which criteria fired, with actual values vs thresholds.
- **The health bar is immediately readable.** `██████████████░░░░░░░░░░░░░░░░ 46.5% Weak` — visual proportion matches the label. No ambiguity.
- **Tech debt in human time is effective.** `~6h (6 violations)` or `67d 3h 20min` lands better than abstract scores.
- **Default hints are contextual.** The first-run hint `--namespace='Doctrine\ORM\Query' to drill down` correctly names the actual worst namespace — not a generic suggestion.
- **Circular dependency messages are complete.** `Circular dependency (2 classes): Orx → Andx → Orx. Break the cycle by introducing interfaces` — shows the chain and suggests a fix.
- **`LCOM4` is self-explanatory.** `LCOM4: 8 (max 5) — class has 8 unrelated method groups` — the description makes the abbreviation irrelevant.
- **`Data Class detected` message includes internal reasoning.** `high public surface (WOC=100%, threshold 80%) with low complexity (WMC=5, threshold 10)` — a new user can verify the diagnosis themselves.
- **Rule codes in brackets.** `[coupling.cbo.class]` is consistently present, providing a searchable key for documentation.
