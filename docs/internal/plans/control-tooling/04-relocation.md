# Stage 04 — one home for the rig, code beside its data

## The subject, and what `assurance/` is declared to be

`tools/` and `scripts/` are role buckets. The honest completion of "this directory is
about ___" for this material is *evidence about the product*.

**`assurance/` is a navigation taxonomy, exactly as `Analysis/` is** — no PHP types, no
state, no shared contract, no dependency allow-list target, no wildcard between
children. The architectural boundaries are the families:

```
assurance/<family>/{code, *.tsv, fixtures/, README.md}
```

The first revision named a subject and then, one stage later, put a shared substrate
directly under it — which is what a role bucket does. Declaring the taxonomy here
forces stage 05 to give its substrate a **named family owner**, not a root under the
taxonomy.

The three evidence tests:

- **Name.** Completes as a subject, not a role. Weakest of the three; opens the
  question, does not close it.
- **Co-change.** Today one family's change touches two roots — `scripts/<family>/` and
  `<family>/`. Co-location makes the ordinary change local. Strongest evidence, and
  checkable against history before the move.
- **Counterfactual ownership.** What every family would otherwise copy is stage 05's
  subject. Everything else stays with its family.

## What does not move

`dev-glue` — `init-environment.sh`, `check-private-leaks.sh`, `format-md-tables.py`,
`pre-commit-hook.sh` — stays in `scripts/`. It is the development environment, not
evidence about the product; stage 01 already exempts it from every budget, and moving
it would make `assurance/` wider than its declared subject. The move-set is the
Ledger B rows whose `kind` is not `dev-glue`, read from the ledger — not the 122 files
of the planning snapshot.

## `benchmarks/` — a sibling, and a wrong name

**Resolved: it does not join `assurance/`, and it is renamed.**

What is actually inside: fourteen open-source PHP packages pulled through its own
`composer.json`, plus `src/` itself, plus optional machine-local private codebases. It
exists to calibrate health-score formulas to a target percentile distribution, to
validate metric correctness across diverse codebases, and to regression-test formula
changes. **Nothing in it measures speed** — which is what "benchmark" means to almost
every reader of a PHP repository.

The name misleads in both directions at once: someone asking "are our thresholds sane
against real code?" will not search `benchmarks/`, and someone asking "how fast is the
analyser?" will open it and find a corpus. That is the discoverability defect which
already produced one duplicate in this repository.

Proposed name: **`calibration/`** — the subject is the calibration of formulas and the
expected ranges it yields; the corpus is its input, not its identity.

The subject is also scattered across four roots today, which is the co-change argument
for gathering it:

| piece                                         | where it lives now                                           |
| --------------------------------------------- | ------------------------------------------------------------ |
| the corpus manifest and local-config examples | `benchmarks/`                                                |
| collection, regression check, comparison      | `scripts/benchmark-*.{php,sh}`, `scripts/compare-metrics.py` |
| the expected ranges                           | `docs/internal/benchmark-baselines.json`                     |
| the guard that keeps the consumers tracked    | `tests/.../BenchmarkConsumersCoverageTest.php`               |

**Why a sibling of `assurance/` and not a child.** Assurance proves claims about *this*
codebase; calibration measures the product's formulas against *other* codebases.
Different corpus, different lifecycle, different consumers — `benchmark:check` is not
in `composer check`. Counterfactual ownership settles it: developed independently,
assurance would not copy the corpus and calibration would not copy the ledger. A parent
that accepts both because both are "evidence" is a container that would accept anything,
which is the sign the subject is not named.

Command names (`benchmark:check`, `benchmark:update`) carry the same wrong word and are
renamed with the directory; `docs/internal/CLI_CONVENTIONS.md` governs the new spelling
and must be read before choosing it. The rename touches `AGENTS.md`, the README,
`.gitignore`, and the test's literal paths — all inside this stage's radius table.

## Blast radius, measured

| consumer                            | what breaks                                                      |
| ----------------------------------- | ---------------------------------------------------------------- |
| `composer.json`                     | every `scripts/...` path across 57 commands                      |
| `phpstan.neon`                      | `paths: - scripts` — the rig **is** analysed at level 8          |
| `.githooks/{pre-commit,commit-msg}` | dev-glue paths (which do not move — verify, don't assume)        |
| `.github/workflows/qmx.yml`         | `scripts/check-private-leaks.sh`                                 |
| `tests/`                            | literal `scripts/...` strings, plus paths built by concatenation |
| `.gitattributes`, `.gitignore`      | both name the data dirs                                          |
| tool READMEs                        | `finding-gate/README.md` is 1049 lines of paths                  |
| `AGENTS.md`                         | 36 commands in Essential Commands                                |
| intra-rig                           | literal `require` plus six dynamic autoloaders                   |

Checked and **not** in the radius: the modular-architecture manifest does not
reference the rig at all, and the two inventory generators hold five `scripts/`
references between them — the 887 generated files do not regenerate for this move.

## The hazard that must be proved, not reasoned

`composer gate --reference=<ref>` checks out a reference commit. If the gate read its
corpus from that checkout, moving `finding-gate/` would destroy the proof-of-step use
the canon prescribes. Reading `scripts/finding-gate.php:109`, corpus and maps load
from `candidateRoot` — the current tree. Expected safe; **expected is not proved.**
P1 runs the gate against a pre-move commit and records the result. Red or cannot-run
stops the stage.

## Packages

| package                                     | files                                               | depends on |
| ------------------------------------------- | --------------------------------------------------- | ---------- |
| P1 prove the gate survives the move         | a scratch move, one gate run, a recorded result     | stage 03   |
| P2 move **and** repoint — one delivery unit | the move script, the nine radius rows, ledger paths | P1         |
| P3 rewrite paths inside the four READMEs    | the READMEs                                         | P2         |

**P2 is one unit, not two.** The first revision split `git mv` from repointing and had
the tree fully red between them, with each half's DoD unverifiable. "A move and an edit
never share a commit" governs *file content*, not repointing a consumer at a new path.
P2 is a script: the move-set is hundreds of files, and the ledger paths are updated by
the same script that moves them.

## Definition of Done

- `composer check` green, and `composer gate --reference=<a pre-move commit>` green —
  the second is the evidence that matters here.
- `git log --follow` still resolves for a sampled file per family.
- No `scripts/` path remains **among executable consumers** — `composer.json`,
  `phpstan.neon`, hooks, CI, tests, intra-rig `require`. Expected count zero *there*;
  dev-glue paths and historical references in other plans' records are explicitly
  outside the promise, because "nowhere at all" is not satisfiable and a DoD nobody
  can satisfy gets quietly dropped.
- The ledgers carry the new paths, written by the move script.
