<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Coverage;

use InvalidArgumentException;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\ViolationChannel;

/**
 * What a run can say about one scope: a channel, optionally narrowed to a
 * single symbol.
 *
 * A null {@see $symbol} makes the statement channel-wide — "this channel was
 * (not) evaluated anywhere in this run" — which is what aggregate and graph
 * findings need, since they have no single owning symbol whose coverage
 * could be queried.
 *
 * The same type doubles as the sparse deviation a rule reports: a rule states
 * only what the centre cannot see, and every such statement is exactly a
 * non-{@see ScopeCoverageStatus::Evaluated} scope coverage.
 */
final readonly class ScopeCoverage
{
    /**
     * @param ViolationChannel $channel The channel this statement is about.
     * @param ScopeCoverageStatus $status Whether the scope was evaluated.
     * @param ?SymbolPath $symbol Null makes the statement channel-wide.
     * @param ?string $reason Required for every non-evaluated status; surfaced
     *                        to the user as the coverage reason.
     */
    public function __construct(
        public ViolationChannel $channel,
        public ScopeCoverageStatus $status,
        public ?SymbolPath $symbol = null,
        public ?string $reason = null,
    ) {
        if (!$status->provesEvaluation() && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException(
                \sprintf(
                    'ScopeCoverage for channel "%s" with status "%s" requires a reason: an unproven scope '
                    . 'blocks resolution, and the user is owed the cause.',
                    $channel->toKey(),
                    $status->value,
                ),
            );
        }

        if ($status->provesEvaluation() && $reason !== null) {
            throw new InvalidArgumentException(
                \sprintf(
                    'ScopeCoverage for channel "%s" is evaluated and must not carry a reason.',
                    $channel->toKey(),
                ),
            );
        }
    }

    public static function evaluated(ViolationChannel $channel, ?SymbolPath $symbol = null): self
    {
        return new self($channel, ScopeCoverageStatus::Evaluated, $symbol);
    }

    public static function notEvaluated(
        ViolationChannel $channel,
        string $reason,
        ?SymbolPath $symbol = null,
    ): self {
        return new self($channel, ScopeCoverageStatus::NotEvaluated, $symbol, $reason);
    }

    public static function indeterminate(
        ViolationChannel $channel,
        string $reason,
        ?SymbolPath $symbol = null,
    ): self {
        return new self($channel, ScopeCoverageStatus::Indeterminate, $symbol, $reason);
    }

    public function provesEvaluation(): bool
    {
        return $this->status->provesEvaluation();
    }

    /** Whether the statement covers the whole channel rather than one symbol. */
    public function isChannelWide(): bool
    {
        return $this->symbol === null;
    }
}
