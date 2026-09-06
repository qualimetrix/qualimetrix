<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use JsonException;
use RuntimeException;

/**
 * Rewrites the `channel` field of a baseline file's entries along a declared
 * map, and nothing else.
 *
 * **It runs no analysis and reads no project code.** After a channel's
 * `occurrence` was frozen against its name, a rename is a substitution of one
 * string — `occurrence` cannot be recomputed from a baseline in the first
 * place, so a carry that ran an analysis would be recomputing identities from
 * a tree that is not the one the entries were accepted against.
 *
 * **It carries the raw document, not a loaded `Baseline`.** The loader does
 * not refuse an entry it cannot apply; it demotes it to an
 * {@see InertBaselineEntry} (ADR 0017). A load-rewrite-save cycle is
 * therefore lossy in exactly the case that matters: the loader of *today's*
 * build meeting a file written for another one. Reading raw keeps every line
 * the user accepted, including the ones this build has no opinion about, and
 * the price — that the document is rendered rather than echoed — is paid by
 * {@see BaselineDocumentLayout}, which is the same renderer every other
 * `baseline:*` command writes through.
 *
 * What a carry changes is therefore exactly: the value of a `channel` field
 * the map names, the position of an entry among its siblings when the new
 * name sorts differently, and the order of subject keys if the file was not
 * already canonical. Subject keys, `occurrence`, `count`, `magnitudes`,
 * `mode`, `edge`, `scope`, `generated` and any envelope field this build does
 * not know are carried through untouched.
 */
final readonly class BaselineChannelRenamer
{
    /** The identity key separator, as {@see BaselineIdentity} spells it. */
    private const string KEY_SEPARATOR = "\x1F";

    public function __construct(
        private BaselineDocumentWriter $documents,
    ) {}

    /**
     * @throws ChannelRenameRefusal when the file is not a version this build can carry, when
     *                              its envelope cannot be read, or when the carry would put
     *                              two entries of one identity in the file
     * @throws BaselineConflictException when the file changed between the read and the write
     * @throws RuntimeException when the file cannot be read or replaced
     */
    public function carry(string $path, ChannelRenameMap $map): ChannelRenameReport
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Cannot read the baseline file %s.', $path));
        }

        $document = self::decode($contents, $path);
        $entries = self::readEntries($document, $path);
        unset($document['entries']);

        $rowHits = array_fill_keys($map->oldNames(), 0);
        $unreadable = [];
        $total = 0;
        $renamed = 0;
        $carried = [];

        foreach ($entries as $subjectKey => $payloads) {
            $before = [];
            $after = [];
            $ordered = [];

            foreach ($payloads as $raw) {
                ++$total;
                $entry = BaselineEntryPayload::of($subjectKey, $raw);
                $before[] = $entry->identityKey();

                $channel = $entry->channel();
                $replacement = $channel === null ? null : $map->translate($channel);

                if ($channel !== null && $replacement !== null) {
                    $entry = $entry->withChannel($replacement);
                    ++$rowHits[$channel];
                    ++$renamed;
                }

                $after[] = $entry->identityKey();
                $ordered[] = ['sort' => $entry->orderingKey(), 'payload' => $entry->raw];
                self::count($entry->unreadableReason(), $unreadable);
            }

            self::assertNoCarriedCollision($subjectKey, $before, $after);
            self::countPreexistingDuplicates($before, $unreadable);

            usort($ordered, static fn(array $a, array $b): int => strcmp($a['sort'], $b['sort']));

            $block = [];
            foreach ($ordered as $item) {
                $block[] = $item['payload'];
            }

            $carried[$subjectKey] = $block;
        }

        if ($renamed === 0) {
            return new ChannelRenameReport($total, 0, $rowHits, $unreadable, written: false);
        }

        ksort($carried, \SORT_STRING);

        $this->documents->replace(
            $path,
            BaselineDocumentLayout::render($document, $carried),
            hash('sha256', $contents),
        );

        return new ChannelRenameReport($total, $renamed, $rowHits, $unreadable, written: true);
    }

    /**
     * The envelope, checked only as far as a carry needs it: this is not the
     * loader, and a defect inside an entry is carried rather than refused.
     *
     * @throws ChannelRenameRefusal
     *
     * @return array<string, mixed>
     */
    private static function decode(string $contents, string $path): array
    {
        try {
            $document = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ChannelRenameRefusal(\sprintf('%s is not valid JSON: %s', $path, $e->getMessage()));
        }

        if (!\is_array($document) || array_is_list($document)) {
            throw new ChannelRenameRefusal(\sprintf('%s is not a baseline document: its root must be a JSON object.', $path));
        }

        $version = $document['version'] ?? null;

        if (!\is_int($version)) {
            throw new ChannelRenameRefusal(\sprintf('%s has no integer "version"; it is not a baseline file.', $path));
        }

        if ($version !== Baseline::VERSION) {
            throw new ChannelRenameRefusal(\sprintf(
                '%s is a version %d baseline and this build carries version %d. A carry substitutes a name and '
                . 'converts nothing, so the file has to be brought to version %d first.',
                $path,
                $version,
                Baseline::VERSION,
                Baseline::VERSION,
            ));
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * A subject whose block is not a JSON array is refused rather than
     * carried: there are no entry lines to enumerate under it, and the two
     * alternatives are worse. Rendering it verbatim would spell a document
     * the writer never produces, and reshaping it into a one-element block
     * would be this command deciding what a line the user wrote means. A
     * refusal leaves the file byte-identical and names the subject.
     *
     * @param array<string, mixed> $document
     *
     * @throws ChannelRenameRefusal
     *
     * @return array<string, list<mixed>>
     */
    private static function readEntries(array $document, string $path): array
    {
        $entries = $document['entries'] ?? null;

        if (!\is_array($entries)) {
            throw new ChannelRenameRefusal(\sprintf('%s has no "entries" object.', $path));
        }

        $blocks = [];

        foreach ($entries as $subjectKey => $block) {
            $subjectKey = (string) $subjectKey;

            if (!\is_array($block) || !array_is_list($block)) {
                throw new ChannelRenameRefusal(\sprintf(
                    '%s stores the entries of "%s" as something other than a JSON array, so they cannot be '
                    . 'carried one by one. Repair that block first; the file is left untouched.',
                    $path,
                    $subjectKey,
                ));
            }

            $blocks[$subjectKey] = $block;
        }

        return $blocks;
    }

    /**
     * Refuses only a collision this carry *created*.
     *
     * A file that already holds two entries of one identity is a file the
     * loader already demotes to inert on both, and a carry is not the place
     * that repair happens: refusing on it would let one pre-existing
     * duplicate — possibly under a subject the map never touches — block the
     * rename of everything else. What must not happen is the carry *making*
     * such a pair, because the writer's own guard against it
     * ({@see BaselineWriter::serializeEntries()}) is bypassed by the raw
     * path.
     *
     * @param list<?string> $before
     * @param list<?string> $after
     *
     * @throws ChannelRenameRefusal
     */
    private static function assertNoCarriedCollision(string $subjectKey, array $before, array $after): void
    {
        $beforeCounts = array_count_values(array_filter($before, static fn(?string $key): bool => $key !== null));
        $afterCounts = array_count_values(array_filter($after, static fn(?string $key): bool => $key !== null));

        foreach ($afterCounts as $key => $count) {
            if ($count < 2 || $count <= ($beforeCounts[$key] ?? 0)) {
                continue;
            }

            throw new ChannelRenameRefusal(\sprintf(
                'Carrying "%s" would give %d entries of "%s" one identity, and a baseline cannot hold two '
                . 'ceilings for one thing. Nothing was written; decide which of them survives first.',
                $subjectKey,
                $count,
                explode(self::KEY_SEPARATOR, (string) $key)[1] ?? '',
            ));
        }
    }

    /**
     * @param list<?string> $before
     * @param array<string, int> $unreadable
     */
    private static function countPreexistingDuplicates(array $before, array &$unreadable): void
    {
        foreach (array_count_values(array_filter($before, static fn(?string $key): bool => $key !== null)) as $count) {
            if ($count > 1) {
                $unreadable[ChannelRenameReport::UNREADABLE_ALREADY_DUPLICATE]
                    = ($unreadable[ChannelRenameReport::UNREADABLE_ALREADY_DUPLICATE] ?? 0) + $count;
            }
        }
    }

    /**
     * @param array<string, int> $unreadable
     */
    private static function count(?string $reason, array &$unreadable): void
    {
        if ($reason === null) {
            return;
        }

        $unreadable[$reason] = ($unreadable[$reason] ?? 0) + 1;
    }
}
