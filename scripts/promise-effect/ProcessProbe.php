<?php

declare(strict_types=1);

/**
 * The fourth observation point — the report — and the cache-isolation protocol
 * of 02 §4.
 *
 * `--no-cache` does not hold in this tree; that was measured in the round and
 * carried into `DEFERRED`. An oracle that trusts a broken switch is not an
 * oracle, so the flag is never used here. Instead every probe:
 *
 *   1. names its cache directory explicitly, and the name is unique to the
 *      probe — a run cannot quietly fall back to the default one;
 *   2. deletes that directory before the run;
 *   3. is checked afterwards: the named directory is absent or was created by
 *      this run, and the run is single — the probe's run directory is fresh,
 *      so no file this probe wrote could have been read by it;
 *   4. FAILS, rather than reporting, when the default `.qmx-cache` appears
 *      anywhere under the run directory: that is the product ignoring the
 *      directory it was given, and a measurement taken under it is not a
 *      measurement.
 *
 * Point 3 alone would be weaker than the claim "the cache was not read" — it
 * says nothing about a different directory. Points 1 and 4 close that.
 */

namespace Qualimetrix\PromiseEffect;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

final class ProbeFailure extends RuntimeException {}

final readonly class ProcessObservation
{
    public function __construct(
        public int $exit,
        public string $digest,
        public string $stdoutHead,
        public string $stderrHead,
        public string $cacheNote,
    ) {}

    public function outcome(): string
    {
        if ($this->exit === 3 && str_contains($this->stderrHead . $this->stdoutHead, 'Configuration error:')) {
            return Observation::REFUSED_FRAMED;
        }

        if ($this->exit === 3) {
            return Observation::REFUSED_UNFRAMED;
        }

        if ($this->exit !== 0 && $this->exit !== 1) {
            return Observation::REFUSED_UNFRAMED;
        }

        if ($this->exit === 1) {
            return Observation::CRASHED;
        }

        return Observation::ACCEPTED;
    }

    public function text(): string
    {
        return $this->outcome() === Observation::ACCEPTED
            ? $this->digest
            : 'exit=' . $this->exit . ' ' . trim($this->stderrHead . ' ' . $this->stdoutHead);
    }
}

final class ProcessProbe
{
    private const string DEFAULT_CACHE_DIRECTORY = '.qmx-cache';

    private int $runs = 0;

    /** @var array<string, ProcessObservation> */
    private array $memo = [];

    public function __construct(
        private readonly string $repositoryRoot,
        private readonly string $scratchRoot,
    ) {}

    public function runs(): int
    {
        return $this->runs;
    }

    /**
     * @param array<string, mixed> $document the qmx.yaml the probe writes
     * @param list<string> $extraArguments CLI doors the probe exercises
     * @param list<string> $withdraw invariants this probe must not carry
     */
    public function observe(array $document, array $extraArguments = [], bool $cacheOwned = false, string $observable = 'findings', array $withdraw = []): ProcessObservation
    {
        $key = md5(serialize([$document, $extraArguments, $cacheOwned, $observable, $withdraw]));

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $this->memo[$key] = $this->run($document, $extraArguments, $cacheOwned, $observable, $key, $withdraw);

        return $this->memo[$key];
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $extraArguments
     * @param list<string> $withdraw
     */
    private function run(array $document, array $extraArguments, bool $cacheOwned, string $observable, string $key, array $withdraw = []): ProcessObservation
    {
        $runDirectory = $this->scratchRoot . '/run/' . $key;
        self::remove($runDirectory);
        self::copy($this->repositoryRoot . '/promise-effect/fixtures/probe', $runDirectory);

        $cacheDirectory = $runDirectory . '/probe-cache';

        if (!$cacheOwned) {
            // Step 1: explicit and unique. Written into the document rather
            // than onto the command line so a probe of a CLI door cannot
            // collide with it.
            $document['cache'] = ['dir' => $cacheDirectory];
        }

        // Step 2.
        self::remove($cacheDirectory);
        self::remove($runDirectory . '/' . self::DEFAULT_CACHE_DIRECTORY);

        $document['paths'] ??= ['src'];
        file_put_contents($runDirectory . '/qmx.yaml', Yaml::dump($document, 8, 2));

        // A probe on the door that carries one of this stand's invariants is
        // taken with that invariant withdrawn. A probe left standing next to
        // its own shadow measures the shadow.
        $logFile = $runDirectory . '/probe.log';
        $invariants = ['--workers=0', '--no-progress', '--format=json', '--fail-on=none'];

        if ($observable === 'logfile') {
            $invariants[] = '--log-file=' . $logFile;
            $invariants[] = '--log-level=debug';
        }

        foreach ($withdraw as $flag) {
            $invariants = array_values(array_filter(
                $invariants,
                static fn(string $invariant): bool => $flag === 'target' || !str_starts_with($invariant, $flag . '='),
            ));
        }

        $argv = array_merge(
            [\PHP_BINARY, $this->repositoryRoot . '/bin/qmx', 'check'],
            \in_array('target', $withdraw, true) ? [] : ['src'],
            $invariants,
            $extraArguments,
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($argv, $descriptors, $pipes, $runDirectory);

        if (!\is_resource($process)) {
            throw new ProbeFailure('cannot start the product');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // Taken from the process, never from a pipeline's status: a piped exit
        // code is the last command's, and the round has been bitten by that.
        $exit = proc_close($process);
        ++$this->runs;

        // Step 4, before anything is believed.
        if (is_dir($runDirectory . '/' . self::DEFAULT_CACHE_DIRECTORY) && !$cacheOwned) {
            throw new ProbeFailure(\sprintf(
                'the product created %s while it was told to cache in %s — the probe measures nothing',
                self::DEFAULT_CACHE_DIRECTORY,
                $cacheDirectory,
            ));
        }

        // Step 3.
        $cacheNote = 'absent';

        if (is_dir($cacheDirectory)) {
            $cacheNote = 'created-by-this-run';
        }

        if ($cacheOwned) {
            $cacheNote = is_dir($runDirectory . '/' . self::DEFAULT_CACHE_DIRECTORY)
                ? 'cache-owned: default directory appeared'
                : 'cache-owned: no default directory';
        }

        $observation = new ProcessObservation(
            $exit,
            self::observable($observable, $stdout, $runDirectory, $cacheDirectory, $logFile),
            $this->tokenize(substr($stdout, 0, 400), $runDirectory),
            $this->tokenize(substr($stderr, 0, 400), $runDirectory),
            $cacheNote,
        );

        self::remove($runDirectory);

        return $observation;
    }

    /**
     * The observable: the finding population, reduced to a digest so the raw
     * snapshot stays readable and machine-independent. The count travels with
     * it because a digest alone cannot say "fewer findings".
     */
    /**
     * The observable of one probe. Named per root rather than assumed: the
     * effect of `cache.dir` is a directory, of `format` a shape of output, of
     * `parallel.workers` a number that only reaches the debug log — and a
     * stand that asked all of them for a finding digest would report the three
     * of them as NOT OBSERVABLE and call that a property of the product.
     */
    private static function observable(string $observable, string $stdout, string $runDirectory, string $cacheDirectory, string $logFile): string
    {
        // A witness whose envelope enables a second rule beside the probed
        // one cannot read "the run said something" as "this producer spoke".
        // Counting only the channels that carry the declared prefix is what
        // keeps the envelope from buying its own witness.
        if (str_starts_with($observable, 'channel:')) {
            return self::channelDigest(substr($observable, \strlen('channel:')), $stdout);
        }

        return match ($observable) {
            // Digits normalized and no byte count: the duration in the header
            // changes width between runs, and a length that flickers would
            // break the "two runs, same text" property for a reason that has
            // nothing to do with the door.
            'stdout' => 'shape=' . substr(md5(preg_replace('/\\d/', '#', $stdout) ?? $stdout), 0, 10),
            'exitcode' => 'exit-only',
            'cachedir' => 'named=' . (is_dir($cacheDirectory) ? self::countFiles($cacheDirectory) : 'absent')
                . ' default=' . (is_dir($runDirectory . '/' . self::DEFAULT_CACHE_DIRECTORY) ? self::countFiles($runDirectory . '/' . self::DEFAULT_CACHE_DIRECTORY) : 'absent'),
            'logfile' => self::logEcho($logFile),
            default => self::digest($stdout),
        };
    }

    private static function channelDigest(string $prefix, string $stdout): string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($stdout, true);

        if (!\is_array($decoded) || !isset($decoded['violations']) || !\is_array($decoded['violations'])) {
            return 'n/a:' . substr(md5($stdout), 0, 10);
        }

        $tuples = [];

        foreach ($decoded['violations'] as $violation) {
            if (!\is_array($violation) || !str_starts_with((string) ($violation['channel'] ?? ''), $prefix)) {
                continue;
            }

            $tuples[] = implode('|', [
                (string) ($violation['file'] ?? ''),
                (string) ($violation['line'] ?? ''),
                (string) ($violation['channel'] ?? ''),
                (string) ($violation['severity'] ?? ''),
                (string) ($violation['message'] ?? ''),
            ]);
        }

        sort($tuples, \SORT_STRING);

        return substr(md5(implode("\n", $tuples)), 0, 10) . '/n=' . \count($tuples);
    }

    private static function countFiles(string $directory): string
    {
        $count = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $ignored) {
            ++$count;
        }

        return 'files=' . $count;
    }

    private static function logEcho(string $logFile): string
    {
        if (!is_file($logFile)) {
            return 'no log';
        }

        $read = file($logFile, \FILE_IGNORE_NEW_LINES);
        $lines = [];

        foreach ($read === false ? [] : $read as $line) {
            if (str_contains($line, 'orkers')) {
                $lines[] = (string) preg_replace('/^.*?(\{.*)$/', '$1', $line);
            }
        }

        sort($lines, \SORT_STRING);

        return $lines === [] ? 'no workers line' : substr(md5(implode("\n", $lines)), 0, 10) . '/' . \count($lines);
    }

    private static function digest(string $stdout): string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($stdout, true);

        if (!\is_array($decoded) || !isset($decoded['violations']) || !\is_array($decoded['violations'])) {
            return 'n/a:' . substr(md5($stdout), 0, 10);
        }

        $tuples = [];

        foreach ($decoded['violations'] as $violation) {
            if (!\is_array($violation)) {
                continue;
            }

            $tuples[] = implode('|', [
                (string) ($violation['file'] ?? ''),
                (string) ($violation['line'] ?? ''),
                (string) ($violation['channel'] ?? $violation['rule'] ?? ''),
                (string) ($violation['severity'] ?? ''),
                (string) ($violation['message'] ?? ''),
            ]);
        }

        sort($tuples, \SORT_STRING);

        return substr(md5(implode("\n", $tuples)), 0, 10) . '/n=' . \count($tuples);
    }

    /**
     * The snapshot is tracked, and `scripts/check-private-leaks.sh` forbids an
     * absolute home path in a tracked file. Longest prefix first, raw and
     * resolved, because the run directory lives inside the scratch root.
     */
    private function tokenize(string $text, string $runDirectory): string
    {
        // Longest prefix first, raw and resolved: the run directory lives
        // inside the scratch root, and on macOS `/tmp` resolves to
        // `/private/tmp`, so the reverse order would leave `<RUN>` unreachable.
        // The product tree is here too — a crash quotes the frame it crashed
        // in, and that frame is an absolute path inside this checkout.
        $roots = [
            $runDirectory => '<RUN>',
            $this->scratchRoot => '<SCRATCH>',
            $this->repositoryRoot => '<REPO>',
        ];

        foreach ($roots as $root => $token) {
            foreach ([$root, realpath($root)] as $candidate) {
                if (\is_string($candidate) && $candidate !== '') {
                    $text = str_replace($candidate, $token, $text);
                }
            }
        }

        return str_replace(["\t", "\n", "\r"], ' ', $text);
    }

    private static function remove(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (!is_dir($path)) {
            unlink($path);

            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }

    private static function copy(string $from, string $to): void
    {
        mkdir($to, 0o775, true);

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            $target = $to . '/' . substr($entry->getPathname(), \strlen($from) + 1);
            $entry->isDir() ? mkdir($target, 0o775, true) : copy($entry->getPathname(), $target);
        }
    }
}
