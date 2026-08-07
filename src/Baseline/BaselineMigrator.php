<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * Converts a version 5 baseline into a version 10 one — `bin/qmx
 * baseline:migrate` (§7 of the baseline-ceiling plan).
 *
 * The new baseline is not a merge: it is exactly `$freshCapture`'s baseline,
 * because a v5 record carries no magnitude to merge in (§6 — its `hash` is
 * opaque and does not recover one). What this class adds is the report:
 * which of the old file's acceptances the fresh capture still backs, which
 * it lost, and which entries are new. See {@see MigrationReport} for the
 * three groups' exact meaning.
 *
 * **Matching is on `($symbolKey, $rule)` only**, because that pair is the
 * one thing a v5 record and a v10 finding both carry: the v5 key already is
 * `SymbolPath::toCanonical()`, and `$rule` is the prefix of a v10 channel
 * key up to `#`. Everything finer — `violationCode`, the dependency edge,
 * the magnitude itself — exists only on one side and cannot be matched
 * across the format boundary. A symbol/rule pair that produced two v10
 * entries (two violation codes, or two edges) still counts as one carried
 * pair; {@see MigrationReport::$carriedV10EntryCount} is where that shows up.
 */
final readonly class BaselineMigrator
{
    public function __construct(
        private V5BaselineReader $v5Reader,
    ) {}

    /**
     * @param V5Baseline $v5 the parsed v5 file
     * @param BaselineCapture $freshCapture what {@see BaselineGenerator} captured from the
     *                                      measured set over the same paths — the new baseline
     *                                      and the report are both built from this run alone
     */
    public function migrate(V5Baseline $v5, BaselineCapture $freshCapture): BaselineMigratorResult
    {
        $v5Pairs = self::groupV5ByPair($v5);
        $v10CountsByPair = self::groupV10ByPair($freshCapture->baseline);

        $carriedV5EntryCount = 0;
        $carriedV10EntryCount = 0;
        $dropped = [];

        foreach ($v5Pairs as $pairKey => $group) {
            if (isset($v10CountsByPair[$pairKey])) {
                $carriedV5EntryCount += $group['count'];
                $carriedV10EntryCount += $v10CountsByPair[$pairKey];

                continue;
            }

            $dropped[] = new MigrationReportDroppedEntry($group['symbolKey'], $group['rule']);
        }

        $freshV10EntryCount = 0;
        foreach ($v10CountsByPair as $pairKey => $count) {
            if (!isset($v5Pairs[$pairKey])) {
                $freshV10EntryCount += $count;
            }
        }

        $report = new MigrationReport(
            carriedV5EntryCount: $carriedV5EntryCount,
            carriedV10EntryCount: $carriedV10EntryCount,
            dropped: $dropped,
            freshV10EntryCount: $freshV10EntryCount,
            uncapturedGroupCount: \count($freshCapture->uncaptured),
        );

        return new BaselineMigratorResult($freshCapture->baseline, $report);
    }

    /**
     * The predicate `--force` is built on (§7): a `migrate` destination that
     * is not recognisably the v5 file the command exists to convert — it is
     * already version 10, unparseable, or simply not present — must not be
     * silently overwritten by a fresh capture. A typo'd path pointing at a
     * good v10 baseline is exactly the accident this guards against.
     *
     * `true` means the command must refuse to write unless the caller passed
     * `--force`; `false` means the ordinary migration may proceed.
     */
    public function destinationRequiresForce(string $baselinePath): bool
    {
        return !$this->v5Reader->isV5File($baselinePath);
    }

    /**
     * @return array<string, array{symbolKey: string, rule: string, count: int}> pair key =>
     *                                                                           how many v5
     *                                                                           records shared it
     */
    private static function groupV5ByPair(V5Baseline $v5): array
    {
        $groups = [];

        foreach ($v5->entries as $entry) {
            $pairKey = self::pairKey($entry->symbolKey, $entry->rule);

            $groups[$pairKey] ??= ['symbolKey' => $entry->symbolKey, 'rule' => $entry->rule, 'count' => 0];
            ++$groups[$pairKey]['count'];
        }

        return $groups;
    }

    /**
     * @return array<string, int> pair key => how many v10 entries share it
     */
    private static function groupV10ByPair(Baseline $baseline): array
    {
        $counts = [];

        foreach ($baseline->entries as $entry) {
            $pairKey = self::pairKey($entry->identity->symbolKey, $entry->identity->channel->ruleName);

            $counts[$pairKey] = ($counts[$pairKey] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Mirrors {@see BaselineIdentity}'s own separator choice: a printable
     * separator could occur inside a symbol key or a rule name and let two
     * different pairs collapse onto one string.
     */
    private static function pairKey(string $symbolKey, string $rule): string
    {
        return $symbolKey . "\x1F" . $rule;
    }
}
