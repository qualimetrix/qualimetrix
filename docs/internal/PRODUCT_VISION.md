# Qualimetrix Product Vision

**Updated:** 2026-08-08

This document states the **target state** of the product and the reasoning that governs the roadmap.
It is not a CLI reference, not marketing copy, and not a status report. It deliberately avoids naming
command-line options, output shapes, and configuration keys: those change while the product outcome
stays the same, and their home is the user documentation.

---

## 1. Why Qualimetrix Exists

In the projects Qualimetrix serves, most code is written by autonomous AI agents. An agent can produce
and ship a correct-looking change quickly, and it has no memory of why a boundary was drawn.
Left unchecked it will dissolve architectural boundaries, accumulate structural debt, and route
around the project's own rules — each step locally reasonable, the aggregate irreversible.

**Qualimetrix makes architectural intent machine-checkable, so that agent-produced change stays
inside it.**

Crucially, it lets CI refuse a change independently of anyone's claim that it was checked. Everything
else the tool does is in service of that, or is evidence for it.

---

## 2. What Qualimetrix Is

Qualimetrix is a local-first structural quality control system for PHP projects developed by humans
and AI agents. It works with two kinds of statement, and the difference between them matters
everywhere else in this document:

- **Declared contracts** — architectural layers and their permitted relations, forbidden
  dependencies, explicit policy. Binary and checkable: a breach is a fact, not an opinion.
  Qualimetrix *enforces* them.
- **Structural measurements** — complexity, coupling, cohesion, size, design, type coverage,
  duplication, code smells, security patterns. Graded and threshold-dependent. Qualimetrix treats
  them as *evidence*: about where to look first, and about which direction the codebase is moving.

Either kind may be compared against the **accepted state** — the reviewable record of what this
project previously agreed to tolerate.

**Core promise:** declare what must hold, and every change — human or agent — is measured against it,
explained in the same terms to a person, a machine, and a pipeline, and stopped before it lands if it
breaks what you declared or makes the codebase measurably worse.

---

## 3. The Decision Boundary

> **Qualimetrix controls observable code outcomes, not agent behaviour.** Declared contracts
> determine enforcement; metrics provide evidence; humans remain responsible for architectural
> intent.

The tool does not know what good architecture is. It knows what this project declared, what it can
measure, and how today compares to what was accepted. Consequences that bind product design:

- A low health score is a navigation signal, not a refactoring order.
- High structural centrality means centrality, not poor design.
- Remediation estimates are calibrated heuristics, not commitments.
- One isolated metric outside its threshold is weaker evidence than several agreeing signals.
- A quality gate enforces a declared policy or a measured regression against an accepted state —
  never a bare score, which would only invite optimising the score.
- The tool never claims a mechanically suggested change is architecturally correct.

---

## 4. Who It Is For

These are roles in one loop, not a ranking of importance. Each consumes the same analysis result
through a different surface.

**Architecture owner (human) — the primary beneficiary.** Decides which dependencies are permitted,
which boundaries must hold, what degradation is unacceptable, which exceptions are deliberate, and
what blocks a merge. Every other role works inside the intent this one expresses.

**AI agent — the primary producer of change, and the primary machine consumer of feedback.** Explores
an unfamiliar project, evaluates its own change before proposing it, understands *why* something is a
violation, corrects the code, and re-verifies without a human. Feedback must be stable enough to act
on and precise enough not to be acted on wrongly.

**CI — the independent check.** Assumes nothing was checked. Applies the same policy to the pushed
result, deterministically, from committed inputs only.

**Human developer / reviewer — the same loop by hand.** Writes code directly, and reviews what the
agent produced. Needs the same evidence in a form a person can read at review time, including what
the agent changed about the *policy* itself.

Secondary scenarios — PR review, onboarding into an unfamiliar codebase, consulting audit — reuse the
same loop and the same analysis result. They do not justify an independent product direction.

These are functions, not headcount: today one person holds all the human roles. That is a reason to
keep them distinct rather than merge them — declaring intent and reviewing what an agent did with it
need different evidence, whoever is doing both.

---

## 5. The Core Loop

```text
Declare -> Inspect -> Explain -> Correct -> Verify -> Enforce
```

| Stage       | Target outcome                                                                                    |
| ----------- | ------------------------------------------------------------------------------------------------- |
| **Declare** | Architectural intent and accepted limits expressed as checkable, reviewable policy                |
| **Inspect** | Structural state of the project and of the change, at project, namespace, class, and method scope |
| **Explain** | The broken contract or the contributing measurements, with thresholds and locations               |
| **Correct** | Enough grounding for an agent or a developer to fix the cause rather than the symptom             |
| **Verify**  | A comparison against the accepted state, distinguishing new, worsened, unchanged, and resolved    |
| **Enforce** | A deterministic verdict, reproducible from committed inputs, with machine-readable reasons        |

**Decide** is not a stage — it is the boundary that runs through all of them. An agent may apply an
existing declared policy on its own, investigate the evidence, and propose or verify a correction.
Deciding that a contested finding is acceptable, changing architectural intent, or accepting a weaker
state stays with the architecture owner. Qualimetrix supplies verifiable evidence for that decision
and never substitutes for it.

Two consequences that are easy to lose:

- Filtering findings to changed files is *not* Verify. Attributing a finding to a change scope
  answers "was this touched", not "did this get worse". Regression semantics are core product, not a
  reporting convenience.
- The health-score subsystem — aggregate scores, ranked hotspots, debt estimates, exploratory
  reports — is evidence serving Inspect and Explain: global orientation and first signals about where
  to look. It orients; the loop is what controls.

---

## 6. Design Principles

1. **A finding carries the authority of whoever set the limit.** Every finding has three independent
   dimensions, and all three are part of what it means:
   - **semantics** — a contract breach, or a structural measurement;
   - **limit provenance** — the limit was declared by the project, or supplied by the tool's
     calibration;
   - **comparison status** — against the accepted state: new, worsened, unchanged, resolved, or not
     comparable.

   The dimensions are independent: a complexity value past a threshold the project itself set on that
   class is a measurement *and* a declared limit *and* possibly a regression. Each one carries its own
   consequence — a declared limit is not "usually" true and no preset may dial it down; a calibrated
   default is expected to be adapted to the project; an accepted state may only be re-accepted
   deliberately. A comparison also has to be valid to mean anything: same metric, same scope, same
   semantics, and evidence that is missing or incomparable is reported as such rather than quietly
   becoming an improvement. A surface that flattens all of this into one undifferentiated "violation"
   leaves an agent no way to tell what it may argue with.

2. **Relaxing policy is a reviewable event, not a fix.** Widening an exclusion, raising a threshold,
   suppressing a finding, or re-accepting the current state is the one move that makes every check
   pass — and it is exactly the move an agent will reach for. Such changes must be distinguishable
   within a change, attributable, and visible at review and in CI as themselves, never as a green
   build. A tool that can be silenced by the thing it supervises supervises nothing.

3. **Silence is not proof.** An absence of findings means the declared policy found nothing to say —
   which is not the same as the code having been checked. Code outside every declared boundary is the
   one bypass that leaves nothing behind: no policy edit, no suppression, no accepted entry, nothing
   for a reviewer to see. So enforcement states what it covers. What the declared policy does not
   reach is itself reportable. Own code outside the boundaries is reported apart from third-party
   code — a project can never classify its dependencies, and burying the first in the second reports
   neither — and a project may make its own uncovered code fail the build.
4. **Enforcement is reproducible from committed inputs.** The state a verdict is based on — declared
   policy, accepted baselines, ratchet limits — lives in the repository and is reviewed like code.
   Local caches, home-directory history, and machine-specific state may make a human faster; they may
   never change a verdict. Historical trend data is a convenience for people reasoning about
   direction, not an input to enforcement.
5. **One analysis model, many projections.** Terminal output, machine-readable output, interactive
   reports, and CI annotations are projections of one analysis result. No surface invents its own
   scoring, severity, or identity for a finding. An agent and a human reviewing the same change must
   be looking at the same fact.
6. **For heuristic rules, a false positive costs more than a missed violation.** Under an autonomous
   agent the cost compounds: it will either mechanically "fix" noise, damaging correct code, or learn
   to route around the rule. So prefer a narrower rule that is always right to a broad one that is
   usually right, and treat the false-positive rate of a new rule as the first piece of work rather
   than a detail. Declared hard contracts and high-impact risk checks may warrant the opposite
   trade-off, but that reversal must be stated, not assumed. Repeated suppression of a rule is
   evidence to go and examine the rule; a structural exclusion the project can justify is not by
   itself proof of a defect.
7. **Defaults are calibrated, metrics are faithful.** Thresholds and formulas are validated against a
   corpus of real projects, and base metrics implement their original published algorithm, with every
   deviation documented. Changing a formula is a release event: the corpus is re-measured and the
   shift is explained. A human discounts an implausible number from experience; an agent has no such
   intuition, so the numbers have to be right.
8. **Distinguish "your code failed" from "the analysis failed".** A configuration error, an
   unparseable input, or incomplete coverage is a different outcome from a policy breach, and is
   signalled differently everywhere. Conflating them teaches an agent to fix code in response to a
   broken tool.
9. **Summary first, details on demand — in standard vocabulary.** The default answer fits one screen
   and states where the problems are; depth is available on request, down to the individual finding.
   Established metric names are used as-is, paired with plain-language meaning — not for newcomers'
   sake alone, but because a human reviewing an agent's work reads the same output under time
   pressure.
10. **Local-first.** Full value from a project-local run, with no service, account, or network.

11. **Fast enough to sit in the loop.** Analysis runs on every change an agent makes, not only
    nightly. A check slower than the rate of change stops being a check and becomes a report.

---

## 7. What Qualimetrix Is Not

- **Not a design tool.** It enforces boundaries you declare; it does not propose them.
- **Not a decision maker.** It supplies evidence about structure. It cannot know business
  criticality, defect history, ownership, or what you plan to build next quarter.
- **Not a type checker.** Type safety belongs to PHPStan and Psalm; type coverage is measured as a
  metric, not inferred.
- **Not a code formatter.** Style belongs to PHP-CS-Fixer and PHPCS.
- **Not an auto-fixer.** Mechanical transformation belongs to Rector, and architectural correction to
  a human or an agent holding project context.
- **Not a security scanner.** Pattern-based checks only; taint analysis and full SAST belong to
  dedicated tools.
- **Not a test-coverage tool.** Coverage may be consumed as an input; it is not produced.
- **Not a runtime profiler.** The internal profiler measures Qualimetrix, never the analysed program.
- **Not a hosted service, management dashboard, or account-based platform.** Local CLI, project
  files, and CI are the product; nothing essential may require a hosted component. A self-contained
  local interactive report is in scope — the server, the account, and the organisation-wide rollup
  are not.

---

## 8. Horizons

A horizon is not a queue of capabilities. It says what counts as validation, what may be sacrificed to
get there, and what would end it. Do not read horizons as phases and defer a feature because it
"belongs to a later one": what defers an item is its *justification*, not how polished it is.

**Now — dogfooding.**

- *Validation:* it works on the author's own projects, personal and professional, under real
  agent-driven work. Nothing else counts as proof.
- *May be sacrificed:* backward compatibility, migration paths, install friction, breadth of platform
  support, documentation written for strangers.
- *Ends when:* the loop holds without the author's hand on it.

**The product horizon is a standard, not a later phase.** Helping a developer who works with AI agents
keep control of their architecture — formal architectural intent, compact and stable agent feedback,
new and worsened conditions told apart from accepted ones, a policy violation told apart from a defect
in the analysis, gradual adoption on legacy code, one model across every surface — is what the work is
already held to, because the author *is* that developer. What gets deferred is not "product-grade
work"; it is work whose only justification is a user who does not exist yet.

**Later — the market horizon.** Replacing the scattered set of PHP tools (PHPMD, PHPMetrics, PHPCPD,
Deptrac) with one local, explainable, automation-ready control loop is a **maturity criterion, never a
justification for an item**. Qualimetrix replaces them where doing so strengthens the core loop, and
nowhere else. Distribution, install friction, and the supported PHP floor are real work that is
deliberately not driving the roadmap yet.

---

## 9. What Is Not Built Yet

Everything above is written as the target state. Three parts of it are not built, and this section
exists so that none of them reads as shipped. How far each has progressed belongs to
[PRODUCT_ROADMAP.md](PRODUCT_ROADMAP.md), not here.

- **A first-class delta model** — new, worsened, unchanged, resolved and incomparable exposed
  consistently through the one shared analysis result, instead of reconstructed from what survived
  filtering. Accepted-state ceilings already work on comparable finding groups; this is the general
  form of them.
- **Enforcement-input changes surfaced separately** — policy, suppressions, and accepted state told
  apart from code findings, so that repository review rules can require separate approval for them.
- **Historical trend data**, human-facing only: it helps a person see where the project is heading
  and is never an input to a verdict (§6.4).

---

## 10. Key Questions

| Question                                                   | Who asks it                      | How Qualimetrix answers                                                                                    |
| ---------------------------------------------------------- | -------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| "Did this change break the architecture we agreed on?"     | Architecture owner, CI, reviewer | Violations of declared layers and permitted relations, attributed to the edge that broke the rule          |
| "Did this change make the codebase measurably worse?"      | CI, agent, reviewer              | Comparison against the accepted state, separating new, worsened, and unchanged                             |
| "Why is this a violation, and what exactly caused it?"     | Agent, developer                 | The broken contract, or the contributing measurements with thresholds and locations                        |
| "Where are the problems, and what should I look at first?" | Developer, architecture owner    | Health scores per dimension plus ranked hotspots, as orientation                                           |
| "How healthy is this codebase I just met?"                 | Agent, developer, auditor        | One concise structural overview, drillable to the finding                                                  |
| "How do we stop the bleeding without fixing everything?"   | Team adopting on legacy code     | An accepted starting state, explicit reviewable suppression, enforcement on new debt only                  |
| "What did the agent change about the rules themselves?"    | Architecture owner, reviewer     | *(target, §9)* Enforcement-input changes identified separately from code findings                          |
| "May this land?"                                           | CI                               | A deterministic verdict with machine-readable reasons, distinguishing policy failure from analysis failure |

---

## 11. Success Criteria

The principles in §6 say what the product must do. These say how we would know it worked — they are
the claims worth measuring, not restating.

- **Nothing lands silently.** A change that breaks a declared boundary is stopped, whoever produced
  it, and the only way past it is a policy change a reviewer can see.
- **An agent closes the loop alone.** From a finding to a change that satisfies the stated contract,
  and back to a verified result — no human, no re-deriving the analysis. Correctness beyond the
  observed contract is outside what the product claims.
- **The machine contract fits the context an agent actually has.** Compact, versioned, and bounded.
- **A rule that is widely excluded is treated as a defect.** False positives and suppressions are
  tracked per rule, so the question is answered with data rather than impressions.
- **The tool is useful on an unconfigured project, on the first run, at the scale it is meant for.**

---

## 12. Roadmap Decision Test

For a proposed feature, answer:

1. Which stage of the core loop does it strengthen?
2. Whose control over architectural outcomes improves — and does it stay on the correct side of the
   decision boundary (§3)?
3. Can it be silenced by the thing it supervises? If so, what makes that silencing visible?
4. Is it based on evidence Qualimetrix can observe reliably, at an acceptable false-positive cost?
5. Does it preserve one analysis model and reproducibility from committed inputs?
6. Does it cross a declared boundary (§7) or duplicate a complementary tool?
7. Does its justification rest on a horizon (§8) that is not current — a user who does not exist yet,
   or a market position?
8. What more central work does it delay?

An item that cannot answer 1–4 does not enter the roadmap. An item that conflicts with 5 or 6 requires
an explicit revision of this document, not an implementation-level exception. An item justified only
by a horizon that is not current (7) is recorded as deferred, not scheduled. An item that delays work
closer to containment, or to Verify and Enforce, needs an explicit priority argument (8) —
usefulness on its own is not one.

If a feature you believe in has no place here, amending this document may be the right outcome — but
say so in the same change, rather than shipping past it.

---

## 13. Maintaining This Document

Revisit at each minor release, and whenever a shipped feature contradicts it. A feature that
contradicts the vision is a fork in the road, not a footnote: either the feature changes or this
document does, in the same change.

This document owns the product's identity, boundaries, and decision model;
[PRODUCT_ROADMAP.md](PRODUCT_ROADMAP.md) owns sequencing, effort, and status, and derives from here.
One claim, one home — if the two disagree, this document is the one that decides.

If a role, question, or boundary stated here cannot be traced to something the tool does or is
committed to doing, that is a defect in this document, not an aspiration.
