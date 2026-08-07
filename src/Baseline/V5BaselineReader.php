<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use JsonException;
use RuntimeException;

/**
 * Reads a version 5 baseline file — the format {@see BaselineLoader} refuses
 * to load (§6 of the baseline-ceiling plan) and names `bin/qmx
 * baseline:migrate` as the way out of.
 *
 * ```json
 * {
 *   "version": 5,
 *   "generated": "...",
 *   "entries": {
 *     "<canonical symbol>": [ {"rule": "<rule name>", "hash": "<16 hex>"} ]
 *   }
 * }
 * ```
 *
 * v5 carries no magnitude and no `count` — one array element per finding
 * that existed at generation time, distinguished only by an opaque hash
 * (§7). {@see BaselineMigrator} never reads `hash`; it exists here purely so
 * the file is read completely rather than partially.
 *
 * Unlike {@see BaselineLoader}, this reader does not try to salvage a
 * malformed entry as "inert but present": v5 is retired, `migrate` runs
 * once, and a row that does not parse as `{rule, hash}` is simply not a v5
 * record — it is skipped rather than reported, since nothing downstream
 * (§14.1's report) has a slot for "unreadable v5 line". The **envelope**
 * still fails loudly: an unreadable file, invalid JSON, or a version that is
 * not 5 rejects the whole read, mirroring {@see BaselineLoader}'s split
 * between envelope and entry failures.
 */
final readonly class V5BaselineReader
{
    /**
     * @throws RuntimeException when the file cannot be read, is not valid JSON,
     *                          or is not a version 5 baseline
     */
    public function read(string $path): V5Baseline
    {
        $data = self::decode(self::readFile($path));

        self::assertV5($data);

        return self::parseEntries($data);
    }

    /**
     * The non-throwing envelope check `baseline:migrate --force` is built
     * on (§7): whether `$path` is recognisably a v5 file, regardless of
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
            self::assertV5(self::decode(self::readFile($path)));

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

    /**
     * @param array<mixed, mixed> $data already asserted to carry `"version": 5`
     */
    private static function parseEntries(array $data): V5Baseline
    {
        $rawEntries = $data['entries'] ?? null;
        if (!\is_array($rawEntries)) {
            throw new RuntimeException('Baseline "entries" must be an object');
        }

        $entries = [];

        foreach ($rawEntries as $symbolKey => $symbolEntries) {
            $symbolKey = (string) $symbolKey;

            if (!\is_array($symbolEntries)) {
                continue;
            }

            foreach ($symbolEntries as $raw) {
                if (!\is_array($raw)) {
                    continue;
                }

                $rule = $raw['rule'] ?? null;
                $hash = $raw['hash'] ?? null;

                if (!\is_string($rule) || $rule === '' || !\is_string($hash) || $hash === '') {
                    continue;
                }

                $entries[] = new V5Entry($symbolKey, $rule, $hash);
            }
        }

        return new V5Baseline($entries);
    }
}
