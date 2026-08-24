<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Resolves the exact declarations owned by each logical layer target.
 *
 * A dependency graph deliberately uses logical classes. Layer-violation
 * findings, however, must be independently controllable at each declaration
 * selected by that logical target. This index bridges those two projections
 * without assigning policy evaluation or Finding construction to the
 * repository boundary.
 */
final readonly class OwnedLayerTargets
{
    /**
     * @param array<string, list<MetricSubject>> $byLogicalCanonical
     */
    private function __construct(
        private array $byLogicalCanonical,
    ) {}

    /**
     * @param iterable<\Qualimetrix\Core\Symbol\SymbolInfo> $declarations
     */
    public static function fromDeclarations(iterable $declarations): self
    {
        $byLogicalCanonical = [];

        foreach ($declarations as $declarationInfo) {
            $subject = $declarationInfo->subject;
            $declaration = $subject?->declarationPath();
            if ($declaration === null || $declaration->logical->getType() !== SymbolType::Class_) {
                continue;
            }

            $byLogicalCanonical[$declaration->logical->toCanonical()][$subject->toCanonical()] = $subject;
        }

        foreach ($byLogicalCanonical as $logicalCanonical => $subjects) {
            ksort($subjects);
            $byLogicalCanonical[$logicalCanonical] = array_values($subjects);
        }

        return new self($byLogicalCanonical);
    }

    /**
     * @return list<MetricSubject>
     */
    public function forLogical(SymbolPath $logicalTarget): array
    {
        return $this->byLogicalCanonical[$logicalTarget->toCanonical()] ?? [];
    }
}
