<?php

declare(strict_types=1);

namespace Qualimetrix\ModularArchitecture;

/**
 * Concurrent, non-blocking drain of a child process's stdout and stderr.
 *
 * A sequential read — stdout to EOF, then stderr — deadlocks once the child
 * writes more than the OS pipe buffer (64 KB on both macOS and Linux by
 * default) to whichever stream is read second: the child blocks mid-write on
 * that stream, so it never exits or closes the first stream, and the
 * parent's blocking read of the first stream never reaches EOF. Draining
 * both descriptors concurrently with `stream_select()` is the only shape
 * that cannot deadlock this way, because neither stream is left unread while
 * the other blocks.
 *
 * Declared in `composer.json`'s `autoload-dev.psr-4` — the namespace
 * (`Qualimetrix\ModularArchitecture\` => `scripts/modular-architecture/`)
 * *and* the three explicit `require_once`s below both stay, deliberately,
 * and that combination was chosen by measuring three variants rather than
 * reasoning about them:
 *
 * 1. Undeclared namespace, `require` only (the original shape). Cheap, but
 *    the repository's ban on `src/` importing development code derives its
 *    list of development namespaces from `autoload-dev.psr-4` alone
 *    (`itRefusesAProductionImportOfEveryDeclaredDevelopmentNamespace`), so
 *    an undeclared namespace is invisible to it — measured directly, a real
 *    `src/` import of this class produced no refusal. Nor does PHPStan
 *    catch it as a substitute: `scripts/` is in `phpstan.neon` `paths`, so a
 *    real static call analyses clean, `class.notFound` never fires. Only a
 *    literal `SomeClass::class` reference is silent at runtime too
 *    (`class_exists()` returns `false` without ever invoking the
 *    autoloader); an actual call throws — but by the time that throw is
 *    reached the ban has already had its one chance to catch the import and
 *    did not.
 * 2. Declared namespace, `require` **dropped** (autoload only). This is
 *    what closes the ban's gap, but it reopens a different one: every
 *    isolated-project negative control in this file
 *    (`createIsolatedProject()`) symlinks `vendor/`, and an autoloaded class
 *    resolves through that symlink's `vendor/composer/autoload_psr4.php`,
 *    whose paths point at *this* tree, not the scratch copy. Probed
 *    directly: a scratch project with a deliberately broken
 *    `ProcessOutput.php` and no `require` of it, only the autoloader
 *    present via the symlink, still resolved the class to the real tree's
 *    file — the scratch copy was never read.
 * 3. Declared namespace, `require` **kept** — the shape below. A `require`
 *    defines the class before anything asks the autoloader for it, so
 *    variant 2's hazard does not arise: probed with the same deliberately
 *    broken scratch copy, this time also `require`d by the driver the way
 *    the real callers `require` it, the scratch copy was what got resolved
 *    and executed, autoloader present or not. And the ban control now sees
 *    the namespace — the same broken-import probe from variant 1 is refused
 *    ("production source imports a development-only namespace") once the
 *    namespace is declared. `composer architecture:check` and the full
 *    `Tooling` suite stay green with the entry in place; the ban control
 *    picks up a second, harmless probe for the added prefix.
 *
 * The residual cost of keeping both: a future class added beside this one,
 * under the same namespace, becomes autoloadable by declaration and would
 * need its own explicit `require_once` to stay isolation-safe the same way
 * — the declaration does not make that automatic, only possible to forget.
 *
 * Loaded by three explicit `require_once`s (the two generator scripts and
 * the refusal test), each self-contained with this file's full path —
 * `require_once` rather than `require` because the class is now also
 * autoloadable, so a caller that both `require`s this file directly and
 * triggers the autoloader for the same class elsewhere in the same process
 * would otherwise redeclare it. Not the shape at
 * `scripts/finding-gate/classes.php` (one manifest, `require`d once by six
 * entry points, listing every class in that namespace), which
 * exists because a *dependency between* classes in a hand-picked per-caller
 * subset once broke a caller that had not guessed the new edge. That risk
 * needs a class graph; this file has no dependencies of its own, so each
 * caller requiring it directly is sufficient.
 *
 * The class is subject-neutral — nothing here is about modular architecture
 * — and lives here anyway, scoped deliberately to this family's three
 * callers rather than a project-wide `scripts/` home. Filed sibling call
 * sites elsewhere (`governance/`, `scripts/generate-suppression-snapshot.php`,
 * …) share this exact two-pipe shape and are follow-up work, not this file's
 * problem to solve; when the next one arrives, decide then whether it
 * `require`s this file by path or a neutral home gets settled first — don't
 * let this docblock's silence be read as a settled answer either way. The
 * `autoload-dev` declaration above adds one more thing a future move would
 * have to update alongside it: a relocation becomes a `composer.json` edit
 * too, not only a path change at the three call sites.
 */
final class ProcessOutput
{
    /**
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     * @param callable(string): never $fail called, instead of throwing
     *                                      directly, so each caller keeps its own failure shape (an exception
     *                                      for the generators, `Assert::fail()` for the test)
     *
     * @return array{string, string} stdout, stderr
     */
    public static function drain($stdoutPipe, $stderrPipe, callable $fail): array
    {
        stream_set_blocking($stdoutPipe, false);
        stream_set_blocking($stderrPipe, false);

        $streams = [
            (int) $stdoutPipe => ['stream' => $stdoutPipe, 'index' => 0],
            (int) $stderrPipe => ['stream' => $stderrPipe, 'index' => 1],
        ];
        $output = ['', ''];

        while ($streams !== []) {
            $read = array_column($streams, 'stream');
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, null) === false) {
                $fail('Cannot read command output streams.');
            }

            foreach ($read as $stream) {
                $key = (int) $stream;
                $chunk = stream_get_contents($stream);
                if ($chunk === false) {
                    $fail('Cannot read command output stream.');
                }

                $output[$streams[$key]['index']] .= $chunk;

                if (feof($stream)) {
                    fclose($stream);
                    unset($streams[$key]);
                }
            }
        }

        return [$output[0], $output[1]];
    }
}
