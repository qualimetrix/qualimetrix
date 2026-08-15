<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * One record of a version 5 baseline file: a symbol, the rule that reported
 * it, and the opaque hash v5 stored instead of a magnitude.
 *
 * The hash is carried along for fidelity to the source file, but nothing in
 * this package reads it: it digests `rule|namespace|type|member|violationCode`
 * (ADR 0017), which is neither a magnitude nor
 * recoverable into one. The only thing a v5 record and a v10 finding share
 * is the pair `($symbolKey, $rule)` — {@see BaselineMigrator} matches on
 * exactly that pair, never on `$hash`.
 */
final readonly class V5Entry
{
    public function __construct(
        public string $symbolKey,
        public string $rule,
        public string $hash,
    ) {}
}
