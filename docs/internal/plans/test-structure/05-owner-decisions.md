# Stage 05 — the decisions the plan left to the owner

[`05-packages.md`](05-packages.md) records three of the owner's decisions as
settled and leaves one open: the two fixtures used from two manifest owners at
once. This file settles that one and says who executes it, so the campaign does
not end holding an adjudication nobody carried out.

## `empty_file.php` and `invalid_syntax.php` are not one fixture with two owners

They are two questions that happen to need a file with the same bytes. Measured
on `476ce1ac`:

| Consumer                                    | What it asks                                                    |
| ------------------------------------------- | --------------------------------------------------------------- |
| `Infrastructure/Ast/Unit/PhpFileParserTest` | what the parser returns for an empty file, and for a broken one |
| four `Infrastructure/Console` tests         | what the **command** does when handed a path                    |

Console's use is not an Ast use wearing a different hat. `empty_file.php` appears
there about twenty times as *"a path that parses and yields no findings"* — a
carrier so the run has something to analyse while the test asserts something
about options, exit codes or refusal text. `invalid_syntax.php` appears once, to
assert the wording the command prints for a path that does not parse; the
assertion embeds the fixture's path as a literal.

Same input, different subject. Under [ADR 0016](../../adr/0016-subject-cohesion.md)
that is two fixtures, not one shared one.

## Decision: each owner gets its own, and Console's are named for their subject

- **Ast** keeps both at `tests/Infrastructure/Ast/Fixtures/`, under their current
  names. The generator currently proposes
  `tests/Infrastructure/Ast/Fixtures/**Ast**/invalid_syntax.php` — a doubled
  segment whose only purpose is to keep a substring alive (below). The doubling
  goes.
- **Console** gets its own under `tests/Infrastructure/Console/Fixtures/`, named
  for what they are for rather than for what is wrong with them: a file that
  parses and yields nothing, and a file the parser refuses.

Rejected: leaving both under Ast and letting Console reach in. It is the cheapest
edit and it re-installs, in the campaign's last stage, the exact defect the
campaign spent four stages removing. Also rejected: a shared `tests/Fixtures/`
root. Declaring a test root is the table of addresses in `CLAUDE.md`, half of
which fail silently, and nothing here needs a root.

Duplicating a 3-line and an 8-line file is not the `dupe` defect this stage
removes: `dupe` is two assertions asserting one thing. These are inputs.

## What the move costs, including the part that fails loudly

Measured, not estimated:

- **Fixtures are not in the judged population** — 0 of 616 — so this move touches
  neither the floor nor any of the four capped lists. The tightest constraint in
  the stage does not bind here.
- `.php-cs-fixer.dist.php` (`->notPath('Fixtures/Ast/invalid_syntax.php')`) and
  `.githooks/pre-commit` (`grep -v 'Fixtures/Ast/invalid_syntax.php'`) both match
  by **substring**. Console's copy needs its own entry in both. Missing it is
  **loud** — `cs-check` reddens on a file that cannot be parsed — so this is a
  required edit, not a silent trap. That it is loud is also why preserving the
  substring is not worth an ugly path.
- `CheckCommandInputValidationTest` asserts the parse-error message containing the
  fixture path; the literal moves with the fixture.
- `targetPath()` in `scripts/generate-modular-architecture-test-inventory.php`
  stops promising the doubled path, and the generated artifacts are re-derived.
  `disposition` is derived from whether the target differs from the current path,
  so this is what retracts the promise — editing prose does not.

## Who executes it

**P5 (Infrastructure)**, which already owns both the Ast and the Console files.
Adjudicating without executing would leave the generator still promising a move
nobody makes, which is the promise-without-effect shape this repository has been
bitten by before. It is bounded: two copies, five test files, two excluders, one
generator function, re-derived artifacts.

## Found while closing stage 05, deliberately not fixed here

Two measured defects that predate this stage. Both were proven by planting, both
are someone else's subject, and fixing either inside a stage about ledger defects
would bury it.

### The development-namespace ban cannot fire for four of its thirteen entries

`DEVELOPMENT_NAMESPACE_PREFIXES` in
`scripts/generate-modular-architecture-production-inventory.php` is the list that
refuses production code importing a development-only namespace —
`CLAUDE.md`'s table marks it as failing **silently**. Its consumer filters
dependencies with `str_starts_with($dependency, 'Qualimetrix\\')`, so every entry
spelled `Qmx*` is unreachable:

| Entry                              | Reachable | Age         |
| ---------------------------------- | --------- | ----------- |
| `QmxDirectiveAudit\Tests\`         | **no**    | before this |
| `QmxDirectiveAuditControls\Tests\` | **no**    | before this |
| `QmxFindingGate\Tests\`            | **no**    | before this |
| `QmxTautologyControls\Tests\`      | **no**    | added here  |
| the other nine (`Qualimetrix\…`)   | yes       | —           |

Proven both ways in an isolated generator run: planting
`use QmxTautologyControls\Tests\…` into `src/Core/Version.php` leaves the
generator at exit 0, while planting `use Qualimetrix\PromiseEffect\Tests\…`
refuses with
`production source imports a development-only namespace`. Stage 05 added its
entry for consistency with the list it joined; the entry is aligned, not armed,
and saying otherwise would be the sort of claim this campaign exists to stop.

The repair is not a line: the ban needs a second accumulator keyed by the
development prefixes, and it changes governance coverage for three roots older
than this stage — each needing its own planted proof and a review. Its own
package.

### `.gitignore`'s blanket `*.json` swallows anything added under `defect-ledger/`

Measured: `git check-ignore -q defect-ledger/anything.json` exits 0 — ignored —
where `finding-gate/anything.json` exits 1, because that root carries a negation
and this one does not. Latent rather than live: the ledger holds only `.tsv` and
`.md` today, and `.tsv` is not swallowed, so verdict appends were never at risk.
It becomes live the day anything there is JSON, and it fails by the file simply
not being there.
