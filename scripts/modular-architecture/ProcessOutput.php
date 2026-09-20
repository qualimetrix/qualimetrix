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
 * Not autoloaded: `composer.json`'s `autoload-dev` maps only
 * `Qualimetrix\ModularArchitecture\Tests\` (`scripts/modular-architecture/tests/`),
 * matching the precedent at `scripts/finding-gate/ProcessHandle.php` — a
 * class in this namespace family that every caller `require`s explicitly.
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
