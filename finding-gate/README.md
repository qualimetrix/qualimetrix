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
│   ├── channels.tsv       # old channel key -> new channel key
│   ├── symbols.tsv        # old FQN or path -> new (generated from git diff --find-renames)
│   └── metric-keys.tsv    # old metric key -> new metric key
├── normalization.tsv      # fields excluded from comparison, each with its reason
└── equivalence-tuple.tsv  # the finding fields the gate compares, derived from code
```

## `case.json`

```jsonc
{
  "id": "smells",                      // == directory name
  "description": "why this case exists, in one line",
  "paths": ["src"],                    // relative to the case directory
  "config": "qmx.yaml",                // relative to the case directory
  "args": ["--rule-opt=complexity.wmc:threshold=0"],   // extra CLI arguments; optional, defaults to []
  "channels": ["code-smell.eval#code-smell.eval"],     // channels this case is responsible for
  "explainSubjects": ["declaration:Corpus\\Smells\\Eval_"]  // subjects for baseline:explain
}
```

`channels` is a claim the gate verifies per case, not documentation: a case that
stops firing a channel it claims fails, and a channel no case claims fails the
coverage check. Coverage is also checked for multiplicity: **a channel must fire
in exactly one case.** Two producers make the deduplicated union blind to a lost
fixture, so the control that deletes one would pass while proving nothing.

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
declaration to three properties:

- **Whole names only.** A row translates a complete name, never a prefix of a
  longer one: `complexity.cyclomatic` does not rewrite
  `complexity.cyclomatic.callable`. Renaming a family means declaring every
  member of it.
- **No chains, no collisions.** A map is refused at load time if one row's
  target is another row's source, if two rows rename the same name, if two rows
  produce the same name, or if a row's two sides are equal. Substitution is a
  single pass over the original text, so rows cannot cascade into an identity no
  row states.
- **No idle rows.** A declared row that translated nothing anywhere in the run
  fails as `map-stale`, exactly as a normalization rule that redacted nothing
  fails as `normalization-stale`.

## Adding a channel

A new channel needs a fixture in the case that owns its family and a line in
that case's `channels`. The gate fails until both exist — that is the point:
coverage is checked in both directions on every step, so neither a fixture lost
nor a channel added silently narrows what the gate proves.
