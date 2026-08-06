<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline\Filter;

use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Core\Violation\Filter\ViolationFilterInterface;
use Qualimetrix\Core\Violation\Violation;

/**
 * Suppresses violations whose identity (§5.1 of the baseline-ceiling plan)
 * is recorded in the baseline.
 *
 * The key is the identity — symbol, channel and dependency edge — replacing
 * the opaque hash of the version 5 file. What is suppressed is unchanged for
 * a user: a finding listed in the baseline does not appear.
 *
 * The magnitude semantics the identity was introduced for — a group is
 * accepted only when no level of severity holds more members than it did at
 * capture — arrive with the stage rewrite in P3. This class stays a plain
 * per-violation predicate until then.
 */
final readonly class BaselineFilter implements ViolationFilterInterface
{
    public function __construct(
        private Baseline $baseline,
    ) {}

    /**
     * Returns true if the violation should be included (not in the baseline).
     */
    public function shouldInclude(Violation $violation): bool
    {
        return !$this->baseline->hasIdentity(BaselineIdentity::forViolation($violation));
    }

    /**
     * Entries whose group did not appear in this run — the debt that was
     * paid off since capture.
     *
     * Keyed on the complete identity, not on the symbol: a repaired finding
     * now strands its own entry while its neighbours under the same symbol
     * keep applying (§5.7).
     *
     * @param list<Violation> $violations current violations from analysis
     *
     * @return list<BaselineEntry>
     */
    public function getResolvedFromBaseline(array $violations): array
    {
        return $this->baseline->staleEntries(self::measuredIdentityKeys($violations));
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<string>
     */
    public static function measuredIdentityKeys(array $violations): array
    {
        $keys = [];

        foreach ($violations as $violation) {
            $keys[BaselineIdentity::forViolation($violation)->key()] = true;
        }

        return array_keys($keys);
    }
}
