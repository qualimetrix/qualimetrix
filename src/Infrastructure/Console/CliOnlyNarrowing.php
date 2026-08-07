<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

/**
 * The narrowing a `check` invocation adds on top of the configuration —
 * `--exclude-path`, `--exclude-namespace` and `--no-suppression-annotations`.
 *
 * **It is deliberately separate from the configuration, and it is not part
 * of the measured set** (§5.5 of the baseline-ceiling plan). The set a
 * baseline captures and compares against is defined by `qmx.yaml` and by the
 * annotations in the source, both of which every command sees. A flag lives
 * on one invocation of one command, and the baseline commands do not accept
 * these flags at all; folding them into the definition would mean each of
 * those commands had to replicate `check`'s option surface to agree with it,
 * and a user who passed a flag to one and not the other would be measuring
 * two different sets again.
 *
 * **The governing invariant, and the reason this class is called *narrowing*:
 * a flag may keep findings out of the report, but no flag may put findings
 * into what the ceiling measures.** Narrowing is safe in the one direction
 * that matters — a smaller group cannot breach an entry, so the worst a
 * `--exclude-*` flag can do is leave an entry unmatched, and §5.5 names that
 * cost: an entry captured without a flag can be permanently inert under a
 * run that passes one, which `check` reports.
 *
 * Widening is authorised by nothing. Under the old reading,
 * `--no-suppression-annotations` dropped the suppression stage from the
 * definition of the set, so a run that passed it measured findings
 * `@qmx-ignore` had removed — findings no capture had ever written an entry
 * for. Groups then read as breached and were promoted to Error on code
 * nobody had touched, and a capture taken under the flag recorded an entry
 * for an annotated finding. Hence: annotation suppression always runs for
 * the measured set, and the flag only decides whether the findings it
 * removed rejoin the report afterwards ({@see ViolationFilterPipeline}).
 *
 * The consequence to accept, rather than mistake for a defect later: under
 * the flag an `@qmx-ignore`d finding is shown at **its own severity** and is
 * compared against no entry at all, because the ceiling never measured it.
 * That is the price of an honest set, and it is the right way round — the
 * flag is a diagnostic view of what the annotations hide, not a stricter
 * mode of the baseline.
 */
final readonly class CliOnlyNarrowing
{
    /**
     * @param list<string> $excludePaths `--exclude-path`, merged with the configured patterns
     * @param list<string> $excludeNamespaces `--exclude-namespace`, merged with the configured prefixes
     * @param bool $annotationSuppressionDisabled `--no-suppression-annotations`: findings `@qmx-ignore` removes
     *                                            are reported anyway. The measured set is unaffected — see
     *                                            the invariant above.
     */
    public function __construct(
        public array $excludePaths = [],
        public array $excludeNamespaces = [],
        public bool $annotationSuppressionDisabled = false,
    ) {}

    /**
     * What every caller other than `check` supplies: nothing.
     */
    public static function none(): self
    {
        return new self();
    }
}
