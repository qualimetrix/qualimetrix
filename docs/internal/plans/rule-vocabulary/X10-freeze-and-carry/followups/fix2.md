# X10 fix pass 2 — report

Nine findings from the execution review (`claude-01`, `claude-04`, `claude-05`,
`claude-06`, `claude-07`; `codex-01`, `codex-02`, `codex-03`, `codex-05`). All
nine addressed; none refuted. One hand-back below that is outside the package's
file set.

## The decision the package is built on

Eight of the nine are one mechanism seen from different sides: **the raw carry
path had a private opinion of what a baseline document is, and it disagreed
with `BaselineLoader` and `BaselineWriter` in both directions** — stricter than
the writer where it refused a block the writer silently normalises
(`claude-04`) and a duplicate the carry did not create (`claude-01`), looser
than the loader where it never looked at `generated`/`scope` (`codex-03`).

The decision, stated in the `BaselineChannelRenamer` class docblock and in
`src/Analysis/Policy/Baseline/README.md`, is that **three owners decide what a
carried file is, and this class is only one of them**:

- the **loader** owns what a *document* is — the carry refuses exactly the
  document-level defects it refuses (invalid JSON, a non-object root, a version
  this build does not hold, a missing `entries` object, an unreadable
  `generated` or `scope`) and demotes exactly what it demotes, counting the
  line rather than refusing the file;
- the **writer** owns what a *file* looks like — block shape and line order,
  through the shared `BaselineDocumentLayout` and `BaselineEntryOrder`;
- the **file** owns each *line's bytes* — a payload is echoed in the field
  order it was decoded in, which is the whole point of the raw path. A later
  command that loads and rewrites the file may re-render such a line in place;
  it will not move it.

What is left to the carry alone is the map, and the one collision a carry can
create that no other writer would.

`generated`/`scope` are checked by calling `BaselineLoader::parseGenerated()`
and `::parseScope()` — made `public static` for this — rather than by a second
copy of the rules. A refusal carries the loader's own sentence. This is what
makes "the loader owns it" a fact about the code rather than a promise in a
docblock.

## Finding by finding

**claude-01 — refusal on a collision the carry did not create.** Confirmed and
fixed. `assertNoCarriedCollision()` compared *counts* per identity key, and a
rename moves the key, so a pair already twinned on the old name arrived at the
new one as a key nothing held before. It now groups the lines by their
post-carry key and refuses only where a group has more than one *pre-image* —
i.e. where lines that were distinct before now share a key. A line that formed
no identity before is its own pre-image, not a twin of every other such line.
Two cases: `itCarriesADuplicateThatStandsOnTheRenamedChannel` (the fix) and
`itRefusesWhenADistinctEntryJoinsAnExistingDuplicate` (the control — a fix that
merely stopped refusing on a duplicated key passes the first and fails this).

**claude-04 — refusal on a subject block that is not a JSON array.** Confirmed;
the refusal is removed, under the decision above. The loader demotes such a
block to one inert entry and the writer puts it back as a one-element list, so
the carry now does the same: `BaselineEntryPayload::ofUncarriableBlock()` reads
it as one line with no channel — so nothing inside it is renamed, exactly as
the loader reads no channel there — counted under a new report reason,
`UNREADABLE_BLOCK_NOT_AN_ARRAY`. `itRefusesASubjectBlockThatIsNotAnArray` is
replaced by `itCarriesASubjectBlockThatIsNotAnArray`, which also asserts that a
`load()` + `BaselineWriter::write()` of the carried file is byte-identical —
for an inert line that is the claim itself, not a tautology.

**codex-03 — envelope not checked.** Confirmed; the "deliberate compromise" is
withdrawn rather than reworded, because the compromise bought nothing: the
fields are ones this build knows, and accepting a bad one produced a file the
same build's `check` refuses. Checked through the loader's own methods, in the
loader's order (version → entries → generated → scope). Four provider cases in
`itRefusesAnEnvelopeTheLoaderWouldRefuse`. Note for the reader of the diff:
`itRefusesADocumentWithoutAnEntriesObject` had a fixture with no envelope at
all, which would have started passing on the new branch instead of the one it
names; its fixture now carries a valid `generated`/`scope`.

**codex-02 — numeric envelope field name.** Confirmed and fixed:
`self::encode((string) $key)`, matching the cast the entries loop already did.
`json_decode(..., true)` turns an object key `"0"` into an `int` array key, and
encoding it as it stood spelled a bare `0` where JSON needs a quoted name.
Round-trip case `itQuotesANumericEnvelopeFieldName`.

**codex-01 — partial temp-file write treated as success.** Confirmed; **the
hole is inherited from `main` verbatim, not introduced by this branch** — the
line moved into `BaselineDocumentWriter` unchanged from `BaselineWriter`, which
is why it shows up in the diff. Fixed as
`@file_put_contents(...) !== \strlen($json)`, which covers `false` and a short
write in one branch; the `finally` already removes the temp file, so the target
is untouched either way. **No regression test**: reproducing a short write needs
a filesystem seam this class does not have (a full disk or an exceeded quota),
and inventing one would be a seam for the test's sake. Stated here rather than
skipped silently.

**codex-05 — the report called renamed unreadable entries "unchanged".**
Confirmed. The decision is that the *wording* was wrong, not the behaviour: a
line whose `occurrence` or `edge` is malformed still names a channel, and a
migration that left it behind on a retired name is the one outcome worse than
carrying it. So it is renamed like any other and appears in both counts, and
the CLI now says "carried rather than dropped, unread by this build, because
…". Said in `ChannelRenameReport`'s docblock too, which carried the same false
claim. `itCarriesALineThisBuildCannotReadInsteadOfDroppingIt` grew the case that
makes it bite: a line on the renamed channel *and* with a malformed occurrence,
asserted to be renamed and counted.

**claude-05 — the cross-check was tautological on its fixture.** Confirmed. The
first branch of `fix_direction` is taken: canonising the payload was rejected,
because it is precisely what the raw path exists not to do. So
`itLeavesTheFileTheProductWouldHaveWritten` now claims only what its fixture can
prove — placement across every sorting branch, and byte-preservation of inert
lines — and says why (all nine channels are undeclared, so the writer echoes
them from raw). The half that was missing is a new case,
`itPlacesADeclaredEntryWhereTheWriterWouldWithoutRerenderingIt`, on
`code-smell.goto` and `complexity.cyclomatic`, which the stub registry declares:
it asserts that placement agrees with a load-and-rewrite, that the carried bytes
keep the file's own field order and magnitude list, and — the accepted
divergence stated rather than discovered — that the two files are *not*
byte-identical. Its fixture is deliberately out of canonical order so the
rename has to move a line: otherwise the placement half would pass on a carry
that never sorted. The website sentence that promised more than that is corrected
in both languages.

**claude-06 — `BaselineFormatVersion` missing from the DI exclusions.**
Confirmed and fixed; added next to the three the same commit had added.

**claude-07 — `--format=json` answered refusals in prose.** Confirmed and
fixed. `--format` is now resolved *before* the readable-file checks, so an
unreachable path answers in the chosen format too, and the carry is wrapped so
that a `RuntimeException` under `--format=json` — map refusal, content refusal,
compare-and-swap conflict, failed replace — is reported as `{"error": …}` at
the exit code `BaselineCommand` already gave. **Only the JSON path is new**:
under `text` the exception is rethrown and answered by `BaselineCommand`
exactly as before, which is where a refusal still carries its trace under `-v`.
Anything that is not a `RuntimeException` reaches `BaselineCommand` in both
formats and is labelled a defect of this tool: a bug is not a machine-readable
outcome. Named in `--help` and in both language versions of the docs.
Functional case `itAnswersARefusalInTheChosenFormat` covers a content refusal
and an unreachable file; both halves were also probed by hand against
`bin/qmx` (text `-v` prints the trace, JSON exits 1 with a parseable object).

## Verification

- `php vendor/bin/phpunit tests/Analysis/Policy/Baseline` — **516 tests, 1435
  assertions, green** (29 in `BaselineChannelRenamerTest`, 7 in the functional
  command test).
- `phpstan --memory-limit=1G` over `src/Analysis/Policy/Baseline`,
  `BaselineRenameChannelsCommand.php`, `OutputConfigurator.php`,
  `tests/Analysis/Policy/Baseline` — **no errors**.
- `composer cs-check` — clean for every file this package touched. It exits
  non-zero on `tests/Analysis/Finding/Integration/OccurrenceKindFreezeGuardTest.php`,
  an untracked file from another package that was already in the working tree.
- `composer docs:check` — **exit 0** after the EN/RU edits.
- `composer check` and the finding gate were not run (per the package brief).

## Hand-back: a generated artifact this package may not write

`composer architecture:check` is red, and was red **before** this package
touched anything (on `test-ownership.tsv`, from another package's untracked test
file). This package adds one more staleness: measured against a fresh run of
`scripts/generate-modular-architecture-production-inventory.php` into a
temporary directory, the only difference is

```
-exact_dependency_edges	3766
+exact_dependency_edges	3768
```

in `manifest-enforcement-summary.tsv` — the two new intra-owner references
(`BaselineChannelRenamer` → `BaselineLoader`, and the command's
`RuntimeException`). `qmx.yaml` is byte-identical, and the manifest **policy**
check accepts both edges: the generator exits 0, so nothing here is an
unlisted-import violation, only a count that must be republished.
`docs/internal/generated/**` is outside this package's file set, so the
regeneration is left to the orchestrator.

`CHANGELOG.md` needs nothing: its `baseline:rename-channels` entry describes the
command's purpose and the selector consequence, and names neither the removed
not-an-array refusal nor the format of a refusal — checked rather than assumed.

The working tree also carries a concurrent package's changes this one did not
touch: `docs/internal/plans/rule-vocabulary/enumeration-renames.tsv`,
`scripts/generate-rename-enumeration.php`,
`tests/Analysis/Finding/Unit/OccurrenceKeyTest.php`, and the untracked
`tests/Analysis/Finding/Integration/OccurrenceKindFreezeGuardTest.php` and
`followups/fix3.md`. The `cs-check` failure noted above is in that untracked
file.
