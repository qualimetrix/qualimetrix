<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\GodClass;

/**
 * Outcome of evaluating a single God Class criterion (WMC, LCOM, TCC, or class LOC)
 * against one class's metrics.
 *
 * Only produced for criteria that had enough data to be evaluated — see
 * {@see GodClassCriteriaEvaluator}, which skips criteria whose backing metric
 * is missing entirely.
 */
final readonly class GodClassCriterionResult
{
    public function __construct(
        public bool $matched,
        public string $message,
    ) {}
}
