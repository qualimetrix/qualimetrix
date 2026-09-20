# Subprocess reads: one drain-safe way to run a child

## The defect

A caller opens `proc_open` with two pipe descriptors and reads them sequentially —
stdout to EOF, then stderr — or reads one and never reads the other. Once the child
writes more than the OS pipe buffer (64 KB on macOS and Linux) to the stream the parent
reads *second* or *never*, the child blocks mid-write. It therefore never exits and never
closes the first stream, so the parent's blocking read of that stream never reaches EOF.
Both sides wait forever.

The failure is a **hang, not a red**. In CI it burns the job timeout and names no cause,
which is strictly worse than failing: a red test says what broke, a hung job says only
that something did. The mirrored hazard exists on stdin — writing more than 64 KB to a
stdin pipe before draining the output pipes blocks the same way.

The confirmed instance (`ModularArchitectureGeneratorRefusalTest::runProcess()`, a
schema-invalid manifest making the generator write ~2.6 MB to stderr in one `fwrite`)
was fixed in #102. That fix is deliberately scoped to one family: its own docblock
leaves the neutral-home question open and names the sibling call sites as follow-up.
This plan is that follow-up.

## Enumeration

`enumeration.md` in this directory: one row per `proc_open` occurrence in tracked PHP, with
its descriptor spec, read shape, resolved child, verdict and **disposition**. It carries its
own "how obtained / what this method cannot see" line, and its counts come with the commands
that derive them — both original hand-written totals were wrong, in different ways.

It was taken twice by parties that could not see each other's work, per the two-witness rule:
a single party that fills both a table and the oracle over it errs consistently and passes
its own guard. The two takings agreed on all thirty verdicts.

`enumeration.md` is the **migration record** — what was found and what each row becomes. The
control's allowlist in stage 03 is a **different artifact**: the small set of occurrences
that still exist afterwards and are permitted by name. They do not coincide, and the earlier
claim that they did was wrong. What keeps a row from rotting away is the control's *negative*
space — every `proc_open` outside the module and the entries is refused — not a positive list
that mirrors this table.

## Architectural decision

### The subject

"Reading everything a child process writes, without deadlocking on a pipe buffer" is a
subject, not a role. It has its own semantics (pipe-buffer capacity, EOF, non-blocking
reads), its own lifecycle (it changes when the read discipline changes, never when a
caller's purpose changes), and independent consumers in three unrelated families.

It passes the counterfactual-ownership test by measurement rather than argument: it has
*already* been copied three times into three families that could not share it. That is
the signature of a legitimate cross-cutting module, not of a premature extraction.

### The home

`scripts/subprocess/`, namespace `Qualimetrix\Subprocess\`.

Three constraints decide this, and the third is the binding one:

1. **Not `src/`.** `src/` is the product's PSR-4 root and ships in the composer dist
   package. This is tooling; the product does not run it.
2. **Not a new top-level root.** A new root pays the whole address table in `CLAUDE.md`,
   most of whose entries fail silently. `scripts/` is an existing root already declared in
   `phpstan.neon` `paths`, the PHP-CS-Fixer finder, the pre-commit path filter,
   `ScratchPathsCarryRealEntropyTest::ROOTS` and `.gitattributes` `export-ignore`, and it
   already houses library-shaped directories (`finding-gate/`, `promise-effect/`,
   `input-doors/`, `modular-architecture/`).
3. **It must work without `vendor/`.** Named by consumer, not counted. Five scripts call the
   module and load no `vendor/autoload.php` at all: `generate-suppression-snapshot.php`,
   `generate-modular-architecture.php`, `generate-modular-architecture-test-inventory.php`,
   `benchmark-regression.php` and `collect-benchmark-data.php`. Anything reachable only
   through Composer's autoloader therefore cannot be the repository's one safe way, and one
   such consumer is enough to settle it.

   Two earlier spellings of that set were wrong, both by counting where they should have
   named. "Both modular-architecture generators" implies there are two; there are three, and
   the third — `generate-modular-architecture-production-inventory.php` — does load
   `vendor/`, so it is not one of the vendor-less callers. And the two benchmark scripts were
   written off here as "already safe, so they do not carry the argument": true of the shape
   they had before the migration, but disposition 1 made them callers of the module, which is
   the property this constraint is about. `finding-gate.php` and `directive-narrow-control.php`
   do run vendor-less and call nothing here.

Constraint 3 also rules out the otherwise obvious candidate — `Symfony\Component\Process`,
which drains correctly and is already on disk. It is unavailable to the vendor-less callers above,
and (see "Defect found in passing") it is not even a production dependency.

### What the module does *not* absorb

Two of the three existing correct implementations stay where they are, and this is a
decision with a named condition, not an oversight:

- `scripts/finding-gate/ProcessHandle.php` + `scripts/finding-gate-controls/Shell.php` —
  these layer a *different* subject on the read discipline: process-group isolation via
  `posix_setsid`, `pgrep`-based descendant termination, launcher-disappearance detection
  and a bounded parallel scheduler. Their behaviour is measured
  (`composer gate:controls`); folding them changes what those measurements cover.
- `tests/Infrastructure/Console/Support/PseudoTerminalRun.php` — pty masters report EIO
  where pipes report EOF, which is a different read discipline, not a caller of this one.

**Condition to revisit:** a fourth family needing supervision (process groups, deadlines,
descendant kills) rather than a plain capture. At that point the supervision layer has two
independent consumers and becomes its own subject; until then extracting it would bind the
gate's lifecycle to a second consumer for no measured gain.

## Stage map

| Stage                 | Subject                                                                                                          | Depends on |
| --------------------- | ---------------------------------------------------------------------------------------------------------------- | ---------- |
| [01](01-runner.md)    | The module: subject, home, contract, its regression test, and every registration address a new tooling root owes | —          |
| [02](02-migration.md) | Every deadlock-capable call site, one disposition each                                                           | 01         |
| [03](03-control.md)   | The governance control that makes the shape unrepeatable, plus the ADR                                           | 01, 02     |

Stage 01 lands the module and proves it. Stage 02 cannot start before it exists. Stage 03
must come last: its allowlist is written against the tree stage 02 leaves behind, and an
allowlist written against the tree before the migration would enumerate the defect.

## Defect found in passing — not in this plan's scope

`src/Infrastructure/Git/GitClient.php` imports `Symfony\Component\Process`, which is a
**dev-only transitive dependency** (`composer why symfony/process` → `friendsofphp/php-cs-fixer`;
`composer.lock` lists it under `packages-dev`). `composer install --no-dev --dry-run`
confirms it is removed. A consumer installing the product without dev dependencies gets a
fatal error the moment a git-scoped run (`--report=git:staged`, `--report=git:main..HEAD`)
reaches `GitClient`.

This is a real production defect, it is adjacent to this subject, and it is deliberately
not fixed here: the fix is a `composer.json` `require` promotion with its own changelog
and package-version governance, not a subprocess-read change.
