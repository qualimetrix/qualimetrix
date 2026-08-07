<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * Reads a version 10 baseline file (ADR 0017).
 *
 * Two failure classes, deliberately handled differently:
 *
 * - **The envelope** — an unreadable file, invalid JSON, a version this
 *   build does not speak, a missing `scope` — throws. There is no partial
 *   answer to give: nothing in the file can be trusted to mean what it says.
 * - **An entry** — never throws. One bad line must not cost a user the other
 *   thousand, so it becomes an {@see InertBaselineEntry}, which does not
 *   suppress and which `check` reports.
 *
 * The loader also records the file's content hash on the returned
 * {@see Baseline}. That is the compare-and-swap token
 * {@see BaselineWriter} needs to make a read-modify-write safe (ADR 0017); it is
 * not, and must never become, a field of the file.
 */
final readonly class BaselineLoader
{
    /**
     * The spellings of `generated` this build reads: the ATOM form the writer
     * emits, and the same form carrying fractional seconds, which other
     * producers of ISO 8601 add.
     */
    private const array GENERATED_FORMATS = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'];

    public function __construct(
        private BaselineEntryParser $entryParser,
    ) {}

    /**
     * @throws RuntimeException if the file is missing, unreadable, or its envelope is invalid
     */
    public function load(string $path): Baseline
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

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON in baseline file: {$e->getMessage()}", 0, $e);
        }

        if (!\is_array($data)) {
            throw new RuntimeException('Baseline file must contain a JSON object');
        }

        return $this->parseBaseline($data, hash('sha256', $content));
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function parseBaseline(array $data, string $contentHash): Baseline
    {
        $this->assertVersion($data['version'] ?? null);

        [$entries, $inertEntries] = $this->parseEntries($data['entries'] ?? null);

        return new Baseline(
            generated: $this->parseGenerated($data['generated'] ?? null),
            scope: $this->parseScope($data['scope'] ?? null),
            entries: $entries,
            inertEntries: $inertEntries,
            sourceContentHash: $contentHash,
        );
    }

    /**
     * Version 5 gets its own message naming the route out of it. The route —
     * `baseline:migrate` — is deliberately named before the command exists:
     * a user meeting this error needs to know what to look for, and a
     * message that only says "unsupported" sends them to regenerate, which
     * throws away every acceptance they had recorded.
     */
    private function assertVersion(mixed $version): void
    {
        if (!\is_int($version)) {
            throw new RuntimeException('Baseline "version" must be an integer');
        }

        if ($version === Baseline::VERSION) {
            return;
        }

        if ($version === 5) {
            throw new RuntimeException(
                'This baseline is version 5, which records only that a finding existed. '
                . 'Version ' . Baseline::VERSION . ' records the magnitude it was accepted at. '
                . 'Run `bin/qmx baseline:migrate <file>` to convert it.',
            );
        }

        throw new RuntimeException(\sprintf(
            'Unsupported baseline version: %d. Expected version %d.',
            $version,
            Baseline::VERSION,
        ));
    }

    /**
     * ADR 0017 says ISO 8601, so ISO 8601 is what is accepted — not everything
     * PHP's date parser understands.
     *
     * `new DateTimeImmutable($s)` accepts `"tomorrow"`, `"now"` and every
     * other relative form, each of which yields a valid-looking timestamp
     * that has nothing to do with when the file was written. Matching the
     * literal grammar instead means a `generated` that cannot be read as an
     * instant is reported as such. Warnings are treated as failures too:
     * `createFromFormat` happily rolls `2026-13-45` over into February and
     * only mentions it in `getLastErrors()`.
     */
    private function parseGenerated(mixed $generated): DateTimeImmutable
    {
        if (!\is_string($generated)) {
            throw new RuntimeException('Baseline "generated" must be a string (ISO 8601 datetime)');
        }

        foreach (self::GENERATED_FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $generated);
            $problems = DateTimeImmutable::getLastErrors();

            if ($parsed !== false && ($problems === false || ($problems['warning_count'] + $problems['error_count']) === 0)) {
                return $parsed;
            }
        }

        throw new RuntimeException(\sprintf(
            'Baseline "generated" must be an ISO 8601 datetime with an offset (for example '
            . '2026-08-05T12:00:00+03:00), got: %s',
            $generated,
        ));
    }

    /**
     * The scope exactly as the file spells it; {@see Baseline} owns the
     * normal form, so a hand-written `["src/", "src"]` becomes `["src"]`
     * there rather than being carried into a comparison unnormalised.
     *
     * @return list<string>
     */
    private function parseScope(mixed $scope): array
    {
        if (!\is_array($scope) || !array_is_list($scope)) {
            throw new RuntimeException('Baseline "scope" must be an array of analysed paths');
        }

        $paths = [];
        foreach ($scope as $path) {
            if (!\is_string($path)) {
                throw new RuntimeException('Baseline "scope" must hold strings');
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * ADR 0017 spells `entries` as an object, and an empty JSON *list* is accepted
     * for it deliberately. `json_decode(..., true)` renders `{}` and `[]`
     * identically as an empty PHP array, so no check can tell them apart, and
     * both mean the same thing: no entries. A non-empty list is a different
     * matter and is not tolerated — its numeric keys become symbol keys whose
     * buckets fail the list check below, so every element turns inert with a
     * reason rather than slipping through.
     *
     * @return array{list<BaselineEntry>, list<InertBaselineEntry>}
     */
    private function parseEntries(mixed $entries): array
    {
        if (!\is_array($entries)) {
            throw new RuntimeException('Baseline "entries" must be an object');
        }

        $parsed = [];
        $inert = [];

        foreach ($entries as $symbolKey => $symbolEntries) {
            $symbolKey = (string) $symbolKey;

            if (!\is_array($symbolEntries) || !array_is_list($symbolEntries)) {
                $inert[] = InertBaselineEntry::forRaw(
                    $symbolKey,
                    null,
                    InertEntryReason::Malformed,
                    'the entries under a symbol key must be a JSON array',
                    $symbolEntries,
                );

                continue;
            }

            foreach ($symbolEntries as $raw) {
                $entry = $this->entryParser->parse($symbolKey, $raw);

                if ($entry instanceof InertBaselineEntry) {
                    $inert[] = $entry;
                } else {
                    $parsed[] = $entry;
                }
            }
        }

        return $this->separateDuplicates($parsed, $inert);
    }

    /**
     * Demotes every entry of a repeated identity, not just the repeats.
     *
     * With nothing in the file to say which of two entries for one identity
     * was meant, keeping either is a guess, and the guess suppresses. ADR 0017
     * calls duplicate identities invalid; this is what invalid has to mean
     * for the fail-safe direction to hold.
     *
     * @param list<BaselineEntry> $entries
     * @param list<InertBaselineEntry> $inert
     *
     * @return array{list<BaselineEntry>, list<InertBaselineEntry>}
     */
    private function separateDuplicates(array $entries, array $inert): array
    {
        $occurrences = [];
        foreach ($entries as $entry) {
            $key = $entry->identity->key();
            $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
        }

        $unique = [];
        foreach ($entries as $entry) {
            if ($occurrences[$entry->identity->key()] === 1) {
                $unique[] = $entry;

                continue;
            }

            $inert[] = InertBaselineEntry::forIdentity(
                $entry->identity,
                InertEntryReason::DuplicateIdentity,
                \sprintf(
                    '%d entries claim this identity, so none of them is applied',
                    $occurrences[$entry->identity->key()],
                ),
                $entry->toArray(),
            );
        }

        return [$unique, $inert];
    }
}
