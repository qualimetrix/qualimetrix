<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypeSourceInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignment;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentInspectorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentShadowVerdict;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ResolvedArchitecturePolicyInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\UnmatchedTypeWarningInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\AnalysedDeclarations;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassSet;
use Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Instance-owned declared-layer policy configuration and prepared state. */
final class ArchitecturePolicy implements ArchitecturePolicyConfiguratorInterface, LayerPolicyPreparationInterface, LayerAssignmentInspectorInterface, UnmatchedTypeWarningInterface
{
    private ?ArchitectureConfiguration $configured = null;

    private ?ArchitectureConfiguration $prepared = null;

    private readonly LayerExpansionStage $expansionStage;

    /**
     * @param ExternalSupertypeSourceInterface|null $install The analysed project's Composer install,
     *                                                       read as data through Architecture's own
     *                                                       supertype port. Null reads as "no install found".
     */
    public function __construct(
        private readonly ArchitectureConfigurationFactory $factory = new ArchitectureConfigurationFactory(),
        ?LayerExpansionStage $expansionStage = null,
        private readonly ?ExternalSupertypeSourceInterface $install = null,
    ) {
        $this->expansionStage = $expansionStage ?? new LayerExpansionStage();
    }

    public function resolve(ConfigurationDocument $document): ResolvedArchitecturePolicyInterface
    {
        return $this->factory->fromResolved($document->resolved());
    }

    public function replace(ResolvedArchitecturePolicyInterface $policy): void
    {
        if (!$policy instanceof \Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureFactoryResult) {
            throw new LogicException('ArchitecturePolicy accepts only a policy resolved by its Architecture factory.');
        }

        $this->configured = $policy->configuration;
        $this->prepared = null;
    }

    public function prepare(DependencyGraphInterface $graph, iterable $classUniverse): void
    {
        $this->prepared = null;
        if ($this->configured === null) {
            throw new LogicException('ArchitecturePolicy::prepare() requires replace() to have been called.');
        }

        $configuration = $this->configured;

        // Materialised once, before the binding: the universe is read twice
        // from here — once as the set of declarations this run analysed, once
        // as the classes template observation walks — and a generator handed in
        // by a caller would be empty by the second read.
        $analysedClasses = \is_array($classUniverse)
            ? array_values($classUniverse)
            : iterator_to_array($classUniverse, false);

        // The run's single binding point: every reader of a class context,
        // template observation included, runs after this line. The universe
        // goes in with the graph, because a context that knows the graph but
        // not the universe cannot tell an inheritance chain that ended from one
        // that was cut at the edge of the analysed set.
        $contextFactory = $configuration->registry()->contextFactory();
        $contextFactory->bindExternalSupertypeSource($this->install);
        $configuration->registry()->bindGraph($graph, $analysedClasses);

        if ($configuration->hasTemplates()) {
            // One factory for the whole run. Observation and membership
            // matching must read the same contexts, or a layer is derived
            // from facts it is then matched against different ones.
            $classes = new ClassSet(
                $analysedClasses,
                $configuration->registry()->contextFactory(),
            );
            $expansion = $this->expansionStage->expand($configuration->entries(), $classes, $configuration->maxExpandedLayers());
            $configuration = $configuration->withExpansion($expansion->expandedLayers, $expansion->emptyTemplateNames);
        }

        $this->prepared = $configuration;
    }

    public function inspect(
        DependencyGraphInterface $graph,
        iterable $classUniverse,
        SymbolPath $subject,
        bool $policyDisabled,
    ): LayerAssignment {
        $analysedClasses = \is_array($classUniverse)
            ? array_values($classUniverse)
            : iterator_to_array($classUniverse, false);
        $this->prepare($graph, $analysedClasses);
        $configuration = $this->prepared
            ?? throw new LogicException('ArchitecturePolicy::inspect() reached an unprepared policy after prepare() returned.');

        $registry = $configuration->registry();
        $declaredSpelling = $registry->contextFactory()->knownTypes()->observedSpellingOf(self::fqnFor($subject));
        $declaredSubject = $declaredSpelling === null ? $subject : SymbolPath::fromClassFqn($declaredSpelling);
        $established = $registry->establishedMatches($declaredSubject);

        return new LayerAssignment(
            array_map(self::assignmentMatch(...), $registry->resolveAll($declaredSubject)),
            !$configuration->isEmpty(),
            $registry->undecidedLayers($declaredSubject),
            $registry->chainStopsAt($declaredSubject),
            $registry->contenders($declaredSubject),
            ($established[0] ?? null)?->layerName,
            array_map(
                static fn($verdict): LayerAssignmentShadowVerdict => new LayerAssignmentShadowVerdict(
                    self::assignmentMatch($verdict->later),
                    $verdict->exemption,
                ),
                LayerShadowing::verdicts($established),
            ),
            $declaredSpelling,
            $policyDisabled,
            $declaredSpelling !== null && !AnalysedDeclarations::of($analysedClasses)->contains($declaredSpelling),
        );
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

    public function getPreparedConfiguration(): ?ArchitectureConfiguration
    {
        return $this->prepared;
    }

    public function notJudgedWarning(ProjectScopeJudgement $scope): ?string
    {
        $configuration = $this->prepared
            ?? throw new LogicException('ArchitecturePolicy::notJudgedWarning() requires prepare() to have been called.');
        $judgement = $configuration->registry()->contextFactory()->knownTypes()->unmatched($configuration->namedTypes(), $scope);
        if ($judgement->occurrences === [] || $judgement->isJudged()) {
            return null;
        }

        $reasons = [];
        if ($judgement->withheldBy !== []) {
            $reasons[] = 'project scope is narrowed by ' . implode(', ', array_map(
                static fn($door): string => $door->value,
                $judgement->withheldBy,
            ));
        }
        if (!$judgement->installConsulted) {
            $reasons[] = 'the project Composer install was not read';
        }

        return \sprintf(
            'Architecture unmatched type names were not judged: %s.',
            implode('; ', $reasons),
        );
    }

    public function reset(): void
    {
        $this->prepared = null;
    }

    private static function fqnFor(SymbolPath $symbol): string
    {
        return $symbol->namespace === null || $symbol->namespace === ''
            ? ($symbol->type ?? '')
            : $symbol->namespace . '\\' . ($symbol->type ?? '');
    }
}
