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
 * Not autoloaded, on purpose, not by omission: `composer.json`'s
 * `autoload-dev` maps only `Qualimetrix\ModularArchitecture\Tests\`
 * (`scripts/modular-architecture/tests/`), and adding a sibling
 * `Qualimetrix\ModularArchitecture\` => `scripts/modular-architecture/`
 * entry was measured, not just considered. It resolves correctly — Composer
 * already runs this exact shape for `Qualimetrix\PhpStan\` /
 * `Qualimetrix\PhpStan\Tests\`, and the longer prefix wins there and would
 * here too — but it silently reopens a known hazard: every isolated-project
 * negative control in this file (`createIsolatedProject()`) symlinks
 * `vendor/`, and an autoloaded class resolves through that symlink's
 * `vendor/composer/autoload_psr4.php`, which points at *this* tree's path,
 * not the scratch copy. Probed directly: a scratch project with a
 * deliberately broken `scripts/modular-architecture/ProcessOutput.php` and a
 * symlinked `vendor/` still resolved the class to the real tree's file. An
 * explicit `require __DIR__ . '/...'` has no such failure mode, because it
 * is a filesystem path relative to the physical file being run, not a
 * lookup through the autoloader — so it reads whichever copy is actually
 * present. That is worth the cost of three explicit `require` lines. This
 * also means the class is one more member of the gap tracked as "запрет
 * импорта dev-неймспейса осознанно неполон" (undeclared development roots
 * are not reached by the production-import ban, which derives its list
 * solely from `autoload-dev.psr-4`): pre-existing, not introduced here, and
 * not closed by this file either.
 *
 * Loaded by three explicit `require`s (the two generator scripts and the
 * refusal test), each self-contained with this file's full path — not the
 * shape at `scripts/finding-gate/classes.php` (one manifest, `require`d
 * once, listing every class in that namespace), which exists because a
 * *dependency between* classes in a hand-picked per-caller subset once broke
 * a caller that had not guessed the new edge. That risk needs a class graph;
 * this file has no dependencies of its own, so each caller requiring it
 * directly is sufficient.
 *
 * The class is subject-neutral — nothing here is about modular architecture
 * — and lives here anyway, scoped deliberately to this family's three
 * callers rather than a project-wide `scripts/` home. Filed sibling call
 * sites elsewhere (`governance/`, `scripts/generate-suppression-snapshot.php`,
 * …) share this exact two-pipe shape and are follow-up work, not this file's
 * problem to solve; when the next one arrives, decide then whether it
 * `require`s this file by path or a neutral home gets settled first — don't
 * let this docblock's silence be read as a settled answer either way.
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
