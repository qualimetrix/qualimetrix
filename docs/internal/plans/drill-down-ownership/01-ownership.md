# DrillDownBinding: one subject, one owner

Tail 1 of PR #133. `src/Infrastructure/Console/DrillDownBinding.php` and
`src/Reporting/DrillDown/FindingFilter.php` are one subject — what a
`--namespace` or `--class` value means — held by two owners.

## Decision

Move `DrillDownBinding` to `Qualimetrix\Reporting\DrillDown`. Keep
`ResultPresenter` in `Infrastructure\Console`, keep its `new DrillDownBinding()`
inline, keep `ConfigurationRefusal::aboutCommandLineInput()` where it is.

Why it is not an adapter, so AGENTS.md's adapter-exclusion rule does not place
it in Infrastructure: it imports nothing from Symfony, takes
`MetricRepositoryInterface` and `NamespaceTree` as arguments rather than
collaborators, and its own docblock states the invariant that binds it to
`FindingFilter` — "The comparison must stay the one FindingFilter makes, or a
value could be accepted here and filter nothing there". That is the co-change
test of ADR 0016 passing out loud: the two files cannot change independently.

**A `--namespace` value reaches `NamespaceMatcher::matchesSingle()` from more
than these two**, and the invariant is deliberately narrower than that set.
`governance/SelectorSyntax/NamespaceMatcherNormalizationSurfaceTest.php`
registers seven call sites; `HealthScoreDrillDown` (lines 104 and 199) and
`WorstOffenderBuilder` are the two that also serve `--namespace`. They stay
where they are, and the reason is that the shared thing between all of them is
only the comparison primitive, which `Core\Pattern\NamespaceMatcher` already
owns and that governance test already guards. What binds `DrillDownBinding` to
`FindingFilter` and to nothing else is a second, narrower agreement: the two
must offer the matcher **the same universe of strings** — namespace plus, for a
ranked-offender level, the whole canonical name. `HealthScoreDrillDown` matches
namespace names and a class's namespace to weight health scores and never sees
an offender's canonical name, so it cannot disagree with the counter the way
the filter can. If that ever changes, the counter's universe is what has to
grow, and this is where to look.

The third test of the framework — counterfactual ownership — cuts the other
way and is named here rather than left out. `FindingFilter` has three consumers
inside Reporting; `DrillDownBinding` will have none, and its only caller stays
in Console. Read alone, that is an argument for leaving it where it was, and
ADR 0022 does place a port with its consumer. It does not win here because this
is not a port: it is not an interface the consumer inverted a dependency
through, it is a counter that must agree with a filter about one thing, and
that filter is the subject it belongs to. The asymmetry is real, and if the
counter ever grows a second caller in Console rather than in Reporting, it is
the reason to look at this again.

Why the refusal stays in Console: the refusal is CLI vocabulary — it names
`--namespace` as an option and routes through `CheckCommand::execute()` for the
exit code. `DrillDownBinding` answers "how many", `ResultPresenter` decides that
zero is a refusal. Splitting them that way is what lets the counter stay
framework-free.

Why `new DrillDownBinding()` stays inline: the class is stateless by
construction and its docblock says so — "the run is an argument, not a
collaborator — so a caller that already holds the run needs no wiring to ask".
Registering it as a service would add a DI address for no gain.

Not in scope: merging `DrillDownBinding` into `FindingFilter`. #133 kept
behaviour unchanged and this tail asks about ownership, not about the class
boundary. Worth a separate look once they are neighbours.

## Measurement that decided it

`bin/qmx check src/ --format=metrics --workers=0`, both sides, this tree at
`1bda6f95`. Namespace-level `coupling.cbo` is the size of the union of
namespaces an edge reaches, incoming and outgoing together — read off
`CouplingCollector::computeNamespaceMetrics()`, not assumed; `coupling.ce-packages`
is the separate metric that counts packages. So the risk was that moving one
class between namespaces would move CBO for every provider while `ca`/`ce`
stood still. Measured instead:

| namespace                                  | before -> after                                                                                                 |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------------- |
| `Qualimetrix\Reporting` (root)             | `coupling.cbo` **16 -> 16**, `ca` 12 -> 12, `ce` 57 -> 58, `health.overall` 81.66 -> 81.38                      |
| `Reporting\DrillDown`                      | `cbo` 9 -> 10, `ce` 5 -> 10, `distance` 0.500 -> 0.333, `health.maintainability` 49.25 -> 54.46, classes 1 -> 2 |
| `Infrastructure\Console`                   | `cbo` 60 -> 58, `ce` 172 -> 170, `health.overall` 80.30 -> 80.53, classes 38 -> 37                              |
| `Analysis\Evidence\Measurement\Contract`   | `cbo` 46 -> 47                                                                                                  |
| `Core\Pattern`                             | `cbo` 13 -> 12                                                                                                  |
| `ComputedMetrics\Health\Contract\Offender` | `cbo` 8 -> 7                                                                                                    |
| `Qualimetrix\Infrastructure`               | `ce` 242 -> 240, `health.overall` 77.90 -> 78.01                                                                |

The root `Reporting` CBO does not move, so the `[Q]ualimetrix\Reporting` entry
in `suppress_namespace_channels` and its "16 against an inclusive 16" reading
both stand and are not touched. It does not move **by construction**, and that
is the stronger statement the numbers allow: every namespace the moving class
brings with it — `Measurement\Contract`, `Core\Pattern`, `Core\Symbol` and
`…Health\Contract\Offender` — was already reached from Reporting by another
file. `ce` 57 -> 58 is the one number in that entry's own text that does move,
and it moves because one class arrived inside an already-coupled package; the
entry's claim is about `cbo`, so it is unaffected, but a reader re-measuring
`ce` will now find 58 and should know it was expected.

`Measurement\Contract` is the one place a value worsens: `cbo` 46 -> 47 against
a namespace threshold of 25. It is silent because that channel is suppressed
for that namespace outright, not because 47 is under a ceiling — there is no
ceiling here, and the entry's own text said 45, which no run has reproduced
since. That text is corrected in this change to the measured 47, dated, with
the step this move contributes named.

The rest does not generalise into one sentence, so it is listed: `cbo` improves
for `Core\Pattern` (13 -> 12), `…Health\Contract\Offender` (8 -> 7) and
`Infrastructure\Console` (60 -> 58); `Reporting\DrillDown` rises 9 -> 10, which
is the moving class bringing its own imports into a namespace that had one
file; and `Qualimetrix\Reporting`'s `health.overall` falls 81.66 -> 81.38,
which is the root's average over a set that did not change being reported
against a subtree that did. Every value above is read off the two runs, not
derived.

## Baseline: three entries, three different causes

Measured by running `bin/qmx check src/ --baseline=qmx-baseline.json
--fail-on=warning` on the moved tree. It prints exactly three stale entries, and
they must not be treated alike:

| entry                                                                                                                                   | cause                                                                                                                                                                                        | action              |
| --------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------- |
| `declaration:callable:...\Console\DrillDownBinding::namespaceUniverse@src/Infrastructure/Console/DrillDownBinding.php` `complexity.ccn` | declaration moved; finding alive under the new key at the same magnitude (10)                                                                                                                | rename key in place |
| `declaration:class:...\Console\DrillDownBinding@src/Infrastructure/Console/DrillDownBinding.php` `health.cohesion`                      | declaration moved; alive at the same magnitude (40.0)                                                                                                                                        | rename key in place |
| `ns:Qualimetrix\Reporting\DrillDown` `health.maintainability`                                                                           | **diluted, not repaired** — the namespace average rises 49.3 -> 54.46 because a second, healthier file joined it; `FindingFilter` itself did not change, and no finding is produced any more | delete              |

Confirm after the move that the run reports zero stale entries and that the two
renamed keys carry the magnitudes above unchanged; a magnitude that moved is a
different finding and needs its own decision.

The third row carries a price worth seeing before it is paid: because the entry
is deleted rather than re-magnituded, the follow-up left open below — merging
the counter into the filter — would put the namespace back to one file, return
`health.maintainability` to roughly 49, and produce the finding again with no
accepted entry behind it. That is a cost of the merge, not a reason to keep a
stale entry.

A fourth address follows from the deletion alone and from none of the renames:
`docs/ARCHITECTURE.md` publishes the ratchet's derived size as "N groups across
M subjects", so dropping a subject moves both numbers and
`BaselineCountPublicationTest` names the file. It is listed below.

## Edit addresses

Obtained by three sweeps over the whole tree, not over `src/`:
`git grep -w DrillDownBinding`, `git grep 'Infrastructure/Console/DrillDownBinding'`
(path spelling, which the name sweep misses inside baseline and manifest keys),
and a separate pass over the test tree, whose namespace does not neighbour the
production one.

**A fourth channel found what no sweep could**: the baseline entry
`ns:Qualimetrix\Reporting\DrillDown` names neither the class nor any path, so
it is unreachable by grep in principle. It came from running the ratchet on the
moved tree and reading the stale block — which is the only instrument that
enumerates baseline keys by what a run measures rather than by their text.

**What these sweeps cannot see**, and is covered by the named second witness
instead: DI service ids and string FQCNs (none here — the class is never
registered and never named as a string; `git grep` for a quoted FQCN returns
only the baseline and manifest keys already listed), reflection and tag-based
autoconfiguration (the class implements no interface and carries no attribute),
and the generated artifacts, which are re-derived rather than edited. PHPStan
and `composer architecture:check` are the second witness for PHP references.

Hand-edited:

1. `src/Infrastructure/Console/DrillDownBinding.php` -> `src/Reporting/DrillDown/DrillDownBinding.php`, `namespace` line only
2. `src/Infrastructure/Console/ResultPresenter.php` — one `use`
3. `tests/Infrastructure/Console/Unit/DrillDownBindingTest.php` -> `tests/Reporting/Unit/DrillDown/DrillDownBindingTest.php`, namespace and `use`
4. `governance/SelectorSyntax/NamespaceMatcherNormalizationSurfaceTest.php` — the `SELECTOR_SURFACES` path key
5. `src/Analysis/Evidence/Measurement/Contract/MetricRepositoryInterface.php` — the `@qmx-threshold` docblock names `Infrastructure\Console\DrillDownBinding`
6. `src/Infrastructure/README.md` — drop the row
7. `src/Reporting/README.md` — add the row under `DrillDown/`
8. `qmx-baseline.json` — two keys renamed, one deleted (table above)
9. `docs/internal/modular-architecture-manifest.json` — owner and exact imports
10. `CHANGELOG.md` — a `Breaking` entry, in the form the three PR #133 commits
    used for the same kind of move: the old and the new spelling, and what a
    consumer has to retype
11. `docs/ARCHITECTURE.md` — the derived ratchet tuple, which the deletion moves
12. `docs/internal/plans/README.md` — the plan index, which
    `PlanningRecordIsolationTest` requires to name every plan directory

Re-derived, never hand-edited: `qmx.yaml` layer patterns,
`docs/internal/generated/modular-architecture/*` (ownership, cross-owner
imports, public imports, reporting classification, test ownership, PHPUnit
discovery, topology), `finding-gate/enumeration-renames.tsv`. Produced by
`composer architecture:generate` and `composer enumeration:renames`.

Not touched: `finding-gate/maps/` — no channel, rule, metric key or published
finding field is renamed, so the gate has no declaration to make.

## Test plan

- `DrillDownBindingTest` moves with its subject to
  `tests/Reporting/Unit/DrillDown/`, beside `FindingFilterTest`; its 17 cases
  (counted, not assumed) must be listed and executed after the move. The suite
  totals must **not** match: `tests/Infrastructure/**` is the `Infrastructure`
  suite and `tests/Reporting/Unit/**` is the `Unit` suite, so the move is
  expected to read Infrastructure -17 and Unit +17 with the sum over all suites
  unmoved. `test-phpunit-suites.txt` prints those totals and is regenerated, so
  it is the thing to read rather than a count kept by hand.
- The test imports no fixture from `Tests\Infrastructure` — only production
  types. Its one import of a Measurement internal,
  `Measurement\Repository\InMemoryMetricRepository`, is already what
  `tests/Reporting/Unit/Formatter/Html/HtmlTreeBuilderTest.php` does from the
  Reporting test tree, so the move introduces no new kind of edge.
- `ResultPresenterTest` and `ConfigurationRefusalRoutingTest` exercise the
  refusal end to end and must stay green without edits; if either needs an edit,
  the refusal moved when it should not have.

## Definition of Done

1. `composer architecture:generate` and `composer enumeration:renames` re-run,
   diffs reviewed rather than accepted.
2. `bin/qmx check src/ --baseline=qmx-baseline.json --fail-on=warning` reports
   zero stale entries and zero violations.
3. `bin/qmx check src/ --format=metrics --workers=0` A/B re-taken on the final
   tree and compared against the table above; a value that does not match is a
   finding, not a rounding difference.
4. `composer check` whole, with its own captured exit code.
5. Review before push.
