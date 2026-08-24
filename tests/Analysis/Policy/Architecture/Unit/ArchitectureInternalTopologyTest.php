<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use FilesystemIterator;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ArchitectureInternalTopologyTest extends TestCase
{
    /** @var list<string> */
    private const array ARCHITECTURE_DECLARATIONS = [
        'Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\AllowValidator',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowAliasExpander',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowListEntry',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowTarget',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\CaptureBinding',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\InvalidSelectorException',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelector',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelectorParser',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\ParseCapturedState',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\SelectorKind',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\SelectorSegment',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfigurationFactory',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureFactoryResult',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageValidator',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\ExactAllowCycleValidator',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\ExcludeBlockValidator',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\LayerCriterionNormalizer',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\LayersValidator',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\LongFormAllowEntryNormalizer',
        'Qualimetrix\Analysis\Policy\Architecture\Configuration\WildcardSelfAllowDetector',
        'Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException',
        'Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationWarning',
        'Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface',
        'Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePreparationException',
        'Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignment',
        'Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentInspectorInterface',
        'Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch',
        'Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface',
        'Qualimetrix\Analysis\Policy\Architecture\Contract\ResolvedArchitecturePolicyInterface',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\DeclaredLayerReachability',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\DiagnosticSampleList',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerEvidence',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerEvidenceCollector',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerRoutingGuidance',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationFinding',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\OwnedLayerTargets',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassMode',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassRule',
        'Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassSummary',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\CapturePattern',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\ClassSet',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\CriterionListValidator',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionResult',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerInstantiator',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\TupleExtractor',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\InvalidLayerDefinitionException',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\LayerCriteriaMatcher',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\LayerLifecycle',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\LayerPolicy',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterionKind',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipResult',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\PatternScope',
        'Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition',
    ];

    /** @var array<string, list<string>> */
    private const array ALLOWED = [
        'Contract' => [],
        'Configuration/Allow' => ['Contract'],
        'Layer' => ['Contract', 'Configuration/Allow'],
        'Configuration' => ['Contract', 'Configuration/Allow', 'Layer'],
        'Layer/Expansion' => ['Contract', 'Configuration', 'Configuration/Allow', 'Layer'],
        'ArchitecturePolicy' => ['Contract', 'Configuration', 'Layer', 'Layer/Expansion'],
        'LayerViolation' => ['Contract', 'ArchitecturePolicy', 'Configuration', 'Layer'],
    ];

    #[Test]
    public function itValidatesEveryMaterializedP4ArchitectureDeclarationAgainstTheFrozenZoneDag(): void
    {
        $root = $this->repositoryRoot();
        $manifest = json_decode((string) file_get_contents($root . '/docs/internal/modular-architecture-manifest.json'), true, flags: \JSON_THROW_ON_ERROR);
        $targets = $manifest['declarations'];
        $expected = array_filter(
            $targets,
            static fn(mixed $target): bool => \is_array($target) && ($target['owner'] ?? null) === 'Analysis.Policy.Architecture',
        );
        $declarations = array_keys($expected);
        sort($declarations, \SORT_STRING);
        self::assertSame(self::ARCHITECTURE_DECLARATIONS, $declarations);

        $resolvedArchitecturePolicy = $targets['Qualimetrix\Analysis\Policy\Architecture\Contract\ResolvedArchitecturePolicyInterface'];
        self::assertIsArray($resolvedArchitecturePolicy);
        self::assertSame('Contract', $this->zoneForPath($resolvedArchitecturePolicy['path']));
        self::assertSame('contract', $resolvedArchitecturePolicy['visibility']);
        self::assertSame('Analysis.Policy.Architecture', $resolvedArchitecturePolicy['owner']);

        foreach ($expected as $fqcn => $target) {
            $path = $root . '/' . $target['path'];
            self::assertFileExists($path, $fqcn);
            $sourceZone = $this->zoneForPath($target['path']);
            self::assertSame(
                [],
                $this->disallowedDependencies(
                    $sourceZone,
                    $this->architectureDependencies((string) file_get_contents($path), $fqcn),
                ),
                $fqcn . ' has a forbidden Architecture zone dependency.',
            );
        }
    }

    #[Test]
    public function itRejectsInjectedReverseAndUnknownCrossZoneEdgesWithoutWildcardWidening(): void
    {
        self::assertNotContains('ArchitecturePolicy', self::ALLOWED['Layer']);
        self::assertNotContains('Layer/Expansion', self::ALLOWED['Configuration']);
        self::assertArrayNotHasKey('*', self::ALLOWED);
        foreach (self::ALLOWED as $allowed) {
            self::assertNotContains('*', $allowed);
        }

        $inlineDependencies = $this->architectureDependencies(
            '<?php new \\Qualimetrix\\Analysis\\Policy\\Architecture\\ArchitecturePolicy();',
            'Qualimetrix\\Analysis\\Policy\\Architecture\\Layer\\Probe',
        );
        self::assertSame(
            ['Qualimetrix\\Analysis\\Policy\\Architecture\\ArchitecturePolicy'],
            $inlineDependencies,
        );
        self::assertSame($inlineDependencies, $this->disallowedDependencies('Layer', $inlineDependencies));

        $groupedDependencies = $this->architectureDependencies(
            '<?php use Qualimetrix\\Analysis\\Policy\\Architecture\\Layer\\{Expansion\\LayerExpansionStage as Stage}; new Stage();',
            'Qualimetrix\\Analysis\\Policy\\Architecture\\Configuration\\Probe',
        );
        self::assertSame(
            ['Qualimetrix\\Analysis\\Policy\\Architecture\\Layer\\Expansion\\LayerExpansionStage'],
            $groupedDependencies,
        );
        self::assertSame($groupedDependencies, $this->disallowedDependencies('Configuration', $groupedDependencies));

        $aliasedUnknown = $this->architectureDependencies(
            '<?php use Qualimetrix\\Analysis\\Policy\\Architecture\\Future\\Thing as Alias; new Alias();',
            'Qualimetrix\\Analysis\\Policy\\Architecture\\Contract\\Probe',
        );
        self::assertSame(['Qualimetrix\\Analysis\\Policy\\Architecture\\Future\\Thing'], $aliasedUnknown);
        self::assertSame($aliasedUnknown, $this->disallowedDependencies('Contract', $aliasedUnknown));
    }

    #[Test]
    public function itLeavesNoValidationNamespaceOrDirectoryInTheArchitectureLeaf(): void
    {
        $root = $this->repositoryRoot() . '/src/Analysis/Policy/Architecture';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            self::assertStringNotContainsString('/Validation/', $file->getPathname());
            if ($file->getExtension() === 'php') {
                self::assertStringNotContainsString('\\Validation\\', (string) file_get_contents($file->getPathname()));
            }
        }
    }

    private function zoneForPath(string $path): string
    {
        return match (true) {
            str_contains($path, '/Contract/') => 'Contract',
            str_contains($path, '/Configuration/Allow/') => 'Configuration/Allow',
            str_contains($path, '/Layer/Expansion/') => 'Layer/Expansion',
            str_contains($path, '/LayerViolation/') => 'LayerViolation',
            str_ends_with($path, '/ArchitecturePolicy.php') => 'ArchitecturePolicy',
            str_contains($path, '/Configuration/') => 'Configuration',
            str_contains($path, '/Layer/') => 'Layer',
            default => throw new LogicException('Unknown Architecture zone for ' . $path),
        };
    }

    private function zoneForFqcn(string $fqcn): string
    {
        return $this->zoneForPath('src/' . str_replace('\\', '/', substr($fqcn, \strlen('Qualimetrix\\'))) . '.php');
    }

    /**
     * @param list<string> $dependencies
     *
     * @return list<string>
     */
    private function disallowedDependencies(string $sourceZone, array $dependencies): array
    {
        $disallowed = [];
        foreach ($dependencies as $dependency) {
            try {
                $targetZone = $this->zoneForFqcn($dependency);
            } catch (LogicException) {
                $disallowed[] = $dependency;
                continue;
            }
            if ($targetZone !== $sourceZone && !\in_array($targetZone, self::ALLOWED[$sourceZone], true)) {
                $disallowed[] = $dependency;
            }
        }

        return $disallowed;
    }

    /** @return list<string> */
    private function architectureDependencies(string $source, string $declaration): array
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        if ($nodes === null) {
            throw new LogicException('Unable to parse Architecture source.');
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $collector = new class extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $dependencies = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof GroupUse) {
                    foreach ($node->uses as $use) {
                        $this->dependencies[$node->prefix->toString() . '\\' . $use->name->toString()] = true;
                    }
                } elseif ($node instanceof Use_) {
                    foreach ($node->uses as $use) {
                        $this->dependencies[$use->name->toString()] = true;
                    }
                } elseif ($node instanceof Name) {
                    $resolved = $node->getAttribute('resolvedName');
                    if ($resolved instanceof Name) {
                        $this->dependencies[$resolved->toString()] = true;
                    } elseif ($node->isFullyQualified()) {
                        $this->dependencies[$node->toString()] = true;
                    }
                }

                return null;
            }
        };
        $traverser->addVisitor($collector);
        $traverser->traverse($nodes);

        $prefix = 'Qualimetrix\\Analysis\\Policy\\Architecture\\';
        $result = [];
        foreach (array_keys($collector->dependencies) as $fqcn) {
            if ($fqcn !== $declaration && str_starts_with($fqcn, $prefix)) {
                $result[] = $fqcn;
            }
        }
        sort($result, \SORT_STRING);

        return $result;
    }

    private function repositoryRoot(): string
    {
        $root = realpath(__DIR__ . '/../../../../../');
        self::assertIsString($root);

        return $root;
    }
}
