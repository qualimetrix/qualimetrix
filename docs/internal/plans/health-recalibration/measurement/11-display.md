# What the report says a score is made of

## The brief was half wrong, and the live half was the one it missed

This stage was scoped around the `ideal` strings in the dimension catalog, which
had drifted from the formulas. Measured, **those strings were never rendered** —
neither PHP nor the report's JavaScript reads them; they shipped inside the HTML
payload and nothing consumed them.

The target a user actually sees comes from a different catalog, through the
health summary's decomposition, and it had drifted further, because a lookup
falls back to the base metric key: `maintainability.mi.min` advertised "above
65" while the project formula stops penalising at 5.

So the dead strings were deleted and the checks were pointed at the live ones.
A stage scoped from a plan can be scoped at the wrong object; the measurement is
what said so.

## Two defects the level-blind catalog was hiding

The catalog held one input list per dimension for all three levels. Measured per
level, project coupling reads `distance.avg`, `cbo.avg`, `cbo.p95`, `cbo.max`;
namespace reads `distance`, `ce-packages.avg`, `ce.avg`, `ce.max`, `ce`; class
reads `ce-packages`, `ce`. Two of the three inputs printed under a project score
were not inputs to it.

Typing was worse than wrong. `design.type-coverage.all` **does not exist above
class level**, so the namespace and project tooltip named a key with no value
and displayed nothing at all.

## A guard that was disarmed and stayed green

Splitting the catalog moved the `health.*` keys into a new file. A JavaScript
check reads the family prefixes out of that source by pattern, so `health`
dropped out of its list, and every `health.*` literal in the report's JavaScript
stopped being checked. **Its test stayed green by checking less.**

This was caught by its own author, repointed, and proved to bite again by
planting a `health.bogus` key. It is the same shape as every other finding in
this work: not a broken check, but a check whose subject quietly shrank.

## One advertised target that is honest and reads as absurd

The maintainability minimum now advertises "target: above 5", which is exactly
what the formula does. Five of the seventeen corpus projects report a project
`mi.min` of 0, so the term measures a single worst method rather than the
subject. Removing the term is a model decision and was not taken here; the
display now tells the truth about a formula that deserves a second look.
