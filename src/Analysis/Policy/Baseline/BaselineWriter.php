<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use RuntimeException;

/**
 * Turns a {@see Baseline} into the bytes of a baseline file and puts them in
 * place (ADR 0017).
 *
 * The two halves it needs are owned elsewhere and shared with the channel
 * carry, which renders the same document without ever building a `Baseline`:
 * {@see BaselineDocumentLayout} decides how the document is spelled, and
 * {@see BaselineDocumentWriter} decides how a file is replaced under a lock
 * and a compare-and-swap. What is left here is the part only this class can
 * do — deciding what a `Baseline` serializes to, and which two entries may
 * never share one line.
 *
 * When the baseline being written was loaded from the same file,
 * {@see Baseline::$sourceContentHash} carries what was read and becomes the
 * compare-and-swap token. The hash is a property of the guard, never of the
 * file: nothing written here is described by it, and ADR 0017 lists no such
 * field.
 */
final readonly class BaselineWriter
{
    private BaselineDocumentWriter $documents;

    /**
     * @param float $lockTimeoutSeconds how long to wait for another writer to finish before
     *                                  reporting the contention; tests shorten it, nothing
     *                                  else needs to
     */
    public function __construct(
        float $lockTimeoutSeconds = BaselineDocumentWriter::DEFAULT_LOCK_TIMEOUT_SECONDS,
    ) {
        $this->documents = new BaselineDocumentWriter($lockTimeoutSeconds);
    }

    /**
     * Writes the baseline and returns the SHA-256 of the bytes written —
     * the token a caller passes on if it goes on to modify and write again,
     * via {@see Baseline::withSourceContentHash()}.
     *
     * @throws BaselineConflictException if the target changed or vanished since it was read
     * @throws RuntimeException if the write fails
     */
    public function write(Baseline $baseline, string $path, AbsolutePath $projectRoot): string
    {
        $serialized = $this->serializeBaseline($baseline, $projectRoot);
        $entries = $serialized['entries'];
        unset($serialized['entries']);

        $json = BaselineDocumentLayout::render($serialized, $entries);

        if ($baseline->expectsSourceAbsence) {
            $this->documents->create($path, $json);
        } else {
            $this->documents->replace($path, $json, $baseline->sourceContentHash);
        }

        return hash('sha256', $json);
    }

    /**
     * An empty entry set stays an empty array here and becomes `{}` in
     * {@see BaselineDocumentLayout}: ADR 0017 spells `entries` as an object, and the layout
     * is the one place that decides how a value is spelled.
     *
     * @return array{
     *     version: int,
     *     generated: string,
     *     scope: list<string>,
     *     entries: array<string, list<mixed>>
     * }
     */
    private function serializeBaseline(Baseline $baseline, AbsolutePath $projectRoot): array
    {
        return [
            'version' => BaselineFormatVersion::CURRENT,
            'generated' => $baseline->generated->format('c'),
            'scope' => $baseline->scope,
            'entries' => $this->serializeEntries($baseline, $projectRoot),
        ];
    }

    /**
     * Groups entries under their subject keys in a fixed order.
     *
     * **Every entry read is an entry written.** Entries are accumulated as a
     * list and never as a map: a map key that two entries can share resolves
     * the clash by overwriting, and an entry that leaves the file without
     * anybody deciding it should is removal by inference — the one thing
     * {@see InertBaselineEntry} exists to prevent. Two of those keys were
     * genuinely shared: every entry of a duplicated identity carries the same
     * selector by construction, and so do two byte-identical unreadable
     * lines.
     *
     * **Order does not depend on whether an entry happens to be applicable.**
     * All entries under a symbol sort by channel and then by edge, whatever
     * their state, so the file a command writes does not depend on which
     * configuration produced it. Applicability itself is not a stable fact
     * about an entry — a different `--preset`, a different `--config`, or a
     * run with `computed_metrics:` absent can each change whether a
     * `computed.*` entry resolves as applicable or inert from one invocation
     * to the next — so a valid-block-then-inert-block layout would move
     * those lines whenever that changed. Only an entry whose channel could
     * not be read at all has nothing to sort on; those follow, ordered by
     * selector. Ties keep their input order, which a stable sort guarantees.
     *
     * Inert entries are written back exactly as they were read: `cleanup`
     * never removes an entry on its own, and rewriting a line the loader
     * could not understand would be the same inference in a different place.
     *
     * @throws InvalidArgumentException when two entries collapse onto one identity after
     *                                  their subject keys are relativized
     *
     * @return array<string, list<mixed>>
     */
    private function serializeEntries(Baseline $baseline, AbsolutePath $projectRoot): array
    {
        /** @var array<string, list<array{sort: string, payload: mixed}>> $grouped */
        $grouped = [];
        /** @var array<string, array<string, true>> $seen */
        $seen = [];

        foreach ($baseline->entries as $entry) {
            $key = $this->portableKey($entry->identity->subjectKey, $projectRoot);
            $sort = BaselineEntryOrder::forComponents(
                $entry->identity->channel->code,
                $entry->identity->occurrenceKey,
                $entry->identity->edge?->key(),
            );

            if (isset($seen[$key][$sort])) {
                throw new InvalidArgumentException(\sprintf(
                    'Two baseline entries collapse onto the identity %s once their subject keys are '
                    . 'made project-relative; refusing to write a file that would keep only one of them.',
                    $entry->identity->describe(),
                ));
            }

            $seen[$key][$sort] = true;
            $grouped[$key][] = ['sort' => $sort, 'payload' => $entry->toArray()];
        }

        foreach ($baseline->inertEntries as $entry) {
            $key = $this->portableKey($entry->subjectKey, $projectRoot);
            $grouped[$key][] = ['sort' => self::inertOrderingKey($entry), 'payload' => $entry->raw];
        }

        $serialized = [];
        foreach ($grouped as $key => $items) {
            usort($items, static fn(array $a, array $b): int => strcmp($a['sort'], $b['sort']));

            $payloads = [];
            foreach ($items as $item) {
                $payloads[] = $item['payload'];
            }

            $serialized[$key] = $payloads;
        }

        ksort($serialized, \SORT_STRING);

        return $serialized;
    }

    private static function inertOrderingKey(InertBaselineEntry $entry): string
    {
        if ($entry->identity !== null) {
            return BaselineEntryOrder::forComponents(
                $entry->identity->channel->code,
                $entry->identity->occurrenceKey,
                $entry->identity->edge?->key(),
            );
        }

        if ($entry->channelKey !== null) {
            return BaselineEntryOrder::forComponents($entry->channelKey, null, null);
        }

        return BaselineEntryOrder::forUnreadable($entry->selector);
    }

    /**
     * Converts absolute `file:` canonical paths to relative for portability.
     *
     * Only affects `file:` keys — `class:`, `callable:`, `ns:` keys are
     * FQN-based and already portable. Out-of-tree absolute paths are
     * preserved verbatim so external baselines stay round-trippable.
     * Malformed `file:` payloads (empty, lexically escaping segments)
     * propagate as VO construction exceptions: the writer treats them as
     * in-memory corruption, not as tolerated input.
     *
     * **This is the one place where two identities can become one.** The
     * duplicate guard in {@see Baseline} runs on the raw subject key, so
     * `file:<root>/src/Foo.php` and `file:src/Foo.php` are two legal
     * identities in memory that name one key here. {@see serializeEntries()}
     * therefore refuses such a pair rather than resolving it: silently
     * dropping one of two accepted ceilings is the unsafe direction, and
     * which one survived would depend on assembly order. Normalizing the key
     * on the way into the object instead would need the project root at every
     * construction site, including {@see BaselineLoader}, which has no reason
     * to know it; that plumbing belongs with P3's measured-set seam, not with
     * the file format.
     */
    private function portableKey(string $canonical, AbsolutePath $projectRoot): string
    {
        if (!str_starts_with($canonical, 'file:')) {
            return $canonical;
        }

        $filePath = substr($canonical, 5);

        if ($filePath === '') {
            return $canonical;
        }

        $relative = PathFactory::tryProjectRelative($filePath, $projectRoot);

        return $relative !== null ? 'file:' . $relative->value() : $canonical;
    }
}
