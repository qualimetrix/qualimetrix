# Qualimetrix Product Vision

**Updated:** 2026-03-15

---

## What is Qualimetrix?

Qualimetrix is a CLI tool for static analysis of PHP code quality. It measures complexity, coupling, cohesion,
type safety, and maintainability — then tells you where the problems are and how bad they are.

**Core promise:** Run one command, understand your code health. Know exactly where to invest refactoring effort.

---

## Who is it for?

### Primary Users

**1. PHP Developer (individual)**

- **Job:** "Where are my problems? What should I fix first?"
- **Context:** First run on a project, periodic check, pre-commit hook
- **Needs:** Quick overview, not a wall of text. Plain language explanations, not metric jargon. Actionable drill-down
  into specific namespaces and classes
- **Success metric:** User understands the state of their code within 10 seconds of running `qmx check`

**2. Tech Lead / Architect**

- **Job:** "Where should the team invest refactoring effort this sprint?"
- **Context:** Periodic health check, sprint planning, tech debt prioritization
- **Needs:** Worst offenders ranked by impact. Tech debt estimates. Overview suitable for sharing with stakeholders
- **Success metric:** Can identify top 3 refactoring targets from a single CLI command

**3. CI Pipeline (automated)**

- **Job:** "Did this PR make things worse? Should the build fail?"
- **Context:** Runs on every push/PR. Must be fast and produce a clear pass/fail signal
- **Needs:** Fast execution, clear exit codes, structured output for CI reporting tools (GitHub, GitLab, SARIF)
- **Success metric:** Non-zero exit code on quality regression, clean integration with CI systems

### Secondary Users

**4. AI Agent (LLM-based coding assistant)**

- **Job:** "What's wrong with this code and how do I plan a refactoring?"
- **Context:** Agent runs Qualimetrix to understand code health before making changes
- **Needs:** Structured, concise output (JSON) that fits in a context window. Enough context to prioritize, not so much
  it overwhelms. Drill-down capability for specific namespaces/classes
- **Success metric:** Agent can build a refactoring plan from one JSON summary call + 1-2 drill-down calls

**5. New Team Member (onboarding)**

- **Job:** "I just joined this team — how healthy is this codebase?"
- **Context:** Exploring an unfamiliar project. Doesn't know metric theory
- **Needs:** Plain language: "too many methods", "depends on too many classes", "deeply nested logic". No unexplained
  abbreviations
- **Success metric:** Understands what's wrong without Googling "LCOM4"

**6. PR Reviewer**

- **Job:** "Did this PR introduce new quality issues?"
- **Context:** Reviewing a colleague's PR, wants a quick quality check on changed code
- **Needs:** Focus on changed files only. New violations highlighted
- **Success metric:** Sees only relevant violations, not the entire project's issues

**7. Consultant / Auditor**

- **Job:** "Assess this unfamiliar codebase's health for a stakeholder report"
- **Context:** Short engagement, needs quick overview of worst areas
- **Needs:** Summary suitable for sharing (terminal output, HTML report). Worst hotspots ranked
- **Success metric:** Can produce a quality assessment within minutes, not hours

---

## Key Questions Qualimetrix Answers

| Question                      | Who asks it                | How Qualimetrix answers                                                     |
| ----------------------------- | -------------------------- | --------------------------------------------------------------------------- |
| "Where are my problems?"      | Developer, Tech Lead       | Health scores (0-100) per dimension + worst offenders ranked                |
| "What should I fix first?"    | Developer, Tech Lead       | Worst namespaces/classes by health score + tech debt estimates              |
| "Did I make things worse?"    | Developer, CI, PR Reviewer | Violation counts, exit codes, scoped analysis (`--analyze=git:staged`)      |
| "Why is this class bad?"      | Developer, AI Agent        | Health decomposition: which metrics contribute, what the numbers mean       |
| "Is the build safe to merge?" | CI Pipeline                | Exit code (0/1/2) + structured reports (SARIF, GitLab, GitHub annotations)  |
| "How do I plan refactoring?"  | AI Agent, Developer        | JSON summary with health + worst offenders + metrics + drill-down           |
| "What does this number mean?" | New Team Member, Developer | Standard metric names + plain language explanations ("too many code paths") |

---

## Design Principles (product-level)

1. **Summary first, details on demand.** The default output fits on one screen and answers "where are my problems?"
   Progressive disclosure via `--detail`, `--namespace`, `--class`, and `--format=html`.

2. **Standard names + plain language.** Use established metric abbreviations (CCN, TCC, LCOM4) so experts recognize
   them, paired with plain language explanations so newcomers understand them. Never invent synonyms ("Method
   connectivity") that confuse both audiences.

3. **One tool, many surfaces.** The same analysis produces terminal summaries, JSON for machines, HTML for exploration,
   SARIF for IDEs, and CI annotations. All share a single data source — no duplication, no drift.

4. **Respect the user's time.** Fast execution (parallel processing). Concise output (no walls of text).
   Context-appropriate detail (summary for terminals, full data for machines).

5. **Transparent scoring.** Health scores (0-100) are computed from documented formulas with documented thresholds.
   Users can inspect decomposition ("why is cohesion 45?") and customize thresholds. No black boxes.

6. **Graceful degradation.** Works on a single file. Works on 10k+ files. Works with partial analysis. Omits sections
   that have no data rather than showing empty sections. Adapts to narrow terminals.

---

## What Qualimetrix is NOT

- **Not a type checker.** Use PHPStan or Psalm for type safety. Qualimetrix measures type coverage as a metric, but doesn't do
  type inference.
- **Not a code formatter.** Use PHP-CS-Fixer or PHPCS for style. Qualimetrix measures structural quality, not formatting.
- **Not an auto-fixer.** Use Rector for automated refactoring. Qualimetrix identifies where to refactor, not how.
- **Not a security scanner.** Qualimetrix detects basic security patterns (hardcoded credentials, SQL injection patterns) but
  is not a replacement for dedicated tools (Psalm taint analysis, SonarQube SAST).

See [PRODUCT_ROADMAP.md](PRODUCT_ROADMAP.md) for strategic positioning and feature roadmap.

---

## How This Document Guides Development

When designing a new feature, ask:

1. **Which user(s) does this serve?** If you can't point to a specific persona above, reconsider.
2. **Which question does it answer?** If it doesn't help answer one of the key questions, it may be a "nice to have"
   rather than essential.
3. **Does it follow the design principles?** Especially: is the default output concise? Are metric names explained? Does
   it degrade gracefully?
4. **Does it respect the "is NOT" boundaries?** Avoid scope creep into type checking, formatting, or auto-fixing
   territories.
