# Stage 01 — the module

## Contract

`Qualimetrix\Subprocess\ChildProcess`, **one file** at `scripts/subprocess/ChildProcess.php`,
with no second class of its own — so a caller may `require_once` it by path without a class
graph.

```php
final class ChildProcess
{
    /**
     * Starts the command, feeds $stdin, drains stdout and stderr until both
     * reach EOF, and returns once the child has exited.
     *
     * @param list<string>|string       $command
     * @param array<string,string>|null $environment null inherits the parent's
     * @return array{stdout: string, stderr: string, exitCode: int}
     * @throws \RuntimeException the child could not be started, or a stream
     *                           could not be read
     */
    public static function run(
        array|string $command,
        ?string $workingDirectory = null,
        string $stdin = '',
        ?array $environment = null,
    ): array;

    // private static function drain(...) — the select loop, not public surface
}
```

### One public method

An earlier draft exposed `drain()` publicly, with a `$fail` callback and an optional stdin
descriptor, for callers that own their own `proc_open`. Walking the dispositions in
`enumeration.md` showed it would have **no executable caller**:

- `scripts/finding-gate/SelfTest.php` needs a live child back after reading two
  announcement lines, while its child runs `sleep 30`. A `drain()` that reads to EOF would
  block for thirty seconds and return a corpse.
- `tests/…/BaselineChannelRenamerTest` opens its deadlock window *while the parent holds a
  lock*, which no read discipline closes; it takes disposition 3.
- Both generators and the refusal test's `runProcess()` are plain capture — `run()`.

A public method with no caller is surface the control then has to guard. `drain()` is
private, `run()` owns the loop, and the stdin descriptor lives inside it.

### stdin is written inside the loop

Writing stdin to completion before draining is the same deadlock mirrored. Measured on this
platform rather than argued: a parent pushing 1 MB into the stdin pipe of a child that never
reads stdin was still blocked in `fwrite()` at 5 s and had to be SIGKILLed (exit 137). A
caller passing `''` gets its stdin pipe closed on the first iteration.

**EPIPE on stdin is not a failure.** A child that exits or closes stdin without reading it
(`git ls-files` does) makes the parent's `fwrite()` fail; that is a normal path. The runner
stops writing, closes the stdin descriptor and keeps draining the reads.

### Failure shape

`run()` throws `\RuntimeException` — a PHP built-in, deliberately, so the file stays a
single class with nothing to autoload. A named `SubprocessFailure` was rejected: PSR-4 puts
it in a second file, the vendor-less callers have no autoloader, and the class would be
missing **on the error path**, where nothing exercises it. That is precisely the hazard
`ProcessOutput`'s own docblock warns about and the reason `scripts/finding-gate/classes.php`
exists.

A non-zero **exit code is not a failure**: it is returned in the result, because most
callers assert on it. `timedOut` is likewise a result, not a throw.

### No deadline, and why that is not an oversight

An earlier draft gave `run()` a `?float $deadlineSeconds`. It has no executable caller —
the same defect that removed the public `drain()`, found the same way, by walking the rows.
The only two rows that carry a deadline today (13 and 27) both take disposition 3 and keep
their own bespoke loops; not one row on disposition 1 has or needs one.

It was also the wrong subject. `00-overview.md` scopes timeouts, process groups and
descendant kills to the **supervision** layer, deliberately left with the finding-gate, and
putting a deadline in the module would have contradicted that in the same plan. This module
fixes deadlock caused by *the parent's own read discipline*. A child that wedges for its own
reasons is supervision, and supervision has a named owner.

Adding a deadline would also have meant specifying a termination protocol nobody needs yet:
which signal, whether descriptors close, whether `proc_close()` runs, whether `timedOut`
can return true with the child still alive.

**Condition to add it:** a migrating caller that needs a bounded wait and does not belong to
the supervision layer. There is none today. Any caller that has a deadline now keeps its own
— a migration that dropped one would make that caller worse while claiming to make it safer.

### Result key naming

`exitCode`, not `exit` — `tests/Infrastructure/Console/Functional/ApplicationRefusalTest`
already returns `['stdout','stderr','exitCode']`, and the contract should not rename a
spelling the tree already uses.

## Loading

Both mechanisms, deliberately, exactly as #102 measured for `ProcessOutput` — that
measurement is about this file's *shape*, not its old address, so it transfers:

- an `autoload-dev.psr-4` entry `Qualimetrix\Subprocess\` → `scripts/subprocess/`, without
  which `src/`'s development-namespace import ban cannot see the namespace at all; and
- an explicit `require_once` at each caller, without which the isolated-project negative
  controls resolve the class through their symlinked `vendor/` to *this* tree rather than
  to their scratch copy.

Re-probe both on the new path rather than inheriting the verdict: a deliberately broken
scratch copy must be what executes, and a planted `src/` import of the new namespace must
be refused by name.

## Tests

The cases below live at `scripts/subprocess/tests/ChildProcessDrainTest.php` (`Tooling`
suite). Two of them **move** from `ModularArchitectureGeneratorRefusalTest`, because after this stage
neither is about modular architecture: `runProcess()` becomes a two-line wrapper over
`run()`. Moving beats rewriting — both were measured against real mutants, and a fresh pair
would start with no such evidence.

1. **The deadlock property** (moved from `itDrainsAChildThatFloodsBothStreamsWithoutDeadlocking`).
   A child flooding both streams past the buffer, with unequal sizes and different byte
   sequences per stream so neither assertion can pass by coincidence. The call under test
   runs in an **external harness process**, supervised by `proc_get_status()` polling against
   a wall-clock deadline — never a blocking pipe read, or a reintroduced deadlock hangs the
   suite instead of reddening it. Its `runWithDeadline()` helper moves with it: that helper
   has exactly one caller, verified, so the refusal test is left with no `proc_open` at all.
   Rewired to drive `ChildProcess::run()` directly, which also drops the reflection the old
   harness needed to reach a private method.
2. **The truncation property** (moved from `itDrainsAChildThatClosesOneDescriptorWhileTheOtherKeepsWriting`).
   A child that closes one descriptor while the other still has more than a buffer's worth
   in flight — the mutant test 1 structurally cannot see, since there both descriptors close
   together at exit. Observed through `run()`'s returned stderr length. Needs no deadline
   harness: it cannot hang.
3. **The stdin mirror** — new; nothing existing covers it. A child writing a large stdout
   while the parent feeds it more than 64 KB of stdin, under the same external harness and
   deadline. Red against a write-stdin-to-completion-first mutant. A fourth case pins the
   EPIPE path: a child that never reads stdin must not turn into a failure.

**Mutant proof is Definition of Done.** For each case: plant the mutant, record the red run
and its wall-clock time, restore, record the green. A test whose red has never been observed
is a claim, not a control.

## Registration addresses

`scripts/subprocess/tests/` is a new test root. Every address below was re-derived against
the tree; `CLAUDE.md`'s table is the checklist, the tree is the authority.

| Address                                                                       | Edit                                                                                                                                                                                                                                                                                                                      | Fails                                                     |
| ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| `composer.json` `autoload-dev.psr-4`                                          | two entries: the namespace root and `…\Tests\`                                                                                                                                                                                                                                                                            | loudly                                                    |
| `phpunit.xml.dist`                                                            | a `<directory>` under `Tooling`                                                                                                                                                                                                                                                                                           | loudly                                                    |
| `generate-modular-architecture-test-inventory.php` `TOOLING_TEST_ROOT_OWNERS` | a key — this also feeds the generator's scan-scope pathspec, closing that silent address too                                                                                                                                                                                                                              | silently                                                  |
| the same file's `testSuitePrefixTable()`                                      | a row agreeing with `phpunit.xml.dist`                                                                                                                                                                                                                                                                                    | loudly                                                    |
| `.dockerignore`                                                               | its own exact line beside the other `scripts/*/tests/` entries                                                                                                                                                                                                                                                            | loudly (`DockerBuildContextExcludesToolingTestRootsTest`) |
| `scripts/generate-rename-enumeration.php` `surfaces()`                        | append to the `tests` surface's `roots`                                                                                                                                                                                                                                                                                   | **silently**                                              |
| `createIsolatedProject()` in `ModularArchitectureGeneratorRefusalTest`        | **three** edits, not one: `mkdir scripts/subprocess`, `copy ChildProcess.php`, and a `copyDirectory` for `scripts/subprocess/tests` — the new `<directory>` must exist in the scratch project or PHPUnit exits 2 there. The existing `mkdir`/`copy` pair for `ProcessOutput.php` (lines 784–788) is removed with the file | loudly                                                    |

Measured as **needing no edit**, each against the file rather than assumed:
`.gitattributes` (`/scripts/ export-ignore` is wholesale), `.githooks/pre-commit` (filter is
`^(src|tests|governance|scripts|tools)/`), `ScratchPathsCarryRealEntropyTest::ROOTS`
(`scripts` wholesale), `scripts/init-environment.sh` (enumerates no roots),
`scripts/phpunit-aggregate.py` `SUITES` and its test's copy (the root joins the existing
`Tooling` suite, adding no suite), `phpstan.neon` `paths` and the PHP-CS-Fixer finder (both
already name `scripts`).

Then sweep the two spellings a path-and-name sweep is blind to: a hardcoded count asserted
over a generated artifact, and a literal command string embedding a path.

## Callers rewired in this stage

The three `ProcessOutput` callers move to the new class in the same commit that moves the
file, so the tree is never in a state where both exist:
`scripts/generate-modular-architecture.php`,
`scripts/generate-modular-architecture-test-inventory.php`,
`scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`.

`scripts/modular-architecture/ProcessOutput.php` is deleted, and with it the `autoload-dev`
entry `Qualimetrix\ModularArchitecture\` → `scripts/modular-architecture/`, which exists
only for that class. The `…\Tests\` entry beside it stays.

## Definition of Done

- `ChildProcess::run()` exists at the stated path; `drain()` is private and there is no
  second class in the file. Checked by tokenizing, not by `grep -c 'class '` — that counts
  lines containing the word and a docblock mentioning "class" breaks it:
  `php -r '$n=0; foreach (token_get_all(file_get_contents($argv[1])) as $t) { if (is_array($t) && $t[0]===T_CLASS) $n++; } echo $n;' scripts/subprocess/ChildProcess.php`
  must print 1.
- One select loop in the file.
- All four test cases red against their own mutant and green against the module, each run
  recorded with its wall-clock time.
- `scripts/modular-architecture/ProcessOutput.php` no longer exists, nothing references it,
  and `ModularArchitectureGeneratorRefusalTest` contains no `proc_open`.
- Each `proc_open` that lands in `scripts/subprocess/tests/` carries its own entry, and the
  private helpers the moved tests need (`root()` at least) move or are re-derived with them.
- The isolation probe (a deliberately broken scratch copy must be what executes) and the
  import-ban probe (a planted `src/` import of the new namespace must be refused by name)
  re-measured on the new path — inherited verdicts do not transfer to a new address.
- `composer architecture:check` green; `Tooling` suite green.
