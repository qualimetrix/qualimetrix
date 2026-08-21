# 0027. Weight of Class Measures Accessors, Not Visibility

**Date:** 2026-08-21
**Status:** Accepted
**Related:** [ADR 0013](0013-threshold-override-validators.md) (threshold override validators), [ADR 0017](0017-baseline-ceiling.md) (baseline ceiling and channel declarations)

## Context

`design.data-class` was inverted on its reported axis, and had been since the
rule was written. Three properties combined into a rule that could not report
what its name and its documentation promised.

**The `woc` metric measured visibility, not accessors.** It was computed as
`public methods / all methods * 100` and labelled "PDepend WOC metric". Method
names and bodies never entered the calculation, so any class whose methods are
all public scored 100 regardless of what those methods do. Weight of Class in
Lanza & Marinescu is the opposite quantity: *functional* (non-accessor) public
methods over all public members, and a Data Class is characterised by a **low**
value, below roughly 1/3.

**The rule then gated on a high value of it.** Emission required
`woc >= wocThreshold` at a default of 80, i.e. "at least four fifths of the
methods are public", combined with low WMC. That describes a small class with a
plain public API — a visitor, a routing table, a lifecycle holder — not a class
that hoards data.

**Two exclusions removed what remained of the true positives.** `isDataClass`
(only accessors and a constructor) was excluded as an "intentional DTO", and
`minMethods` counted `methodCount`, which excludes accessors. A textbook data
class fails both: it is `isDataClass`, and its non-accessor method count is
zero. Counting size in methods at all has the same defect in a second form: a
struct of public fields declares no methods and is the purest Data Class
there is. The rule's own documented "Flagged" example did not reproduce, and no
test caught that, because every unit case fed the rule a hand-written metric
bag. A bag can assert what the rule does with a number; it cannot assert what
the number means.

The observable consequence was six `@qmx-ignore design.data-class` controls in
this repository, each written against a delegating visitor or a run-scoped
state holder, each recording the same false positive in different words. Six
identical suppressions are evidence about the rule, not about six classes.

## Decision

**`woc` is the Lanza & Marinescu ratio.** Numerator: public methods that are
neither accessors nor the constructor. Denominator: every other public member —
public methods, accessors included, plus public properties. Accessor-ness is
decided by name in `MethodCountVisitor` (`get*`, `is*`, `has*`, `set*`, and the
bare prefixes), as it already was for `methodCount`.

**The constructor is outside the ratio, on both sides.** Lanza & Marinescu
define a functional method as neither accessor nor constructor. Leaving it in
the numerator floors the ratio at `1/N` for exactly the small classes the rule
targets: a record with a constructor and four accessors scored 20 instead of 0,
and a two-accessor holder landed on the threshold itself rather than deep
inside the range.

**Only members declared by the class itself are counted.** Inherited and
trait-imported members are invisible to a per-declaration visitor, so a
subclass of an accessor base scores as though the base did not exist. Widening
this needs the global graph; it is named as a limit in the metric docblock and
on both rule pages instead of being fixed here.

**A method body is never read.** WOC describes the shape of a public interface,
not the weight of the work behind it. A public method that only forwards to a
collaborator is functional. This is the property that produced the six
suppressions, so it is stated in the rule docblock, in both component READMEs,
and on both language versions of the rule page, rather than left to be
rediscovered.

**A class with no public members scores 100.** The ratio is undefined there.
Defining the degenerate case as "fully functional" keeps `DataClassRule` a
plain two-threshold gate instead of requiring a third input, and is safe in the
direction that matters: a class that exposes nothing is never a Data Class.

**The rule gates on `woc <= wocThreshold`, default 33.** The channel is
declared `WorseDirection::Lower`, so a baseline ratchets the reported axis in
the direction that is actually worse. Both `@qmx-threshold` axes are now upper
bounds; `IndependentAxisValidator` already permits a warning value below the
error value, so the annotation contract is unchanged.

**The bound is inclusive, so the canonical one third is a finding.** Lanza &
Marinescu write the strategy as `WOC < 1/3`; every threshold in this tool is
inclusive, and `woc` is an integer percentage where `1/3` rounds to 33 anyway.
Consistency with the rest of the tool wins over a distinction that a rounded
integer cannot carry. A test pins the exact-threshold case, because a class of
three public members is common enough that the boundary decides real
findings.

**The `isDataClass` exclusion is removed** — the shape it names is the target,
not an exemption — **and the size floor counts members, not methods.** The
option is `minMembers` (`min_members`, `--data-class-min-members`) and sums
declared methods and declared properties, so a struct of public fields clears
it. Intent is still respected through `excludeReadonly` and
`excludePromotedOnly`: a deliberate record says so in the code.
`methodCountTotal` moved to `MetricName::STRUCTURE_METHOD_COUNT_TOTAL` so
Design can require it without importing a Size constant. The `isDataClass`
metric itself stays: `WmcRule` consumes it, and its meaning did not change.

## Consequences

The rule now reports the opposite population. All six in-repository
suppressions were removed and none of those six classes reproduces. The
replacement population is of two kinds: mutable metric bags with public fields
or accessor pairs, and classes whose methods merely *read* as accessors —
`is*`/`has*`/`get*` names that compute rather than expose. The second kind is
the residual cost of deciding accessor-ness by name, which is the same
heuristic the original metric uses; it is documented rather than worked around.
All of the old channel's ceilings in `qmx-baseline.json` were rewritten: their
stored magnitudes were WOC values under the old definition and are not
comparable to the new ones.

For consumers this is breaking three times over: the `woc` metric value changes
for every class in any report that carries it; `woc_threshold` reverses
meaning, so a configured value must be rewritten rather than kept (a kept
`woc_threshold: 80` is now nearly unconditional rather than strict); and
`min_methods` becomes `min_members`. An existing baseline must be regenerated —
its stored magnitudes were recorded under `WorseDirection::Higher` and would be
read under `Lower`, turning previously accepted findings into breaches.

The regression is pinned by `DataClassDetectionTest`, which drives the rule
from PHP source: accessors only, public fields, a struct without methods,
delegating methods, behaviour methods, an accessor trait, a class with no
public surface, inheritance, the constructor's contribution, the exact
threshold, and the WMC gate. Every one of its original cases fails against the
previous implementation.

Traits stay in the population. A trait carrying fields and their accessors is a
Data Class spread across a reuse unit, and unlike an interface it ships the
state itself; the exclusion list keeps naming only interfaces, abstract
classes, exceptions and classes without properties. New rules whose axis is a ratio should be tested from
source for the same reason: the unit suite here was complete and green
throughout the defect's life.
