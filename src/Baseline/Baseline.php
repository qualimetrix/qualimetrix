<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A loaded or freshly captured baseline file (§6 of the baseline-ceiling
 * plan): when it was written, over which paths, and the entries it holds.
 *
 * The version is a constant rather than a field. This type *is* the version
 * 10 shape; a file carrying any other version never becomes an instance of
 * it, because {@see BaselineLoader} refuses to build one. Keeping a
 * `version` field would invite code that reads it and branches, which is the
 * shim the project's compatibility policy rules out.
 *
 * Valid and inert entries are kept apart (see {@see InertBaselineEntry}).
 * Everything that suppresses reads {@see $entries}; everything that reports
 * problems reads {@see $inertEntries}; nothing has to remember to filter.
 */
final readonly class Baseline
{
    /** The only file version this type represents (§6). */
    public const int VERSION = 10;

    /** @var array<string, BaselineEntry> identity key => entry */
    private array $byIdentityKey;

    /** @var array<string, list<BaselineEntry|InertBaselineEntry>> selector => entries */
    private array $bySelector;

    /**
     * The analysed path set that produced this file, in the normal form of
     * {@see normalizeScope()} — §6 states the normalisation as an invariant of
     * the *file*, so it is enforced here rather than by whichever component
     * happens to build the object.
     *
     * @var list<string>
     */
    public array $scope;

    /**
     * @param list<string> $scope the analysed path set; normalized on the way in, so capture,
     *                            load and any future `update` all produce one normal form
     * @param list<BaselineEntry> $entries
     * @param list<InertBaselineEntry> $inertEntries
     * @param ?string $sourceContentHash the compare-and-swap token of the file this was
     *                                   loaded from — see {@see BaselineWriter}. It describes
     *                                   *this object's provenance*, not the file's content:
     *                                   nothing in the file is a hash (§5.8)
     *
     * @throws InvalidArgumentException when two entries claim one identity. The loader
     *                                  turns such entries inert before constructing, so
     *                                  reaching this means an in-memory programming error
     */
    public function __construct(
        public DateTimeImmutable $generated,
        array $scope,
        public array $entries,
        public array $inertEntries = [],
        public ?string $sourceContentHash = null,
    ) {
        $this->scope = self::normalizeScope($scope);

        $byIdentityKey = [];
        $bySelector = [];

        foreach ($entries as $entry) {
            $key = $entry->identity->key();

            if (isset($byIdentityKey[$key])) {
                throw new InvalidArgumentException(\sprintf(
                    'Two baseline entries claim the identity %s.',
                    $entry->identity->describe(),
                ));
            }

            $byIdentityKey[$key] = $entry;
            $bySelector[$entry->selector()->value][] = $entry;
        }

        foreach ($inertEntries as $inert) {
            $bySelector[$inert->selector->value][] = $inert;
        }

        $this->byIdentityKey = $byIdentityKey;
        $this->bySelector = $bySelector;
    }

    /**
     * Number of applicable entries. Inert ones are counted separately —
     * reporting "42 entries" and then suppressing on 40 of them would be a
     * lie in the direction that matters.
     */
    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * Every entry the file holds, applicable or not.
     *
     * What a command reporting "the file now has N entries" must use:
     * {@see count()} answers "how many of them suppress", which is a different
     * question and a misleading answer to this one.
     */
    public function totalCount(): int
    {
        return \count($this->entries) + \count($this->inertEntries);
    }

    /**
     * The scope as the file records it: no trailing separators, no duplicates,
     * sorted.
     *
     * Normalizing on the way into the object rather than at one call site is
     * what makes §6's invariant true of *every* baseline: two runs that named
     * the same paths in a different order produce one file, and a loaded file
     * is comparable with a freshly captured one without either side having to
     * remember to normalize first.
     *
     * @param list<string> $scope
     *
     * @return list<string>
     */
    public static function normalizeScope(array $scope): array
    {
        $normalized = [];

        foreach ($scope as $path) {
            $trimmed = rtrim($path, '/');

            if ($trimmed === '' && $path !== '') {
                // The filesystem root is a path; "" after trimming is not.
                $trimmed = '/';
            }

            if ($trimmed !== '') {
                $normalized[$trimmed] = true;
            }
        }

        $paths = array_keys($normalized);
        sort($paths, \SORT_STRING);

        return $paths;
    }

    public function findByIdentity(BaselineIdentity $identity): ?BaselineEntry
    {
        return $this->byIdentityKey[$identity->key()] ?? null;
    }

    public function hasIdentity(BaselineIdentity $identity): bool
    {
        return isset($this->byIdentityKey[$identity->key()]);
    }

    /**
     * Every entry — valid or inert — carrying this selector.
     *
     * Returns a list rather than a single entry because the digest, however
     * unlikely to collide, is not a proof of uniqueness (see
     * {@see EntrySelector}). A caller that finds two reports the ambiguity;
     * it never picks one.
     *
     * @return list<BaselineEntry|InertBaselineEntry>
     */
    public function findBySelector(EntrySelector|string $selector): array
    {
        $value = $selector instanceof EntrySelector
            ? $selector->value
            : (EntrySelector::tryFromString($selector)->value ?? '');

        return $this->bySelector[$value] ?? [];
    }

    /**
     * Entries whose identity did not appear in the run.
     *
     * Keyed on the complete identity of §5.1, not on the symbol: under the
     * finer identity a repaired finding strands its own entry and leaves its
     * neighbours under the same symbol untouched, which is the whole point
     * of the change. What the caller does with the answer differs by
     * caller — staleness reports it, `--show-resolved` counts it — but the
     * predicate is one predicate (§5.7).
     *
     * @param list<string> $measuredIdentityKeys {@see BaselineIdentity::key()} of every
     *                                           identity the run measured
     *
     * @return list<BaselineEntry>
     */
    public function staleEntries(array $measuredIdentityKeys): array
    {
        $measured = array_flip($measuredIdentityKeys);
        $stale = [];

        foreach ($this->entries as $entry) {
            if (!isset($measured[$entry->identity->key()])) {
                $stale[] = $entry;
            }
        }

        return $stale;
    }

    /**
     * The distinct symbol keys the file mentions, valid and inert alike.
     *
     * @return list<string>
     */
    public function symbolKeys(): array
    {
        $keys = [];

        foreach ($this->entries as $entry) {
            $keys[$entry->identity->symbolKey] = true;
        }

        foreach ($this->inertEntries as $inert) {
            $keys[$inert->symbolKey] = true;
        }

        return array_keys($keys);
    }

    /**
     * The same baseline with its compare-and-swap token dropped.
     *
     * Needed when a loaded baseline is written somewhere other than where it
     * came from: the token asserts "the target still holds what I read", and
     * against a different file that assertion is meaningless rather than
     * false.
     */
    public function detached(): self
    {
        return new self(
            generated: $this->generated,
            scope: $this->scope,
            entries: $this->entries,
            inertEntries: $this->inertEntries,
            sourceContentHash: null,
        );
    }

    /**
     * The same baseline holding the compare-and-swap token of a write that
     * has just landed.
     *
     * The counterpart of {@see detached()}: {@see BaselineWriter::write()}
     * returns the token for the bytes it wrote, and without a way to put it
     * back a caller that writes the same instance twice — a read-modify-write
     * such as `update` or `migrate` — would be refused by its own first write.
     */
    public function withSourceContentHash(string $sourceContentHash): self
    {
        return new self(
            generated: $this->generated,
            scope: $this->scope,
            entries: $this->entries,
            inertEntries: $this->inertEntries,
            sourceContentHash: $sourceContentHash,
        );
    }
}
