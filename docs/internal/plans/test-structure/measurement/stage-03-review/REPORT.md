# Stage 03 — plan review, consolidated

Two reviewers, one brief, one material: commit `194dbfea` (documentation only).
`findings-claude.md` (native, 14 findings / 10 confirmed) and
`findings-codex.md` (external Codex CLI, 13 / 10 confirmed, 3 self-refuted).
Material was **not** sliced.

Every finding below was re-checked by the orchestrator against the tree or by
running the command. Nothing is accepted on a reviewer's word, and two were
cut down on checking.

## They agree on four, and all four are mine to fix

| The defect                                                                   | native      | codex      | verified by                                                                                          |
| ---------------------------------------------------------------------------- | ----------- | ---------- | ---------------------------------------------------------------------------------------------------- |
| `surfaces()` in the rename enumeration is a measured address no package owns | claude-02 H | codex-03 H | `:56-61` reads `'tests' => ['roots' => ['tests','governance']]`; the plan says `surfaces` zero times |
| The two-step plant cannot execute as written                                 | claude-03 H | codex-02 H | the plan's step 1 says "under the old scan scope"; what I ran put the probe **outside** the literal  |
| P3 claims it empties `tests/Unit/RuleVocabulary/`, and cannot                | claude-04 M | codex-07 M | five tests there, P3 moves four                                                                      |
| D3-1's rejected alternative is understated by my own measurement             | claude-07 M | codex-05 M | "three tools of five" in the plan, **four of five** in the table                                     |

Agreement raises priority; it did not decide any of them — each was checked
separately.

## Unique to Codex, and the worst single finding of the round

**codex-01 (HIGH) — the DoD names a command that cannot pass.** Confirmed by
running it. `composer directives:controls` is
`['@directives:controls:coverage', 'php scripts/directive-audit-controls.php']`;
coverage is already red on `main`; Composer halts a chain on failure. The run
ends at

```
Script php scripts/directive-audit-coverage-control.php handling the
directives:controls:coverage event returned with error code 1
```

and the full control is never reached. My DoD required exactly that unreachable
run "in full, once, without `--only`".

**codex-10 (MEDIUM) — my own measurement artifacts contradict each other.**
`registration-addresses.md` C5 asserts CLAUDE.md's exit-0 claim with the words
"I did not need a probe to establish this: it is PHPUnit's documented,
long-standing behavior", while `baseline.md` of the same stage refutes it with
four probes. The conclusion is right for a different case than the one cited.

## Unique to native, and the one that breaks the package cut

**claude-01 (HIGH) — P1 cannot go green without editing a file the plan gives
to P5.** `createIsolatedProject()` in
`tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php:186-196`
copies `tests`, `governance`, `src` and the generated directory into a scratch
root. Registering `Tooling` with a `<directory>` under `tools/` makes that
copy incomplete, and the code's own comment says why it matters:

> PHPUnit exits 2 when a `<testsuite>` names a directory that is not there, so
> every root the tracked configuration declares has to exist here before the
> inventory script can reach its own refusal.

That file is row 16, owned by **P5**. So the package boundary is wrong, and the
comment also **independently corroborates** this stage's exit-2 measurement
against CLAUDE.md — a third witness I did not solicit.

**claude-05 (MEDIUM) — the `tools/` guard list is short by one.** The DoD names
five addresses; the measured set is six.
`governance/TestSuiteHygiene/ScratchPathsCarryRealEntropyTest.php:42` is
`ROOTS = ['tests', 'governance', 'scripts']` — `tools` absent, and CLAUDE.md
grades this address **silent**. `scripts` is already there, so only the `tools/`
movers are exposed.

**claude-06 (MEDIUM) — dead prefixes, none named by path.** Enumerated:
`classifyOwner()` `:667` `tests/TestSupport/ArchitectureStaticAnalysis/`, `:960`
`tests/Unit/RuleVocabulary/`, `:966` `tests/Unit/PromiseEffect/`;
`testSuitePrefixTable()` `:1040`; `systemSupportContents()` `:1505`
`tests/TestSupport`; and the `<directory>tests/TestSupport/ArchitectureStaticAnalysis/Unit</directory>`.
`tests/Reporting/Formatter/Suppressed/Unit` **survives** — `SuppressedFormatterTest`
stays.

**claude-12 (LOW, and sharper than its severity) — the "silent" case is loud on
a fresh clone.** `baseline.md` says a declared directory that exists and holds
no tests is silent, measured locally. Git tracks no empty directory, so after
the move the same state is an *absent* directory in CI — exit 2. The local
green and the CI red are the same tree. It makes removing the stale
`<directory>` mandatory rather than tidy.

## Cut down on checking

- **claude-13** — claimed the `Probes.php` cure is unsafe because a mover shares
  the prefix and lands in a different root. **Refuted for `Probes.php`:**
  `DirectiveAuditControlsSuiteKeyTest` appears there **0** times, and the only
  three pinned classes (16 + 38 + 65 = 119) all land in the *same* root. **Kept
  in the general form:** `Qualimetrix\Tests\Unit\RuleVocabulary\` maps to three
  different destinations across the repository, so no single repo-wide
  find/replace is correct — the hazard is real one level up from where the
  finding put it.
- **codex-11** — claimed my CLAUDE.md exit-0 correction is itself wrong. Refuted:
  measured four ways, and now corroborated by the comment quoted above. Codex
  had already self-refuted this one.
- **codex-12, codex-13** — self-refuted by the reviewer and independently wrong
  (the 119 literals are exactly countable; `RuleVocabulary` holds five tests).

## What the orchestrator found that neither reviewer did

Both were read-only, so neither ran the plant. I did, and it produced two
corrections neither reported: the scan-scope entry must be a **test directory**
(widening to `tools` makes the generator refuse on a *production* rule file
instead of the probe), and `classifyKind()`/`classifyOwner()` need a branch for
the new paths — which the package table does not name. Recorded with the rest.

A third population channel was also run — enumerate the 131 tool files, grep
each exact basename inside `tests/` — the inverse of what both witnesses did.
Same 16. Both reviewers independently failed to find a 17th too.

## Reviewer usefulness

- **native (`claude`)** — deeper on the *package cut* and on cross-file
  consequence: claude-01 is the round's structural finding, and claude-12 turned
  a measured "silent" into a CI-loud. It also reproduced the prediction per
  file and the 9198 baseline in a copied tree, which is the only independent
  check of those numbers.
- **codex** — sharper on *contract coherence*: the unreachable composer chain
  and the self-contradiction between two of my own artifacts are both of the
  form "these two documents cannot both be true", which is what it consistently
  finds best. It also self-refuted three of its own findings, which is worth
  more than three extra findings.
- Neither reached: the executed total 9196 / per-suite rerun, `composer check`
  in full, any package rehearsal, stage 04's map, CI workflow YAML.

## Disposition

All ten confirmed findings are accepted and fixed in the next commit. Grouped by
mechanism rather than patched one by one, because three of them share one cause:
**an address the measurement found and the plan did not carry** (`surfaces()`,
the sixth `tools/` guard, the dead prefixes). That is a transport defect between
`measurement/stage-03/` and the package table, so the fix is a derived table in
the plan rather than three more prose sentences.
