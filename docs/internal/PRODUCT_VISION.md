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
and AI agents. It works with two kinds of statement, and the difference between them is load-bearing:

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

These are functions, not headcount. On the current horizon every human role is the same person,
wearing a different hat at a different moment — which is an argument for keeping the roles distinct
in the product, not for collapsing them: the moment of declaring intent and the moment of reviewing
what an agent did with it need different evidence even when the same person lives both.

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
  to look. It is not an independent product contour. It orients; the loop is what controls.

---

## 6. Design Principles

1. **A finding carries the authority of whoever set the limit.** Every finding has three independent
   dimensions, and all three are part of what it means:
   - **semantics** — a contract breach, or a structural measurement;
   - **limit provenance** — the limit was declared by the project, or supplied by the tool's
     calibration;
   - **comparison status** — against the accepted state: new, worsened, unchanged, resolved, or not
     comparable.

   They are independent, not a single ladder: a complexity measurement past a threshold the project
   itself set on that class is a measurement *and* a declared limit *and* possibly a regression. Each
   dimension carries its own consequence — a declared limit is not "usually" true and no preset may
   dial it down; a calibrated default is expected to be adapted to the project; an accepted state may
   only be re-accepted deliberately. A surface that flattens all of this into one undifferentiated
   "violation" leaves an agent no way to tell what it may argue with.

2. **Relaxing policy is a reviewable event, not a fix.** Widening an exclusion, raising a threshold,
   suppressing a finding, or re-accepting the current state is the one move that makes every check
   pass — and it is exactly the move an agent will reach for. Such changes must be distinguishable
   within a change, attributable, and visible at review and in CI as themselves, never as a green
   build. A tool that can be silenced by the thing it supervises supervises nothing.

3. **Silence is not proof.** An absence of findings means the declared policy found nothing to say —
   which is not the same as the code having been checked. Code outside every declared boundary is the
   one bypass that leaves no artefact at all: no policy edit, no suppression, no accepted entry,
   nothing for a reviewer to see. So enforcement carries an explicit coverage posture: what the
   declared policy does *not* reach is itself reportable, first-party gaps are distinguished from
   third-party ones (a project can never classify its dependencies, and drowning the former in the
   latter is the same as reporting neither), and a project may make its own uncovered code blocking.
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
8. **Compare like with like.** A regression verdict compares compatible observations — same metric,
   scope, threshold, and contract semantics. Missing, incomparable, or partial evidence is reported
   as such and never silently becomes an improvement or a regression.
9. **Distinguish "your code failed" from "the analysis failed".** A configuration error, an
   unparseable input, or incomplete coverage is a different outcome from a policy breach, and is
   signalled differently everywhere. Conflating them teaches an agent to fix code in response to a
   broken tool.
10. **Summary first, details on demand — in standard vocabulary.** The default answer fits one screen
   and states where the problems are; depth is available on request, down to the individual finding.
   Established metric names are used as-is, paired with plain-language meaning — not for newcomers'
   sake alone, but because a human reviewing an agent's work reads the same output under time
   pressure.
11. **Local-first, and fast enough to sit in the loop.** Full value from a project-local run with no
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
- **Not a hosted service, management dashboard, or account-based platform.** Local CLI, project
  files, and CI are the product; nothing essential may require a hosted component. Self-contained
  local interactive reports are inside the shape — it is the server, the account and the
  organisation-wide rollup that are not.

---

## 8. Horizons

A horizon is not a queue of capabilities. It says what counts as validation, what may be sacrificed to
get there, and what would end it. Reading horizons as phases — "that feature belongs to a later one"
— is a mistake: the deferral rule below applies to an item's *justification*, never to how
product-grade it is.

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

## 9. Established Direction vs Target Capability

A vision may describe unbuilt capability, but it must not let a target read as shipped. What lies
outside the product entirely is §7; how far each of these has progressed, and in what order, is
[PRODUCT_ROADMAP.md](PRODUCT_ROADMAP.md). This section states only whether something is inside the
product's shape and whether it is settled.

**Established direction** — enforcing declared architectural boundaries; structural metrics and rule
findings; explainable scoring, continuously validated against the benchmark corpus, with
summary-to-detail investigation; gradual adoption on a legacy codebase without fixing it first; and
accepted-state ceilings on comparable finding groups, where a new group stays visible, an accepted one
is suppressed, and growth past what was accepted becomes a blocking breach.

**Target capability** — a first-class delta model: new, worsened, unchanged, resolved and
incomparable exposed consistently through the one shared analysis result, rather than reconstructed
from what survived filtering. Also target: surfacing changes to the enforcement inputs themselves —
policy, suppressions, accepted state — separately from code findings, so that repository review policy
can require independent approval for them.

Historical trend data is a target too, but human-facing only: it helps a person see where the project
is heading and is never an input to a verdict (§6.4).

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
| "What did the agent change about the rules themselves?"    | Architecture owner, reviewer     | *(target, §9)* Enforcement-input changes identified separately from code findings                          |
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

- An agent can go from a finding to a change that satisfies the stated contract, and re-verify it,
  without a human and without re-deriving the analysis. Correctness beyond the observed contract is
  outside what the product claims.
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
