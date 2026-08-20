<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use DateTimeImmutable;
use JsonException;

/**
 * Reads a version 13 baseline file.
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
 *
 * **Two reading paths, one meaning.** A file in the canonical layout the
 * writer emits is read by {@see CanonicalBaselineReader} a line at a time; any
 * other layout, including a canonical file a human reformatted, falls back to
 * decoding the whole document here. The two differ only in how bytes become
 * entries — the same entry parser, the same envelope checks and the same
 * duplicate demotion run over the result either way, so which path ran is not
 * observable in what the caller gets.
 */
final readonly class BaselineLoader
{
    /**
     * The spellings of `generated` this build reads: the ATOM form the writer
     * emits, and the same form carrying fractional seconds, which other
     * producers of ISO 8601 add.
     */
    private const array GENERATED_FORMATS = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'];

    /**
     * Rejection reasons for baseline versions this build refuses outright,
     * keyed by the version number found in the file. Each holds a `%v%`
     * placeholder for {@see Baseline::VERSION}, so bumping that constant
     * cannot leave a message naming a stale target version. Adding "this
     * build's own previous version is now rejected" when `VERSION` moves is
     * one line of data here, not a fourth `if` branch to write from scratch.
     */
    private const array REJECTED_VERSION_REASONS = [
        5 => 'This baseline is version 5, a historical format that cannot be loaded or converted to version %v% '
            . 'because declaration identity cannot be inferred from a logical symbol key. Run a fresh analysis, '
            . 'deliberately map or split accepted entries, review every mapping, then write a new version %v% '
            . 'baseline (or regenerate and review the accepted state).',
        10 => 'Baseline version 10 cannot be converted automatically because declaration identity cannot be inferred '
            . 'from a logical symbol key. Run a fresh analysis, deliberately map or split accepted entries, then '
            . 'write a new version %v% baseline (or regenerate and review the accepted state).',
        11 => 'Baseline version 11 cannot be converted automatically: version %v% drops the redundant "count" field '
            . 'and shortens the occurrence key, and there is no converter for either change. Run a fresh analysis '
            . 'and write a new version %v% baseline (or regenerate and review the accepted state).',
        12 => 'Baseline version 12 cannot be converted automatically: version %v% replaces the file position in a '
            . 'declaration key with an assigned ordinal, and no converter can recover which declaration a stored '
            . 'position meant. Run a fresh analysis and write a new version %v% baseline (or regenerate and review '
            . 'the accepted state).',
    ];

    public function __construct(
        private BaselineEntryParser $entryParser,
    ) {}

    /**
     * @throws BaselineLoadException if the file is missing, unreadable, or its envelope is invalid
     */
    public function load(string $path): Baseline
    {
        if (!file_exists($path)) {
            throw new BaselineLoadException("Baseline file not found: {$path}");
        }

        if (!is_readable($path)) {
            throw new BaselineLoadException("Baseline file is not readable: {$path}");
        }

        // Constructed here rather than injected: the recogniser is an
        // implementation detail of this loader with no other consumer and no
        // dependency of its own, so a constructor seam would only be a seam
        // for its own sake.
        $canonical = (new CanonicalBaselineReader($this->entryParser))->read($path);
        if ($canonical !== null) {
            return $this->assembleCanonical($canonical);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new BaselineLoadException("Failed to read baseline file: {$path}");
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BaselineLoadException("Invalid JSON in baseline file: {$e->getMessage()}", 0, $e);
        }

        if (!\is_array($data)) {
            throw new BaselineLoadException('Baseline file must contain a JSON object');
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
     * The envelope checks the whole-document path runs, applied to an envelope
     * that arrived a line at a time.
     *
     * They run in the same order and throw the same sentences, which is the
     * whole requirement: which path read the bytes must never be visible in
     * what the caller gets back, whether that is an answer or a refusal.
     *
     * @param array{
     *     envelope: array<string, mixed>,
     *     entries: list<BaselineEntry>,
     *     inert: list<InertBaselineEntry>,
     *     contentHash: string
     * } $canonical
     */
    private function assembleCanonical(array $canonical): Baseline
    {
        $envelope = $canonical['envelope'];

        $this->assertVersion($envelope['version'] ?? null);

        [$entries, $inertEntries] = $this->separateDuplicates($canonical['entries'], $canonical['inert']);

        return new Baseline(
            generated: $this->parseGenerated($envelope['generated'] ?? null),
            scope: $this->parseScope($envelope['scope'] ?? null),
            entries: $entries,
            inertEntries: $inertEntries,
            sourceContentHash: $canonical['contentHash'],
        );
    }

    /**
     * Version 5 and version 10 are historical formats, not alternate routes
     * into the current schema: their logical symbol keys cannot determine
     * the exact declaration subjects a {@see Baseline::VERSION} baseline
     * requires, so their accepted entries need explicit mapping and review.
     * Version 11 already carries exact declaration subjects — it is refused
     * for a different reason: this build has no converter for the "count"
     * removal or the shortened occurrence key {@see Baseline::VERSION}
     * introduces.
     */
    private function assertVersion(mixed $version): void
    {
        if (!\is_int($version)) {
            throw new BaselineLoadException('Baseline "version" must be an integer');
        }

        if ($version === Baseline::VERSION) {
            return;
        }

        $reason = self::REJECTED_VERSION_REASONS[$version] ?? null;
        if ($reason !== null) {
            throw new BaselineLoadException(strtr($reason, ['%v%' => (string) Baseline::VERSION]));
        }

        throw new BaselineLoadException(\sprintf(
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
            throw new BaselineLoadException('Baseline "generated" must be a string (ISO 8601 datetime)');
        }

        foreach (self::GENERATED_FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $generated);
            $problems = DateTimeImmutable::getLastErrors();

            if ($parsed !== false && ($problems === false || ($problems['warning_count'] + $problems['error_count']) === 0)) {
                return $parsed;
            }
        }

        throw new BaselineLoadException(\sprintf(
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
            throw new BaselineLoadException('Baseline "scope" must be an array of analysed paths');
        }

        $paths = [];
        foreach ($scope as $path) {
            if (!\is_string($path)) {
                throw new BaselineLoadException('Baseline "scope" must hold strings');
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
     * matter and is not tolerated — its numeric keys become subject keys whose
     * buckets fail the list check below, so every element turns inert with a
     * reason rather than slipping through.
     *
     * @return array{list<BaselineEntry>, list<InertBaselineEntry>}
     */
    private function parseEntries(mixed $entries): array
    {
        if (!\is_array($entries)) {
            throw new BaselineLoadException('Baseline "entries" must be an object');
        }

        $parsed = [];
        $inert = [];

        foreach ($entries as $subjectKey => $symbolEntries) {
            $subjectKey = (string) $subjectKey;

            if (!\is_array($symbolEntries) || !array_is_list($symbolEntries)) {
                $inert[] = InertBaselineEntry::forRaw(
                    $subjectKey,
                    null,
                    InertEntryReason::Malformed,
                    'the entries under a subject key must be a JSON array',
                    $symbolEntries,
                );

                continue;
            }

            foreach ($symbolEntries as $raw) {
                $entry = $this->entryParser->parse($subjectKey, $raw);

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
     * An entry that is already inert still *claims* its identity, so it
     * counts. Counting only the applicable ones would let a hand-edited pair
     * — one line the parser accepted, one it rejected on shape, mode or
     * channel — resolve itself by which line happened to parse, which is the
     * guess this method exists to refuse, arrived at from the other side. An
     * inert entry keeps its own more specific reason rather than being
     * relabelled a duplicate: shape, mode and channel are permanent causes,
     * and the duplicate is the reason its *neighbour* stopped applying.
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

        foreach ($inert as $claimed) {
            if ($claimed->identity === null) {
                continue;
            }

            $key = $claimed->identity->key();
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
