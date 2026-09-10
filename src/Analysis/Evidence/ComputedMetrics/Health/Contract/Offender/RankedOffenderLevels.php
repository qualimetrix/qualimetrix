<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender;

use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The symbol levels a health summary ever ranks a {@see WorstOffender} for.
 *
 * A worst offender is the only thing compared by its whole canonical name; a
 * finding is compared by its namespace alone. So a caller asking what a
 * `--namespace` value can possibly select has to know which levels produce
 * offenders, and deriving that from "levels that carry a namespace" over-counts:
 * a File or Callable canonical name is in no comparison anywhere, and a value
 * matching only such a name reads as bound while the report it produces is
 * empty by construction.
 *
 * Published rather than restated because the answer is a fact about
 * {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummaryBuilder},
 * and a second enumeration of it is the drift this list exists to remove.
 */
final readonly class RankedOffenderLevels
{
    /** @var list<SymbolLevel> */
    public const array LEVELS = [SymbolLevel::Namespace_, SymbolLevel::Class_];
}
