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
 * for each ready owned target declaration. Those count units share a stable
 * occurrence identified by exact source, logical target and dependency kind.
 * With no owned targets, it emits one source finding.
 */
final readonly class LayerViolationFinding
{
    /**
     * Frozen to today's channel spelling on purpose — it does not follow a
     * future rename of {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule::NAME},
     * which is what `$ruleName` below carries in production. Changing this
     * value moves the `occurrence` of every already-accepted finding on
     * this channel.
     */
    private const string OCCURRENCE_KIND = 'architecture.layer-violation';

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
        $finding = $this->toFinding();

        return array_fill(0, max(1, \count($this->ownedTargets)), $finding);
    }

    private function toFinding(): Finding
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

        return new Finding(
            location: $location,
            subject: MetricSubject::declaration($this->dependency->source),
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
            occurrenceKey: OccurrenceKey::semantic(self::OCCURRENCE_KIND, $evidence),
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
