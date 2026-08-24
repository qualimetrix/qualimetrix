# finding-gate — the finding-equivalence gate and its external corpus

**Subject:** proving that a vocabulary change (channel names, symbol names,
metric keys) changed *nothing observable except what a declared map says it
changed*. Everything a step of
[`docs/internal/plans/rule-vocabulary/PLAN.md`](../docs/internal/plans/rule-vocabulary/PLAN.md)
needs in order to be provable lives here; the executable is
`scripts/finding-gate.php`.

The corpus is **external code by construction**. We dogfood ourselves, so a
corpus containing `src/` would move its own input with every step it is
supposed to measure: renaming the class `Violation` would shift the subjects of
the findings the gate compares. Nothing under `cases/` may be product code, and
no case may point at a path outside its own directory.

## Layout

```
finding-gate/
├── cases/<case>/          # one corpus case = fixtures + the config that fires them
│   ├── case.json          # run definition (schema below)
│   ├── qmx.yaml           # the configuration that makes the channels fire
│   ├── composer.json      # the case's own project root: without it a run warns
│   │                      # `No composer.json found`. It does NOT set the
│   │                      # reported project name — see the HTML note below
│   └── src/**.php         # the fixtures
├── maps/                  # what a step declares it renamed; empty = renames nothing
│   ├── channels.tsv       # old channel key -> new channel key; forward only
│   ├── symbols.tsv        # old FQN or path -> new (generated from git diff --find-renames)
│   ├── metric-keys.tsv    # old metric key -> new metric key
│   └── inputs.tsv         # option keys, flag aliases, names inside selectors
├── declared-delta.tsv     # surfaces that changed structurally, not by rename;
├── declared-delta/        # with one exact unified diff each. Both appear only
│                          # when a step declares one, and neither exists now
├── normalization.tsv      # fields excluded from comparison, each with its reason
└── equivalence-tuple.tsv  # the finding fields the gate compares, derived from code
```

## `case.json`

```jsonc
{
  "id": "smells",                      // == directory name
  "description": "why this case exists, in one line",
  "coverage": "authoritative",         // or "auxiliary"; optional, defaults to authoritative
  "paths": ["src"],                    // relative to the case directory
  "config": "qmx.yaml",                // relative to the case directory
  "args": ["--rule-opt=complexity.wmc:threshold=0"],   // extra CLI arguments; optional, defaults to []
  "channels": ["code-smell.eval#code-smell.eval@class"],  // channel AND level pairs this case owns
  "explainSubjects": ["declaration:Corpus\\Smells\\Eval_"]  // subjects for baseline:explain
}
```

`channels` is a claim the gate verifies per case, not documentation: a case that
stops firing a pair it claims fails, and a declared pair no case fires fails the
coverage check. Coverage is also checked for multiplicity: **a channel must fire
in exactly one case.** Two producers make the deduplicated union blind to a lost
fixture, so the control that deletes one would pass while proving nothing.

### A claim is a `channel@level` pair

The unit of a claim is `rule#code@level`, and the level is read out of the
`subject` field, which carries it in its tag (`declaration:callable:…`,
`declaration:class:…`, `file:`, `ns:`, `project:`). The spelling is the product's
own level vocabulary (`SymbolLevel`), not the subject's tag for it, so the claim,
a case's `levels:` list and the drift test's oracle all say `namespace` for the
same thing. The values are not repeated here on purpose: the gate keeps one copy
of them (see below), and a list in prose is a second one. A subject shape the gate
cannot level stops the run instead of being given a default: its level is one the
claim would otherwise stop checking in silence.

Why the pair and not the name. The observed set is keyed by what it is compared
against, so with names alone a channel firing at two levels inside one case was
one entry on either side — and taking away the evidence for one of those levels
changed nothing anywhere: the channel still fires, the claim still lists it, the
coverage union is unchanged, and because both trees read the corpus out of the
candidate's case directory no surface differs either. That is exactly the shape
the collapse of the level channels produces, so the claim counts pairs and the
`lost-level-fixture` control holds it to that.

Multiplicity still counts **channels**, deliberately: the guarantee it carries is
one authoritative owner per channel, and pairing it with levels would let two
cases own one channel as long as they fired it at different levels. The map and
the claim are different accountings too — the unit of *substitution* stays the
name.

### Coverage counts pairs, against a *derived* declaration

The claim direction and the coverage direction are different questions. A claim
says what *one case* fires; coverage asks whether anything at all fires what the
product declares — and its declared side must not be hand-written, or a pair the
product can produce that fires in no case and is claimed in no case is invisible
from both sides at once. That pair passed every check the gate had: not claimed
and not observed, so no claim mismatch; the channel observed at its other level,
so no shortfall. It is also exactly what the collapse of the level channels has
to be proved against, since after the collapse the level is the only thing
telling two former channels apart.

So the declared side is **derived, from two witnesses**, and the hand-written
claim stays beside them as a third, independent voice:

| Witness                                              | Half it answers for | Where the levels come from                                                                  |
| ---------------------------------------------------- | ------------------- | ------------------------------------------------------------------------------------------- |
| the candidate container (`probe-channels.php`)       | static and run-time | `ChannelDeclaration::$levels`, and the resolved `ComputedMetricDefinition` for `computed.*` |
| `tests/…/Fixtures/Channels/declared.txt`, `<levels>` | static only         | the tracked fixture's third column                                                          |
| `case.json`'s `channels`                             | per case            | hand-written, and verified per pair                                                         |

The two declaration witnesses disagreeing is its own failure class,
`witness-disagreement` — not a coverage shortfall, and not a tie broken in
silence. The run-time half has **one** witness for levels, exactly as it has for
names: `computed.*` is open-ended and no fixture line could enumerate it. One
consequence is worth stating, because a control rests on it: a run-time channel's
levels come from the case's own configuration, so that level and the corpus that
fires it move together and pair coverage cannot see one leave —
`lost-level-fixture` is caught by the claim. A static channel's levels come from
product code, so they *can* part company with the corpus, and there a lost level
is a `coverage-shortfall`. Every static channel declares exactly one level today;
Ш5c is what makes that stop being true.

`--incomplete-corpus` downgrades a pair shortfall exactly as it downgraded a name
shortfall. A pair observed that nothing declares — including a level a declared
channel does not say it reports at — is `coverage-surplus`.

The gate's own level vocabulary lives in exactly one place, the tag map in
`scripts/finding-gate/SubjectLevel.php`, and it is held against the product's
`SymbolLevel` on every comparison run and by `--self-test` (failure class
`level-vocabulary-drift`). It has to be measured rather than asserted: the level
the gate derives never reaches a compared artifact — it is checked against a
claim written in the same gate-internal spelling — so a renamed `SymbolLevel`
case would leave every claim matching and every run green.

A bare channel name is refused as a claim entry, and so is a level outside the
vocabulary: a half-migrated `case.json` would otherwise keep passing while
claiming less than it looks like it claims.

### `coverage`: what a case is for

An **authoritative** case owns the channels it claims — it is what the coverage
and multiplicity arithmetic counts, so a channel has exactly one of these.

An **auxiliary** case exists for an *input* nothing else exercises. The corpus
was blind to three of them until Ш4a — `--disable-rule`, `only_rules` and a
non-empty `exclude_paths` — and adding them as ordinary cases was impossible: the
channels they fire are already owned, so a second producer would be
`coverage-multiplicity`. An auxiliary case is therefore compared on every surface
and still has to fire **exactly** what its `channels` claims; it is only left out
of the coverage and multiplicity arithmetic. The original guarantee — one
authoritative owner per channel — is unchanged word for word.

The three that exist address only names later steps do not rename, and each was
checked to bite: without its selector, both fixtures of the case fire. An
auxiliary case is not evidence about a selector's *reach* — after a rule is
split, "does `--disable-rule=<old name>` still find anything" is a question the
reference's vocabulary cannot even state, and it is closed by a test, not here.

Every run uses the case directory as its working directory, so no path in any
artifact depends on where the tree is checked out. The `check` runs add
`--workers=0 --no-cache --no-ansi --fail-on=error`. `baseline:generate` and
`baseline:explain` have **no** `--no-cache` and no `--cache-dir`, and the AST
cache lives in `.qmx-cache` under that shared working directory with a key that
names nothing about the product — so the gate itself removes that directory
before and after every invocation it makes in a case directory. Without it the
side that runs second reads the other side's parser output as authoritative, and
the baseline surfaces would be compared against themselves. The
`baseline:generate` invocation additionally asserts that a cache *was* written
where the gate had just cleared one: if the product ever caches somewhere else,
this isolation must fail loudly rather than quietly guard nothing.

HTML's `project.name` (`qualimetrix/qualimetrix`) and `qmxVersion` come from
`Composer\InstalledVersions`, i.e. from our own repository rather than from the
case. They are **not** normalized and cannot be: both sides run against the same
cloned `vendor/`, so both read the same value and it stays compared like any
other field.

## Surfaces

Per case: the eleven formats (`summary`, `text`, `text-verbose`, `json`,
`checkstyle`, `sarif`, `gitlab`, `github`, `metrics`, `health`, `html`), the
exit code, `check --show-suppressed --format=text` (suppression is a text-only
surface — with `--format=json` the flag prepends a plain-text report and the
artifact stops parsing), the baseline `baseline:generate` writes,
and `baseline:explain` for each subject in `explainSubjects`. Once per tree:
the `bin/qmx rules` snapshot.

## Verdicts

| Verdict   | Exit | Meaning                                                |
| --------- | ---- | ------------------------------------------------------ |
| `GREEN`   | 0    | Full corpus, and the two trees are finding-equivalent. |
| `PARTIAL` | 2    | Nothing failed, but the run claims no equivalence.     |
| `RED`     | 1    | At least one failure class fired.                      |
| —         | 3    | The gate could not run (bad corpus, bad map, no tree). |

A run is `PARTIAL`, never `GREEN`, when `--cases=` restricted the corpus or when
`--incomplete-corpus` downgraded a coverage shortfall to a warning. Only a
`GREEN` full-corpus run is evidence of finding-equivalence; a step's Definition
of Done may not cite anything else.

`--incomplete-corpus` exists for a corpus that does not yet claim the whole
declared channel set — while cases are being written, and for a one-case
development loop such as `--cases=annotations --incomplete-corpus`. It turns the
shortfall into a warning; it cannot turn the run green.

## What normalization may exclude

`normalization.tsv` is measured, never written by taste: a row enters only
because two runs of one unchanged tree disagreed on that field
(`--derive-normalization`), and a row that matched nothing in a whole run fails
as `normalization-stale`. Two further limits keep the list from eating the
comparison it is part of:

- A locator names one field **by path**, never by substring, so SARIF's constant
  `version` stays compared.
- A row may not reach a field the equivalence tuple compares. This is checked
  twice: statically, on the locator (its last segment may not be a tuple field),
  and by measurement, by normalizing the JSON surface and comparing its findings
  section against the raw one. Failing either is `normalization-overreach` —
  excluding a compared field would retire it from the comparison while the tuple
  still claims it is guarded.

## What a map declares

`maps/*.tsv` is how a step states what it renamed, and the gate holds the
declaration to these properties:

- **Whole names only.** A row translates a complete name, never a prefix of a
  longer one: `complexity.cyclomatic` does not rewrite
  `complexity.cyclomatic.callable`. Renaming a family means declaring every
  member of it. A name reaches an artifact in more spellings than a row can be
  written in, and every spelling is substituted by that same row: the
  JSON-escaped form of a backslash-bearing symbol, and checkstyle's
  `source="qmx.<code>"` — the only prefix any surface adds, measured across all
  eleven formats, the baseline file, `baseline:explain` and the rules snapshot.
- **No chains.** A map is refused at load time if one row's target is another
  row's source, if two rows rename the same whole name, or if a row's two sides
  are equal. Substitution is a single pass over the original text, so rows cannot
  cascade into an identity no row states.
- **No idle rows.** A declared row that translated nothing anywhere in the run
  fails as `map-stale`, exactly as a normalization rule that redacted nothing
  fails as `normalization-stale`. Not every map has to fire, though: the corpus
  is external, so a renamed product symbol reaches no compared artifact and
  `symbols.tsv` can legitimately stay empty.

### Direction is declared, and it follows from injectivity

A map is applied backwards **if and only if it is injective in both
directions**, and that is checked when it loads rather than promised.

| Map               | Applied      | To what                                                                                         |
| ----------------- | ------------ | ----------------------------------------------------------------------------------------------- |
| `channels.tsv`    | forward only | reference artifacts: the whole `rule#code` key and each unambiguous half                        |
| `symbols.tsv`     | both ways    | reference artifacts; and input — `baseline:explain` subjects, configuration text                |
| `metric-keys.tsv` | both ways    | reference artifacts; and input — `computed_metrics` formulas in a case's configuration          |
| `inputs.tsv`      | both ways    | option keys, flag aliases, names in selectors: they live on the input and in the rules snapshot |

Forward means the *reference's* output restated in the candidate's vocabulary.
Backward means the *candidate's* input restated in the reference's, because the
reference binary cannot be addressed in a vocabulary it does not have yet.

`channels.tsv` is forward only, and two measured reasons say so. A collapse
gives two rows one target and a split gives one old half several, so neither is
invertible. And after a collapse the target is textually the same string as the
unchanged **producer** name the corpus writes into its own arguments, so an
inverted channel map would rewrite a legitimate input the step never touched.

An input that does need translating says so with an `inputs.tsv` row. One that
needs it and has no row makes the reference refuse its input with exit 3, which
the gate reports as `reference-input-untranslated` rather than letting it arrive
as eleven surface diffs and an empty findings section.

An `inputs.tsv` row names a **whole token**: `rule:option-key` as `--rule-opt=`
writes it, a flag together with its two dashes, or a dotted producer name as a
selector writes it. A bare undotted word is refused — "the option key without its
rule" would translate the same key on every other rule too.

#### One old token, several new ones

An `inputs.tsv` row may name several new tokens, separated by `|`:

```
old                     new                                                                                   reason
design.type-coverage    design.param-type-coverage|design.property-type-coverage|design.return-type-coverage   Ш4b split the producer
```

That is the one shape allowed to break injectivity, and the asymmetry is what
makes it admissible. A split producer is one name in the reference's vocabulary
and several in the candidate's, so **backwards** — the direction this map exists
for — the several candidate names all restate as the one name the reference knows,
which is a function. **Forwards** there is no function to apply, so the row is not
applied forwards at all: an occurrence of the old token on the way out stops the
run and names the row, because taking the first image would publish a rename no
row declared. Either the surface belongs in a declared delta, or the input that
reaches it needs a row of its own naming one token. Without the shape there was no
writable row at all: measured after Ш4b, `design.type-coverage` is three
producers, so a case addressing the old name by a selector was
`reference-input-untranslated` for good.

The obligations are the ordinary ones, and one is decided rather than inherited:

- every image is checked to be a whole token, exactly as a single new side is;
- chains, and rows renaming a name another row produces, are refused as always;
- **every image has to have translated something.** A row with three images is
  three renames, not one, so "one of three fired" is refused and the idle images
  are named in the failure. The weaker rule — any image fires — would let a step
  declare three new names, exercise one, and keep the other two as a standing
  excuse, which is the rubber stamp `map-stale` exists to prevent. The cost is
  that the corpus has to address each new name, which is the same pressure
  coverage already applies to channels.
- Several tokens on the **old** side are refused: that would make the backwards
  direction the undecidable one. A collapse on the way out needs no such shape —
  `channels.tsv` is forward-only and expresses it with two ordinary rows.

### Splitting and collapsing

A channels row translates the whole key and each differing half, so a family
rename can be stated once. Two ways the halves stop being a function:

- **A collapse is allowed.** Two rows with one target is correct forwards: the
  two reference names really do become one, and the map has no backwards
  direction to lose. The findings stay distinguishable because `subject` carries
  the level in its prefix.
- **A split is derived, not declared separately.** When the rows disagree about
  one old half, that half is a split source: it is *not* translated textually,
  because no translation of it is right. Its protection is not lost — every
  reference finding carrying that half must have its `(rule, code)` pair named by
  a declared row, and the candidate must publish the pair that row computes on
  the same subject. An occurrence nothing accounts for is `split-unmapped`.
  `rule` and `code` are fields the equivalence tuple compares, so this is the
  same rule normalization is held to; a declared delta gets no waiver here. What
  the matched records produce is a set of `(from, to)` moves per field, and that
  set — not the set of values in it — is what `delta-overreach` will allow.

## What a declared delta declares

Some of what a step changes is neither a rename nor an excluded field: splitting
one rule turns one aggregate group into three and adds rows to the rule
inventory. `declared-delta.tsv` (`surface`, `file`, `reason`) plus one exact
unified diff per surface is how that is stated, and the diff files are produced
by `--derive-declared-delta`, never typed. The `reason` is the one thing a run
cannot measure: a re-derivation carries existing reasons over and writes `?` for
a new row, and loading refuses `?`.

Four failure classes keep it from becoming a rubber stamp, and three of them are
judged on the diff the run **measures**, not on the declared text:

- `delta-mismatch` — the measured diff is not the declared one, byte for byte.
- `delta-stale` — a delta is declared for a surface the two trees agree on.
- `delta-too-large` — the diff is past the limit, so the pressure stays on
  declaring another map row rather than dropping in a blob.
- `delta-overreach` — a diff line *moves* a field the equivalence tuple
  compares, and no declared split performed that move. Three properties, each
  narrower than the obvious version:
  - **Moved, not mentioned.** A compact JSON record names `channel` on the same
    line as the magnitude it records, so pairing the removed and added lines is
    what makes the question answerable at all.
  - **The move, not the values.** What a declared split licenses is the set of
    `(from, to)` pairs its explained records actually produced. A line moving
    `rule` between two *targets* of the same split — both values that explained
    records carry — is refused, because no record ever paired them.
  - **Read under the spelling each surface uses.** Only the published
    `"field": value` syntax is read, which covers the JSON family and the HTML
    report's embedded payload. The payload spells three tuple fields
    differently (`ruleName`, `violationCode`, `symbolPath`), and those aliases
    are declared in the gate and pinned against the partitioner that writes
    them, so the HTML surface is read in its own vocabulary rather than skipped
    in silence. The plain-text surfaces print a bare name no field syntax marks,
    and there the record-level split check is the guard.
  A line that publishes a *different number* of values for one field is refused
  outright: the record set on that line changed, which no rename explains.

A run with declared deltas still says GREEN, and says loudly how many there are
and how big they are. Lines longer than 500 characters also get a token-level
diff in the failure detail: the HTML report's embedded payload is one line of
roughly 59 thousand characters, and without that nobody can read what moved.

The exact diff is a series of hunks with no context lines: what the two sides
share is dropped, and each hunk carries the line it starts at on both sides. It
used to be a single hunk covering everything between the outermost differing
lines, and that was wrong for the reason its first real user found — two small
changes at opposite ends of a report restated the hundreds of identical lines
between them, and `delta-too-large` then counted padding as change and refused a
declaration that had nothing left to declare.

The split is the longest common run of lines, recursively, not a full LCS over
artifacts the size of the HTML report. Two consequences are worth knowing:
a shared run shorter than four lines stays inside its hunk and *is* counted on
both sides (in the tracked SARIF declaration that is 18 of 36 counted lines), and
a differing span whose line pairs exceed the search budget is **refused** rather
than emitted as one padded hunk — falling back would silently restore the
behaviour the hunks exist to remove.

## Who reads the corpus

`case.json` and the case directories have a schema, and **four** consumers read
it — two of them outside this directory. Changing the schema is a change that has
to touch this list; it exists because the last change to the claim format
recorded its blast radius as "one literal, one mutation" and two of these four
survived by luck.

| Consumer                                                                  | What it reads                                                                                                       |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `scripts/finding-gate/Corpus.php` + `CaseDefinition.php`                  | every `cases/*/case.json`, as the schema                                                                            |
| `scripts/finding-gate-controls/Controls.php`                              | three exact paths it mutates: a fixture file, a case `qmx.yaml`, and one `case.json` literal                        |
| `tests/Analysis/Finding/Integration/ChannelLevelDeclarationDriftTest.php` | every `cases/*/case.json` — `paths`, `config`, `args` — and runs `bin/qmx` over each; it is inside `composer check` |
| `scripts/generate-rename-enumeration.php`                                 | `cases/*/qmx.yaml`, and counts occurrences under `finding-gate/**`                                                  |

Measured, not recalled: `git grep -E "finding-gate/cases|case\.json"` over a
stopped tree, minus prose. The product test is one field-read away from breaking
on a claim-format change and nothing warns it; the enumeration generator is what
made `composer check` red the last time a case file moved.

## Adding a channel

A new channel needs a fixture in the case that owns its family and a line in
that case's `channels`. The gate fails until both exist — that is the point:
coverage is checked in both directions on every step, so neither a fixture lost
nor a channel added silently narrows what the gate proves. A channel reporting at
more than one level needs a fixture *and* a claim line **per declared level**:
coverage counts pairs, so a level with no fixture anywhere is a shortfall even
while the channel fires.

## The controls

`composer gate:controls` runs eleven controls: the positive one and ten planted
breakages, each on its own hardlink clone, each required to produce a named
failure class at a named surface. Two properties of the declaration are worth
knowing before adding one:

- A **toleration** — a further failure the mutation cannot avoid producing — pins
  the surface it lands on, and it also has to *land*. A toleration nothing matched
  fails the control: it states a blast radius nobody measured, and it widens what
  the control accepts the day the product starts producing that class there. A
  toleration whose only overlap is with a required expectation counts as idle too,
  since the required one is what absorbed those failures.
- `lost-level-fixture` is the control on a lost level. Its mutation takes the
  `class` level away from the `health` case's user-defined computed metric, which
  is the only way this corpus can lose one level of a multi-level channel:
  measured, the seven `computed.health` channels are the only ones firing at more
  than one level in a case, they are computed for every class, and deleting any
  single fixture of that case leaves the level set untouched. Nothing is
  tolerated, and the absence of `coverage-shortfall` from its expectations is the
  assertion: the channel is still declared and still observed, so the claim is the
  only place the loss can be seen.
