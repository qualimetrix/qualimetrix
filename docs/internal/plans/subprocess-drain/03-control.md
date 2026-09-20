# Stage 03 — making the shape unrepeatable

## Why a control and not just the migration

Stage 01's test proves the **module**. Stage 02 fixes the **current** call sites. Neither
stops the thirty-first call site from being written next month with the same sequential
read — and the enumeration that found these was hand-driven, which is precisely the kind
of evidence that does not survive into the future.

Without this stage the plan closes an instance, not a class.

## The control

`governance/SubprocessDrain/SubprocessReadsAreDrainedConcurrentlyTest.php`, over every PHP
file the repository would ship or run.

### The rule counts nothing

An earlier draft judged **how many `['pipe'` entries a descriptor spec declares**. Review
killed that mechanism, and three independent counterexamples say it was not repairable:

- `array_fill_keys([1, 2], ['pipe', 'w'])` — one occurrence in the text, two pipes at runtime.
- `["pipe", "w"]` — double quotes, zero substring matches; nothing in the style config
  forbids them.
- `[1 => ['pipe','w'], 2 => ['pty']]` — one `['pipe'`, two blocking streams. **Measured on
  this platform**: an unread pty blocked the child exactly as a pipe would, parent still
  stuck at 5 s. A pty is not a pipe and deadlocks anyway.

So the rule counts nothing and parses no descriptor spec:

> **Every occurrence of `proc_open` or `popen` in a tracked PHP file must be either inside
> the module, or carry a declared entry naming it and giving its reason.**

Everything else is refused. This is the task's own framing — one way to run a subprocess,
everything else a declared exception — and it is fail-closed: a shape nobody has thought of
yet is refused by default rather than permitted by an argument nobody has checked.

**What this does and does not guarantee.** It guarantees that no `proc_open` enters the tree
unnoticed: every one is either the module or a line a reviewer agreed to. It does **not**
verify the read discipline inside an entered site — a two-pipe sequential read filed with a
plausible reason would pass. The control makes the shape *undeclarable in silence*, not
mechanically impossible. That is a real reduction and a smaller claim than "unrepeatable";
the residual is bounded by the entries being few, line-anchored and reviewed.

### Two names, and why not the other five

The needles are `proc_open` and `popen`. `exec`, `shell_exec`, `system`, `passthru` and
backticks are deliberately absent, and the line between them is the **direction** of the
stream the caller is left holding, not how many streams there are. Those five hand the
caller no live stream at all — PHP drains the child's output itself or passes it straight
through — so the shape cannot occur. `popen` hands over exactly one, and its mode decides
the direction: opened for reading, an unread stream is survivable (closing it gives the
child `EPIPE`); opened for **writing** it is the stdin row of `measurements.md` in full.
Measured on this platform: a parent that wrote 1 MB to a child that never reads stdin sat
blocked inside `fwrite()` for the child's entire 30 s life, released only by the child's
exit turning the write into `EPIPE`.

The gate does not read the mode argument, for the same reason it parses no descriptor spec —
the deciding text can be a variable, a constant or a concatenation. Both names are refused
outright and a site that genuinely needs one takes an entry. Today that costs four more
entries and no migration: the tree holds seven `popen` occurrences outside the control, two
in doc comments and five string literals in the command-injection rule's data and fixtures,
and no live call at all.

### Entries are anchored at `file:line`, not at the file

A file-level entry would let a *second*, defective `proc_open` be added tomorrow to an
already-entered file and pass — `SelfTest.php` is ~3,000 lines and already carries two
occurrences. The staleness check would stay green too, since some match in the file still
exists. Anchoring at `file:line` with the enclosing function's name makes a new call in an
entered file refuse by default.

### The gate is textual; the tokenizer only finds comments

**The gate decision is a case-sensitive substring match on `proc_open`.** Not a parse, not a
token type. Every occurrence in a file needs the module or an entry, and the tokenizer is
used for exactly one thing: deciding which occurrences lie inside a comment token, because a
docblock cannot execute.

This matters more than it sounds, and two measurements say why.

*A token-based gate would leak.* `\proc_open(...)` tokenizes as a single
`T_NAME_FULLY_QUALIFIED`, **not** `T_STRING` followed by `(` — measured on this PHP. A gate
built around "`T_STRING` `proc_open` followed by `(`" would not see it at all. And the
leading backslash is not a hypothetical spelling here: it is the house style for global
functions, with 1,716 occurrences of `\sprintf(` and 700 of `\count(` in tracked PHP. A
future `\proc_open(` is the likely spelling, not the exotic one.

*Exempting literals would leak too.* A token scan finds 29 calls where both enumerations
found 30 sites. The missing one is `scripts/finding-gate/ProcessHandle.php:202` — a
`proc_open` inside a nowdoc handed to a spawned `php -r`. A string literal in this file; a
real call in the child. A detector that exempted literals would have exempted precisely the
live one.

So literals are not exempt, which costs six entries for the security rules' data and
fixtures and buys the guarantee that embedded source cannot hide. Token classification still
enriches the refusal message — call, embedded source, or documentation — it just never
decides who is excused.

### Staleness is a refusal

An entry whose `file:line` no longer carries a matching occurrence must redden. Otherwise the
list decays into permission for whatever moves into that path later — the inert-suppression
shape, where a suppression outlives its subject.

### The file set, stated positively

Every PHP file the repository would ship or run, **including files not yet in the index** and
**files without a `.php` extension** — `bin/qmx` is PHP behind a shebang, and a `'*.php'`
filter would not see it.

`git ls-files` alone would make planted breakage 1 vacuous: a **new** file is not in the
index, so the control would not see it and the probe would pass by construction — a check
that cannot fail. Use the flags `--cached --others --exclude-standard`.

Take the flags from `generate-modular-architecture-test-inventory.php` but **not its
pathspec**: that call restricts itself to `tests`, `scripts/tests` and the
`TOOLING_TEST_ROOT_OWNERS` keys, and `src/` is not among them. Copied literally it would
blind the control to `src/Infrastructure/Git/GitRepositoryLocator.php` — the single
production row, and the one whose disposition is forced by the import ban. That is a silent
failure by `CLAUDE.md`'s own table.

### What an entry permits

An entry is permission for a **line**, not for a shape. The descriptor spec and read
discipline on an entered line can change without the control noticing: the occurrence is
still there, the entry still matches, staleness stays green. For row 24 that is a concrete
regression path — today's disposition 3 removes the extra descriptors, and tomorrow's
`2 => ['pipe','w']` on the same line is invisible.

This is accepted, not engineered away: verifying the shape means parsing descriptor specs,
which is the mechanism round 1 correctly killed. What holds the line instead is that entries
are few, line-anchored and reviewed, and that the module is where every non-specialized
caller has been moved.

The rule also sees only the literal spelling. `$f = 'proc_' . 'open'; $f(…)` is invisible to
it. The enumeration swept for dynamic names and found none *today*; the control is about
tomorrow, and this is a named gap, not a covered one.

### Where the entries live

In the control's own source, as a declared constant — not as a fixture file. A fixture
containing `proc_open` would fall under the control and need an entry for itself, and it
would owe the two silently-failing fixture addresses (`.gitignore` negation and
`phpstan.neon` `excludePaths`).

### Proving it bites

Plant one at a time in a temporary tree from `mktemp -d`. That tree must be a **git
repository with at least one commit**: the `--cached` flag needs an index, and without one
probes 2–4 redden for the wrong reason — everything at once rather than the planted subject.
Do not plant under `governance/SubprocessDrain/`, which would make the probe circular.

Each of these must redden the control by name:

1. a new, untracked file containing `proc_open`, with no entry — refused (this is what
   `--others` exists for);
2. an existing entry removed while its call stays — refused, proving entries are load-bearing;
3. an entry whose `file:line` no longer matches — refused as stale;
4. a **second** `proc_open` added to a file that already has an entry — refused, proving the
   anchor is the line and not the file;
5. `proc_open` inside a nowdoc in a new file — refused, proving literals are not exempt;
6. `\proc_open(` — the fully-qualified spelling, refused, proving the gate is textual rather
   than token-typed. This is the house style for global functions here;
7. the same six for `popen`, of which three are not redundant: a live `popen(` in a new file,
   the name inside a string literal in a new file, and `\popen(` — each refused, and the
   third proving the second needle is textual too;
8. one `popen` entry removed while its literal stays — refused, proving the new entries are
   load-bearing;
9. the `popen` needle misspelled in `NEEDLES` — the **population** assertion reddens by name
   ("matches nothing in the whole tree"). Without this one, a typo in the second needle
   would make every `popen` pass in silence while the control stayed green, which is the
   scan-returned-nothing shape rather than a check.

And two that must **pass**, recorded so the blind spots are measured rather than assumed:

10. an entered line whose descriptor spec is changed from one pipe to two — **passes**. If it
    ever refuses, the control grew a shape check nobody designed, and the claim above about
    what an entry permits is no longer true;
11. a new file naming both spawners only inside a docblock — **passes**, which is the comment
    exemption and the reason this plan and the control may spell the names at all.

A control whose red has not been observed under each planted breakage is not evidence.

### The module must also be loaded by path

A second control in the same group, over the same population:
**every file that calls `ChildProcess::run(` outside a comment must also `require_once` the
module by path.**

This is the other half of "loaded two ways on purpose", and it was a convention nothing
enforced. A convention nothing enforces is a claim about a set that drifts: four callers had
already stopped carrying the `require_once` while three separate documents still said every
caller did. Without it the isolated-project negative controls decay in silence — those
projects symlink `vendor/`, so an autoloaded class resolves back to *this* tree and a
deliberately broken scratch copy is never read.

What it accepts is computed, never spelled: the `require_once` expression is resolved from
`__DIR__` or `dirname(__DIR__, N)` plus one string literal and compared against the module's
realpath. A form the resolver cannot read is **refused**, not passed. `require` is not
accepted — the module defines a class, so a second `require` is a fatal redeclaration.

Named gaps: an aliased import (`use …\ChildProcess as Child; Child::run(…)`) and a call
through a variable class name are invisible to a textual needle. Neither exists in the tree.

Planted, each reddening by name:

1. the `require_once` removed from a caller — refused, naming the file;
2. a `require_once` kept but pointed at the wrong depth (`dirname(__DIR__, 3)`) — refused,
   and the message prints what it resolved to, proving the check is resolution and not the
   presence of the right-looking text;
3. a new, untracked caller with no `require_once` — refused, proving `--others` covers this
   control too.

And one that must pass: a file naming `ChildProcess::run()` only in a docblock is **not** a
caller. The control's own file carries such a mention and asserts itself out of the caller
set, so the exemption is measured rather than assumed.

## ADR

`docs/adr/00NN-subprocess-read-discipline.md`. It records the **consequence and the
reason**, not a retelling of the code:

- why the read discipline is a subject and why it lives outside `src/`;
- the measurements in `measurements.md`, copied into the ADR body rather than cited — a
  plan directory is a working record, and a future reader must not have to re-measure that
  an unread pty deadlocks;
- the binding constraint, named by consumer rather than counted: the five vendor-less callers
  of the module — `generate-suppression-snapshot.php`, `generate-modular-architecture.php`,
  `generate-modular-architecture-test-inventory.php`, `benchmark-regression.php` and
  `collect-benchmark-data.php` — load no `vendor/autoload.php`. One such consumer is enough to
  rule out `Symfony\Component\Process` and any autoload-only home. Every count written here
  before this one was wrong, in a different way each time ("seven callers", then "both
  modular-architecture generators" when there are three and the third loads `vendor/`), which
  is the argument for naming the set rather than sizing it;
- why the finding-gate supervision layer and the pty runner stay separate, **with the named
  condition that would fold them** (a fourth family needing supervision rather than capture).
  An unnamed exception is read as a settled answer — `ProcessOutput`'s own docblock says so.
- that "one safe way" scopes to **flat capture**. Four independent read loops survive this
  plan (the module, `ProcessHandle`, `Shell`, `PseudoTerminalRun`); without saying so, the
  next reader will try to fold supervision into the module.

The ADR does not restate the class's API: that rots faster than the code it describes.

## Documentation

- `CHANGELOG.md` — nothing user-facing changes except `GitRepositoryLocator`'s descriptor
  spec, which is a `Fixed` entry written from the consumer's side: a `git`-scoped run could
  hang instead of failing.
- `scripts/README.md` (if present) and `docs/ARCHITECTURE.md` — one line pointing at the
  module as the one way, and at the ADR for why.
- No plan reference in any tracked non-plan file: `governance/PlanningRecords` judges every
  tracked file for exactly that.

## Definition of Done

- The control is registered in `phpunit.xml.dist` under `Governance` **and** in
  `testSuitePrefixTable()` — an unregistered group reddens `composer architecture:check`
  by name, which is the cheap way to catch a half-registration.
- Every planted breakage in "Proving it bites" observed with its stated outcome — the
  refusals red and naming the control, and the one expected pass observed passing. Planted in
  a `mktemp -d` git repository, never committed. No count is written here: the list is beside
  it, and a number next to a list is a thing that drifts.
- The ADR is indexed in `docs/adr/README.md`.
- `composer check` green as a whole, run once before review and once after confirmed
  review fixes.
