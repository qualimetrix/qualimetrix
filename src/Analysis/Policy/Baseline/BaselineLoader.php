<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/** Reads the current baseline grammar, then judges entries against the configured channel declarations. */
final readonly class BaselineLoader
{
    public function __construct(private BaselineEntryParser $entryParser) {}

    /** @throws ConfigurationRefusal if the file is unreadable or its document grammar is invalid */
    public function load(string $path): Baseline
    {
        self::assertReadable($path);
        $canonical = (new CanonicalBaselineReader($this->entryParser))->read($path);
        if ($canonical !== null) {
            return $this->assembleCanonical($canonical, $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Failed to read baseline file: {$path}");
        }

        return $this->parseBaseline(BaselineFileShape::decode($content, $path), hash('sha256', $content), $path);
    }

    /** @throws ConfigurationRefusal if the file is missing, not a regular file, or unreadable */
    public static function assertReadable(string $path): void
    {
        if (!file_exists($path)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Baseline file not found: {$path}");
        }
        if (!is_file($path)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Baseline path is not a regular file: {$path}");
        }
        if (!is_readable($path)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Baseline file is not readable: {$path}");
        }
    }

    /** @param array<string, mixed> $data */
    private function parseBaseline(array $data, string $contentHash, string $path): Baseline
    {
        $envelope = BaselineFileShape::envelope($data, $path);
        [$entries, $inertEntries] = $this->parseEntries($data['entries'], $path);

        return new Baseline(
            generated: $envelope['generated'],
            scope: $envelope['scope'],
            entries: $entries,
            exclusions: $envelope['exclusions'],
            inertEntries: $inertEntries,
            sourceContentHash: $contentHash,
        );
    }

    /**
     * @param array{
     *     envelope: array<string, mixed>,
     *     entries: list<BaselineEntry>,
     *     inert: list<InertBaselineEntry>,
     *     contentHash: string
     * } $canonical
     */
    private function assembleCanonical(array $canonical, string $path): Baseline
    {
        $envelope = BaselineFileShape::envelope([...$canonical['envelope'], 'entries' => []], $path);
        [$entries, $inertEntries] = $this->separateDuplicates($canonical['entries'], $canonical['inert']);

        return new Baseline(
            generated: $envelope['generated'],
            scope: $envelope['scope'],
            entries: $entries,
            exclusions: $envelope['exclusions'],
            inertEntries: $inertEntries,
            sourceContentHash: $canonical['contentHash'],
        );
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
    private function parseEntries(mixed $entries, string $path): array
    {
        if (!\is_array($entries)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, 'Baseline "entries" must be an object');
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
        $claimed = [
            ...array_map(static fn(BaselineEntry $entry): BaselineIdentity => $entry->identity, $entries),
            ...array_filter(array_map(static fn(InertBaselineEntry $entry): ?BaselineIdentity => $entry->identity, $inert)),
        ];
        $occurrences = array_count_values(array_map(static fn(BaselineIdentity $identity): string => $identity->key(), $claimed));

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
