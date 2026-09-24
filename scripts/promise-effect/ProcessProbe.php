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
use Qualimetrix\Subprocess\ChildProcess;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

require_once \dirname(__DIR__) . '/subprocess/ChildProcess.php';

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

    /**
     * The RAW reading of one run: what the process did, before anything is
     * judged. Exit 2 lands in the unframed branch here and is turned into the
     * accepted observation it actually is by
     * {@see Observation::ofMeasured()} — the one place an exit code becomes a
     * verdict input, so that the frozen half and a fresh run cannot be judged
     * by two rules. Deciding it here instead would leave the frozen half,
     * which stores this raw wording, on the old rule.
     */
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
     * One run of a command that is not the product, read exactly as a product
     * run is read: the same heads, the same tokenizing, the same
     * {@see ProcessObservation::outcome()}. Not counted in {@see self::runs()}
     * and not put under the cache protocol — neither says anything about a
     * process that is not the product.
     *
     * @param list<string> $argv
     */
    public function observeCommand(array $argv): ProcessObservation
    {
        try {
            $result = ChildProcess::run($argv, $this->repositoryRoot);
        } catch (RuntimeException $failure) {
            throw new ProbeFailure('the command did not complete: ' . $failure->getMessage(), 0, $failure);
        }

        return new ProcessObservation(
            $result['exitCode'],
            'n/a',
            $this->tokenize(substr($result['stdout'], 0, 400), null),
            $this->tokenize(substr($result['stderr'], 0, 400), null),
            'n/a',
        );
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

        // ProbeFailure, not the bare RuntimeException run() throws: Stand.php
        // catches ProbeFailure by name in takeWitnesses() and rootProbe() to
        // turn a failed probe into a reported failure or a CRASHED
        // observation instead of an uncaught crash of the whole stand.
        //
        // The module's message is carried through verbatim because it is the
        // only thing that says which failure this was: a launch that never
        // happened and a stream that died mid-run arrive here as the same
        // exception class, told apart solely by the prefix run() puts on the
        // message. The header therefore claims none of the three, in the
        // wording run()'s other callers use. "could not be run" was rejected
        // for the same reason "cannot start" was: a read failure means the
        // product did run, and its output is what could not be read.
        try {
            $result = ChildProcess::run($argv, $runDirectory);
        } catch (RuntimeException $failure) {
            throw new ProbeFailure('the product did not complete: ' . $failure->getMessage(), 0, $failure);
        }

        $stdout = $result['stdout'];
        $stderr = $result['stderr'];
        // Taken from the process, never from a pipeline's status: a piped exit
        // code is the last command's, and the round has been bitten by that.
        $exit = $result['exitCode'];
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
            'logfile' => self::workerDecision($logFile),
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

    /**
     * The worker decision, read out of the debug log and nothing else.
     *
     * The line is a JSON record and every field of it used to travel into the
     * observation: the whole line was kept and hashed. Two of those fields
     * move on their own — `timestamp` is second-granular, and `projectRoot` is
     * the probe's run directory, which is keyed on the document the probe
     * writes. So every logfile probe produced a text unique BY CONSTRUCTION,
     * `~` differed from an omitted key for a reason that was the stand's, and
     * `parallel.workers|null` read COLLAPSED against a product that defaults
     * correctly. Only the message and the worker fields survive here.
     *
     * Kept as readable text rather than a digest, deliberately: the defect
     * above lived inside an md5 for a whole round, and a number a reader can
     * see is a number a reader can doubt.
     *
     * Public so a control can address it directly. There is no other way to
     * prove this extraction: the frozen half stores the contaminated digest,
     * md5 is not invertible, and re-judging cannot reach what the digest ate.
     */
    public static function workerDecision(string $logFile): string
    {
        if (!is_file($logFile)) {
            return 'no log';
        }

        $read = file($logFile, \FILE_IGNORE_NEW_LINES);
        $lines = [];

        foreach ($read === false ? [] : $read as $line) {
            /** @var mixed $decoded */
            $decoded = json_decode($line, true);

            if (!\is_array($decoded)) {
                // The stand cannot tell the observable from its environment in
                // a line it cannot read, and guessing would put the run
                // directory back into the comparison.
                if (str_contains($line, 'orkers')) {
                    $lines[] = 'unreadable log line';
                }

                continue;
            }

            // Matched on the DECODED fields, never on the raw line: every
            // record carries file paths, and a scratch directory whose own
            // name happens to hold the word would enrol the whole log.
            $message = (string) ($decoded['message'] ?? '');
            /** @var mixed $context */
            $context = $decoded['context'] ?? [];
            $fields = [];

            foreach (\is_array($context) ? $context : [] as $key => $value) {
                if (!str_contains((string) $key, 'orkers')) {
                    continue;
                }

                $fields[] = (string) $key . '=' . json_encode($value);
            }

            if ($fields === [] && !str_contains($message, 'orkers')) {
                continue;
            }

            $lines[] = implode(' ', [$message, ...$fields]);
        }

        sort($lines, \SORT_STRING);

        return $lines === [] ? 'no workers line' : implode(' / ', $lines);
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
    private function tokenize(string $text, ?string $runDirectory): string
    {
        // Longest prefix first, raw and resolved: the run directory lives
        // inside the scratch root, and on macOS `/tmp` resolves to
        // `/private/tmp`, so the reverse order would leave `<RUN>` unreachable.
        // The product tree is here too — a crash quotes the frame it crashed
        // in, and that frame is an absolute path inside this checkout.
        $roots = [
            $this->scratchRoot => '<SCRATCH>',
            $this->repositoryRoot => '<REPO>',
        ];

        // A null run directory is omitted rather than passed as '': realpath('')
        // resolves to the working directory and would tokenize it as `<RUN>`.
        if ($runDirectory !== null) {
            $roots = [$runDirectory => '<RUN>', ...$roots];
        }

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
