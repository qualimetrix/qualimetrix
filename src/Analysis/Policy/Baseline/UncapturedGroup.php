<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * A group of findings the capture refused to record, and why.
 *
 * It exists so the refusal can be *said*. A dropped group never becomes an
 * entry, so ADR 0017 "check reports it as inert" can never reach it either: the
 * state is unreportable by construction, and without this the only trace of
 * it is the next run showing findings the user believes they just accepted.
 */
final readonly class UncapturedGroup
{
    /**
     * @param int $memberCount how many findings shared the identity
     */
    public function __construct(
        public BaselineIdentity $identity,
        public UncapturedReason $reason,
        public int $memberCount,
    ) {}
}
