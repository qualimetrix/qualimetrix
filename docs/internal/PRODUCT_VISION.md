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

It makes the project's declared boundaries explicit and enforceable, detects measurable structural
degradation, explains a violation to whoever — or whatever — has to fix it, and lets CI refuse the
change independently of anyone's claim that it was checked.

Everything else the tool does is in service of that, or is evidence for it.

---

## 2. What Qualimetrix Is

Qualimetrix is a local-first structural quality control system for PHP projects developed by humans
and AI agents. It works with two kinds of statement, and the difference between them is load-bearing:

- **Declared contracts** — architectural layers and their permitted relations, forbidden
  dependencies, explicit policy. Binary and checkable: a breach is a fact, not an opinion.
  Qualimetrix *enforces* them.
- **Structural measurements** — complexity, coupling, cohesion, size, design, type coverage,
  duplication, code smells, security patterns. Graded and threshold-dependent. Qualimetrix treats
  them as *evidence*: about where to look first, and about which direction the codebase is moving.

A third thing binds the two together: the **accepted state** — the record of what this project has
already agreed to live with, against which a later run is compared.

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
what blocks a merge. Owns the policy and every contested case. Every other role operates inside the
intent this role expresses.

**AI agent — the primary producer of change, and the primary machine consumer of feedback.** Explores
an unfamiliar project, evaluates its own change before proposing it, receives a compact machine
result, understands *why* something is a violation, corrects the code, and re-verifies without a
human in the loop. Feedback must be stable enough to act on and precise enough not to be acted on
wrongly.

**CI — the independent enforcement contour.** Assumes nothing was checked. Applies the same policy to
the pushed result, deterministically, from committed inputs only.

**Human developer / reviewer — the same loop by hand.** Writes code directly, and reviews what the
agent produced. Needs the identical evidence in a form a person can read at review time, including
what the agent changed about the *policy* itself.

Secondary scenarios — PR review, onboarding into an unfamiliar codebase, consulting audit — reuse the
same loop and the same analysis result. They do not justify an independent product direction.

**Not for:** anyone wanting a hosted dashboard with management charts; anyone looking for SAST;
projects wanting automated fixes; non-PHP codebases.

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

**Decide** is not a stage — it is the boundary that runs through all of them. Whether a finding is a
real problem in this context is decided by a human, or by an agent that holds the project context;
Qualimetrix supplies verifiable evidence for that decision and never substitutes for it.

Two consequences that are easy to lose:

- Filtering findings to changed files is *not* Verify. Attributing a finding to a change scope
  answers "was this touched", not "did this get worse". Regression semantics are core product, not a
  reporting convenience.
- The health-score subsystem — aggregate scores, ranked hotspots, debt estimates, exploratory
  reports — is evidence serving Inspect and Explain: global orientation and first signals about where
  to look. It is not an independent product contour. It orients; the loop is what controls.

---

## 6. Design Principles

1. **A finding carries the authority of whoever set the limit.** Every finding states which of three
   things it is: a breach of a limit *the project declared*, a measurement past a default *the tool
   calibrated*, or a regression against a state *the project accepted*. The three share no tuning
   story and no default response — a declared boundary is not "usually" true and no preset may dial
   it down; a calibrated default is expected to be adapted to the project; an accepted state may only
   be re-accepted deliberately. Note that this axis is not "architecture rules versus metrics": a
   threshold the project set on a specific class is a declared limit, while a cycle the tool found is
   a measurement. A surface that renders all three as one undifferentiated "violation" has erased the
   distinction the product is built on, and leaves an agent no way to tell what it may argue with.

2. **Relaxing policy is a reviewable event, not a fix.** Widening an exclusion, raising a threshold,
   suppressing a finding, or re-accepting the current state is the one move that makes every check
   pass — and it is exactly the move an agent will reach for. Such changes must be separable from
   code changes, attributable, and visible at review and in CI as themselves, never as a green build.
   A tool that can be silenced by the thing it supervises supervises nothing.

3. **Enforcement is reproducible from committed inputs.** The state a verdict is based on — declared
   policy, accepted baselines, ratchet limits — lives in the repository and is reviewed like code.
   Local caches, home-directory history, and machine-specific state may make a human faster; they may
   never change a verdict. Historical trend data is a convenience for people reasoning about
   direction, not an input to enforcement.

4. **One analysis model, many projections.** Terminal output, machine-readable output, interactive
   reports, and CI annotations are projections of one analysis result. No surface invents its own
   scoring, severity, or identity for a finding. An agent and a human reviewing the same change must
   be looking at the same fact.

5. **A false positive costs more than a missed violation.** A rule users must switch off, or exclude
   a directory to live with, is a defect in the rule — not a configuration matter. Under an
   autonomous agent the cost compounds: it will either mechanically "fix" noise, damaging correct
   code, or learn to route around the rule. Prefer a narrower rule that is always right to a broad
   one that is usually right; the false-positive rate of a new rule is the first piece of work, not a
   detail.

6. **Defaults are calibrated, metrics are faithful.** Thresholds and formulas are validated against a
   corpus of real projects, and base metrics implement their original published algorithm, with every
   deviation documented. Changing a formula is a release event: the corpus is re-measured and the
   shift is explained. A human discounts an implausible number from experience; an agent has no such
   intuition, so the numbers have to be right.

7. **Compare like with like.** A regression verdict compares compatible observations — same metric,
   scope, threshold, and contract semantics. Missing, incomparable, or partial evidence is reported
   as such and never silently becomes an improvement or a regression.

8. **Distinguish "your code failed" from "the analysis failed".** A configuration error, an
   unparseable input, or incomplete coverage is a different outcome from a policy breach, and is
   signalled differently everywhere. Conflating them teaches an agent to fix code in response to a
   broken tool.

9. **Summary first, details on demand — in standard vocabulary.** The default answer fits one screen
   and states where the problems are; depth is available on request, down to the individual finding.
   Established metric names are used as-is, paired with plain-language meaning — not for newcomers'
   sake alone, but because a human reviewing an agent's work reads the same output under time
   pressure.

10. **Local-first, and fast enough to sit in the loop.** Full value from a project-local run with no
    service, account, or network. Analysis is fast enough to run on every change an agent makes, not
    only nightly — an enforcement contour slower than the change rate stops being enforcement.

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
- **Not a server, service, dashboard, or account.** Local CLI, project files, and CI are the product.
  Nothing essential may require a hosted component.

---

## 8. Horizons

The three goals below are sequential horizons, not competing priorities. Confusing them is how a
roadmap starts copying competitors' feature lists.

**Now — dogfooding.** Qualimetrix is built first for its author's own projects, personal and
professional, and is validated by controlling real agent-driven work in them. This licenses the
current mode of development: optimise for real agent workflows, change contracts freely, keep no
compatibility shims for hypothetical users, choose depth and correctness over distribution, and prove
an idea on a real codebase before stabilising it.

**Next — the product horizon.** Help developers working with AI agents keep control of their
architecture: express architectural intent formally, give agents compact and stable feedback,
distinguish new and worsened conditions from accepted ones, separate a policy violation from a defect
in the analysis, adopt gradually on legacy codebases without fixing everything first, and apply one
model across human, machine, and CI surfaces. This is the external value, and it is what stabilisation
is *for*.

**Later — the market horizon.** Replacing the scattered set of PHP tools (PHPMD, PHPMetrics, PHPCPD,
Deptrac) with one local, explainable, automation-ready control loop is a **maturity criterion, not a
reason to exist**. Qualimetrix replaces them where doing so strengthens the core loop, and nowhere
else. Adoption work — distribution, install friction, the supported PHP floor — is deliberately not
driving the roadmap until the product horizon is stable.

---

## 9. Established Direction vs Target Capability

A vision may describe unbuilt capability, but it must not let a target read as shipped. What lies
outside the product entirely is §7; how far each of these has progressed, and in what order, is
[PRODUCT_ROADMAP.md](PRODUCT_ROADMAP.md). This section states only whether something is inside the
product's shape and whether it is settled.

**Established direction** — enforcing declared architectural boundaries; structural metrics and rule
findings; explainable scoring with summary-to-detail investigation (calibration continuing);
gradual adoption on a legacy codebase without fixing it first.

**Target capability** — regression against an accepted state, as the mechanism the Verify and Enforce
stages ultimately rest on. Historical trend data is a target too, but human-facing only: it helps a
person see where the project is heading and is never an input to a verdict (§6.3).

**Outside the shape while local-first holds** — cross-project or organisation-wide aggregation.

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
| "What did the agent change about the rules themselves?"    | Architecture owner, reviewer     | Policy, suppression, and accepted-state changes surfaced as themselves                                     |
| "May this land?"                                           | CI                               | A deterministic verdict with machine-readable reasons, distinguishing policy failure from analysis failure |

---

## 11. Success Criteria

**Containment** — the criterion that matters most.

- A change that breaks a declared boundary cannot land silently, whoever or whatever produced it.
- Relaxing a policy is visible as a policy change at review time; it never appears merely as a green
  build.
- A CI verdict is reproducible from committed inputs alone.

**Trust.**

- Every score, ranking, and verdict is explainable from documented inputs.
- Incomplete or incomparable evidence is reported explicitly, never silently rounded to a conclusion.
- False-positive and suppression patterns are tracked per rule; a rule that is widely excluded is
  treated as a defect.

**Feedback fitness.**

- An agent can go from a violation to a correct fix and a re-verification without a human, and
  without re-deriving the analysis.
- The machine contract is compact, versioned, and bounded — it fits the context an agent actually has.
- A human reviewing that agent's work reaches the same understanding from the same result.

**Operational fitness.**

- Fast enough to run on every change, on projects of the intended scale.
- Useful on an unconfigured project on the first run.

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
7. Which horizon (§8) does it belong to — and is that horizon current?
8. What more central work does it delay?

An item that cannot answer 1–4 does not enter the roadmap. An item that conflicts with 5 or 6 requires
an explicit revision of this document, not an implementation-level exception.

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
