# promise-effect — configuration promise/effect oracle

This subject owns the evidence for what a recognised configuration key does,
against what its carrier promises. It differs from `input-doors`: that oracle
asks whether an unresolved value is reported; this one supplies a real value
and judges its observable effect.

## Artifacts

| artifact                                                            | role                                                                  | producer or owner                            |
| ------------------------------------------------------------------- | --------------------------------------------------------------------- | -------------------------------------------- |
| `promise-ledger.tsv`                                                | frozen promise inventory                                              | human-authored source record                 |
| `promise-ledger-frozen-ranges.tsv`                                  | SHA-256 freeze for every ledger carrier range                         | `scripts/promise-ledger-freeze.py`           |
| `key-pairs.tsv`                                                     | frozen pair enumeration for composition and neighbourhood populations | human-authored source record                 |
| `config-paths.tsv`                                                  | configuration paths outside `rules:`                                  | human-authored source record                 |
| `form-deciding-sites.tsv`                                           | form-deciding source-site inventory                                   | human-authored source record                 |
| `form-deciding-sites-resolution.tsv`                                | disposition for each any-key form-deciding site                       | human-authored source record                 |
| `hierarchical-options.tsv`                                          | hierarchy inventory for line-granular freezes                         | human-authored source record                 |
| `p1-file-set.tsv`                                                   | generated projection of the relevant file set                         | `scripts/promise-effect-p1-set.php`          |
| `forms.tsv`                                                         | value forms and their canonical inputs                                | human-authored source record                 |
| `axis-a-hits.tsv`, `axis-d-envelopes.tsv`, `axis-d-observables.tsv` | real hits, document envelopes, and observables for form probes        | human-authored source records                |
| `cli-root-flags.tsv`                                                | root CLI flags and shadowing relationships                            | human-authored source record                 |
| `witness-envelopes.tsv`                                             | minimal documents that make quiet producers observable                | human-authored source record                 |
| `pair-kind-scope.tsv`                                               | source coordinates owed by each pair kind                             | human-authored source record                 |
| `door-normalization.tsv`                                            | value that each door hands to its declaration for each form           | human-authored source record                 |
| `composition-magnitudes.tsv`, `effect-magnitudes.tsv`               | distinguishable values for composition and counter-default probes     | human-authored source records                |
| `observability-limits.tsv`                                          | declared limits on questions the stand cannot put to the product      | human-authored source record                 |
| `floor.tsv`                                                         | known defects, declared repairs, and withdrawn observations           | human-authored source record                 |
| `run-declaration.tsv`                                               | axes, blocking ownership, and frozen-shot commit                      | human-authored source record                 |
| `fixtures/probe/**`                                                 | fixture project for process observations                              | human-authored source record                 |
| `../docs/internal/generated/promise-effect/verdicts.tsv`            | generated current verdict grid                                        | `scripts/promise-effect.php`                 |
| `../docs/internal/generated/promise-effect/inputs.stamp.tsv`        | input freshness stamp                                                 | `scripts/promise-effect.php`                 |
| `../docs/internal/generated/promise-effect/observations-before/**`  | frozen normalized raw observations                                    | `scripts/promise-effect.php --freeze-before` |

## Evidence protocol

The registry and declaration are authored independently: the registry author
must not read the declaration before both records are complete. Their comparison
is evidence only after that separation. Carrier ranges cited by the ledger are
frozen with their SHA-256 values in `promise-ledger-frozen-ranges.tsv`; changing
a carrier requires a deliberate ledger update and a new freeze.

The stand stores normalized raw observations, not only verdict labels. The
current classifier rejudges both the live and frozen halves. A source-input
change requires `--freeze-before` and a reason in `shot.txt`; changing the
classifier alone does not. Carrier prose records a promise and is never silently
substituted for the observed effect.

## Observation points and form probes

The stand observes four points — door output, merged document, options object,
and report — and the deepest available point votes. A shallower loss is kept in
`decided_by` as `lost at <point>`. CLI overrides do not pass through the merged
document, so that point is never invented for a CLI observation.

Axes A and D compare `omitted / value / equivalent`. Axis B compares
`onlyA / onlyB / both`; Axis C compares `onlyLow / onlyHigh / both`; Axis E
compares `omitted / neighbour / nullAlone / both`. A probe needs a real hit:
a canonical value that equals the product default is not sensitive evidence.
For pair and neighbourhood probes, `effect-magnitudes.tsv` supplies a
counter-default value when the canonical magnitude is indistinguishable from an
omitted key.

| verdict          | meaning                                                               |
| ---------------- | --------------------------------------------------------------------- |
| `OK`             | the observed effect matches the promise and the producer is witnessed |
| `INERT`          | a promised effect equals the omitted observation                      |
| `COLLAPSED`      | the canonical value behaves as another declared form                  |
| `REFUSES`        | exit 3 with `Configuration error:` framing                            |
| `MALFORMED`      | unframed refusal, exit 1, or crash                                    |
| `NOT OBSERVABLE` | the stand cannot distinguish the relevant observations                |
| `UNPROMISED`     | the grid carries a row absent from the ledger                         |

The verdict label and `defect` bit are separate. A framed refusal can still be
a defect when the ledger promised acceptance; all floors and controls compare
`VERDICT|defect`, never the label alone.

## Composition and neighbourhood

Axis C preserves both source composition and sibling loss. `LOST_SIBLING` is
asked before winner equality: otherwise a middle layer that removes the lower
layer's exclusive slot can look like a correct higher-layer winner. `FRANKENSTEIN`
means an accepted merged list or map equals neither input leaf-by-leaf. A triple
has a distinct middle observation when a promise depends on the layer that
wrote a slot last; the classifier refuses to guess when that observation is
missing.

Axis E has a narrower claim: writing `~` beside a neighbour must have the same
effect as leaving that key out. It reports `PRESENCE_NEUTRAL`,
`PRESENCE_SWITCHED_BRANCH`, `PRESENCE_REFUSED`, or `NOT OBSERVABLE`. A `~`
already defective alone belongs to the form axis and is not counted twice.

## Reachability, cache, and refusal framing

`OK` requires evidence that the named producer ran. A quiet fixture produces
`NOT OBSERVABLE`, not a claim that the producer is dead. Producers whose
subject requires a configuration section use their declared minimal document
in `witness-envelopes.tsv`.

Every process probe names and clears a unique cache directory and fails if the
default cache appears. `--no-cache` is not trusted. The refusal classifier also
uses a framed and an unframed control on every measured tree; a changed control
shape is a refusal of the stand, not a verdict.

## Limits and floor

`observability-limits.tsv` records a question the stand cannot put to the
product; it does not claim the product is silent. A limit may not hide an `OK`
cell, an unpublished `MALFORMED` outcome, or a `COLLAPSED` value under a
value-domain limitation. Door expressibility is read from `InputDefinition`,
not duplicated in prose.

`floor.tsv` has distinct dispositions:

- an ordinary row must remain defective;
- a `cure` row must no longer be defective;
- a `withdrawn` row must be `NOT OBSERVABLE` for its declared reason;
- a `pending:` row is defective in the frozen half and repaired in the live
  half.

Absence from the grid is a floor miss under every disposition. The comparison
uses the defect bit: a changed verdict name is not evidence of repair. A frozen
shot cannot be taken while a `pending:` row remains, because the new half would
make its two-sided claim false.

## Declarations and populations

The population guard derives producer names, option implementations,
`config-paths.tsv` positions, and `key-pairs.tsv` members from code and source
artifacts. Independent enumerators enlarge a disagreement rather than shrinking
it. `pair-kind-scope.tsv` declares the coordinates each pair kind owes; an
unknown kind is refused rather than defaulted.

The four declaration sets compare the registry with the effective declaration
after `door-normalization.tsv`:

| set                               | meaning                                                   |
| --------------------------------- | --------------------------------------------------------- |
| `ledger ∩ declaration`            | a form both sources name                                  |
| `declaration \ ledger`            | a declaration accepts a form the registry did not promise |
| `declaration \ ledger`, unopposed | the registry made no claim for that row                   |
| `declaration \ ledger`, deeper    | a declared key absent from the registry denominator       |
| `ledger \ declaration`            | a promised form is not accepted                           |

These sets compare declarations, not runtime behaviour. A wider declaration is
not itself a product defect; the verdict grid measures behaviour. The fifth set,
`consumer \ declaration`, finds key literals read by code but absent from the
declaration. Form-deciding sites are resolved by file path and the documented
union of `acceptedOptionKeys()` with framework keys; unresolved spelling is a
reported state, never a guessed key.

## Operating commands

```bash
composer promise-effect
composer promise-effect:check
composer promise-effect:before
composer promise-effect:grid
composer promise-effect:grid:check
composer promise-effect:controls
composer promise-effect:stability
php scripts/promise-effect.php --freeze-before --reason='…'
```

`promise-effect:grid:check` validates declared populations, grid span, and
input stamp. It does not measure current product behaviour; only the full stand
does that. A narrowed `--axis=` run rewrites the published grid partially, so
follow it with a full run before relying on generated verdict data.
