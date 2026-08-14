<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationContext;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;
use Qualimetrix\Core\Symbol\SymbolPath;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(ArchitectureConfigurationFactory::class)]
#[CoversClass(ConfigurationPipeline::class)]
final class DogfoodingTopologyTest extends TestCase
{
    #[Test]
    public function itProjectsEveryManifestDeclarationToItsOwnerOrSingletonSeam(): void
    {
        $manifest = $this->manifest();
        $architecture = $this->loadProjectArchitecture();
        $declared = array_map(
            self::entryName(...),
            $architecture->entries(),
        );
        sort($declared, \SORT_STRING);
        $expected = array_map(self::ownerLayerName(...), $manifest['owners']);
        $expected = array_merge($expected, array_column($manifest['enforcement_seams'], 'layer'), ['external']);
        sort($expected, \SORT_STRING);

        self::assertSame($expected, $declared);
        $internalLayerCount = \count($manifest['owners']) + \count($manifest['enforcement_seams']);
        self::assertSame($this->enforcementSummaryCount('internal_enforcement_layers'), $internalLayerCount);
        self::assertCount($internalLayerCount + 1, $declared);

        $seamMembers = [];
        foreach ($manifest['declarations'] as $fqcn => $declaration) {
            $expectedLayer = $manifest['enforcement_seams'][$fqcn]['layer']
                ?? self::ownerLayerName($declaration['owner']);
            self::assertSame($expectedLayer, $this->resolveFqcn($architecture, $fqcn), $fqcn);
            if (isset($manifest['enforcement_seams'][$fqcn])) {
                $seamMembers[$expectedLayer][] = $fqcn;
            }
        }

        foreach ($manifest['enforcement_seams'] as $fqcn => $seam) {
            self::assertSame($manifest['declarations'][$fqcn]['owner'], $seam['semantic_owner']);
            self::assertSame([$fqcn], $seamMembers[$seam['layer']] ?? []);
        }
    }

    #[Test]
    public function itKeepsCoverageFailClosedWithoutOpenOwnershipPatterns(): void
    {
        $architecture = $this->loadProjectArchitecture();
        self::assertSame(CoverageMode::Error, $architecture->coverage());

        foreach ([
            'Qualimetrix\\Analysis\\DirectTaxonomyType',
            'Qualimetrix\\Analysis\\Evidence\\Accidental',
            'Qualimetrix\\Analysis\\Policy\\Accidental',
            'Qualimetrix\\Rules\\Accidental',
            'Qualimetrix\\Reporting\\Accidental',
            'Qualimetrix\\Core\\Accidental',
            'Qualimetrix\\Configuration\\Accidental',
            'Qualimetrix\\Baseline\\Accidental',
            'Qualimetrix\\Infrastructure\\Analysis\\Accidental',
        ] as $fqcn) {
            self::assertNull($this->resolveFqcn($architecture, $fqcn), $fqcn);
        }

        foreach ($architecture->entries() as $entry) {
            self::assertInstanceOf(LayerDefinition::class, $entry);
        }
    }

    #[Test]
    public function itKeepsTheGeneratedOwnerAndSeamAllowGraphExactAndAcyclic(): void
    {
        $architecture = $this->loadProjectArchitecture();
        $layers = array_map(self::entryName(...), $architecture->entries());
        $edgeCount = 0;
        $graph = [];
        foreach ($layers as $source) {
            $targets = $architecture->policy()->allowedTargets($source);
            $edgeCount += \count($targets);
            $graph[$source] = $targets;
            foreach ($targets as $target) {
                self::assertContains($target, $layers, "{$source} targets an undeclared layer {$target}");
                self::assertDoesNotMatchRegularExpression('/[*?{\[]/', $target);
            }
        }

        self::assertSame($this->enforcementSummaryCount('declared_allow_edges'), $edgeCount);
        self::assertSame([], $graph['external']);
        foreach (array_diff($layers, ['external']) as $source) {
            self::assertContains('external', $graph[$source]);
        }
        self::assertNull($this->findCycle($graph));
    }

    #[Test]
    public function itUsesExternalOnlyForNonProjectNamespaces(): void
    {
        $architecture = $this->loadProjectArchitecture();

        self::assertSame('external', $this->resolveFqcn($architecture, 'Symfony\\Component\\Console\\Application'));
        self::assertNull($this->resolveFqcn($architecture, 'Qualimetrix\\UnlistedModule\\FutureType'));
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $contents = file_get_contents($this->repoRoot() . '/docs/internal/modular-architecture-manifest.json');
        self::assertIsString($contents);
        $manifest = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    private static function ownerLayerName(string $owner): string
    {
        return strtolower(str_replace('.', '-', $owner));
    }

    private static function entryName(LayerDefinition|TemplateLayerDefinition $entry): string
    {
        return $entry instanceof LayerDefinition ? $entry->name : $entry->nameTemplate;
    }

    private function resolveFqcn(ArchitectureConfiguration $architecture, string $fqcn): ?string
    {
        $separator = strrpos($fqcn, '\\');
        self::assertNotFalse($separator);

        return $architecture->registry()->resolveLayer(SymbolPath::forClass(
            substr($fqcn, 0, $separator),
            substr($fqcn, $separator + 1),
        ));
    }

    /** @param array<string, list<string>> $graph */
    private function findCycle(array $graph): ?string
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $node) use (&$visit, &$visiting, &$visited, $graph): ?string {
            if (isset($visiting[$node])) {
                return $node;
            }
            if (isset($visited[$node])) {
                return null;
            }
            $visiting[$node] = true;
            foreach ($graph[$node] ?? [] as $target) {
                $cycle = $visit($target);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
            unset($visiting[$node]);
            $visited[$node] = true;

            return null;
        };
        foreach (array_keys($graph) as $node) {
            $cycle = $visit($node);
            if ($cycle !== null) {
                return $cycle;
            }
        }

        return null;
    }

    private function loadProjectArchitecture(): ArchitectureConfiguration
    {
        $repoRoot = $this->repoRoot();
        $loader = new YamlConfigLoader();
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new ComposerDiscoveryStage(new ComposerReader()));
        $pipeline->addStage(new PresetStage($loader, new PresetResolver()));
        $pipeline->addStage(new ConfigFileStage($loader));
        $pipeline->addStage(new CliStage());

        $definition = new InputDefinition([
            new InputArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, '', []),
            new InputOption('preset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('format', null, InputOption::VALUE_REQUIRED),
            new InputOption('cache-dir', null, InputOption::VALUE_REQUIRED),
            new InputOption('no-cache', null, InputOption::VALUE_NONE),
            new InputOption('disable-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('only-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('fail-on', null, InputOption::VALUE_REQUIRED),
            new InputOption('exclude-health', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('include-generated', null, InputOption::VALUE_NONE),
            new InputOption('workers', null, InputOption::VALUE_REQUIRED),
        ]);

        $resolved = $pipeline->resolve(new ConfigurationContext(new ArrayInput([], $definition), $repoRoot));

        return (new ArchitectureConfigurationFactory())
            ->fromContributions($resolved->document->contributions('architecture'))
            ->configuration;
    }

    private function repoRoot(): string
    {
        $root = realpath(__DIR__ . '/../../..');
        self::assertIsString($root);

        return $root;
    }

    private function enforcementSummaryCount(string $metric): int
    {
        $path = $this->repoRoot() . '/docs/internal/generated/modular-architecture/manifest-enforcement-summary.tsv';
        $rows = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($rows);

        foreach ($rows as $row) {
            [$name, $count] = array_pad(explode("\t", $row, 2), 2, null);
            if ($name === $metric) {
                self::assertIsNumeric($count);

                return (int) $count;
            }
        }

        self::fail("Missing {$metric} in {$path}");
    }
}
