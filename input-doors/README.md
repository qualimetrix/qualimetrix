# The input-door oracle

What each user-input door does when the value it is given points at nothing.

This file is the operating manual for the artifacts in this directory: what
each column means, what the stand keeps constant, and what the oracle does
**not** cover. The completed planning record remains history; live input-door
data is owned here with the generator and stand that read it.

## The promise, in one sentence

For every input visible to reflection over `InputDefinition` and `ConfigSchema`,
on **every command where reflection sees it**, and for every **declared** site
inside its value, there is a machine-reproducible verdict about the reaction to
a miss. `SPEAKS` and `REFUSES` are awarded only when a **pre-declared** signal
fires and only when the hit is observable; everything else is `SILENT`.

**Not knowing means `SILENT`.** An oracle that errs towards "the product speaks"
is not an oracle, it is permission not to fix.

## What is here

| file                                                            | what it is                                                                   | written by |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------- | ---------- |
| `../docs/internal/generated/input-doors/doors.tsv`              | the denominator: the grid `command × door × site`                            | generator  |
| `../docs/internal/generated/input-doors/command-options.tsv`    | every option each command declares, valueless flags included                 | generator  |
| `door-annotations.tsv`                                          | the handwritten input of the generator: non-referentiality, sites, shadowing | a person   |
| `doors-reconciled.tsv`                                          | the reconciliation measurement that bounds the declared matching-site count  | a person   |
| `probes.tsv`                                                    | the handwritten declarations: miss, hit, empty hit, observable, signal, echo | a person   |
| `cure-sites.tsv`                                                | rows declared for repair, keyed by grid row                                  | a person   |
| `command-observables.tsv`                                       | the default observable and the preconditions of each command                 | a person   |
| `normalization-supplement.tsv`                                  | the surfaces the finding gate does not own, plus one named override          | a person   |
| `fixtures/**`                                                   | the trees the probes run against                                             | a person   |
| `../docs/internal/generated/input-doors/verdicts.tsv`           | the verdict snapshot of the current tree                                     | the stand  |
| `../docs/internal/generated/input-doors/observations-before/**` | the frozen raw pre-cure observations                                         | the stand  |

## Row keys

- **Grid row**: `surface|command|door|site`. No part is constant. A CLI door is
  multiplied over every command that declares it; a configuration door over
  every command that declares `--config`. There is no `(qmx.yaml)` placeholder
  command, because a placeholder is verdict inheritance under another name.
- **Probe row**: `surface|door|site` — no command. One declaration is multiplied
  across commands, and a per-command row overrides it. An override needs a
  reason, always.
- **Frozen observation**: the *grid* key, because one probe row serves several
  grid rows and storing by probe key would let them overwrite each other.

## Columns of `probes.tsv`

| column          | question it answers                                                                  |
| --------------- | ------------------------------------------------------------------------------------ |
| `miss`          | a value that points at nothing                                                       |
| `hit`           | a value that points at something — a probe without a hit is not a probe              |
| `hit_empty`     | a value the door accepts and that legitimately narrows to nothing, or `none` + why   |
| `observable`    | where a hit would be visible: `format:<name>`, `stdout`, `exitcode`, `file:`, `dir:` |
| `signal`        | the form the product must use to say the value missed                                |
| `echoes`        | whether the product prints the submitted value back                                  |
| `fixture`       | which tree under `fixtures/` the probe runs in                                       |
| `signal_source` | `dictionary`, `observation`, `cure:<sha>`, or `none`                                 |
| `reason`        | mandatory whenever a field says `none`, and for every per-command override           |

Signal forms: `refusal`, `stderr:<regex>`, `stdout:<regex>`, `exit:<code>`,
`finding:<channel>@<format>`, or `none` with a reason.

**`refusal` means exit 3 *and* the product's own refusal framing**
(`Configuration error:`) on either stream. The framing is not decoration: the A
side of every required argument is Symfony's `Not enough arguments`, which also
exits 3, and without the framing test a genuinely refusing door would read as a
signal that fires on its own baseline.

## What the stand keeps constant, and which doors that shadows

`--fail-on=none`, `--workers=0`, `--no-cache`, a fixed run target, an explicit
`--config`, `--format` set to the observable; the working directory is the
materialized fixture; the exit code is taken without a pipe. An invariant flag
is passed **only if the command declares it** — that is what
`command-options.tsv` is for.

A door in `shadowed_by` is probed with **its** invariant withdrawn, and takes
another observable instead. A probe submitted with its shadowing neighbour still
in place proves nothing: a YAML `failOn` was measured to exit 0 only because a
CLI `--fail-on=none` stood next to it.

## Oracle protocol

The denominator is reflected from `InputDefinition` and `ConfigSchema`, then
expanded over every command that exposes the input. Each declared site has a
miss, a hit, and an omitted-value observation where the input form permits one.
The raw observations are frozen before classification; normalization comes from
`finding-gate/normalization.tsv` plus the named supplement, never from a second
implicit rule.

`REFUSES` and `SPEAKS` require a declared, specific signal and a verified hit.
`SILENT` is the referential default, including an undeclared site or a signal
that cannot be shown to be caused by the miss. `NOT OBSERVABLE` remains a
separate result: it is not folded into either verdict or used to improve a
summary. Claims apply at the submitted input boundary, not to an unrelated
substring inside the rendered document.

## Configuration probes are documents

For `surface=config`, `miss` and `hit` are inline YAML mappings merged into the
fixture's own `qmx.yaml` at the **top level**, and shallow replacement is the
only semantics a document has. A snippet touching a key the base already
carries must restate the base's value — otherwise the hit differs from the
baseline because the base setting vanished, not because of the door.

`echoes=no` for every configuration probe, and the claim is about the submitted
**document**: a scalar leaf of the snippet quoted inside a refusal message is
not excised. That direction is safe — an un-excised leaf makes the hit *more*
observable, and the outcome then lands on `SILENT`, which is the outcome that
names a defect.

## Path tokenization

Raw output carries absolute paths, and `scripts/check-private-leaks.sh` forbids
`/Users` and `/home` in a tracked file, while the frozen observations are
tracked. Before storage, roots are replaced **longest prefix first**, in both
their raw and their `realpath`-resolved forms:

1. the materialized fixture directory -> `<FIXTURE>`
2. the scratch root -> `<SCRATCH>`
3. the product tree under test -> `<PRODUCT>`
4. the repository -> `<REPO>`

The fixture directory lives inside the scratch root, which on macOS resolves
`/tmp` to `/private/tmp`; the reverse order would make `<FIXTURE>` unreachable.
The same substitutions run over the declared value before the echo is excised,
because the literal from `probes.tsv` is machine-independent and never appears
in a stored text.

## What the oracle does not prove

- **Completeness over sites.** The set of matching sites inside a value is
  handwritten. An undeclared site is invisible by construction; the generator
  only refuses a door that declares *fewer* sites than the measurement named.
- **The absence of silence where there is no door at all.** `@qmx-ignore` in
  source, the environment, baseline entry keys, references inside computed-metric
  formulas. A green door table is compatible with silence in every one of them.
- **That "refuses" means "refuses always".** The verdict is taken under one
  order of configuration sources.
- **That silence is a defect.** It is a policy judgement, not a measurement.
- **That a miss of the form "matched the wrong thing" is handled.** One form of
  miss is measured: "matched nothing at all".
- **That `NOT OBSERVABLE` means the product is silent.** It means this fixture
  cannot tell a hit from a miss on this observable. The share of it — counted
  separately over the configuration half of the grid — is part of the summary,
  not a footnote, precisely because a growing share devalues the denominator.

## Supplement and its one override

The gate's `finding-gate/normalization.tsv` is the single authority over the
surfaces the gate produces, and the stand does not rewrite it: a second
normalization diverges from the first in silence. The supplement adds the
surfaces the gate does not own. It may override a gate-owned surface only with
`gate_owned=yes` plus a reason citing the measurement, and such a row is
reported as a named remainder rather than folded into the total.

Today there is exactly one: the duration in the `format:summary` header. The
gate's row anchors on `analyzed, `, and **every** header insertion breaks that
anchor — the echo of `--namespace`, of `--class`, and the literal `(scoped)`,
inside which the submitted value does not appear at all. The supplement row
normalizes only the duration, so the `N files analyzed` counter stays
load-bearing: a missed `--exclude` moves exactly that counter, and the
observability of the door hangs on it.

## Running it

```bash
composer input-doors            # write the verdict snapshot
composer input-doors:check      # 0 fresh, 1 drift or a red outcome
composer input-doors:grid       # regenerate the denominator
composer input-doors:stability  # repeat the miss side, demand the same text
composer input-doors:controls   # plant one flaw at a time; each must redden its own case
php scripts/input-doors.php --before   # recompute the frozen pre-cure verdicts
```

The `--before` half is recomputed from raw observations by **today's**
classifier, so both halves of the pair are judged by one rule. Retaking the shot
needs `--refreeze-before --reason=<...>`; editing the classifier is not a reason,
because it requires nothing to be retaken.
