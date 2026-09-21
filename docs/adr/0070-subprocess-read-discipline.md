# 0070. Subprocess Read Discipline

**Date:** 2026-09-20
**Status:** Accepted

## Context

A parent that opens a child with two pipe descriptors and reads them one after
the other — stdout to EOF, then stderr — hangs as soon as the child writes more
than the operating system's pipe buffer (64 KB on both macOS and Linux) to the
stream the parent reads *second*. The child blocks mid-write, so it never exits
and never closes the first stream, so the parent's blocking read of the first
stream never reaches EOF. Both sides then wait forever.

The failure is a hang, not a red. In CI it burns the job timeout and names no
cause, which is strictly worse than failing: a red test says what broke, a hung
job says only that something did. One instance was confirmed in a governance
test whose child wrote ~2.6 MB to stderr in a single `fwrite()` when handed a
schema-invalid manifest, and it was fixed in place. A sweep of every
`proc_open` in tracked PHP afterwards found thirty call sites spread over four
roots: repository controls, development tooling under `scripts/`, the test
suite, and one production file.

It also found that the discipline had *already been implemented correctly three
times*, independently, by parties that could not share it. That is a
measurement rather than an argument, and it is the counterfactual-ownership
test passing: "read everything a child writes without deadlocking on a buffer"
has its own semantics (buffer capacity, EOF, non-blocking reads) and its own
lifecycle — it changes when the read discipline changes, never when a caller's
purpose changes.

### What was measured

Recorded here so a future reader does not have to re-measure any of it. Taken
on macOS 25.6.0 with this repository's PHP, 2026-09-20.

| Shape                                                                                                          | Result                                                                                                                                                                                                                  |
| -------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Two read pipes, read sequentially, child floods the second                                                     | **deadlock** — reproduced three times plus a three-line standalone repro                                                                                                                                                |
| One read pipe, never read, then `proc_close()`, child floods it                                                | **no deadlock** — the child died with `errno=32 Broken pipe` and the parent returned exit 255 at once, because `proc_close()` closes the pipes it created *before* waiting, so the child gets EPIPE instead of blocking |
| One **write** (stdin) pipe, parent writes 1 MB, child never reads stdin                                        | **deadlock** — the parent was still blocked in `fwrite()` at 5 s and had to be SIGKILLed (exit 137)                                                                                                                     |
| `[1 => ['pipe','w'], 2 => ['pty']]`, child floods the pty, parent reads the stdout pipe to EOF                 | **deadlock** — parent still blocked at 5 s, SIGKILLed (exit 137). The child filled the pty master's kernel buffer and blocked mid-write, so it never closed stdout                                                      |
| `popen($cmd, 'w')` — one stream, write direction — parent writes 1 MB, child never reads stdin                 | **deadlock** — after the first 64 KB the parent sat inside `fwrite()` for the child's entire 30 s life, released only by the child's exit turning the write into `EPIPE`. A child that never exits blocks it forever    |
| `run(['/bin/sh','-c','( sleep 6 ) & echo parent-done'])` — child exits at once, grandchild inherits its stdout | **returns at 6.01 s**, not at the child's exit: `run()` waits for pipe EOF, and the grandchild holds the descriptor open                                                                                                |
| `run([…,'bin/qmx','check','src/','--workers=4'])` — the product's own parallel workers, 973 files              | **11.5 s**, 270 KB on stdout, and stdout reaches EOF in the same 20 ms window the child is reaped: amphp's workers do not hold the parent's stdout, so the row above reaches no caller in this tree                     |

That last row was re-measured on 2026-09-21, because the first attempt measured
nothing it claimed: it ran `src/Core`, 29 files, and the parallel strategy falls
back to sequential below a hundred, so no worker ever started and the 0.70 s it
recorded was a sequential run. `--workers=4` picks the strategy and the worker
count; it does not lower that floor. The row now names a tree above it, and
both halves of its claim are witnessed rather than assumed. The branch: stderr
under `-vv` carried `starting parallel processing` with
`{"files_count":973,"workers":4}` and no `file count below threshold`, and four
`amphp/parallel` worker processes were visible mid-run against none before or
after. The timing: the same command with no pipes at all — stdout and stderr
straight to `/dev/null`, where nothing *can* be held open — returned in
11.475 s, which is the pipe run's own figure. And the exit-versus-EOF instants
come from a probe that reports `+2.980 s` on the shape in the row above cut to
`sleep 3`, so a held pipe is something it can see.

Three consequences follow, and each killed a mechanism that had looked
reasonable:

- **The single-pipe deadlock is in the write direction, not the read
  direction.** "At most one pipe cannot deadlock" is unsound as a rule, but not
  for the reason first proposed: an undrained single stdout pipe does *not*
  hang. Stdin does. A rule that counted pipes without distinguishing direction
  would have been wrong for a reason nobody had measured.
- **A pty is not a pipe and deadlocks anyway.** Any rule that counts `['pipe'`
  occurrences is therefore unsound: `[1 => ['pipe','w'], 2 => ['pty']]` has one
  such occurrence and two blocking streams. "Blocking stream" means `pipe`,
  `pty` or `socket`; `file`, a passthrough constant, and no descriptor at all
  are safe.
- **A token-level detector must not exempt anything.** `\proc_open(` tokenizes
  as a single `T_NAME_FULLY_QUALIFIED`, *not* as `T_STRING` followed by `(`, so
  a gate keyed on the latter would not see the spelling this repository's house
  style actually uses (`\sprintf(` occurs 1,716 times in tracked PHP). And a
  token scan finds 29 calls where the hand enumeration found 30 sites: the
  missing one sits inside a nowdoc handed to a spawned `php -r`, a string
  literal in one file and a real call in the child. A detector that exempted
  literals would have exempted precisely the live one.

## Decision

### The read discipline is a subject, and it lives outside `src/`

`Qualimetrix\Subprocess\ChildProcess`, one file at
`scripts/subprocess/ChildProcess.php`, is the repository's one way to start a
child process and capture everything it writes. Its API is not restated here;
it rots faster than the code it would describe.

Three constraints picked that home, and the third is the binding one:

1. **Not `src/`.** That is the product's PSR-4 root and ships in the composer
   dist package. This is tooling; the product does not run it.
2. **Not a new top-level root.** A new root owes a long table of registration
   addresses, most of which fail silently when missed. `scripts/` is an
   existing root already declared to static analysis, the style fixer, the
   pre-commit path filter and the dist export rules, and it already houses
   library-shaped directories.
3. **It must work without `vendor/`.** Named by consumer rather than counted:
   five callers of the module load no `vendor/autoload.php` at all —
   `generate-suppression-snapshot.php`, `generate-modular-architecture.php`,
   `generate-modular-architecture-test-inventory.php`,
   `benchmark-regression.php` and `collect-benchmark-data.php`. Anything
   reachable only through Composer's autoloader therefore cannot be the
   repository's one safe way, and one such consumer is enough to settle it.
   (Sized rather than named, this set has been wrong twice: there are three
   modular-architecture generators, not two, and the third —
   `generate-modular-architecture-production-inventory.php` — does load
   `vendor/`.)

Constraint 3 is also what rules out the otherwise obvious candidate,
`Symfony\Component\Process`: it drains correctly and is already on disk, but it
is unavailable to those callers — and it is a dev-only transitive dependency
here, not a production one.

The same constraint decides the failure shape: the module throws the built-in
`\RuntimeException` rather than a named exception class. PSR-4 would put a named
class in a second file, which the vendor-less callers have no autoloader to
find, and it would then be missing exactly on the error path, where nothing
exercises it. A non-zero exit code is not a failure; it is returned in the
result.

### "One safe way" scopes to flat capture

Four independent read loops survive this decision, and that is deliberate. The
module owns *flat capture*: start a child, feed it stdin, drain both streams,
wait, return. It does not own supervision — process groups, deadlines,
descendant kills — and it has no timeout parameter.

The reason is **not** that every caller needing a bounded wait already belongs
to a layer that owns one; an earlier draft said so and it is false. No
flat-capture caller is bounded by anything. `CorpusCaseRun`,
`DuplicationMemoryLimitProcessTest` and the governance controls run under
PHPUnit, which this repository configures without `enforceTimeLimit`, so
nothing bounds them short of the CI job timeout; `benchmark-regression.php`,
`collect-benchmark-data.php` and `generate-suppression-snapshot.php` are
standalone scripts bounded by nothing at all. The reason is that none of them
*needs* a deadline — their children are the product and `git`, which terminate
on their own — and that adding one would owe a termination protocol (which
signal, whether descriptors close, whether `proc_close()` runs) that nothing in
the tree would exercise. The callers that genuinely need a bounded wait keep
their own, and they are the supervision families named below.

**What that leaves open, named rather than covered.** `run()` returns when both
pipes reach EOF, not when the child exits, so a *descendant* that inherits the
child's stdout holds the call open after the child is gone. Measured:
`['/bin/sh', '-c', '( sleep 6 ) & echo parent-done']` returned at 6.01 s rather
than at the shell's own immediate exit, and a descendant that never exits would
hold it forever. Nothing in this tree touches that: measured separately, a
`bin/qmx check src/ --workers=4` through `run()` — 973 files, four worker
processes seen running — returned with stdout at EOF in the same 20 ms window
the child was reaped, so amphp's workers do not hold the parent's stdout. A
caller that backgrounds a long-running descendant needs supervision, not this
module.

Two families therefore keep their own implementations:

- The finding-gate's process handle and its controls' shell layer a *different*
  subject on the read discipline: process-group isolation via `posix_setsid`,
  `pgrep`-based descendant termination, launcher-disappearance detection and a
  bounded parallel scheduler. Their behaviour is what `composer gate:controls`
  measures; folding them changes what those measurements cover.
- The console tests' pseudo-terminal runner reads pty masters, which report EIO
  where pipes report EOF. That is a different read discipline, not a caller of
  this one.

**The condition that would fold them:** a fourth family needing supervision
rather than plain capture. At that point the supervision layer has two
independent consumers and becomes its own subject. Until then, extracting it
would bind the gate's lifecycle to a second consumer for no measured gain. This
exception is written down because an unnamed exception is read by the next
person as a settled answer, and the next person's instinct will be to move
supervision into the module.

### The governance control counts nothing

`governance/SubprocessDrain/` refuses every occurrence of `proc_open` or
`popen` in a PHP file the repository ships or runs, unless the occurrence is
inside the module or carries a declared entry anchored at its line and giving
its reason.

Those two names and not the other five. `exec`, `shell_exec`, `system`,
`passthru` and backticks hand the caller no live stream at all — PHP drains the
child's output itself or passes it straight through — so the shape cannot
occur. `popen` hands over exactly one stream and its mode decides the
direction, which is what the earlier reasoning missed: "at most one stream,
therefore safe" is the same unsound rule the measurements above already killed
for pipes. Opened for writing, `popen` carries this defect in full (see the
table). The gate does not read the mode argument, for the same reason it parses
no descriptor spec; both names are refused outright, which today costs four
entries for the command-injection rule's data and fixtures and no migration at
all, since the tree holds no live `popen` call.

The rule deliberately parses no descriptor spec and counts nothing, because
every counting mechanism proposed was refuted by the measurements above, and
because counting is not fail-closed: a shape nobody has thought of yet is
refused by default rather than permitted by an argument nobody has checked. The
decision is a textual substring match, folded to lower case because PHP
resolves function names without regard to case and a case-sensitive gate would
have been one spelling away from blind; the tokenizer excuses exactly one kind
of occurrence — one inside a comment, because a docblock cannot execute — and
otherwise only labels the refusal. The fold is `strtolower`, not
`mb_strtolower`: offsets found in the lowercased copy are read back out of the
original, so the fold has to preserve byte length.

Entries are anchored at `file:line`, not at the file, so a second call added
tomorrow to an already-entered file is refused by default. An entry whose line
stops matching is refused as stale, so the list cannot decay into permission
for whatever moves into that path later.

### Why `file:line` and not something that survives an unrelated edit

The line anchor has a standing price, and it was re-opened on the strength of
it. Measured rather than recalled: replaying every commit since 2026-06-01 that
touched a file holding an entry gives 40 commit-to-commit transitions, of which
**nine moved an occurrence without changing the text of its line** and **none
changed that text**. Each of the nine reddens the group for a change that
touches no read discipline. One was paid twice over, though it broke only once:
`10978986` put a seven-line comment above the second `proc_open` in
`PseudoTerminalRun.php`, and two concurrent sessions each re-took the same
`78 → 85` within half an hour (`6ac2b6d1`, `9a85d431`) — the bill was doubled by
the visibility of the red, not by the anchor. The tally is a floor taken at one
point in time and is not maintained.

Four cheaper forms were weighed by the question the control is judged on — what
does each stop refusing? The cost column is over the same window.

| Anchor form                                  | Re-anchorings     | What it stops refusing                                                                                                                                                                                                                                                                                                                                                |
| -------------------------------------------- | ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `file:line` — chosen                         | 9                 | Nothing beyond what the control already names: two occurrences sharing one line collapse to one key, and the shape on an entered line is unverified.                                                                                                                                                                                                                  |
| Enclosing function or symbol                 | fewer, unmeasured | A second spawn inside an already-entered symbol. Present in the tree, not hypothetical: eight of the twenty-three occurrences outside the module sit four-to-a-function in two data providers, so the six reviewed entries covering them become two. Two more sit in a class constant array with no enclosing function to name, which the form cannot express at all. |
| Normalized line content                      | 0                 | Two occurrences in one file whose lines read alike — one entry then permits both. No such pair exists today and the tree is one character away from one: `PseudoTerminalRun.php` carries `$process = @proc_open(` and `$process = proc_open(`. It is also silent on a swap, since an identical line written elsewhere in the file inherits the entry.                 |
| `file` plus an occurrence count              | 0                 | The pairing between a reason and the call it describes. The reasons differ materially per call — `ProcessHandle.php` has a live handle on one line and a nowdoc literal on another — and a delete-plus-add that keeps the count is silent.                                                                                                                            |
| A generator re-anchors, the diff is reviewed | 0                 | The refusal itself. Nothing textual distinguishes "this call moved" from "this call was replaced", so on a swap the tool carries a reviewed reason onto a different call and the build stays green. That converts a red into a rewrite, which is the inert-suppression shape with a step added.                                                                       |

The four rejections are one finding rather than four: **an anchor's churn and
its specificity are the same property.** A form survives an unrelated edit
exactly when it identifies an occurrence by something a *different* occurrence
can also carry — and that is precisely what lets one reviewed entry come to
permit two. A hybrid keyed on content and disambiguated by line where the
content repeats was considered and folds back into the line anchor on the only
case where the two differ. The price is therefore accepted rather than reduced,
and it is written into the control so that re-opening the question costs a fresh
measurement instead of a fresh argument.

What the form buys was re-confirmed on an isolated copy of the tree — its own
`git init`, its own re-dumped autoloader, and each mutation read back out of the
file before the run, because a stand that silently resolves back into the source
tree has already produced false greens in this campaign. Four plantings, four
reds: an undeclared spawn in a new untracked file; a second spawn appended to an
already-entered file, refused by name and line; an entered call replaced by
`ChildProcess::run()`, refused as a stale entry; and an entry moved one line off
its occurrence, refused twice over — stale at the declared line and undeclared at
the real one.

## Consequences

- **A new subprocess call cannot enter the tree in silence.** Every one is
  either the module or a line a reviewer agreed to.
- **What this does not guarantee:** the control does not verify the read
  discipline *inside* an entered line. A two-pipe sequential read filed with a
  plausible reason would pass, and so would a descriptor change on a line that
  already has an entry. The shape is made undeclarable in silence, not
  mechanically impossible; what bounds the residual is that entries are few,
  line-anchored and reviewed.
- **One named gap.** A dynamically assembled function name is invisible to a
  textual gate (the tree has none today, and this was swept for), and no
  textual gate can close it: the deciding text does not exist until run time.
  The differently-cased spelling that was named beside it is closed — both
  controls in the group fold case. PHP resolves function, class and method
  names without regard to case, so a needle that did not was one spelling away
  from blind. Each control carries its own standing case over text it writes
  itself, for reasons that differ by control. The caller scan has no witness in
  the tree at all — every call is spelled in the class's own casing, so a
  reverted fold there answers exactly as before and the group stays green. The
  occurrence scan does have one, a camelCase seam the fold newly matches, but
  it sits in a test about something else and a rename would carry it away.
  Both were also confirmed once by planting `Proc_Open(` and
  `childprocess::Run(` in a scratch repository — the occurrence scan refuses by
  file and line, the caller scan by file — and the standing cases are what keep
  that true afterwards.

  The match itself is one class both controls read the tree through, beside the
  population they already shared, and for the same reason that one is shared:
  two copies agree today and drift apart on the next change to either. That was
  not hypothetical — the copies were byte-identical when this fold landed, and
  the follow-up fix had to be found twice, once per copy, because repairing one
  said nothing about the other.

  The group's own support classes are required by path too, not only the
  module. The reason is the module's reason unchanged — a scratch project
  symlinks `vendor/`, so an autoloaded class resolves back into the source tree
  and a mutated copy is never read — and it started to apply the moment this
  mechanism left the control files, which PHPUnit loads by path regardless.
  Measured on such a stand in both directions.

  Each control keeps its own case anyway, and those cases measure behaviour
  rather than delegation: a copy of the scan pasted back into a control
  satisfies them, measured. Staying at one scan is therefore its own control,
  which refuses any file in the group that folds a file's case or finds the
  token at an offset for itself. It fences the two forms that were actually
  duplicated rather than proving no third is possible, and it names its
  exemption — the scan — by file rather than by shape.

  Those cases pin the fold to `strtolower` rather than to lowercasing in
  general. Offsets are found in the folded copy and read back out of the
  original, so a fold that does not preserve byte length reads the wrong bytes.
  Each case therefore *asserts the bytes it read*, with a codepoint
  `mb_strtolower` shortens placed ahead of them. Asserting an answer instead of
  the bytes was tried and is worse: a shifted offset still lands on some token,
  so whether the answer flips depends on how far the next token boundary
  happens to be — measured, that form needed five copies of the codepoint to
  speak at all, four were silent, and one space added to the fixture would have
  silenced five.

  Folding added exactly one occurrence to the tree and none to the caller set.
  That occurrence is not a call: a test method name whose camelCase seam spells
  the single-stream spawner across two words. It is declared like any other.
  The fold is witnessed directly, by a case the control runs over text it
  writes itself, rather than only by that seam — a seam inside a test about
  something else is evidence a rename can carry away.

  The seam is also the fold's standing cost, and it was not reduced: the byte
  before a match is not read, so any identifier spelling the same seam needs an
  entry. Reading it would narrow a fail-closed gate on an argument, which is
  the move this control has refused twice; the direct case above is what turns
  red if a later change makes it anyway.
- **The module is loaded two ways on purpose**, and both are load-bearing: the
  namespace is declared in `autoload-dev` so that the ban on production code
  importing development namespaces can see it at all, and every caller also
  `require_once`s the file by path so that isolated scratch-project controls
  execute their own copy rather than resolving through a symlinked `vendor/` to
  this tree. Adding a second class beside it would make that class autoloadable
  by declaration but not isolation-safe; the declaration makes the second
  `require_once` possible to forget, not automatic.

  That second half is now a control rather than a convention, in the same
  governance group: every file calling `ChildProcess::run(` outside a comment
  must carry a `require_once` whose expression *resolves* to the module — the
  resolution is computed from `__DIR__` or `dirname(__DIR__, N)`, and a form the
  resolver cannot read is refused rather than passed. It exists because the
  convention had already drifted: four callers had stopped carrying the
  `require_once` while this document and two others still said every caller did.
  An aliased import or a variable class name is invisible to it, and neither
  spelling exists in the tree. A differently-cased one is not invisible: that
  scan folds case too, which costs nothing — it adds no caller to the tree, so
  the fold is measured on text the control writes itself rather than on a
  spelling the tree supplies. The comment exemption is unchanged and now
  reaches a docblock in any casing, which widens what it could excuse rather
  than what it excuses today.
- **One production behaviour changed.** The git repository locator no longer
  opens descriptors it never reads, so a `git`-scoped run fails instead of
  hanging when git is unusually talkative on stderr. Production code may not
  import a development namespace, so that site removes the hazard by
  construction rather than by using the module.

## Related

- [0016 — Subject Cohesion](0016-subject-cohesion.md) — a directory is a
  subject, not a role; the counterfactual-ownership test applied above.
- [0022 — Capability-Oriented Modular Monolith](0022-capability-oriented-modular-monolith.md) —
  why tooling lives outside the product's PSR-4 root.
