<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerAssignment;

use LogicException;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentShadowVerdict;
use Qualimetrix\Analysis\Policy\Architecture\Layer\AnalysedDeclarations;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Projects prepared layer state into the facts carried by a debug assignment. */
final readonly class LayerAssignmentProjection
{
    /** @var list<LayerAssignmentMatch> */
    public array $matches;

    public bool $layersDeclared;

    /** @var list<string> */
    public array $undecidedLayers;

    /** @var list<string> */
    public array $chainStopsAt;

    /** @var list<string> */
    public array $contenders;

    public ?string $establishedLayer;

    /** @var list<LayerAssignmentShadowVerdict> */
    public array $shadowVerdicts;

    public ?string $declaredSpelling;

    public bool $edgeEndOnly;

    /** @param list<SymbolPath> $analysedClasses */
    public function __construct(
        ArchitectureConfiguration $configuration,
        array $analysedClasses,
        SymbolPath $subject,
    ) {
        $registry = $configuration->registry();
        $declaredSpelling = $registry->contextFactory()->knownTypes()->observedSpellingOf(self::fqnFor($subject));
        $declaredSubject = $declaredSpelling === null ? $subject : SymbolPath::fromClassFqn($declaredSpelling);
        $established = $registry->establishedMatches($declaredSubject);

        $this->matches = array_map(self::assignmentMatch(...), $registry->resolveAll($declaredSubject));
        $this->layersDeclared = !$configuration->isEmpty();
        $this->undecidedLayers = $registry->undecidedLayers($declaredSubject);
        $this->chainStopsAt = $registry->chainStopsAt($declaredSubject);
        $this->contenders = $registry->contenders($declaredSubject);
        $this->establishedLayer = ($established[0] ?? null)?->layerName;
        $this->shadowVerdicts = array_map(
            static fn($verdict): LayerAssignmentShadowVerdict => new LayerAssignmentShadowVerdict(
                self::assignmentMatch($verdict->later),
                $verdict->exemption,
            ),
            LayerShadowing::verdicts($established),
        );
        $this->declaredSpelling = $declaredSpelling;
        $this->edgeEndOnly = $declaredSpelling !== null
            && !AnalysedDeclarations::of($analysedClasses)->contains($declaredSpelling);
    }

    private static function assignmentMatch(LayerMatch $match): LayerAssignmentMatch
    {
        $criteria = array_map(
            static fn($criterion): string => $criterion->describe(),
            $match->matchedCriteria,
        );
        if ($criteria === []) {
            throw new LogicException('A layer assignment match requires at least one criterion.');
        }

        return new LayerAssignmentMatch($match->layerName, $criteria);
    }

    private static function fqnFor(SymbolPath $symbol): string
    {
        return $symbol->namespace === null || $symbol->namespace === ''
            ? ($symbol->type ?? '')
            : $symbol->namespace . '\\' . ($symbol->type ?? '');
    }
}
