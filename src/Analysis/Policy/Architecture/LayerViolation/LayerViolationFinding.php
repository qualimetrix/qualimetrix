<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use LogicException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterionKind;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Immutable construction input for one exact layer-policy finding.
 *
 * Policy evaluation remains in {@see LayerViolationRule}. Once the rule has
 * established that an edge is forbidden, this value materializes one finding
 * for each ready owned target declaration. With no owned targets, it emits
 * exactly one finding for the exact source declaration instead.
 */
final readonly class LayerViolationFinding
{
    public function __construct(
        private Dependency $dependency,
        private LayerMatch $fromMatch,
        private LayerMatch $toMatch,
        /** @var list<MetricSubject> */
        private array $ownedTargets,
        private string $ruleName,
        private Severity $severity,
        private string $recommendation,
    ) {}

    /**
     * @return list<Finding>
     */
    public function toFindings(): array
    {
        $subjects = $this->ownedTargets === []
            ? [MetricSubject::declaration($this->dependency->source)]
            : $this->ownedTargets;

        return array_map($this->toFinding(...), $subjects);
    }

    private function toFinding(MetricSubject $subject): Finding
    {
        $location = $this->dependency->location;
        if (!$location instanceof Location) {
            $file = $location->file();
            $line = $location->line();
            if ($file === null || $line === null) {
                throw new LogicException('Layer violation findings require an exact dependency location.');
            }

            $location = new Location($file, $line);
        }

        $evidence = [
            'source' => $this->dependency->source->toCanonical(),
            'target' => $this->dependency->targetLogical()->toCanonical(),
            'type' => $this->dependency->type->value,
        ];
        if ($subject->declarationPath()?->toCanonical() !== $this->dependency->source->toCanonical()) {
            $evidence['projectedTarget'] = $subject->toCanonical();
        }

        return new Finding(
            location: $location,
            subject: $subject,
            symbolPath: $this->dependency->sourceLogical(),
            ruleName: $this->ruleName,
            code: $this->ruleName,
            message: \sprintf(
                'Layer "%s" must not depend on layer "%s" (%s → %s, %s)%s',
                $this->fromMatch->layerName,
                $this->toMatch->layerName,
                $this->dependency->sourceLogical()->toString(),
                $this->dependency->targetLogical()->toString(),
                $this->dependency->type->description(),
                self::describeMatchTrailer($this->fromMatch, $this->toMatch),
            ),
            severity: $this->severity,
            recommendation: $this->recommendation,
            dependencyTarget: $this->dependency->targetLogical(),
            dependencyType: $this->dependency->type,
            occurrenceKey: OccurrenceKey::semantic($this->ruleName, $evidence),
        );
    }

    private static function describeMatchTrailer(LayerMatch $fromMatch, LayerMatch $toMatch): string
    {
        if (self::isPlainPatternMatch($fromMatch) && self::isPlainPatternMatch($toMatch)) {
            return '';
        }

        return \sprintf(
            ' [source matched by %s; target matched by %s]',
            self::describeCriteriaList($fromMatch->matchedCriteria),
            self::describeCriteriaList($toMatch->matchedCriteria),
        );
    }

    private static function isPlainPatternMatch(LayerMatch $match): bool
    {
        return \count($match->matchedCriteria) === 1
            && $match->matchedCriteria[0]->kind === MatchedCriterionKind::Pattern;
    }

    /**
     * @param list<MatchedCriterion> $criteria
     */
    private static function describeCriteriaList(array $criteria): string
    {
        return implode(', ', array_map(
            static fn(MatchedCriterion $criterion): string => $criterion->describe(),
            $criteria,
        ));
    }
}
