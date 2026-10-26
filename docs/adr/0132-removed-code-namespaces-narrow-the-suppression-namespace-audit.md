# 0132. Removed Code's Namespaces Narrow the Suppression Namespace Audit

**Date:** 2026-10-26
**Status:** Accepted

Supplements [ADR 0093](0093-measured-run-scope-and-project-tree-queries.md):
its rule that the project-scope audit "opens no source" now has one bounded
exception, the `namespace` statements of removed code. The two measured
questions, the other doors and the report states keep their ADR 0093 meaning.

## Context

An authored `exclude` that removes any PHP file, or any file skipped as
`@generated`, made `judgesNamespaceClaims()` false. Every namespace-absence
channel was then withheld: the removed file could have declared any namespace,
so absence of one could not be proved. The reason is sound; its reach was not.
One excluded `Kernel.php` switched off the audit of every `suppress_namespaces`
value in the run, so a stale `exact:App\Does\Not\Exist` went unreported while
`projectScope.state` still read `covered`. The only trace was the
`unjudgedChannels` array and a summary line.

Which namespaces the removed code declares is cheap to read without analysing it.
For two of the eight withheld channels, `suppression.unmatched-namespace` and the
namespace values of `suppression.unmatched-rule-ledger`, absence of a literal
selector follows exactly from that set. The other six need more than namespaces:
class FQNs, method names, references, attributes, ancestors.

## Decision

1. **Only literal namespace suppression selectors narrow.** An `exact` or
   `subtree` namespace value, global or under `rules.<name>`, is judged when the
   removed code cannot have declared a namespace it binds. `judgesNamespaceClaims()`
   is unchanged and stays strict for its other readers; Finding answers for these
   values with two narrower questions, one per selector form
   (`ProjectScopeJudgement::judgesExactNamespace()` and `judgesNamespaceSubtree()`).
   A value is consulted against the removed code's namespaces whenever one exists,
   even with no withholding door open: an exclude entry outside the measured
   universe (autoload-dev `tests/` on `check src tests`) opens no door yet may
   declare a namespace the value names.
2. **`regex` selectors are unchanged.** Case semantics of a regex over a removed
   namespace are ambiguous, so a stale regex stays withheld under removal, and
   removal outside the universe does not withhold it.
3. **Only two doors narrow:** `Exclude` with `php-file` evidence, and
   `Generated`. Omitted PHP paths, an unknown universe, an unlistable excluded
   directory, a missing PSR-4 map and unmeasured scope withhold as before. A file
   that failed analysis leaves every namespace value, literal or regex, unjudged:
   the namespace universe is incomplete.
4. **Run measures, Finding judges.** Discovery reads the removed code once per walk
   into one `RemovedNamespaceSurvey` and carries its `RemovedCodeNamespaces`
   (`none`, `known`, `unknown`) in the scope facts into `ProjectScopeMeasurement`.
   Anything unreadable, or any spent limit, makes it `unknown`, which withholds
   every literal as before. The selector ledger and `ExcludeSelectorVerdict` carry
   no namespaces and are unchanged.
5. **The scan never builds an AST.** A file up to 64 KiB is tokenized: block and
   semicolon namespaces, several per file, the global block, `namespace\foo()`
   not counted, comments and strings ignored. A larger file is read as text in
   64 KiB chunks and every `namespace` word followed by a name or `{` counts. That
   is a superset of the real declarations, so it errs toward withholding; a name
   that does not end within 1 KiB makes the namespaces unknown. A declaration-shaped
   statement the scan does not recognise makes the file unreadable, hence unknown.
   Comparison folds ASCII case in the withholding comparison only (the binding of
   the configured value stays case-sensitive), and the ancestors of a declared
   namespace count as declared, so a file in `App\Foo\Bar` also withholds
   `exact:App\Foo`. Global code declares the empty namespace.
6. **Descent is complete, and what it cannot read it does not trust.** The early
   exit at the first PHP file of a removed entry is gone, and so is the second
   short circuit that skipped further entries of a selector that already had
   `php-file` evidence: the survey needs every removed entry. An unlistable child
   makes the namespaces unknown even after a PHP file was found. Inside a removed
   directory a directory link, or a non-regular `.php` entry (file link, dangling
   link, special file), also makes them unknown, because the main walk would record
   that entry as skipped coverage; other non-regular entries are ignored, as the
   main walk ignores them. An excluded named file symlink is unknown too.
   `@generated` files get a separate full read, because the generated-file filter
   reads only a 2,048-byte header. Removed run entries outside the measured universe
   are surveyed for namespaces only; their selector verdicts do not change.
7. **Three limits bound the cost.** One survey per walk allows 2,000 file reads,
   32 MiB of bytes read and 20,000 entries inspected inside removed directories,
   shared by excluded and generated files. When any is spent the namespaces are
   unknown and the descent returns to the old cost profile (stop at the first PHP
   file; no reads outside the universe). A large excluded data or fixture tree
   inside a PSR-4 root would otherwise be read in full. (`vendor` is a built-in
   floor and never part of a run, so it is not that case.)
8. **Publication follows judgement.** `ReportProjectScope::measured()` omits
   `suppression.unmatched-namespace` from `unjudgedChannels` when at least one of
   its values was judged. The directive audit computes no values and still names
   the channel. The other six namespace-claim channels (`architecture.*`,
   `cohesion.unmatched-exclude-method`, `coupling.unmatched-framework-namespace`)
   stay all-or-nothing.
9. **`check` says what remained, and only what removed code alone caused.** The
   audit, not the presenter, decides which values removed code alone kept from
   being judged: literal values that would have been judged had the removed code
   declared nothing. Regex values, values from an unanalysed PSR-4 root, runs
   without production autoload or a namespace tree, runs with analysis failures and
   exclusions of unlistable directories are never counted. `check` writes a plain
   stderr warning at report publication naming every exclude selector that removed
   an entry (plus generated-file filtering, or "an exclude entry" when none is
   recorded), the count of unjudged values and their channels; `-q` silences it.
   `directives` and `baseline:*` print none. The `exclude` reason stays in
   `projectScope.reasons`.

Rejected:

- **Lazy scan on demand.** It needs file IO inside a Finding contract type and
  breaks "Run measures, Finding judges".
- **Scanning FQNs, methods and references to narrow the other six channels.**
  That is a second analyser without an AST.
- **Reading removed code without limits.** An excluded tree inside the universe
  would be read and tokenized in full; the first version, with a read count only,
  still ran out of memory on a single 2 MB excluded file or a 10 MB `@generated`
  file, which is why large files take the chunked text path.

## Consequences

- A stale literal namespace selector is reported although unrelated files are
  excluded; a selector the removed code could have declared stays withheld and
  is named.
- Regex selectors and six channels keep the old all-or-nothing behaviour; the
  website names this as a limitation. The warning is a `check` surface only, and
  of the commands that publish scope only `directives` also prints a
  `projectScope` object.
- `unjudgedChannels` no longer proves that every value of an absent channel was
  judged; that was already true of value channels (ADR 0093) and now holds for
  `suppression.unmatched-namespace`. `unjudgedValues` stays the complete list.
- Project scope reads removed source beyond the generated-file header, bounded by
  the limits. Reading is best-effort: every failure degrades to the previous
  withholding, never to a judgement.
- The text path's superset can withhold a value that a comment or string
  containing `namespace Foo` only appeared to declare.

Measured cost, median of 7 runs of `ProjectFiles::discover` on synthetic
projects (read limit only, before the byte and entry limits):

| Project                                                         | Before     | After      |
| --------------------------------------------------------------- | ---------- | ---------- |
| 6,500 files, 3,000 excluded and 3,000 `@generated` (over limit) | 361-383 ms | 629-649 ms |
| 2,000 files, 1,500 excluded (under limit)                       | 39-50 ms   | 253-303 ms |

A read costs roughly 130-170 us, so the read limit bounds the addition at about
2,000 reads (+250-300 ms). After the memory fix, end to end at `memory_limit=128M`:
a 2 MB excluded file and a 10 MB `@generated` file both exit 0 at 64 MB RSS (before:
out of memory, exit 4). The text path takes 0.5 ms for 2 MB and 1.8 ms for 10 MB;
an adversarial 9 MB file with 500,000 `namespace` words takes 800 ms. With 50,000
assets in an excluded directory discovery takes 0.41-0.45 s against 0.33 s without
them (0.63-0.66 s before the entry limit).
