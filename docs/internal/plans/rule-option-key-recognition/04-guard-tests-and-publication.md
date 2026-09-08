# 04 — The standing guard, and publication

## Goal

Stages 01–03 make the declaration correct once. This stage makes it stay
correct, and tells the outside world what changed.

## The guard: declared ⊇ read, checked by a second witness

`measurement/option-declared-vs-read.tsv` is a snapshot. The invariant behind
it becomes a test:

> For every options class and every level class, the key set declared by
> `acceptedOptionKeys()` (both halves) contains every key its `fromArray()`
> reads.

The oracle is not a hand-written list — a hand-written list is the same author
filling in both the claim and its check, and it passes its own guard
(`MEMORY.md`, *Two-witness enumeration*). It is the AST reader already written
for the measurement: `scripts/enumerate-rule-option-keys.php` derives the read
side by parsing `fromArray()` bodies, and the declared side from the real
container plus reflection. The guard reuses that reader; the declaration and
its oracle then come from two different places.

**Where the reader lives.** It moves out of the script into
`tests/Analysis/Finding/RuleConfiguration/Support/`, under
`Qualimetrix\Tests\` (composer `autoload-dev`, already covering `tests/`), and
the script requires it from there. This follows CLAUDE.md's rule that tests
follow their owning subject with support as a subdivision inside it: the
invariant is Finding's — it is the factory's contract that is being kept
honest — and the reader has exactly two consumers, the guard and the script
that regenerates the measurement.

**The guard's blind spots, stated as its limit rather than discovered later.**
`measurement/option-enumeration-blind-spots.tsv` names 16 sites the reader
cannot resolve to a literal key: 14 `nested-delegation` (a wrapper handing
`$config` to a level class, five classes) and 2 `dynamic-key`
(`LayerViolationOptions` line 120, the `foreach` over a constant map that hid
pairs #24–#26). Consequences, both of which the guard must state in its own
failure message:

- nested delegation is *why* the level classes are walked separately; with
  `LevelOptionsInterface::acceptedOptionKeys()` in place, the 14 sites are
  covered by the child's own declaration rather than by the parent's reader.
- a dynamic key remains invisible. The guard therefore asserts
  declared ⊇ read, not equality: a class may declare a key the reader cannot
  see (which is what #24–#26 are), and a *second* assertion — declared keys
  that neither the reader saw nor a test exercises — is deliberately not made,
  because it would fail on exactly the honest case.

A companion assertion keeps the interfaces П3.1 deleted dead:
`ShorthandOptionKeysInterface` and `AdditionalOptionKeysInterface` must not
exist, so a reintroduction is a red test rather than a review catch.

## Regression cases: one per position where a key can go unrecognised

The population is not invented here; it is the list in
`03-refusal-at-every-depth.md` plus the pairs table of
`02-declarations-per-capability.md`:

| group                                          | cases | source                                          |
| ---------------------------------------------- | ----- | ----------------------------------------------- |
| depth-2 positions closed                       | 10    | E48, E53–E59, E61, E73                          |
| the 26 pairs                                   | 26    | `02`, one per row                               |
| Fact 1 / Fact 2 / Fact 3 discriminators        | 3     | `03`, *Test plan*                               |
| door symmetry (YAML vs `--rule-opt`)           | 2     | `03`, *Test plan*                               |
| routing (`-q`, `--format=json`, `--workers=2`) | 3     | rows 51, 52, and the unmeasured worker question |
| the universal off-switch over every rule       | 1     | `02`, *Test plan* — `rules: {<rule>: false}`    |
| retired refusal precedence, both depths        | 2     | ADR 0047 interplay                              |

The 9 *declare* pairs assert the positive: the key works **and** no line is
written to stderr. The 6 *refuse* and 7 *remove-then-refuse* pairs assert exit
3 and the sentence. The 4 *answered-by-the-class* pairs assert exactly one
sentence, the class's own.

## Documentation, and what its scope grew to

- **`website/docs/rules/`, EN and RU together** — `complexity.md` loses
  `warning_threshold`/`error_threshold` from anywhere they appear and gains a
  statement that top-level `warning`/`error` are not options of a hierarchical
  rule; `coupling.md` gains the four keys pairs #17/#18/#20/#21 make public.
  That last item is a cost of the decision, not an afterthought: those keys are
  documented nowhere today because `CboOptions`' docblock declares them
  deliberately unadvertised, and the docblock is rewritten in П2.2 rather than
  left contradicting the declaration.
- **`website/docs/reference/`** — the configuration page states the three
  equivalent spellings (row 61), that an empty level block is the same as an
  omitted one (row E60), and that an unknown option key is a configuration
  error at any depth.
- **`qmx.yaml.example`** — the commented examples at lines 57, 252–258 and 333
  are uncommented one at a time and run, because a commented example is invisible
  to both checkers used in the overview's reddening measurement.
- **Capability READMEs** touched by П2.1/П2.2/П2.5 (`Complexity`, `Coupling`,
  `Policy/Architecture`), and `src/Analysis/Finding/README.md` — the paragraph
  describing `ThresholdAwareOptionsInterface::warningBoundary()` gains its
  sibling paragraph about `acceptedOptionKeys()`, in the same shape.
- **`CHANGELOG.md`, `Breaking`** — three entries, each naming old and new
  surface: the seven removed aliases; top-level `warning`/`error` on the three
  complexity rules becoming an error; and an unknown rule option key at any
  depth becoming exit 3 instead of a warning or silence.
- **ADR** — the new one described in `00-overview.md`. Its migration section is
  written from the consumer's side: what a `qmx.yaml` written against the old
  contract does now, and the mechanical edit for each of the ten changed
  spellings.

## Work packages

**П4.1 — the guard.** Exactly three files:
`tests/Analysis/Finding/RuleConfiguration/Support/FromArrayKeyReader.php` (the
reader, moved out of the script),
`tests/Analysis/Finding/RuleConfiguration/Unit/DeclaredOptionKeysCoverReadKeysTest.php`
(the guard, including the dead-interface assertion), and
`scripts/enumerate-rule-option-keys.php` (now requiring the moved reader
through composer's `autoload-dev`, which already maps `Qualimetrix\Tests\` to
`tests/`). Depends on stage 03.

**П4.2 — the regression cases.** Every other file under
`tests/Analysis/Finding/RuleConfiguration/Unit/`, plus the CLI-door cases under
`tests/Infrastructure/Console/`. The three filenames П4.1 owns are named above
and are the whole of the overlap, so the two packages do not collide. Depends
on stage 03. **Parallel with П4.1** — the guard is a different question from
the behaviour.

**П4.3 — documentation and ADR.** `website/docs/**` (EN and RU),
`qmx.yaml.example`, `CHANGELOG.md`, `docs/adr/00NN-*.md`, the four READMEs.
Depends on stage 03. **Parallel with П4.1 and П4.2** — no file overlap.

**П4.4 — validation and gate.** No files of its own. Runs after 4.1–4.3 land.

## Validation, in the order a failure is cheapest to read

1. Per package: `composer cs-check`, scoped PHPStan, the package's own tests.
2. Before the aggregate: full PHPStan, `composer architecture:check`, and
   `bin/qmx check src/ --workers=0 --fail-on=warning --format=json`. The last
   one is the dogfooding check that the cure did not redden this tree — the
   planning-time measurement says it will not, and this is where that
   prediction is settled rather than assumed.
3. `composer check` once, whole.
4. `composer gate -- --reference=<the commit this plan starts from>`, expected
   GREEN with empty maps and no declared delta: no channel, finding field or
   published name moves in this plan. A GREEN run here proves the normalization
   list is complete for these steps, not that any single step was safe — each
   stage's own gate run, against the previous stage's commit, is what proves
   the step.
5. `composer gate:controls` is **not** re-run: the comparator is untouched.
6. Review per `dvizh-vr-workflow:review` — contract change, so both the plan
   review that precedes execution and the execution review that follows it.

## What this stage leaves broken

Nothing intended. Two things it explicitly does not do, restating the
overview so a reader of this file alone is not misled:

- the eleven other mechanisms of `measurement/merged-enumeration.md`, and in
  particular M6's exception routing — `validateNumericFields()` still throws
  `RuntimeException` and still exits 1 under `Unexpected error:`, in the very
  method this plan rewrites;
- the two crash defects (`computed_metrics` with `levels:` as a map; a
  non-numeric threshold routed as a tool crash), and the narrowed
  `suppress_namespace_channels` question, which after its remeasure is ADR
  0044's open follow-up and not a defect of this seam.

`measurement/merged-enumeration.md` stays as it is. This directory's
`README.md` gains one line under *Status* naming which mechanisms the plan
closed and pointing at the ADR — the enumeration is evidence and is not edited
to match a treatment.
