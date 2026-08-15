<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use JsonException;
use RuntimeException;

/**
 * Reads a version 5 baseline file — the format {@see BaselineLoader} refuses
 * to load (ADR 0017) and names `bin/qmx
 * baseline:migrate` as the way out of.
 *
 * ```json
 * {
 *   "version": 5,
 *   "generated": "...",
 *   "violations": {
 *     "<canonical symbol>": [ {"rule": "<rule name>", "hash": "<16 hex>"} ]
 *   }
 * }
 * ```
 *
 * v5 carries no magnitude and no `count` — one array element per finding
 * that existed at generation time, distinguished only by an opaque hash
 * (ADR 0017). {@see BaselineMigrator} never reads `hash`; it exists here purely so
 * the file is read completely rather than partially.
 *
 * Unlike {@see BaselineLoader}, this reader does not try to salvage a
 * malformed entry as "inert but present": v5 is retired and a row that does
 * not parse as `{rule, hash}` is simply not a v5 record, so it cannot become
 * an entry. It is **collected rather than dropped**, though — see
 * {@see V5UnreadableRecord}: `migrate` runs once, so a row skipped in silence
 * is an acceptance the user loses without ever learning it was there. Refusing
 * the whole file over one bad row would be the opposite mistake, and the one
 * ADR 0017 names explicitly for the v10 loader.
 *
 * The **envelope** still fails loudly: an unreadable file, invalid JSON, or a
 * version that is not 5 rejects the whole read, mirroring
 * {@see BaselineLoader}'s split between envelope and entry failures.
 */
final readonly class V5BaselineReader
{
    /**
     * @throws RuntimeException when the file cannot be read, is not valid JSON,
     *                          or is not a version 5 baseline
     */
    public function read(string $path): V5Baseline
    {
        return self::parseSnapshot(self::readFile($path));
    }

    /**
     * Reads the single source snapshot `baseline:migrate` will replace.
     *
     * Under `--force`, valid bytes that are not a v5 envelope intentionally
     * produce no old records, but their hash is still preserved: force permits
     * replacing that snapshot, not a later write another process made. An
     * absent destination has an explicit expected-absence provenance. An existing
     * unreadable destination cannot be compared safely and is still rejected.
     *
     * @throws RuntimeException when the file cannot be read, or is not v5 without `--force`
     */
    public function readForMigration(string $path, bool $force): V5Baseline
    {
        if (!file_exists($path) && $force) {
            return new V5Baseline([]);
        }

        $content = self::readFile($path);

        try {
            return self::parseSnapshot($content);
        } catch (RuntimeException $e) {
            if (!$force) {
                throw $e;
            }

            return new V5Baseline([], [], hash('sha256', $content));
        }
    }

    /**
     * The non-throwing envelope check `baseline:migrate --force` is built
     * on (ADR 0017): whether `$path` is recognisably a v5 file, regardless of
     * whether every entry inside it would parse.
     *
     * A path that does not exist, is not readable, is not valid JSON, or
     * names any version other than 5 — including 10 — answers `false`.
     * `migrate` uses this the other way round: a destination that answers
     * `false` here is not the v5 file `migrate` exists to convert, so
     * overwriting it needs `--force`.
     */
    public function isV5File(string $path): bool
    {
        try {
            self::parseSnapshot(self::readFile($path));

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @throws RuntimeException
     */
    private static function readFile(string $path): string
    {
        if (!file_exists($path)) {
            throw new RuntimeException("Baseline file not found: {$path}");
        }

        if (!is_readable($path)) {
            throw new RuntimeException("Baseline file is not readable: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read baseline file: {$path}");
        }

        return $content;
    }

    /**
     * @throws RuntimeException
     */
    private static function decode(string $content): mixed
    {
        try {
            return json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON in baseline file: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * @throws RuntimeException
     */
    private static function assertV5(mixed $data): void
    {
        if (!\is_array($data)) {
            throw new RuntimeException('Baseline file must contain a JSON object');
        }

        $version = $data['version'] ?? null;
        if (!\is_int($version)) {
            throw new RuntimeException('Baseline "version" must be an integer');
        }

        if ($version === 5) {
            return;
        }

        if ($version === Baseline::VERSION) {
            throw new RuntimeException(\sprintf(
                'This baseline is already version %d; there is nothing to migrate.',
                Baseline::VERSION,
            ));
        }

        throw new RuntimeException(\sprintf(
            'Expected a version 5 baseline to migrate, got version %d.',
            $version,
        ));
    }

    private static function parseSnapshot(string $content): V5Baseline
    {
        $data = self::decode($content);
        self::assertV5($data);

        return self::parseViolations($data, hash('sha256', $content));
    }

    /**
     * @param array<mixed, mixed> $data already asserted to carry `"version": 5`
     */
    private static function parseViolations(array $data, string $sourceContentHash): V5Baseline
    {
        $rawEntries = $data['violations'] ?? null;
        if (!\is_array($rawEntries)) {
            throw new RuntimeException('Baseline "violations" must be an object');
        }

        $entries = [];
        $unreadable = [];

        foreach ($rawEntries as $symbolKey => $symbolEntries) {
            $symbolKey = (string) $symbolKey;

            if (!\is_array($symbolEntries)) {
                $unreadable[] = new V5UnreadableRecord(
                    $symbolKey,
                    'the symbol\'s value is not a list of records',
                );

                continue;
            }

            foreach ($symbolEntries as $raw) {
                if (!\is_array($raw)) {
                    $unreadable[] = new V5UnreadableRecord($symbolKey, 'a record is not an object');

                    continue;
                }

                $rule = $raw['rule'] ?? null;
                $hash = $raw['hash'] ?? null;

                $problem = self::recordProblem($rule, $hash);

                if ($problem !== null) {
                    $unreadable[] = new V5UnreadableRecord($symbolKey, $problem);

                    continue;
                }

                \assert(\is_string($rule) && \is_string($hash));

                $entries[] = new V5Entry($symbolKey, $rule, $hash);
            }
        }

        return new V5Baseline($entries, $unreadable, $sourceContentHash);
    }

    /**
     * What is wrong with a record's two fields, or `null` when nothing is.
     *
     * Both fields are named in one message rather than reporting the first
     * failure only: a user fixing a hand-edited file wants the whole verdict
     * on the row, and `migrate` will not read the file a second time to find
     * the rest.
     */
    private static function recordProblem(mixed $rule, mixed $hash): ?string
    {
        $problems = [];

        if (!\is_string($rule) || $rule === '') {
            $problems[] = '"rule" is missing or not a non-empty string';
        }

        if (!\is_string($hash) || $hash === '') {
            $problems[] = '"hash" is missing or not a non-empty string';
        }

        return $problems === [] ? null : implode('; ', $problems);
    }
}
