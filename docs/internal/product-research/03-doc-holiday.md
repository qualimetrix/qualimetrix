# Documentation Quality Audit — "Doc Holiday" Review

**Reviewer:** Doc Holiday (documentation-only perspective)
**Date:** 2026-03-15
**Scope:** All English documentation under `website/docs/`

---

## 1. Executive Summary

The documentation is remarkably thorough for a pre-1.0 tool. Rule documentation is the standout: every rule has clear plain-language explanations, concrete PHP examples, actionable fix guidance, and implementation notes for power users. The main weaknesses are (1) a Quick Start page that is not actually a quick start -- it jumps straight into integration methods without showing basic usage first, (2) several CLI options and output formats that exist in the tool but are undocumented or documented incorrectly, and (3) the configuration docs lack coverage of several YAML options visible in the CLI help. A newcomer can get productive within 5 minutes if they find the installation page, but the Quick Start page will confuse them.

---

## 2. Quick Start Evaluation

### Problem: It is not a Quick Start

The page titled "Quick Start" (`getting-started/quick-start.md`) does not show how to run the tool. It jumps directly into three integration methods: pre-commit hook, GitHub Action, and Docker. A newcomer who just installed the tool and wants to see what it does will not find what they need here.

**What is missing from Quick Start:**
- A simple "run your first analysis" section: `vendor/bin/aimd check src/`
- What the output looks like (example output)
- What to do with the results
- Link to understanding the violations

**What the Quick Start currently covers (which belongs elsewhere):**
- Pre-commit hook setup (3 installation variants)
- GitHub Action YAML
- Docker setup with Docker Compose, GitLab CI, Jenkins
- Excluding paths
- Method comparison table
- Team size recommendations
- Troubleshooting

This is an "Integration Guide" mislabeled as a Quick Start. The actual installation page (`installation.md`) is clean and functional, but it ends with "Head to the Quick Start" -- which then does not show basic usage.

### Recommended fix

1. Add a "First Analysis" section at the top of Quick Start (or as a separate page between Installation and Quick Start):
   ```
   ## Your First Analysis
   vendor/bin/aimd check src/
   ```
   Show example output, explain the severity levels, link to rules docs.

2. Rename the current Quick Start to "Integration Guide" or "Setting Up CI & Hooks".

### Installation page

The installation page is solid. Clear requirements (PHP 8.4+, Composer), three methods, verification step. The PHAR section says "Coming soon" -- this is fine as long as it ships before or at 1.0. One minor issue: the Docker section says "Run analysis in a container" but does not explain where the Docker image comes from (no public image reference or Dockerfile path).

---

## 3. Configuration Documentation

### What works well

- Clear explanation of `paths`, `exclude`, `exclude_paths` with the important semantic difference (exclude skips files entirely; exclude_paths only suppresses violations)
- Good minimal and full config examples
- CLI override semantics are documented
- Link to CLI Options reference at the end

### Gaps and issues

**A. Missing YAML options.** The configuration page does not document these YAML options that the CLI help and usage-scenarios page reveal:
- `fail_on` -- mentioned only in `cli-options.md` and `usage-scenarios.md`, not in `configuration.md`
- `only_rules` -- mentioned only in `usage-scenarios.md`, not in `configuration.md`
- `disabled_rules` -- referenced in `usage-scenarios.md` ("Add them to `disabled_rules`") but never defined in the configuration docs

**B. `only_rules` vs `--only-rule`.** The usage-scenarios page shows `only_rules` as a YAML key, but `configuration.md` never mentions it. A user reading only the configuration page would not know this exists.

**C. No schema reference.** There is no complete list of all valid top-level YAML keys. A user has to piece together the schema from examples scattered across multiple pages. A "Configuration Reference" table listing every valid key would be valuable.

**D. Rule options syntax.** The config page shows threshold customization with nested YAML keys (`method: { warning: 15, error: 25 }`), but does not explain the naming convention (when to use `method.warning` vs just `warning`). The rule docs do this individually, but a general pattern explanation in the config docs would help.

---

## 4. Rule Documentation Audit

### Overall quality: Excellent

Every rule page follows a consistent structure: Rule ID, What it measures, How to read the value (interpretation table), Thresholds, Example (PHP code), How to fix (actionable), Implementation notes (when relevant), Configuration (YAML + CLI). This is best-in-class documentation.

### Per-rule findings

#### Complexity rules (`complexity.md`)

- **Cyclomatic Complexity:** Excellent. The CCN2+ extension is clearly explained with a "Comparing with other tools" callout. The `match` arm counting behavior is documented. Configuration shows both YAML and `--rule-opt` syntax.
- **Cognitive Complexity:** Good. The nesting penalty example is clear with inline comments showing point values. Missing: no explicit reference to SonarSource as the spec source (would help users who want to verify).
- **NPath Complexity:** Good. The multiplicative nature is well-explained with the "8 independent if statements = 256 paths" example. Implementation notes cover PHP-specific extensions. The additive `match` approach vs pdepend's multiplicative is a nice differentiator.
- **WMC:** Good. The example with per-method CCN values is helpful. Minor: the config example shows `exclude_data_classes: true` but this option name is not listed in the default-thresholds page and it is unclear if this is `excludeDataClasses` or `exclude_data_classes` in YAML.

#### Coupling rules (`coupling.md`)

- **CBO:** Excellent. Bidirectional formula (Ca + Ce) is clearly explained. The 14 coupling types are listed. The namespace-level configuration with `min_class_count` and `exclude_namespaces` is a nice touch.
- **Instability:** Good. The inverted formula explanation ("why is high instability bad?") is well done. The example with `DailyReportJob` is practical.
- **Distance:** Excellent. The Zone of Pain / Zone of Uselessness concepts are explained clearly. The namespace-level scope with `include_namespaces`/`exclude_namespaces` is documented.
- **ClassRank:** Good. The PageRank analogy with highway interchanges is effective. Implementation notes include algorithm parameters (damping factor, iterations, epsilon).

One issue: the rules index page (`rules/index.md`) lists ClassRank in the Coupling summary table but does NOT include it. The coupling section in `index.md` shows only CBO, Instability, and Distance. ClassRank is missing from the index.

#### Cohesion rules (`cohesion.md`)

- **TCC/LCC:** Good documentation for metrics-only (no violations). The dinner party analogy is effective. Implementation notes clearly state the B&K spec deviations (no invocation trees, public-only methods). Good cross-reference to LCOM in design rules.
- Issue: TCC and LCC are described as "Metric ID: `tcc`/`lcc`" rather than "Rule ID" -- correct since they do not produce violations, but a user searching for how to configure them might be confused. The page could benefit from a more prominent note that these are informational metrics only.

#### Code Smell rules (`code-smell.md`)

- All 15 code smell rules are documented with examples and fix guidance.
- **Constructor Over-injection:** Well documented with thresholds and configuration.
- **Data Class / God Class:** Multi-criteria detection is explained clearly with threshold tables.
- **Identical Sub-expression:** Excellent -- four distinct patterns documented with examples.
- **Unused Private:** Good edge-case coverage (magic methods, constructor promotion, anonymous classes).
- Issue: The "Configuration" section at the bottom repeats individual rule configs, which is nice for completeness but creates a very long page.

#### Size rules (`size.md`)

- Clean, consistent structure. Property Count documents `excludeReadonly` and `excludePromotedOnly` options well.
- The "strict comparison" note for property count ("> not >=") is a useful detail.

#### Maintainability rules (`maintainability.md`)

- Excellent. The MI formula is given explicitly. The LLOC vs physical LOC distinction is documented. The `minLoc` option is explained with rationale. The "inverted thresholds" warning is prominent.

#### Security rules (`security.md`)

- All 5 rules documented. Hardcoded Credentials has detailed detection pattern documentation (suffix words, compound keys, value filtering).
- Issue: SQL Injection, XSS, and Command Injection rules only detect superglobal usage, which is quite narrow. This limitation is not explicitly called out -- a user might expect broader taint analysis.

### Missing from rules index

The rules index page (`rules/index.md`) is missing these rules from its summary tables:
- `coupling.class-rank` -- not in the Coupling summary table
- `code-smell.constructor-overinjection` -- not in the Code Smell table
- `code-smell.data-class` -- not in the Code Smell table
- `code-smell.god-class` -- not in the Code Smell table
- `code-smell.unused-private` -- not in the Code Smell table
- `security.sql-injection` -- not in the Security table
- `security.xss` -- not in the Security table
- `security.command-injection` -- not in the Security table
- `security.sensitive-parameter` -- not in the Security table

The Security section in the index shows only Hardcoded Credentials and links to "Read more." While the detail pages cover everything, the index should list all rules for quick reference.

---

## 5. Reference Accuracy — Default Thresholds vs CLI

### Comparison: default-thresholds.md vs CLI `--help`

The default thresholds page (`reference/default-thresholds.md`) lists thresholds for all major rules. Comparing with CLI help:

**Matches:**
- Cyclomatic: 10/20 (method), 30/50 (class) -- matches
- Cognitive: 15/30 (method), 30/50 (class) -- matches
- NPath: 200/1000 (method) -- matches
- WMC: 50/80 -- matches
- Method count: 20/30 -- matches
- Class count: 15/25 -- matches
- Property count: 15/20 -- matches
- CBO: 14/20 -- matches
- Instability: 0.8/0.95 -- matches
- Distance: 0.3/0.5 -- matches
- ClassRank: 0.02/0.05 -- matches
- MI: 40/20 -- matches
- LCOM: 3/5 -- matches
- NOC: 10/15 -- matches
- DIT: 4/6 -- matches
- Type Coverage: 80/50 -- matches
- Long parameter list: 4/6 -- matches
- Constructor overinjection: 8/12 -- matches

**Issues in the thresholds page:**
1. **Broken table formatting** in Code Smell Rules section. Several rows have misaligned columns (constructor-overinjection, data-class, god-class, long-parameter-list, unreachable-code all have formatting issues where the columns do not align with headers). The table mixes two different column schemas -- some rows use "Severity | Default" and others use "Warning | Error | enabled".

2. **Code Duplication thresholds** -- the page says warning for <50 lines and error for >=50 lines. The docs page for duplication says the same. However, the CLI help does not show any shortcut flags for duplication thresholds, so these cannot be verified from CLI alone. This is consistent but unverifiable.

---

## 6. CLI Options vs Documentation — Accuracy Check

### Options in CLI but missing/incorrect in docs

| CLI Option                         | Status in Docs                                                                                                                                                                                                                                                     |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `--format=summary`                 | **MISSING.** CLI lists `summary` as a format. The output-formats page lists `text` as default and does not mention `summary` at all. The CLI default says "Default: summary" while the CLI options doc says "Default: `text`". This is a **direct contradiction**. |
| `--format=html`                    | **MISSING.** CLI lists `html` as a format. Not documented in output-formats page.                                                                                                                                                                                  |
| `--output`, `-o`                   | **MISSING from output-formats page.** Documented in cli-options.md but not mentioned in the output formats page where a user would most expect it.                                                                                                                 |
| `--namespace`                      | **MISSING from configuration/usage docs.** Only appears in cli-options.md implicitly. Not documented as a filtering mechanism in usage-scenarios.                                                                                                                  |
| `--class`                          | Same as `--namespace` -- undocumented outside CLI help.                                                                                                                                                                                                            |
| `--detail`                         | **Partially documented.** CLI help says "Show detailed output (grouped violations with explanations and debt breakdown)." Mentioned in cli-options.md shortcut tables but not as a standalone option. Not explained in output-formats.md.                          |
| `--wmc-exclude-data-classes`       | CLI flag exists. Documented in complexity.md config example as `exclude_data_classes` but not in the shortcut flags table.                                                                                                                                         |
| `--mi-exclude-tests`               | CLI flag exists. Not in the shortcut flags table in cli-options.md.                                                                                                                                                                                                |
| `--lcom-exclude-readonly`          | CLI flag exists. Not in the shortcut flags table in cli-options.md.                                                                                                                                                                                                |
| `--property-exclude-readonly`      | CLI flag exists. Documented in size.md but listed in shortcut flags table of cli-options.md.                                                                                                                                                                       |
| `--property-exclude-promoted-only` | CLI flag exists. Same as above.                                                                                                                                                                                                                                    |
| `--property-count-warning/error`   | CLI flags exist. Not in the Size shortcut flags table -- only `--class-count-*` and `--method-count-*` are listed.                                                                                                                                                 |
| `--class-rank-warning/error`       | CLI flags exist. Not in the Coupling shortcut flags table.                                                                                                                                                                                                         |

### Documentation describes things that do not match CLI

1. **Default format discrepancy:** `cli-options.md` says default format is `text`. CLI help says default is `summary`. One of these is wrong.

2. **`text-verbose` format:** Documented in `output-formats.md` and `cli-options.md` as an available format. CLI help does NOT list `text-verbose` in the format options. It lists `summary, text, json, checkstyle, sarif, gitlab, github, metrics-json, html`. This suggests `text-verbose` was either renamed or removed.

3. **`analyze` command name:** `cli-options.md` opens with "AI Mess Detector provides the `analyze` command" but then shows `bin/aimd check [options]`. The CLI help shows the command is `check` with `analyze` as an alias. The docs should be consistent.

---

## 7. Cross-references and Navigation

### Can you find what "CCN" means?

Yes. The complexity rules page defines "Cyclomatic Complexity (often abbreviated CCN)" in the opening paragraph. The index page also mentions "Cyclomatic (CCN)" in the Available Metrics table. Searchable and well-linked.

### Can you find CI integration?

Yes. Multiple paths: Quick Start has GitHub Action section, navigation has ci-cd section, usage-scenarios has CI/CD pipeline section, git-integration has CI pipeline examples. Redundancy here is good -- the user will find it.

### Can you find how to suppress a violation?

Yes. The baseline page has a comprehensive "@aimd-ignore" section with all four tag variants documented in a table. The default-thresholds page also shows `@aimd-ignore` in a code example. Cross-reference from configuration page would be helpful but is not critical.

### Navigation gaps

- No "What's Next?" links at the end of most rule pages (complexity, coupling, etc.). After reading about complexity rules, there is no guidance on where to go next.
- The rules index page is the primary navigation hub for rules but is missing many rules from its summary tables (see Section 4).
- No search guidance -- if a user gets a violation code like `complexity.cyclomatic.method`, there is no explanation of the `.method` suffix convention. The docs explain `complexity.cyclomatic` but not the sub-levels.

---

## 8. Gaps and Missing Content

### High priority

1. **No "First Analysis" tutorial.** The gap between installation and integration is the biggest documentation problem. A user needs to see: install -> run -> understand output -> fix issues -> configure.

2. **Format `summary` and `html` are undocumented.** These are listed in the CLI help but have no documentation at all.

3. **Default format contradiction.** CLI says `summary`, docs say `text`. This must be resolved.

4. **`text-verbose` may be a ghost format.** Documented but not in CLI `--format` help. If it was renamed to `text --detail`, the docs need updating.

5. **Rules index is incomplete.** Missing ~9 rules from summary tables.

### Medium priority

6. **Configuration reference is incomplete.** Missing `fail_on`, `only_rules`, `disabled_rules` YAML keys.

7. **`--namespace` and `--class` filtering are undocumented** outside of bare CLI help. These seem like powerful features (drill-down by namespace or class) that deserve explanation.

8. **`--detail` flag is under-documented.** It seems to be the replacement for `text-verbose` but this is not explained.

9. **Violation code sub-levels.** Violation codes like `complexity.cyclomatic.method` vs `complexity.cyclomatic.class` are used in output but never formally explained. A user seeing `.method` for the first time would benefit from a brief explanation.

10. **Security rules scope limitation.** SQL Injection, XSS, and Command Injection only detect superglobal usage. This is a significant limitation that should be stated explicitly to set user expectations.

### Low priority

11. **Docker image source.** Installation and Quick Start reference `aimd` as a Docker image but never explain where it comes from (Docker Hub? build from Dockerfile?).

12. **`--output` flag is not mentioned in output-formats page.** Documented in cli-options.md but users will naturally look for it when choosing a format.

13. **`composer.json` autoload detection.** The CLI help says paths default to "auto-detect from composer.json" but this is not explained in the docs.

14. **Computed metrics.** The CLAUDE.md mentions computed metrics with Symfony Expression Language formulas and health scores. These appear to be user-facing features but have no website documentation.

---

## 9. What Works Well

1. **Rule documentation is best-in-class.** Every rule has: plain-language explanation, interpretation table, thresholds, PHP code example, actionable fix guidance, implementation notes, configuration with both YAML and CLI. This is better than phpmd, phpstan, or psalm documentation for individual rules.

2. **Consistent structure across all rule pages.** A user who learns one page can navigate any other. The format is predictable and complete.

3. **"How to fix" sections are practical.** They do not just say "refactor" -- they show specific patterns (Strategy, Extract Method, composition over inheritance, parameter objects). This transforms the tool from a problem-reporter into a teaching tool.

4. **"Implementation notes" and "Comparing with other tools" callouts.** Users migrating from phpmd or phpmetrics will appreciate knowing why values differ. The LCOM4 vs Henderson-Sellers note is particularly valuable.

5. **Git integration documentation.** The `--analyze` vs `--report` distinction is clearly explained with a comparison table and use-case recommendations. This is a nuanced feature that is documented well.

6. **Baseline documentation.** Complete workflow from creation to CI usage to cleanup. Best practices section is practical.

7. **Security rules.** Detection patterns are enumerated explicitly (which variable names, which function calls). Users know exactly what is and is not detected.

8. **Output formats page.** Each format has: when to use, example output, CI usage snippet. The comparison table and exit codes section tie it together.

---

## 10. Recommendations (Prioritized)

### Critical (blocks user onboarding)

1. **Add a "First Analysis" section** to the beginning of Quick Start (or as a standalone page). Show: run command -> example output -> what the numbers mean -> link to rules.

2. **Fix the default format discrepancy.** CLI says `summary`, docs say `text`. Update docs to match reality.

3. **Document `summary` and `html` output formats** in the output-formats page. These are in the CLI help and users will encounter them.

4. **Clarify `text-verbose` status.** If it was replaced by `text --detail`, update the output-formats page accordingly. If it still exists, add it to the CLI `--format` help.

### High (misleading or incomplete info)

5. **Complete the rules index page.** Add ClassRank, Constructor Over-injection, Data Class, God Class, Unused Private, SQL Injection, XSS, Command Injection, Sensitive Parameter to the summary tables.

6. **Document all YAML config keys** in the configuration page: `fail_on`, `only_rules`, `disabled_rules`.

7. **Add missing CLI shortcut flags** to the cli-options.md tables: `--property-count-*`, `--class-rank-*`, `--wmc-exclude-data-classes`, `--mi-exclude-tests`, `--lcom-exclude-readonly`.

8. **Document `--namespace` and `--class` filtering options** with examples and use cases.

### Medium (improves user experience)

9. **Document `--detail` flag** with example output showing the difference from default.

10. **Add violation code sub-level explanation** (`.method`, `.class`, `.namespace` suffixes).

11. **Add explicit scope limitation note** to SQL Injection/XSS/Command Injection rules: "Only detects superglobal usage. Does not perform full taint analysis."

12. **Fix default-thresholds.md Code Smell table formatting** -- column alignment is broken for several rows.

13. **Document computed metrics / health scores** if they are user-facing features.

### Low (polish)

14. **Clarify Docker image source** in installation docs.

15. **Add "What's Next?" links** at the end of rule pages.

16. **Document `--output` flag** in the output-formats page alongside format descriptions.

17. **Explain `composer.json` autoload path detection** in the paths documentation.
