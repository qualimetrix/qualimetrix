# 0063. One Declaration Answers About a Rule's Options

**Date:** 2026-09-15
**Status:** Accepted

## Context

Two sides of the product answer the question "what options does this rule
take?". The refusal raised for an unknown key names the allowed set, and
`bin/qmx rules` advertises what a reader may write. They answered it from
different places: the refusal read the options class declarations, while the
listing enumerated CLI aliases.

The two answers therefore differed by construction, and the difference was
measured rather than assumed. Across the 54 registered producers there are 132
substantive options; 78 carry a CLI alias and 54 did not appear in the listing
at all — 32 of them the `threshold` shorthand. Two producers advertised no
options while accepting five and two. `check --help` points readers at the
listing for "all available rules and their options", and the website's rule
pages were written against it. Thirteen rule sections had omitted real options
by the time that was noticed, and a separate repair brought the pages back in
line with the product. The pages are correct today; what makes this worth an ADR
is that the oracle they were written against is still the incomplete one, so
nothing stops them drifting again.

A second, smaller divergence had the same root. An alias target is authored by
hand in a CLI attribute, so 34 of the 80 were spelled in snake or camel while
every refusal about those keys printed canonical kebab.

## Decision

**The question is a subject, and it has one owner.**
`Analysis\Finding\Contract\Rule\RuleOptionSurface` answers where a rule option
key may be written and which declaration answers there: the rule's own options
class at depth one, and the class named by `levelOptionsClasses()` inside a
level slot. Both the refusal walk and the `rules` listing address through it.

**It states facts and shapes nothing.** `writableAt()` is what may legally
stand at a depth, which is exactly what a refusal prints. A reader that
presents some of those facts elsewhere — the listing gives each level slot a
line of its own and moves the framework keys to a footer — performs that
subtraction itself. An earlier revision of this type carried one method per
output shape of each of its two callers, which named it after its callers
rather than after a subject, and ADR 0016 rules that out.

**Judging a key does not move.** `RuleOptionKeySet` keeps `knows()` and
`shapeOf()`: whether a key is recognised is a fact about one class's
declaration, not about the two-depth shape.

**The keys the framework consumes have one enumeration.**
`FrameworkOptionKeys` holds `suppress-paths`, `suppress-namespaces` and
`suppress-namespace-channels`. This is a relocation, not a new owner:
`Exclusion\ConfiguredSuppression` already declared itself the enumeration every
consumer shares, and a second owner beside it would have been this decision's own
defect one layer down. That promise is now kept from a namespace the answering
sides can also reach, and a guard fails loudly if the authored spellings there
drift from it. `ConfiguredSuppression` remains the only reader of a producer's
raw options.

**Six copies, and how each was found, because the count moved three times.** A
sweep of `src/` for the literal spellings found four; a fifth was in `scripts/`
and announced itself — `promise-effect`'s cross-check reads the list off the
product by reflection and failed loudly, as it was built to. The sixth was found
by review: `RuleInputValidator` held both spellings of one key in a constant and
subscripted raw options with the loop variable, a shape the only-reader guard
could not see, because it recognises a key written *at* the subscript. That guard
now also flags a file holding two suppression spellings side by side, whatever it
does with them, and the validator reads its value through `ConfiguredSuppression`
like every other consumer.

**The listing prints names, not reach.** What an option covers — for
`threshold` especially — is a separate question with its own unfinished round;
a listing that described scope would have to be rewritten by it.

**`enabled` is printed per rule rather than in the footer.** It is nearly
universal, and the exception is why: `architecture.unassigned-class` does not
accept it — its switch is `mode` — so a footer claiming every rule takes
`enabled` would contradict that rule's own refusal, which is the defect class
this decision closes, one line lower.

## Consequences

`bin/qmx rules` grew by one line per rule plus one per level slot, and 34 alias
annotations changed spelling. The listing is a surface the finding gate
compares: the structural growth is a declared delta, and the spelling change is
declared as `inputs.tsv` rows, because a spelling of a published name is what
that map is for.

The regression guard is an agreement test over the live container rather than a
committed table of expected options — a committed table greens as the product
grows, which is how the gap reached a release. It compares the set the listing
advertises, reassembled from its three places, against the set the refusal
admits, for every producer and every slot. Reassembly rather than subtraction
is deliberate: comparing only the options line would let a dropped level slot
pass as a narrower set legitimately printed elsewhere. It reads the footer back
from the rendered output rather than from `FrameworkOptionKeys`, because a
reassembly built from the owner agrees with itself whatever the command
printed — measured, on a planted footer naming one key of three.

Both sides now read one declaration, so the comparison of sets is close to
tautological. What the test still catches is wiring: a forgotten level slot, a
classless producer the renderer skips, a footer out of step with its owner, a
rule whose `enabled` is answered-by-the-class rather than accepted.
