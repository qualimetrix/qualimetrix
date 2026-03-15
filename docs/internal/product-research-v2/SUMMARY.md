# Product Research V2 — Summary

**Date:** 2026-03-15
**Methodology:** 7 AI agents as user personas, testing on 12 benchmark projects (reshuffled from V1). Focus on depth of user experience after V1 fixes.

## Research Team

| Agent                | Persona                     | Focus                               | Report                           |
| -------------------- | --------------------------- | ----------------------------------- | -------------------------------- |
| Captain Obvious      | Mid-level dev, first run    | Output clarity, message quality     | [01](01-captain-obvious.md)      |
| The Archaeologist    | Technical writer            | Documentation vs reality            | [02](02-the-archaeologist.md)    |
| Number Crunch McData | Data scientist              | Cross-project metrics, anomalies    | [03](03-number-crunch-mcdata.md) |
| Drill Down Diana     | Tech Lead, sprint planning  | Progressive disclosure workflow     | [04](04-drill-down-diana.md)     |
| Pipeline Patty       | DevOps engineer             | CI/CD formats, baseline, exit codes | [05](05-pipeline-patty.md)       |
| Config Wizard        | Experienced dev, team setup | Config UX, YAML, CLI interaction    | [06](06-config-wizard.md)        |
| GPT-5 the Intern     | AI coding assistant         | JSON consumption, refactoring plan  | [07](07-gpt5-the-intern.md)      |

## Projects Analyzed

| Project         | Agent(s)       | Files | Violations  | Overall Health   |
| --------------- | -------------- | ----: | ----------: | ---------------: |
| Doctrine ORM    | Captain, GPT-5 | 453   | 1,200       | 66.6% Acceptable |
| Guzzle          | Captain        | 41    | 218         | 70.5% Strong     |
| Symfony Console | Archaeologist  | 132   | ~560        | ~67% Good        |
| AIMD self       | Archaeologist  | 415   | 1,288       | ~68% Acceptable  |
| Monolog         | McData, Config | 121   | 494–505     | 66.3% Acceptable |
| PHP-Parser      | McData, GPT-5  | 269   | 1,447       | 57.7% Acceptable |
| Laravel         | McData, Diana  | 1,536 | 7,290–7,489 | 51.8% Acceptable |
| Flysystem       | McData         | 55    | 116         | 86.2% Strong     |
| PHPUnit         | Diana          | 995   | 1,801       | 73.5% Strong     |
| Doctrine DBAL   | Pipeline       | 432   | 880         | 69.7% Acceptable |
| Composer        | Pipeline       | 286   | 2,694       | —                |
| Symfony DI      | Config         | 194   | 1,007       | —                |

---

## Consolidated Findings

### CRITICAL — Trust-Breaking Issues

| #   | Issue                                                               | Found by              | Description                                                                                                                                                                                                                                                     |
| --- | ------------------------------------------------------------------- | --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C1  | **`--class` drill-down shows project health, not class health**     | Captain, Diana, GPT-5 | `--class='Foo\Bar'` header shows `Health 51.8% Acceptable` (project-wide), while the actual class health is 36.6% Weak. `--namespace` correctly scopes the header; `--class` does not. Three independent agents flagged this — the most-reported finding in V2. |
| C2  | **Namespace drill-down shows contradictory health scores**          | Captain, GPT-5        | Same namespace appears as `78.8% Strong` in header and `44.7 Weak` in worst-namespaces list. Header uses hierarchical (recursive sub-namespace) aggregation; list uses flat (direct members only). Zero explanation in output.                                  |
| C3  | **`fail_on` and `computed_metrics` YAML keys blocked by validator** | Config Wizard         | `YamlConfigLoader::ALLOWED_ROOT_KEYS` rejects both keys despite full implementation in `ConfigFileStage`. Features are documented but completely inaccessible via config file. One-line fix per key.                                                            |
| C4  | **JSON format documentation describes wrong schema**                | Archaeologist         | Docs show PHPMD-compatible `files`-array structure. Actual output is summary-oriented (`meta`, `health`, `worstNamespaces`, `violations`). Any script written against docs will break.                                                                          |

### HIGH — Significant UX/Data Issues

| #   | Issue                                                          | Found by               | Description                                                                                                                                                                                                                                      |
| --- | -------------------------------------------------------------- | ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| H1  | **health.complexity is a near-binary scale**                   | McData                 | Monolog (CCN=14.3), PHP-Parser (CCN=10.6), Laravel (CCN=18.2) cluster in 53.9–55.7% — a 1.8pt band. CCN has only 8% formula weight; NPath absent entirely. PHP-Parser NPath.avg=7894 (554× Laravel's 14.3) has zero effect on score.             |
| H2  | **Bidirectional CBO devastates popular utility classes**       | McData                 | `Collection` (CBO=231, but CA=218/CE=17) scores 6.2% — ranked as Laravel's worst-coupled class. 93% of its CBO is afferent (reuse). `Application` (CE=108, genuine coupling) scores 12.1% — ranks better. Formula doesn't distinguish direction. |
| H3  | **`worstClasses` silently empty for well-structured projects** | GPT-5, Diana           | Doctrine (1143 violations) returns `worstClasses: []` because no class falls below the hardcoded 50% health threshold. PHPUnit shows same behavior. AI agents and tech leads get zero class-level guidance from top-level output.                |
| H4  | **`computed.health` rule undocumented, `humanMessage: null`**  | Archaeologist, Captain | Rule fires by default, generates violations in `[project]` section. No documentation page exists. Violations have `humanMessage: null` (only rule with this). Messages look like tool errors.                                                    |
| H5  | **`text-verbose` documented as first-class but deprecated**    | Archaeologist          | Full docs section, examples, and comparison table. Actual: emits deprecation warning, absent from `--help`. Replacement (`--format=text --detail`) not mentioned in the docs section.                                                            |
| H6  | **CLI options example shows wrong default thresholds**         | Archaeologist          | `cli-options.md` example: cyclomatic class 50/100, cognitive method 8/15. Actual: 30/50 and 15/30. Thresholds off by 2×. `default-thresholds.md` is correct.                                                                                     |
| H7  | **`metrics-json` docs show wrong symbol structure**            | Archaeologist          | Docs show per-method metrics embedded as `"ccn:Class::method"` keys inside file symbols. Actual: separate `"type": "method"` symbols.                                                                                                            |
| H8  | **Per-dimension labels differ silently**                       | Captain                | `70.5%` = Strong (overall), `78.8%` = Weak (typing). Higher number gets worse label. No explanation that dimensions have independent thresholds. Looks like a bug to newcomers.                                                                  |
| H9  | **Duplicate violations across all formats**                    | Pipeline               | Same violation appears 2–4 times for certain files. Causes: GitLab fingerprint collisions, inflated SARIF entries, baseline count inflation. Upstream bug in metric collection.                                                                  |
| H10 | **`--generate-baseline` exits 2**                              | Pipeline               | Baseline written successfully, but exit code is 2 (violations found). CI step fails unless `--fail-on=none` added. Non-obvious: output says "Baseline written" right before non-zero exit.                                                       |
| H11 | **Root namespace health misleading for single-root projects**  | Diana                  | PHPUnit summary shows `PHPUnit` at 36.1% Weak. Drill-down shows 80.2% Strong. Root namespace has zero direct classes → `typing: 0`, `maintainability: 0`. Hint leads to namespace that looks *better* than project average.                      |
| H12 | **Unknown rule names in `rules:` config silently ignored**     | Config Wizard          | `rules: { nonexistent.rule: { threshold: 10 } }` runs without warning. Silent misconfiguration.                                                                                                                                                  |
| H13 | **50-violation cap with no per-rule summary in JSON**          | GPT-5                  | Doctrine: 50 of 1143 violations visible (4%). No `violationsByRule` summary. AI agents blind to coupling/code-smell violations. `--format-opt=violations=all` undocumented in `--help`.                                                          |
| H14 | **Typing dimension shows no `↳` sub-breakdown**                | Captain                | Complexity shows `↳ Cyclomatic: X, ↳ Cognitive: Y`. Typing shows only the score with no decomposition of what's missing (property types? parameter types? return types?).                                                                        |

### MEDIUM — UX Improvements

| #   | Issue                                                                        | Found by      | Description                                                                                                                                                                     |
| --- | ---------------------------------------------------------------------------- | ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M1  | **Debt/kLOC inversely correlated with project health**                       | McData        | Flysystem (86.2% best) = 1028 min/kLOC; Laravel (51.8% worst) = 606 min/kLOC. Small clean codebases penalized by small LOC denominator. Misleading as quality signal.           |
| M2  | **health.complexity mis-aggregated at project level**                        | McData        | Project `ccn.avg` is average of namespace-level averages, not method-level median. 3–4 pathological namespaces inflate the entire score. Method median is 1.0 for all projects. |
| M3  | **Baseline message count mismatch**                                          | Pipeline      | "Baseline with 2694 violations written" but file stores 2368. Message uses raw count; file stores deduplicated count.                                                           |
| M4  | **GitLab `path: "."` for 74 project-level violations**                       | Pipeline      | `architecture.circular-dependency` violations use `"."` as path. Invalid per GitLab spec, silently dropped in MR widget.                                                        |
| M5  | **SARIF `helpUri` is generic for all 36 rules**                              | Pipeline      | Every rule links to repo root, not rule-specific docs. GHAS "Learn more" links useless.                                                                                         |
| M6  | **`--format-opt=top=N` silently ignored by summary formatter**               | Diana         | Hardcoded `MAX_WORST_OFFENDERS = 3` in `SummaryFormatter`. Works for JSON but not summary format. No error/warning.                                                             |
| M7  | **Namespace drill-down hint doesn't suggest `--class`**                      | Captain       | Summary hints suggest `--namespace=X`. Namespace output does NOT hint `--class=Y` for the next drill step. Progressive disclosure chain breaks.                                 |
| M8  | **`humanMessage` identical to `message` in JSON**                            | GPT-5         | Every violation: `humanMessage == message`. Wastes tokens (duplicate string), intended richer guidance not implemented.                                                         |
| M9  | **String value for numeric threshold silently coerces to 0**                 | Config Wizard | `error_threshold: "not_a_number"` → PHP `(int)` cast → `0` → every method flagged. 1156 violations instead of ~505. No warning.                                                 |
| M10 | **`--detail` also changes `text` format (not just summary)**                 | Archaeologist | Docs: "only affects summary format." Actual: `--format=text --detail` produces verbose multi-line grouped output.                                                               |
| M11 | **TCC/LCC listed with rule IDs in index but aren't rules**                   | Archaeologist | `website/docs/rules/index.md` lists TCC/LCC with IDs. Using them in `--only-rule` gives "does not match any registered rule."                                                   |
| M12 | **Several CLI shortcut flags absent from docs**                              | Archaeologist | ~18 shortcut flags in `--help` not listed in `cli-options.md` tabs (god-class, data-class, constructor-overinjection, lcom, mi, circular-deps).                                 |
| M13 | **`summary` format docs show wrong header and section labels**               | Archaeologist | Docs: `"AI Mess Detector — Project Health"`, `"Worst offenders (namespaces):"`. Actual: `"AI Mess Detector — N files analyzed"`, `"Worst namespaces"`.                          |
| M14 | **`--only-rule` + config `enabled: false` confusing behavior**               | Config Wizard | Warning says `--disable-rule is active` when disable came from config. `--only-rule` doesn't restore config-disabled rules.                                                     |
| M15 | **PHP-Parser worst classes are machine-generated code**                      | McData        | `Php7`/`Php8` parser (LR tables from php-yacc) flagged as worst. No way to distinguish generated from authored code.                                                            |
| M16 | **`Boolean argument detected` doesn't name the argument**                    | Captain       | Line number given but not parameter name. Useless for lines with multiple bool params.                                                                                          |
| M17 | **`Cyclomatic complexity: 10 (max 10)` reads as not-a-violation**            | Captain       | "10 (max 10)" looks like "exactly at limit." Actually a warning ≥ threshold. Phrasing ambiguous.                                                                                |
| M18 | **Default thresholds table has broken Markdown (5 cells in 4-column table)** | Archaeologist | Constructor Over-injection and Long Parameter List rows have extra cell.                                                                                                        |

### LOW — Polish Items

| #   | Issue                                                          | Found by       | Description                                                                                                                |
| --- | -------------------------------------------------------------- | -------------- | -------------------------------------------------------------------------------------------------------------------------- |
| L1  | **`+131 more` in worst classes is a dead end**                 | Captain        | No hint about how to see the full list. Needs `(use --format=html for full list)`.                                         |
| L2  | **No total debt line at class level in summary**               | Diana, Captain | Per-rule debt shown but no `Total: ~7h 40min` aggregation. Must sum manually.                                              |
| L3  | **`text-verbose` example output header doesn't match reality** | Archaeologist  | Docs show `AI Mess Detector Report` header with dividers. Actual: no header.                                               |
| L4  | **Quick Start mixes `vendor/bin/aimd` and `bin/aimd`**         | Archaeologist  | Inconsistent paths confuse end users who installed via Composer.                                                           |
| L5  | **Docker Compose example uses `analyze` alias, not `check`**   | Archaeologist  | Undocumented alias inconsistency.                                                                                          |
| L6  | **Docs claim "Top-3 worst namespaces and classes"**            | Archaeologist  | Actual: 2 namespaces, no classes at project level.                                                                         |
| L7  | **`worstClasses[n].healthScores` omits `overall` key**         | GPT-5          | Top-level health has 6 keys; offenders have 5. Inconsistent.                                                               |
| L8  | **`--format=metrics-json` truncated in pipe (64KB buffer)**    | GPT-5          | Must redirect to file. No warning. Common AI agent pattern breaks.                                                         |
| L9  | **Config error doesn't suggest correct key**                   | Config Wizard  | `Unknown configuration keys: fail_on` — doesn't hint what the valid key is.                                                |
| L10 | **Health scores persist when rules disabled**                  | Config Wizard  | Disabling all complexity rules doesn't change complexity health score. By design (health = raw metrics), but undocumented. |
| L11 | **OOM on duplication analysis exits 1, not 2**                 | Pipeline       | CI scripts checking for exit 2 treat OOM crash as success.                                                                 |
| L12 | **Checkstyle missing `column` attribute**                      | Pipeline       | Optional per spec, but SonarQube/Jenkins use it for cursor placement.                                                      |
| L13 | **Violation count vs health ranking divergence unexplained**   | Diana          | 59-violation `Builder` at rank 6; 15-violation classes rank higher. Correct (health ≠ count) but confusing.                |
| L14 | **`ClassRank` name counterintuitive**                          | Captain        | Evokes PageRank (positive). In AIMD, high = bad. Description explains, name misleads.                                      |
| L15 | **`Instability` metric direction ambiguous**                   | Captain        | "0.83 (max 0.80) — highly unstable" — is 0 or 1 the bad end?                                                               |

---

## What Works Well

Confirmed strengths across multiple agents:

1. **Health bar visualization** (Captain, Diana) — `████████████████░░░░░░░░░░░░░░ 51.8% Acceptable` is immediately readable. Strongest UX feature.

2. **Namespace drill-down scoping is correct** (Diana, Captain) — Health scores at namespace level reflect the filtered scope, not the project. The V1 bug (H1) is fully fixed.

3. **Contextual hints** (Captain, Diana) — `--namespace='Illuminate\Validation\Concerns' to drill down` names the actual worst namespace. Smart, not generic.

4. **Violation messages are actionable** (Captain) — "Cyclomatic complexity: 26 (max 20) — too many code paths" follows value-threshold-consequence pattern. God Class message shows all criteria with actual vs threshold values.

5. **Cross-format consistency** (Pipeline) — Workers=0 and workers=4 produce identical results down to 4 decimal places. Deterministic parallelism.

6. **Baseline workflow works** (Pipeline) — Generate → Apply suppresses all violations, exits 0. Robust.

7. **Default thresholds docs accurate** (Archaeologist) — Every numeric threshold checked against source code matches.

8. **Overall project ranking correct** (McData) — Flysystem (86.2%) > Monolog (66.3%) > PhpParser (57.7%) > Laravel (51.8%) matches expert intuition.

9. **Coupling discrimination excellent** (McData) — 25.2%–96.4% spread (71pt) at namespace level. Worst namespaces match domain knowledge.

10. **Compact JSON for AI** (GPT-5) — ~9,500 tokens for 450-file project. Fits 32K context. `violationsMeta.truncated` is an honest incomplete-data signal.

11. **Per-violation tech debt** (GPT-5, Diana) — `techDebtMinutes` per violation enables effort-weighted prioritization. Per-rule breakdown at class level is "the most actionable output in the tool."

12. **Config auto-discovery and verbose logging** (Config Wizard) — `aimd.yaml` auto-detected. `-v` shows `Configuration loaded from: defaults, composer.json, test-aimd.yaml`.

---

## Prioritized Action Plan

### P0 — Must Fix (trust / correctness)

1. **Scope `--class` health to the class** (C1) — Most-reported finding (3 agents). When `--class=X`, header should read `Health [class: X] 36.6% Weak`, not project-wide 51.8%.

2. **Add `fail_on` and `computed_metrics` to `ALLOWED_ROOT_KEYS`** (C3) — One-line fix per key in `YamlConfigLoader.php`. Unlocks documented features.

3. **Rewrite JSON format documentation** (C4) — Current docs describe a completely different schema. Must reflect actual `meta`/`health`/`worstNamespaces`/`violations` structure.

4. **Fix contradictory namespace scores** (C2) — Either label the two scores distinctly ("namespace only: 44.7" vs "namespace + children: 78.8") or consistently use one aggregation method.

### P1 — Should Fix (UX impact)

5. **Retune complexity formula** (H1) — Increase CCN weight from 0.2 to 0.5–0.8. Consider adding NPath component. Goal: spread 3 real projects into 10–15pt range instead of 1.8pt.

6. **Address CBO direction bias** (H2) — Consider CE-only or CA/CE weighting at class level. `Collection` (CE=17) shouldn't score worse than `Application` (CE=108).

7. **Lower `worstClasses` threshold or add fallback** (H3) — Lower from 50% to 60%, or always include top-N by violation count regardless of health.

8. **Document `computed.health` rule** (H4) — Add docs page. Implement `humanMessage` for health violations explaining what to fix.

9. **Fix `text-verbose` docs** (H5) — Remove full section, add deprecation notice pointing to `--format=text --detail`.

10. **Fix `cli-options.md` threshold examples** (H6) — Change 50/100 → 30/50 for cyclomatic class, 8/15 → 15/30 for cognitive method.

11. **Fix `metrics-json` docs** (H7) — Show actual separate `"type": "method"` symbols, not embedded `"ccn:Class::method"`.

12. **Explain per-dimension label thresholds** (H8) — Add a note or `(*)` footnote in summary output explaining that dimensions have independent scales.

13. **Fix duplicate violations** (H9) — Deduplicate in rule execution or metric collection layer. Affects all output formats.

14. **`--generate-baseline` should exit 0** (H10) — Baseline generation's purpose is to capture current state; exit 2 is wrong semantics.

15. **Fix root namespace aggregation** (H11) — Don't show root namespace in worst list when it has zero direct classes.

16. **Warn on unknown rule names in config** (H12) — Validate rule names against registry, emit warning.

17. **Add `violationsByRule` summary to JSON** (H13) — `{"complexity.npath": 340, "coupling.cbo": 87, ...}` in `violationsMeta`. ~500 bytes, complete visibility.

18. **Add Typing decomposition** (H14) — Show `↳` lines for property/parameter/return type coverage like Complexity does.

### P2 — Should Fix (quality of life)

19. **Rethink debt/kLOC display** (M1) — Either remove or add prominent context. Alternative: violations per 100 classes.

20. **Fix baseline message count** (M3) — Use `$baseline->count()` instead of `\count($violations)`.

21. **Fix GitLab `path: "."`** (M4) — Use first file in dependency chain or synthetic path.

22. **Add rule-specific `helpUri` to SARIF** (M5) — Link to website docs pages.

23. **Support `--format-opt=top=N` in summary formatter** (M6) — Replace hardcoded `MAX_WORST_OFFENDERS = 3`.

24. **Add `--class` hint in namespace drill-down** (M7) — Complete the progressive disclosure chain.

25. **Differentiate `humanMessage` from `message`** (M8) — Either remove or implement richer guidance.

26. **Validate numeric config values** (M9) — Reject non-numeric strings for numeric thresholds.

27. **Fix remaining docs inconsistencies** (M10–M13, M18) — `--detail` scope, TCC/LCC rule IDs, missing CLI flags, summary labels, broken table.

### P3 — Nice to Have

28. **Add `--class` hint after `+N more` in worst classes** (L1)
29. **Add total debt aggregation at class level** (L2)
30. **Fix Quick Start path inconsistency** (L4, L5)
31. **Add `overall` to `worstClasses[n].healthScores`** (L7)
32. **Warn about metrics-json pipe truncation** (L8)
33. **Generated code detection** (M15) — `--exclude-generated` or auto-detect heuristics.

---

## Cross-Cutting Themes

### 1. The `--class` Drill-Down Is Broken

Three agents independently found that `--class` shows project health in the header. This is the V2 equivalent of V1's "nonexistent paths produce fake scores" — a trust-breaking bug at the deepest drill level. The namespace drill-down works correctly, making the inconsistency more jarring. **This is the single most impactful fix.**

### 2. Formulas Need Direction-Aware Tuning

The complexity formula compresses three real-world projects into a 1.8-point band. The coupling formula treats afferent and efferent coupling identically, punishing popular utility classes. Both work well in the middle range but fail at the extremes — exactly where users need the most discrimination.

### 3. Documentation Drift Is Concentrated in Format Specs

The JSON format docs describe a completely different schema. The `metrics-json` docs show a non-existent embedding pattern. The `text-verbose` docs describe a deprecated format. The *rule documentation* and *default thresholds* are accurate. The drift is specifically in output format specifications — likely because the UX redesign changed output structure without updating format docs.

### 4. Config Validator Is Out of Sync

`ALLOWED_ROOT_KEYS` is a manually-maintained list that missed `failOn` and `computedMetrics`. Unknown rule names in config are silently ignored. The validator catches invalid keys but doesn't validate values (strings coerce to 0). The validation layer needs to be integrated with the processing layer rather than maintained separately.

### 5. The Tool Is More Mature Than V1

V1 found trust-breaking correctness bugs (fake scores for nonexistent paths, zero-discrimination coupling formula). V2 findings are primarily about **presentation accuracy** (wrong health scope, contradictory scores) and **documentation lag** (format specs outdated). The core metric engine, parallel processing, and baseline workflow are solid. The worst offender rankings match domain expert intuition across all 12 projects tested.

---

## Comparison with V1

| Aspect              | V1 (2026-03-15)                             | V2 (2026-03-15)                                                       |
| ------------------- | ------------------------------------------- | --------------------------------------------------------------------- |
| CRITICAL findings   | 2 (fake scores, zero-discrimination CBO)    | 4 (class health scope, namespace scores, config validator, JSON docs) |
| HIGH findings       | 10                                          | 14                                                                    |
| Character           | Correctness bugs, missing features          | Presentation bugs, documentation drift                                |
| Scoring engine      | Broken (CBO bottomed at 0)                  | Working (rankings correct, formulas need tuning)                      |
| Documentation       | Major gaps (9 missing rules, wrong default) | Format specs outdated, rules docs accurate                            |
| CI integration      | "Production-ready" (Pipeline Pete)          | Mostly ready, duplicates + baseline exit code                         |
| Drill-down workflow | Broken (H1: project-wide health shown)      | Namespace works perfectly; `--class` broken                           |
| Config              | Not tested in V1                            | Validator blocking documented features                                |
| AI consumption      | JSON worked, missing hints                  | JSON compact and useful, `worstClasses` threshold too strict          |
