# Stage 5 — acceptance

## The ledger and the grid

Three things this round changes are promises, and the promise ledger says so
before the grid is asked to agree:

- the four `composition-triple` rows whose claim was `DECIDED` ("the middle
  layer's value must survive in the half the top layer did not rewrite") now have
  a product that keeps it;
- the cross-layer `~` cell, which belonged to neither axis and was found by
  reading code, gets a ledger row of its own rather than being cured in silence;
- the three word-set keys move from a reader's refusal to the seam's.

The grid is regenerated once per code package, not once at the end: a commit whose
`verdicts.tsv` is stale reddens `composer check` through
`check:artifacts → promise-effect:grid:check`.

## The floor

The four axis-C rows carry `pending:` from stage 2 onward. No row is removed, and
no row's `verdict` is relaxed to `any-defect` to make a run green — the DoD of
stage 2 is that they stop being defects on the live grid, not that the floor stops
asking.

**The witness question this answers.** The round was asked whether to restore the
floor's positive direction by hand-picking new rows under live defects. It does
not need to: with the sentinel, every cure a round lands after its own snapshot is
automatically a positive witness on the frozen half — a row that must be green on
one half and red on the other. The mechanism replaces the list, and the list would
have gone stale the moment the next round cured something else.

## Blocking axes

`run-declaration.tsv` declares which axes move the exit code. Today: A and D.
C and E were excluded because the round that built them could not also be held to
them. That reason expires here.

- **C becomes blocking.** Its one mechanism is cured; a new defect on it is a
  regression, and the four floor rows are not a substitute for an exit code.
- **E becomes blocking.** It stands at 0 defects after X19. An axis at zero with
  no exit code is an axis nothing protects.
- **B stays non-blocking**, and the declaration's comment is corrected: the reason
  is not a missing substrate, it is that its 4 mechanisms are an undecided
  question about what a top-level shorthand means beside a nested block. The
  citation of `channel-identity-substrate.md` is removed, because that document is
  about something else.

## Documentation

- **ADR.** One, recording: the cure and its direction (ADR 0055's first question,
  closed); the registry kept and its heuristic deleted (the second question,
  closed — ADR 0056 handed it here); the reverse-direction behaviour change with
  its table; the `~` defect as measured, correcting ADR 0056's understatement; the
  floor sentinel and why ancestry was rejected; and the deferrals with their
  prices. ADR 0056 is not edited — it is superseded in the parts this round
  decides and says so.
- **Website**, EN and RU together: the layered-configuration page gains what a
  `threshold` means when a higher layer rewrites half a band, and the three
  option keys get their accepted words.
- **CHANGELOG.** `Changed` for the layering behaviour and for the three keys'
  earlier refusal. Not `Breaking`: nothing that was accepted is refused, and
  nothing that was refused is accepted — a value that reached a compiled default
  now reaches the value that was written for it.
- **Component READMEs** under `src/Analysis/Finding/` for the deleted heuristic
  and the new guards.
- `promise-effect/README.md` for the sentinel.

## The deferrals, written down where the next round will look

`00-overview.md` holds the table. `measurement/deferred.md` holds the banked
enumerations by path — the axis-F positions and population arithmetic, the axis-B
mechanism table, the stand-ten correction (4 rows over 2 bases, not 10 over 6),
and the bool-blindness figure as it actually stands today. Each names what was
measured and what the method does not see.

## Validation, in the order a failure is cheapest to read

1. Per package: the package's own tests, `cs-check`, scoped PHPStan.
2. After each code package: full `composer check`, redirected to a file, exit code
   read off the process.
3. Once, before review: `composer gate -- --reference=1210b037`. Its differences
   are expected and are READ — the layering change and the moved refusal both
   surface there. A `PARTIAL` is not evidence and is not accepted as green.
4. `composer promise-effect` before and after every package, exit codes recorded
   side by side. The round's claim is a movement from 135 defects, and a number
   quoted without its ledger is not a fact about the product.
5. `composer promise-effect:controls` — the floor's own classifier changed, so the
   controls that prove the floor bites are re-run rather than assumed.

## Review

Contract level: native reviewer and `codex`. `codex exec` runs from the repository
root with no `--model`; outside a trusted directory it exits 1 with a message that
reads like a refusal and is not one. Exit 141 with a complete `out.log` is a
SIGPIPE on a correct answer.

Both are asked the open question — "what does this change break that the tests do
not ask about" — rather than handed a long brief, and both are asked one named
question this round owes them: **which layer combination does the unfolding leave
worse than it found it?**

## Definition of Done for the round

- `composer check` green; `composer promise-effect` exits with a defect count
  strictly below 135, and the difference is attributed row by row, not summed.
- The four axis-C floor rows and the 8 DECLARATION rows are repaired as declared.
- No floor row removed, no verdict relaxed, no ledger row weakened to make a run
  green.
- The round's report names, for each deferral, the number that made it a deferral.
