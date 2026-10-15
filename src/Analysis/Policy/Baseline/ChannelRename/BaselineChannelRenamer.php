<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\ChannelRename;

use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineDocument;
use Qualimetrix\Analysis\Policy\Baseline\DocumentAccess\BaselineConflictException;
use Qualimetrix\Analysis\Policy\Baseline\DocumentAccess\BaselineDocumentWriter;
use Qualimetrix\Analysis\Policy\Baseline\DocumentReading\BaselineFileShape;
use Qualimetrix\Analysis\Policy\Baseline\DocumentWriting\BaselineDocumentLayout;
use Qualimetrix\Analysis\Policy\Baseline\DocumentWriting\BaselineEntryOrder;
use Qualimetrix\Analysis\Policy\Baseline\DocumentWriting\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\Entry\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\Entry\BaselineIdentity;

use Qualimetrix\Analysis\Policy\Baseline\Entry\InertBaselineEntry;
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
 * `mode`, `edge`, `scope`, `exclusions` and `generated` are carried through untouched.
 *
 * **Three owners decide what a carried file is, and this class is only one of
 * them.** Reading a document raw is not a licence to hold a private opinion of
 * what a baseline is:
 *
 * - **BaselineFileShape** judges the same closed document grammar as the
 *   loader, including envelope, entry, edge and exclusion keys. A known
 *   entry field with an unusable value is carried and counted instead;
 * - the **writer** owns what a *file* looks like — block shape and line order
 *   are {@see BaselineDocumentLayout} and {@see BaselineEntryOrder}, so a
 *   carried file is laid out where {@see BaselineWriter} would have laid it
 *   out;
 * - the **file** owns each *line's bytes* — a payload is echoed in the field
 *   order it was decoded in, because reshaping a line written by another
 *   build is the one thing the raw path exists to avoid. A later command that
 *   loads and rewrites the file may therefore re-render such a line in place;
 *   it will not move it.
 *
 * What is left for this class alone is the map, and the one collision a carry
 * can create that no other writer would.
 *
 * @qmx-threshold coupling.instability warning=0.800001 error=0.95 -- Channel carry composes the existing loader, writer, layout, and map vocabulary for one command. Extracting a single-reader wrapper would move the same dependencies; the ceiling keeps added dependencies visible.
 */
final readonly class BaselineChannelRenamer
{
    /** The identity key separator, as {@see BaselineIdentity} spells it. */
    private const string KEY_SEPARATOR = "\x1F";

    public function __construct(
        private BaselineDocumentWriter $documents,
    ) {}

    /**
     * @throws \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal when the document grammar is invalid
     * @throws ChannelRenameRefusal when a carry would put two distinct entries under one identity
     * @throws BaselineConflictException when the file changed between the read and the write
     * @throws RuntimeException when the file cannot be read or replaced
     */
    public function carry(BaselineDocument $source, ChannelRenameMap $map): ChannelRenameReport
    {
        $document = BaselineFileShape::decode($source->bytes(), $source->path);
        $entries = self::readEntries($document);
        unset($document['entries']);

        $rowHits = array_fill_keys($map->oldNames(), 0);
        $unreadable = [];
        $total = 0;
        $renamed = 0;
        $carried = [];

        foreach ($entries as $subjectKey => $payloads) {
            $before = [];
            $moved = [];
            $ordered = [];

            foreach ($payloads as $entry) {
                ++$total;
                $wasKey = $entry->identityKey();
                $before[] = $wasKey;

                $channel = $entry->channel();
                $replacement = $channel === null ? null : $map->translate($channel);

                if ($channel !== null && $replacement !== null) {
                    $entry = $entry->withChannel($replacement);
                    ++$rowHits[$channel];
                    ++$renamed;
                }

                $moved[] = ['before' => $wasKey, 'after' => $entry->identityKey()];
                $ordered[] = ['sort' => $entry->orderingKey(), 'payload' => $entry->raw];
                self::count($entry->unreadableReason(), $unreadable);
            }

            self::assertNoCarriedCollision($subjectKey, $moved);
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
            $source->target,
            BaselineDocumentLayout::render($document, $carried),
            $source->contentHash,
        );

        return new ChannelRenameReport($total, $renamed, $rowHits, $unreadable, written: true);
    }

    /**
     * A subject whose block is not a JSON array holds no entry lines to
     * enumerate, and it is carried rather than refused: the loader demotes
     * exactly this block to a single inert line (ADR 0017) and the writer
     * puts that line back as a one-element list, so refusing would make the
     * carry the only member of the family with an opinion — and would let one
     * hand-edited block, possibly under a subject the map never touches,
     * block the rename of everything else. The line is counted in the report
     * and its bytes are untouched; in particular no `channel` inside it is
     * renamed, because the loader reads no channel there either.
     *
     * @param array<string, mixed> $document
     *
     * @throws ChannelRenameRefusal
     *
     * @return array<string, list<BaselineEntryPayload>>
     */
    private static function readEntries(array $document): array
    {
        $entries = $document['entries'] ?? null;

        \assert(\is_array($entries));

        $blocks = [];

        foreach ($entries as $subjectKey => $block) {
            $subjectKey = (string) $subjectKey;

            if (!\is_array($block) || !array_is_list($block)) {
                $blocks[$subjectKey] = [BaselineEntryPayload::ofUncarriableBlock($subjectKey, $block)];

                continue;
            }

            $lines = [];

            foreach ($block as $raw) {
                $lines[] = BaselineEntryPayload::of($subjectKey, $raw);
            }

            $blocks[$subjectKey] = $lines;
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
     * "Created" is therefore a question about the *pre-images* of a shared
     * key, not about counts under it: a rename moves the identity key itself,
     * so a pair that was already twinned on the old name arrives at the new
     * one as a key nothing held before, and counting would read that as a
     * collision the carry made. What actually distinguishes the two is
     * whether the lines now sharing a key were distinct beforehand.
     *
     * @param list<array{before: ?string, after: ?string}> $moved
     *
     * @throws ChannelRenameRefusal
     */
    private static function assertNoCarriedCollision(string $subjectKey, array $moved): void
    {
        /** @var array<string, array{origins: array<string, true>, lines: int}> $groups */
        $groups = [];

        foreach ($moved as $index => $line) {
            if ($line['after'] === null) {
                continue;
            }

            // A line that formed no identity before shared one with nothing,
            // so it is its own pre-image rather than a twin of every other
            // such line.
            $origin = $line['before'] ?? self::KEY_SEPARATOR . $index;

            $groups[$line['after']] ??= ['origins' => [], 'lines' => 0];
            $groups[$line['after']]['origins'][$origin] = true;
            ++$groups[$line['after']]['lines'];
        }

        foreach ($groups as $key => $group) {
            if (\count($group['origins']) < 2) {
                continue;
            }

            throw new ChannelRenameRefusal(\sprintf(
                'Carrying "%s" would give %d entries of "%s" one identity, and a baseline cannot hold two '
                . 'ceilings for one thing. Nothing was written; decide which of them survives first.',
                $subjectKey,
                $group['lines'],
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
