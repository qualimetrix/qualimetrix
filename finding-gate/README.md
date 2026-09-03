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
│   ├── metric-keys.tsv    # old metric key -> new metric key; forward only, and a
│   │                      # row covers each `<key>.<strategy>` spelling too
│   └── inputs.tsv         # option keys, flag aliases, names inside selectors
├── declared-delta.tsv     # surfaces that changed structurally, not by rename;
├── declared-delta/        # with one exact unified diff each. Both appear only
│                          # when a step declares one
├── declared-field-moves.tsv # one exact (surface, field, from, to) pair each,
│                          # licensing a compared field to move inside a
│                          # declared diff. Typed, not derived
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
  "channels": ["code-smell.eval@callable"],             // channel AND level pairs this case owns
  "explainSubjects": ["declaration:callable:Corpus\\Smells\\Smells::report@src/Smells.php"]  // subjects for baseline:explain
}
```

`channels` is a claim the gate verifies per case, not documentation: a case that
stops firing a pair it claims fails, and a declared pair no case fires fails the
coverage check. Coverage is also checked for multiplicity: **a channel must fire
in exactly one case.** Two producers make the deduplicated union blind to a lost
fixture, so the control that deletes one would pass while proving nothing.

### A claim is a `channel@level` pair

The unit of a claim is `channel@level` — one name and one level, since Ш5b left a
channel with a single name; a claim still written as the old `rule#code` pair is
refused, because no channel carries that name. The level is read out of the
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
is a `coverage-shortfall`. Five static channels declare two levels since Ш5c
took the level out of the name; the rest declare one.

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

Each auxiliary case addresses one kind of input and was checked to bite: take
that input away and the case stops firing exactly what it claims. For the
selector cases that is both fixtures firing once the selector is gone. An auxiliary case is not
evidence about a selector's *reach* — after a rule is split, "does
`--disable-rule=<old name>` still find anything" is a question the reference's
vocabulary cannot even state, and it is closed by a test, not here.

`applied-threshold` is the one whose input is an annotation rather than a
selector. Before it, both `@qmx-threshold` annotations in the corpus were
refusals — a rule that declares no override support, and a value that does not
parse — so a green gate was evidence about how an override is *rejected* and
about nothing else. It carries four fixtures, because an applied override has
more than one thing to witness:

- **the lowering direction publishes itself.** `Retuned::classify` fires at no
  configured threshold of the case, and the finding prints the annotated number
  rather than the configured one.
- **the raising direction publishes nothing.** `Accepted::assemble` is an error
  at the configured threshold and the annotation accepts it, so the evidence is
  a pair the case must fire none of.
- **an annotation written on a class has to reach a declaration inside it.**
  `ClassScoped` is the second binding path, and the only one a mutation can cut
  while every annotation of the case stays in place.
- **an annotation must not reach anything else.** `Retuned::untouched` and
  `Neighbour` carry the annotated method's own complexity and no annotation, one
  inside the annotated file and one outside it. Both are needed: the binding is
  built per file, so a file-wide leak is invisible to a witness in another file,
  and a run-wide one is invisible to a witness in the same file.

Each of the three annotations was taken away on its own, and each is a
`case-claim-mismatch`: twice as *only in claimed*, once as *only in fired*. The
witnesses were measured against the product instead, on isolated copies — cut
the class-to-declaration propagation, bind a callable annotation to its whole
file, or drop the subject comparison that selects an override, and the run is
red on findings and surfaces. The first of those mutations left this corpus
green before the case existed.

What the case does not witness is worth stating, because the annotations look
like they cover more than they do. The raising direction pins no *value*: any
annotation lifting both boundaries clear of the fixture is indistinguishable
from this one. Both directions bite only against today's defaults — a default
that moved past a fixture would leave the annotation deciding nothing, and only
the claim would notice. And one standard `warning`/`error` pair is the only
option shape exercised: the rules whose options hold several boundaries, or
whose `withOverride()` writes something other than a threshold, are untested
here.

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

Per case: the twelve formats (`summary`, `text`, `text-verbose`, `json`,
`checkstyle`, `sarif`, `gitlab`, `github`, `metrics`, `health`, `html`,
`suppressed`), the exit code, `check --show-suppressed --format=text` (the flag
and the `suppressed` format are two publications of one composition, and both
are compared; the flag's report goes to stderr whatever the format, so it is
captured as an artifact of its own beside a stdout payload that is
byte-identical with and without the flag), the baseline `baseline:generate`
writes,
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

## What a fingerprint is compared by

A finding's fingerprint is `channel:subject[:occurrence][:edge]`, and consumers
track alerts by it: a fingerprint that moves for no stated reason makes every
consumer treat every finding as new. It is also the one published value a rename
*must* move, so it can never be compared for equality across a step that renames
a channel.

The two publications are not the same problem, and the gate treats them
differently:

| Publication                                         | Form                | How it is compared                                         |
| --------------------------------------------------- | ------------------- | ---------------------------------------------------------- |
| SARIF `partialFingerprints.primaryLocationLineHash` | the identity, plain | as text, through the maps, like any other name             |
| GitLab `fingerprint`                                | `md5` of it         | the hash is **substituted** by the identity, then compared |

Three rules, and each one is a failure class rather than a promise:

- **Each side must agree with itself.** The published value is compared against a
  recomputation from that side's own published fields, on the **raw** artifact,
  before any map touches it. A disagreement is `fingerprint-mismatch`.
- **The opaque publication is substituted, never redacted.** Every GitLab hash is
  replaced by the identity this side just proved it hashes, and only then is the
  reference's text translated forward and the two compared. So an identity that
  moved with a declared row to explain it compares equal, and an identity that
  moved with nothing to explain it is `surface-mismatch` on that surface — in
  readable names, not hex. Substituting fewer values than the surface published
  is `fingerprint-opaque`: a hash left as hex agrees with itself under every
  rename, which is the one thing this must not do.
- **Substitute, then translate — never translate, then recompute.** The
  reference's artifacts are translated forward, so after translation its
  `channel` field speaks the candidate's vocabulary while the hash beside it is
  still the old one. Recomputing from translated fields would report a mismatch
  on every finding of every honest rename; nothing in the gate does it.

The licence for replacing the hash is that every field the composition reads —
`channel`, `subject`, `occurrence`, `edge` — is a field the equivalence tuple
already compares, so the hash carries no datum the comparison loses. That is
checked against the tracked tuple on every run, and a composition reaching
outside it fails as `tuple-field-drift`.

Measured on 2026-08-24, over the whole corpus, with a channel published as its
own name alone (the Ш5b collapse): before substituting, twelve GitLab
surfaces differed by 376 lines of nothing but hashes — a declaration made of hex,
which is the blob `delta-too-large` exists to refuse; after substituting, every
surface of every case agreed under the declared channel rows alone.

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
  longer one: a row for `X` does not rewrite `X.y`. Renaming a family means
  declaring every member of it. Two rows may share a *target* — that is a
  collapse, which the map states forwards — but no two may share a source. A
  name reaches an artifact in more spellings than a row can be written in, and
  every spelling is substituted by that same row: the
  JSON-escaped form of a backslash-bearing symbol, checkstyle's
  `source="qmx.<code>"` — the only prefix any surface adds, measured across all
  twelve formats, the baseline file, `baseline:explain` and the rules snapshot —
  and SARIF's `rules[].name`, which is the channel code title-cased. The
  title-cased spelling belongs to channel rows whose two sides are plain names:
  title-casing a whole `rule#code` key or a class FQN produces a phrase no
  artifact contains, and a substitution nothing can match is the rubber stamp
  these rules refuse everywhere else. It was measured, not foreseen — a control
  renaming a channel code left exactly one surface differing, and no row could be
  written for a spelling with spaces in it.
- **No chains.** A map is refused at load time if one row's target is another
  row's source, if two rows rename the same whole name, or if a row's two sides
  are equal. Substitution is a single pass over the original text, so rows cannot
  cascade into an identity no row states.
- **No idle rows.** A declared row that neither translated nor explained
  anything anywhere in the run fails as `map-stale`, exactly as a normalization
  rule that redacted nothing fails as `normalization-stale`. Not every map has to
  fire, though: the corpus is external, so a renamed product symbol reaches no
  compared artifact and `symbols.tsv` can legitimately stay empty.

  *Explaining* counts beside *translating*, and the rule is: a channel row is
  credited by a record it named whose published identity its target actually
  **moved**. Not by a match — a row is compared against what the record it names
  already publishes, in the fields that row constrains: a `rule#code -> rule#code`
  row against the record's own pair, a `rule#code -> name` row against the
  record's own code, since such a row says nothing about `rule`. A row whose
  target is what the record already publishes has claimed nothing, and matching
  it leaves the row exactly as stale as it was.

  The shape this exists for is the producer move
  (`computed.health#health.complexity -> health.complexity#health.complexity`),
  which has nothing to substitute anywhere: its rule half is one side of the
  split such rows derive and is deliberately left untranslated, its code half is
  the same string on both sides, and no surface prints the whole `rule#code` key
  the row is written as. Judged by substitution alone it would be idle, which
  would make the only shape a producer move can be declared in unwritable. The
  credit is not restricted to that shape, though — any row that moved a record it
  named earns it — so what keeps the relaxation honest is the movement test above
  and the fact that credit is granted per row and per matched record: a row of a
  live split that moved no record of *its own* key is still `map-stale`, because
  "a sibling of mine fired" is not a claim about this row.

### Direction is declared, and it follows from injectivity

A map is applied backwards **if and only if it is injective in both
directions**, and that is checked when it loads rather than promised.

| Map               | Applied      | To what                                                                                         |
| ----------------- | ------------ | ----------------------------------------------------------------------------------------------- |
| `channels.tsv`    | forward only | reference artifacts: the whole `rule#code` key and each unambiguous half                        |
| `symbols.tsv`     | both ways    | reference artifacts; and input — `baseline:explain` subjects, configuration text                |
| `metric-keys.tsv` | forward only | reference artifacts: the key, and each `<key>.<strategy>` spelling of it                        |
| `inputs.tsv`      | both ways    | option keys, flag aliases, names in selectors: they live on the input and in the rules snapshot |

Forward means the *reference's* output restated in the candidate's vocabulary.
Backward means the *candidate's* input restated in the reference's, because the
reference binary cannot be addressed in a vocabulary it does not have yet.

`channels.tsv` is forward only, and two measured reasons say so. A collapse
gives two rows one target and a split gives one old half several, so neither is
invertible. And after a collapse the target is textually the same string as the
unchanged **producer** name the corpus writes into its own arguments, so an
inverted channel map would rewrite a legitimate input the step never touched.

`metric-keys.tsv` is forward only for the same two reasons, measured 2026-08-26.
Nothing on the reference's input is spelled as a metric key: no case argument
carries one, and the corpus' only user-defined formula reads no metric at all —
deliberately, because a formula addresses a key in a *grammar*, and a grammar is
not a name a row can translate. And an inverted key map would rewrite arguments
the step never touched: after the vocabulary rename the new key names are
textually the rule names the corpus writes into its own `--rule-opt` tokens
(`coupling.class-rank` in all fourteen cases, `size.class-count` and its two
siblings in `design`, `complexity.cognitive` and `complexity.npath` in two more),
so a reverse pass would hand the reference `classRank` and `classCount` as rules
it does not have. What a formula *does* still prove is the six built-in health
dimensions, whose bodies live in product source and whose values the gate
compares at three levels on both sides.

### A key row covers its aggregated spellings

A metric is published bare and once per aggregation strategy declared for it,
spelled `<key>.<strategy>`. A `metric-keys.tsv` row therefore translates those
spellings as well as the bare one, and the reasons it may are the reasons it is
not a substring rewrite:

- the strategy list is **closed**, read out of the product's own
  `AggregationStrategy` rather than written here, and the suffix matches only at
  the end of the name. `ccn.avg` is translated, `ccn.average` is not, and neither
  is `ccn.avg.avg` — a doubled suffix is a spelling nothing publishes;
- the list is read from **both** trees and they have to agree. Forward
  translation runs over the reference's artifacts, so a strategy the step removed
  would stop being expanded while the reference still publishes it, and the
  divergence is refused with both lists named;
- the expansion is granted to that one map. Measured over the 14-case corpus: 212
  of 295 published spellings are `base.<strategy>` against 83 base keys, so a row
  per spelling is a list no step can keep complete;
- nothing else may already carry the aggregated spelling of a declared key. Three
  populations can: another declared name, a half the split deliberately leaves
  untranslated, and a base key the product itself declares (read from
  `MetricName`'s constants, which are 71 of the 82 published keys — the other
  eleven are collector-owned literals no single file declares). One spelling with
  two meanings is decided by nothing, so the load refuses it. Measured over all
  83 base keys, no such pair exists today. What the check cannot see is one case:
  a key only the *reference* publishes, shaped like an aggregation of a declared
  one, and moved by the step without a row — every other arrangement of that
  shape ends in a surface diff rather than in silence;
- the aggregated spellings are spellings of the **same** row, exactly as the
  `qmx.` prefix is, so they count towards that row's staleness and are never a
  second declaration.

Two limits of this are worth stating, because both look like escape hatches and
only one is:

- **A key that needs translating on the input** says so with an `inputs.tsv` row
  — *if it has a whole-token shape*. A dotted key does; a bare `ccn` does not, and
  is refused, because "the option key without its rule" would translate the same
  word everywhere. So for the pre-rename undotted keys there is no input row to
  write, which is sound only as long as no case addresses a metric key on the
  input: measured, none does.
- **A step that changes the strategy vocabulary itself** — renaming `avg`, or
  removing a strategy — moves the published spelling of every aggregated metric
  at once, and there is no row shape that states that. The gate refuses to run
  rather than translating what it cannot state; such a step needs a mechanism of
  its own, and this is where it will have to be added.

### One name, two roles

A name can be a channel identity *and* a token the corpus writes into its own
configuration: a user-defined computed metric is both. The step that renames one
declares the same pair in `channels.tsv` and in `inputs.tsv`, and that is **one
declaration in two roles**, not two rows renaming one name. It is applied in the
union of its roles' directions, held to the shape rules of each, and credited
once. Two maps *disagreeing* about a name stays refused — that decides nothing —
and crediting the declaration once is a decision with its reason: the roles
substitute the same string in the same artifacts, so which role a given
occurrence belonged to is not a measurable question.

An input that does need translating says so with an `inputs.tsv` row. One that
needs it and has no row makes the reference refuse its input with exit 3, which
the gate reports as `reference-input-untranslated` rather than letting it arrive
as twelve surface diffs and an empty findings section.

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
- **A channel key stops being a pair.** A row may read `rule#code -> name`: the
  pair collapsing into one identity. It expands into the whole key **only** — the
  rule survives the collapse as its own published field, so translating the rule
  half would rewrite a field the step does not move, and a rename of the code
  half is a rename in its own right and needs its own row. `name -> name` is the
  later rename of an already-collapsed channel and has no halves. `name ->
  rule#code` is refused: no step goes that way, and the halves of the new key
  would be a translation no row declares.
- **A split is derived, not declared separately.** When the rows disagree about
  one old half, that half is a split source: it is *not* translated textually,
  because no translation of it is right. Its protection is not lost — every
  reference finding carrying that half must have its `(rule, code)` pair named by
  a declared row, and the candidate must publish the pair that row computes on
  the same subject. An occurrence nothing accounts for is `split-unmapped`. A
  record a row explained *and moved* credits that row against `map-stale`, by
  key, so the credit reaches the one row that named it and not the split it
  belongs to. Only a row written as a pair can name a record at all: a row whose
  old side is one name declares no channel key, so a split declared in the
  post-collapse vocabulary has nothing to explain its records with and every
  occurrence of its half is `split-unmapped` — a debt of the tool, and the step
  that first needs that shape has to give it one.
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
  compares, and neither a declared split nor a row of
  `declared-field-moves.tsv` (below) accounts for that move. Three properties,
  each narrower than the obvious version:
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

## What a declared field move declares

`delta-overreach` refuses a diff line that *moves* a field the equivalence tuple
compares. Until this file existed its only source of permission was a declared
split, and a split can only ever produce moves of `channel`, `rule` and `code` —
the fields a channel rename rewrites. So `message`, `techDebtMinutes`, `file`,
`line` and `subject` could not be licensed by any declaration that existed: not
because moving them is dangerous, but because there was no list for it. A step
that changes the *text* of a finding — a diagnostic's "did you mean" list, say —
had nothing to declare it with.

`declared-field-moves.tsv` (`surface`, `field`, `from`, `to`, `reason`) is that
list. One row licenses one move:

```
surface                        field    from                to                 reason
case:annotations|format:json   message  …unused-directive.  …directive.        why the text moved
```

- **The key is the whole quadruple, and it is exact.** Not a prefix, not a
  pattern, not "any value of this field". A line moving the same field between
  any other pair of values on that surface is refused exactly as before.
- **A row fires on equality, never on containment.** A `from` that merely occurs
  inside what the run measured would license a move nobody declared.
- **A row nothing fired is `field-move-stale`** — the same lie as `map-stale`,
  `normalization-stale` and `delta-stale`, and it fails the same way. It is
  reported against the surface the row names.
- **It is not a waiver of the declared delta.** The surface still needs its
  `declared-delta` row, that diff is still compared byte for byte
  (`delta-mismatch`) and still refused past the size limit
  (`delta-too-large`). This removes one wall inside a diff that was already
  measured and already declared, and no other.

Unlike the diff files, these rows are **typed**. The pair a row names is printed
verbatim in the `delta-overreach` failure of the run it explains, so what a hand
writes here is a transcription of a measurement — and a mistranscription is
`field-move-stale` rather than a silent widening.

A row is written against one reference. Once the step is merged, the next step's
reference already contains the change, both sides agree, and the row becomes
stale: **the following step empties this file**, exactly as it empties the maps
and the declared delta.

## Who reads the corpus

`case.json` and the case directories have a schema, and **four** consumers read
it — two of them outside this directory. Changing the schema is a change that has
to touch this list; it exists because the last change to the claim format
recorded its blast radius as "one literal, one mutation" and two of these four
survived by luck.

| Consumer                                                                  | What it reads                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| ------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `scripts/finding-gate/Corpus.php` + `CaseDefinition.php`                  | every `cases/*/case.json`, as the schema                                                                                                                                                                                                                                                                                                                                                                                                            |
| `scripts/finding-gate-controls/Controls.php`                              | eight exact corpus paths it mutates: `cases/smells/src/Dead.php`, `cases/health/qmx.yaml`, `cases/disabled-rule/case.json`, `cases/layers/case.json`, `cases/smells/case.json`, `maps/channels.tsv`, `declared-delta.tsv`, `declared-field-moves.tsv`; it also reads `maps/channels.tsv` to write it back with a control's row, creates `declared-delta/control-*.diff`, and digests `declared-delta.tsv` and `declared-delta/` around a derive run |
| `tests/Analysis/Finding/Integration/ChannelLevelDeclarationDriftTest.php` | every `cases/*/case.json` — `paths`, `config`, `args` — and runs `bin/qmx` over each; it is inside `composer check`                                                                                                                                                                                                                                                                                                                                 |
| `scripts/generate-rename-enumeration.php`                                 | `cases/*/qmx.yaml`, and counts occurrences under `finding-gate/**`                                                                                                                                                                                                                                                                                                                                                                                  |

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

`composer gate:controls` runs nineteen controls, each on its own hardlink clone:
seventeen planted breakages, each required to produce a named failure class at a
named surface, and two green ones. `moved-aggregated-spelling`
is the control on the suffix expansion: the metrics
surface publishes `<key>.pct95` where the product computed `<key>.p95`, the base
keys stay exactly where they are, and the gate has to be red rather than absorbing
a movement in the suffix that no row states. It mutates the *formatter* on
purpose — measured, moving the separator inside `MetricName::agg()` takes the
product down instead, and a control that kills the product proves the corpse
differs. Three properties of the declaration are worth knowing before adding one:

- A **toleration** — a further failure the mutation cannot avoid producing — pins
  the surface it lands on, and it also has to *land*. A toleration nothing matched
  fails the control: it states a blast radius nobody measured, and it widens what
  the control accepts the day the product starts producing that class there. A
  toleration whose only overlap is with a required expectation counts as idle too,
  since the required one is what absorbed those failures.
- A **green control with a mutation** (`fingerprint-declared-rename`) asserts the
  other direction: a change the maps declare is absorbed by the declaration and
  by nothing else. It is held to exit 0 *and* to a run that compared no more
  surfaces against a declared delta than this repository itself declares —
  otherwise "the row absorbed it" would be indistinguishable from "a blob of
  hashes absorbed it". Zero was the earlier bar and it stopped being right the
  moment a step declared a delta of its own: an unmutated tree legitimately
  compares those surfaces against their declarations, and the positive control
  would fail for being correct. Its channel is `code-smell.unused-private`,
  because the channel has to be claimed by a case that declares no delta, and
  its code half has to move together with the published `rule` field — after the
  level left the channel names, no static channel's code differs from its rule
  field, so the only rename a whole-name row can make green is one that moves
  both. The new name is also the **same length** as the old one, and that is a
  constraint on renames in general rather than a quirk of this control: a name
  printed in a padded column takes its own length into the surface, and a map row
  translates a name and not the padding beside it. Four surfaces align on a name,
  in two pairs that do not behave alike — measured by enumerating every padding site in
  `src/`, not by naming the ones a failing control happened to point at:

  - `tree|rules` (`RulesCommand`) and the per-rule debt breakdown of
    `--format=text-verbose` (`DebtBreakdownRenderer`) print a **rule** name in a
    fixed `%-40s`, so a length change moves that one line;
  - `--format=health` (`HealthTextFormatter`) and `--format=summary`
    (`HealthBarRenderer`) print a **health dimension's short name** — the part of
    a `health.*` channel after the dot — in a width computed as the **maximum**
    over the six of them, floored at 9 and 10. Renaming the longest one to a
    different length moves every row of the table and its rule, not one line.

  So a step renaming a rule name to a different length declares a delta on the
  first two; a step renaming a `health.*` channel declares one on the last two.
  "Only channels moved, so no column moved" is the reasoning to distrust: it is
  true of the first two surfaces and false of the other two.

  A fifth padding site exists and sits outside this rule for two independent
  reasons, not one: `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php:334`
  pads `%-{$maxLayerNameWidth}s` by a **layer name** the project's own `qmx.yaml`
  layer policy declares, not by any channel, rule, or health-dimension name the
  gate's maps ever touch, so a rename this repository declares cannot move that
  column at all. And even a hypothetical rename of a layer name could not surface
  here regardless: `debug:layer-assignment` is a diagnostic console command, and
  it is not among the gate's compared surfaces — the twelve `check` formats, the
  exit code, the suppression report, the baseline file, `baseline:explain`, and
  the `bin/qmx rules` snapshot (see "Surfaces" above).
- **An expectation may not be pinned to the exact surface a declaration covers.**
  Such a surface is compared against the declared diff and never for equality, so
  a `surface-mismatch` cannot arise there and a control asking for one is
  asserting about a comparison that no longer happens. The harness refuses that
  control before it clones anything; the repair is to move the mutation to a case
  that declares nothing, not to repin onto a `delta-*` class, which would move
  the control off its own subject. A broader pin that merely spans a declared
  surface is fine: the other eleven formats and the baseline file are still compared
  for equality, and the declared one among them is absorbed as declaration noise.
- **A step's own declarations reach into the controls twice more, and both are
  fail-closed rather than obvious.** A control that plants a declared delta
  writes its diff as `declared-delta/control-<surface>.diff`: `Mutation` refuses
  to create a file the repository already has, and without the prefix a control's
  slug collides with the tracked diff of the same surface the moment a step
  declares one. And a control that writes `maps/channels.tsv` whole writes the
  step's rows **plus** its own, read from the tracked file rather than copied
  into the control: the step's rows declare the split that explains its own
  producer move, and dropping them turns the surfaces it declares a delta for
  into `delta-overreach` — which the red controls absorb as declaration noise and
  the green one cannot absorb at all.
- `split-row-idle` and `split-no-row` are the controls on the split mechanism,
  and they watch the two failures a split can hide. `split-row-idle` declares the
  `code-smell.unused-private` rename as a split whose second row names a code the
  product never emits: the first row explains every record and is therefore not
  idle, the second explains none and must fail as `map-stale`. That is the
  boundary of the staleness credit — a relaxation granted per split rather than
  per row would make this control green. Its measured cost is that a split half
  is untranslatable, so the `smells` case's surfaces differ; that toleration is
  drawn as an outline, `case:smells`, rather than enumerated artifact by artifact
  the way the delta controls enumerate theirs. The `qmx rules` listing moves too,
  and is tolerated by nothing: the step declares a delta for it, so what the run
  reports there is a delta class, absorbed as declaration noise — a
  `surface-mismatch` toleration would match nothing and fail the control.
  `split-no-row` perturbs no product code at all: it declares a split of the same channel into two codes the
  product never emits, so the twelve findings that *do* carry the split half have
  no declared row naming their key, and `split-unmapped` is required on that
  case. That class carries the whole delta of the `rule` field whenever a
  producer moves, and no control had watched it fire before.

  What the pair does **not** prove is worth knowing before trusting it: they
  establish that the credit is per row, and nothing more. Move the credit call
  above the "candidate published no such record" check, or grant it without the
  movement test, and both controls still PASS — a corpus run cannot see the
  difference, because the records in it move. Those two properties are held by
  self-test cases (`producerMoves()`) and by them alone.
- `field-move-stale` and `derive-refuses-broken-run` are the two newest, and the
  second is the first control in this harness whose subject is not in the report
  at all. `field-move-stale` replaces `declared-field-moves.tsv` with one row
  licensing a move on a surface where nothing moves; the step's own licence goes
  with the replacement, so the move that *does* happen returns to being
  `delta-overreach` on a surface the step declares and is absorbed as declaration
  noise. `derive-refuses-broken-run` runs `--derive-declared-delta` over a tree
  with one finding dropped: the comparison fails, the run must exit non-zero with
  `finding-count-mismatch`, and `declared-delta.tsv` and `declared-delta/` must
  come out of it byte-identical. That last half is checked by digesting the two
  paths before and after, because the report is exactly what could not be
  trusted — measured on 2026-09-04, the gate printed "nothing was written" and
  had already replaced a planted declaration with thirteen derived rows.
- `lost-level-fixture` is the control on a lost level. Its mutation takes the
  `class` level away from the `health` case's user-defined computed metric, which
  is the only way this corpus can lose one level of a multi-level channel:
  measured, the seven channels of that case's computed family — the six
  `health.*` dimensions and `computed.density`, each with a producer of its
  own since Ш5d — are the only ones firing at more than one level in a case, they
  are computed for every class, and deleting any single fixture of that case
  leaves the level set untouched. Nothing is
  tolerated, and the absence of `coverage-shortfall` from its expectations is the
  assertion: the channel is still declared and still observed, so the claim is the
  only place the loss can be seen.
