<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\MeasurementIdentity;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\Coupling\Distance\DistanceCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MetricAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A project read of namespace evidence must count own declarations once.
 *
 * DistanceCollector owns the eligible-type-weighted project distance, with
 * direct Project definitions rather than a generic namespace aggregation.
 * The source-definition sweep still admits every generic namespace-to-project
 * producer; Distance's direct publication must remain measured when that set
 * is empty. A separate injected definition exercises the supported generic
 * path without making production publish an equal-weight distance again.
 *
 * Subtree type, outgoing-edge and incoming-edge plants must move their subtree
 * witnesses while leaving the parent's own input unchanged. The independent
 * project identity reads every emitted own distance and the four own type
 * counts, not DistanceCollector's arithmetic or a fixed score expectation.
 */
final class NamespaceFoldCountsEachDeclarationOnceTest extends TestCase
{
    /** @var list<string>|null */
    private static ?array $foldedKeys = null;

    /** @var array<string, MetricRepositoryInterface> */
    private static array $runs = [];

    #[Test]
    public function itRecognizesTheOwnerPublishedProjectDistance(): void
    {
        $collector = new DistanceCollector();
        $definitions = [];
        foreach ($collector->getMetricDefinitions() as $definition) {
            $definitions[$definition->name] = $definition;
        }

        $own = $definitions[MetricName::COUPLING_DISTANCE_OWN];
        self::assertSame(SymbolLevel::Namespace_, $own->collectedAt);
        self::assertFalse($own->hasAggregationsForLevel(SymbolLevel::Project));
        foreach ([AggregationStrategy::Average, AggregationStrategy::Count] as $strategy) {
            $key = MetricName::agg(MetricName::COUPLING_DISTANCE_OWN, $strategy);
            self::assertSame(SymbolLevel::Project, $definitions[$key]->collectedAt);
            self::assertContains($key, $collector->provides());
        }
        foreach (self::ownTypeKeys() as $key) {
            self::assertContains($key, $collector->requires());
        }

        self::assertNotNull(self::runOver('plain')->get(SymbolPath::forProject())->get(
            MetricName::agg(MetricName::COUPLING_DISTANCE_OWN, AggregationStrategy::Average),
        ));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function subtreeAxes(): iterable
    {
        yield 'a child gains an unreferenced type' => ['type', MetricName::COUPLING_ABSTRACTNESS];
        yield 'a child gains an edge out of the subtree' => ['efferent', MetricName::COUPLING_CE];
        yield 'a child gains an edge from outside the subtree' => ['afferent', MetricName::COUPLING_CA];
    }

    #[Test]
    #[DataProvider('subtreeAxes')]
    public function itLeavesAParentsFoldedValueStillWhenOnlyItsSubtreeChanges(string $variant, string $subtreeWitness): void
    {
        $before = self::runOver('plain')->get(SymbolPath::forNamespace('FoldFixture'));
        $after = self::runOver($variant)->get(SymbolPath::forNamespace('FoldFixture'));

        // Without this the plant can go inert — the graph stops seeing the edge,
        // the collector stops counting the type — and every folded key below
        // "holds" over a change that never happened.
        self::assertNotSame(
            $before->get($subtreeWitness),
            $after->get($subtreeWitness),
            \sprintf('The "%s" plant no longer moves the parent\'s subtree "%s"; it measures nothing.', $variant, $subtreeWitness),
        );

        foreach (array_unique([MetricName::COUPLING_DISTANCE_OWN, ...self::foldedKeys()]) as $key) {
            self::assertSame(
                $before->get($key),
                $after->get($key),
                \sprintf(
                    '"%s" is folded to the project, and the parent\'s value moved when only its subtree changed'
                    . ' (%s) — so it carries a subtree rollup and the fold counts the child twice.'
                    . ' Declare the project aggregation on an own-scope key instead.',
                    $key,
                    $variant,
                ),
            );
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function measuredVariants(): iterable
    {
        yield 'disconnected own scope stays absent' => ['plain', 2];
        yield 'all eligible type kinds, excluding a bare enum' => ['type', 2];
        yield 'outgoing edge admits the formerly disconnected scope' => ['efferent', 3];
        yield 'incoming edge admits the formerly disconnected scope' => ['afferent', 3];
    }

    #[Test]
    #[DataProvider('measuredVariants')]
    public function itFoldsEveryNamespaceThatCarriesTheKey(string $variant, int $expectedSamples): void
    {
        $repository = self::runOver($variant);
        $sum = 0.0;
        $types = 0;
        $samples = 0;
        $unweighted = 0.0;
        foreach ($repository->all(SymbolLevel::Namespace_) as $namespace) {
            $bag = $repository->get($namespace->symbolPath);
            $distance = $bag->get(MetricName::COUPLING_DISTANCE_OWN);
            if ($distance === null) {
                continue;
            }
            $weight = 0;
            foreach (self::ownTypeKeys() as $key) {
                $count = $bag->get($key);
                self::assertIsInt($count, $key);
                $weight += $count;
            }
            self::assertGreaterThan(0, $weight);
            $sum += $distance * $weight;
            $types += $weight;
            $unweighted += $distance;
            $samples++;
        }

        self::assertSame($expectedSamples, $samples, 'The edge/absence plant must remain discriminating');
        $project = $repository->get(SymbolPath::forProject());
        self::assertSame($samples, $project->get(MetricName::agg(MetricName::COUPLING_DISTANCE_OWN, AggregationStrategy::Count)));
        self::assertEqualsWithDelta($sum / $types, $project->get(MetricName::agg(MetricName::COUPLING_DISTANCE_OWN, AggregationStrategy::Average)), 1e-12);
        self::assertSame(0.0, $repository->get(SymbolPath::forNamespace('FoldFixture'))->get(MetricName::COUPLING_DISTANCE_OWN), 'A measured zero must contribute');

        if ($variant === 'type') {
            $child = $repository->get(SymbolPath::forNamespace('FoldFixture\\Child'));
            self::assertSame(2, $child->get(MetricName::SIZE_ENUM_COUNT), 'The bare enum exclusion plant must be present');
            foreach (self::ownTypeKeys() as $key) {
                self::assertSame(1, $child->get($key), $key);
            }
        }
        if ($variant !== 'plain') {
            self::assertNotEqualsWithDelta($unweighted / $samples, $sum / $types, 1e-12, 'The fixture must distinguish equal namespace weights');
        }

        foreach (self::foldedKeys() as $key) {
            $contributors = 0;
            foreach ($repository->all(SymbolLevel::Namespace_) as $namespace) {
                if ($repository->get($namespace->symbolPath)->get($key) !== null) {
                    $contributors++;
                }
            }
            self::assertGreaterThan(0, $contributors, $key);
            self::assertSame($contributors, $project->get($key . '.count'), $key);
        }
    }

    #[Test]
    public function itRetainsTheGenericNamespaceFoldWithoutALiveProductionProducer(): void
    {
        $repository = self::runOver('type');
        $key = 'governance.own-distance-fixture';
        $values = [];
        foreach ($repository->all(SymbolLevel::Namespace_) as $namespace) {
            $distance = $repository->get($namespace->symbolPath)->get(MetricName::COUPLING_DISTANCE_OWN);
            if ($distance !== null) {
                $values[] = $distance;
                $repository->addScalar($namespace->symbolPath, $key, $distance);
            }
        }
        self::assertCount(2, $values, 'The fixture includes a parent with own declarations and its child');
        $definitions = [new MetricDefinition(
            name: $key,
            collectedAt: SymbolLevel::Namespace_,
            aggregations: [SymbolLevel::Project->value => [AggregationStrategy::Average, AggregationStrategy::Count]],
        )];
        (new MetricAggregator($definitions, self::createStub(ProfilerInterface::class)))->aggregate($repository);

        $project = $repository->get(SymbolPath::forProject());
        self::assertSame(\count($values), $project->get($key . '.count'));
        self::assertEqualsWithDelta(array_sum($values) / \count($values), $project->get($key . '.avg'), 1e-12);
        self::assertNotEqualsWithDelta($project->get($key . '.avg'), $project->get(MetricName::agg(MetricName::COUPLING_DISTANCE_OWN, AggregationStrategy::Average)), 1e-12);
    }

    /** @return list<string> */
    private static function ownTypeKeys(): array
    {
        return [
            MetricName::SIZE_CLASS_COUNT,
            MetricName::SIZE_TRAIT_COUNT,
            MetricName::SIZE_INTERFACE_COUNT,
            MetricName::SIZE_IMPLEMENTING_ENUM_COUNT,
        ];
    }

    /**
     * The keys whose definition is collected on a namespace and declares an
     * aggregation to project level.
     *
     * @return list<string>
     */
    private static function foldedKeys(): array
    {
        if (self::$foldedKeys !== null) {
            return self::$foldedKeys;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $keys = [];

        foreach (self::phpFilesIn(\dirname(__DIR__, 2) . '/src') as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source, $file);

            if (!str_contains($source, 'new MetricDefinition(')) {
                continue;
            }

            $statements = $parser->parse($source);
            self::assertIsArray($statements, $file);

            /** @var list<New_> $instantiations */
            $instantiations = $finder->find($statements, self::isMetricDefinition(...));

            foreach ($instantiations as $instantiation) {
                $key = self::foldedKeyOf($instantiation, $file);

                if ($key !== null) {
                    $keys[$key] = true;
                }
            }
        }

        self::$foldedKeys = array_keys($keys);

        return self::$foldedKeys;
    }

    private static function isMetricDefinition(Node $node): bool
    {
        return $node instanceof New_
            && $node->class instanceof Node\Name
            && $node->class->getLast() === 'MetricDefinition';
    }

    /**
     * The metric name this definition declares, when it is collected on a
     * namespace and aggregated to the project; null when it is not.
     *
     * Every shape this cannot read raises instead of returning null, so a
     * definition written some other way stops the sweep rather than leaving it.
     */
    private static function foldedKeyOf(New_ $definition, string $file): ?string
    {
        $arguments = [];

        foreach ($definition->args as $argument) {
            if (!$argument instanceof Node\Arg || $argument->name === null) {
                throw new RuntimeException(\sprintf(
                    'A MetricDefinition in %s is built with a positional or spread argument; this sweep reads'
                    . ' named arguments only and will not guess which one is the collection level.',
                    $file,
                ));
            }

            $arguments[$argument->name->toString()] = $argument->value;
        }

        $collectedAt = $arguments['collectedAt'] ?? throw new RuntimeException(
            \sprintf('A MetricDefinition in %s names no collectedAt', $file),
        );

        if (self::levelCaseOf($collectedAt, $file) !== SymbolLevel::Namespace_) {
            return null;
        }

        $aggregations = $arguments['aggregations'] ?? null;

        if ($aggregations === null) {
            return null;
        }

        if (!$aggregations instanceof Node\Expr\Array_) {
            throw new RuntimeException(\sprintf(
                'A namespace-collected MetricDefinition in %s declares its aggregations as something other than'
                . ' an array literal, so this sweep cannot tell whether the project is among them.',
                $file,
            ));
        }

        foreach ($aggregations->items as $item) {
            $target = $item->key ?? throw new RuntimeException(
                \sprintf('An aggregation entry in %s names no target level', $file),
            );

            // `SymbolLevel::Project->value` — the case is the fetch's object.
            if (!$target instanceof PropertyFetch || !$target->var instanceof ClassConstFetch) {
                throw new RuntimeException(\sprintf(
                    'An aggregation target in %s is not spelled `SymbolLevel::<Case>->value`, so this sweep'
                    . ' cannot resolve the level it names.',
                    $file,
                ));
            }

            if (self::levelCaseOf($target->var, $file) === SymbolLevel::Project) {
                return self::nameOf($arguments['name'] ?? null, $file);
            }
        }

        return null;
    }

    private static function levelCaseOf(Node\Expr $expression, string $file): SymbolLevel
    {
        if (!$expression instanceof ClassConstFetch
            || !$expression->class instanceof Node\Name
            || $expression->class->getLast() !== 'SymbolLevel'
            || !$expression->name instanceof Node\Identifier
        ) {
            throw new RuntimeException(\sprintf(
                'A symbol level in %s is not a SymbolLevel case this sweep can resolve.',
                $file,
            ));
        }

        $case = \constant(SymbolLevel::class . '::' . $expression->name->toString());
        self::assertInstanceOf(SymbolLevel::class, $case, $file);

        return $case;
    }

    private static function nameOf(?Node\Expr $expression, string $file): string
    {
        if ($expression instanceof String_) {
            return $expression->value;
        }

        if ($expression instanceof ClassConstFetch
            && $expression->class instanceof Node\Name
            && $expression->class->getLast() === 'MetricName'
            && $expression->name instanceof Node\Identifier
        ) {
            $value = \constant(MetricName::class . '::' . $expression->name->toString());
            self::assertIsString($value, $file);

            return $value;
        }

        throw new RuntimeException(\sprintf(
            'A MetricDefinition in %s names itself with an expression this sweep cannot resolve to a key.',
            $file,
        ));
    }

    /**
     * A parent namespace that declares a class of its own, a child that
     * declares one too — the shape the leaf-only fold used to drop — and an
     * unrelated namespace for edges to cross the subtree boundary through.
     *
     * Each variant changes the parent's subtree along one axis and leaves every
     * declaration of the parent itself, and each of its edges, as it was:
     * `type` gives the child an interface nothing references, `efferent` makes
     * the child depend on a class outside the subtree, `afferent` makes a class
     * outside the subtree depend on the child.
     */
    private static function runOver(string $variant): MetricRepositoryInterface
    {
        if (isset(self::$runs[$variant])) {
            return self::$runs[$variant];
        }

        $root = sys_get_temp_dir() . '/qmx_fold_' . bin2hex(random_bytes(6));

        foreach (['/Child', '/Other'] as $directory) {
            if (!mkdir($root . $directory, 0o777, true) && !is_dir($root . $directory)) {
                throw new RuntimeException('Failed to create the fold fixture directory');
            }
        }

        $canonicalRoot = realpath($root);
        $root = $canonicalRoot === false ? throw new RuntimeException('Cannot resolve the fold fixture root') : $canonicalRoot;

        self::write($root . '/Own.php', <<<'PHP'
            <?php

            namespace FoldFixture;

            class Own
            {
                public function reach(): \FoldFixture\Child\Reached
                {
                    return new \FoldFixture\Child\Reached();
                }
            }
            PHP);

        self::write($root . '/Child/Reached.php', $variant === 'efferent' ? <<<'PHP'
            <?php

            namespace FoldFixture\Child;

            class Reached
            {
                public function answer(): \FoldOther\Sink
                {
                    return new \FoldOther\Sink();
                }
            }
            PHP : <<<'PHP'
            <?php

            namespace FoldFixture\Child;

            class Reached
            {
                public function answer(): int
                {
                    return 1;
                }
            }
            PHP);

        self::write($root . '/Other/Sink.php', <<<'PHP'
            <?php

            namespace FoldOther;

            class Sink
            {
            }
            PHP);

        self::write($root . '/Other/Caller.php', $variant === 'afferent' ? <<<'PHP'
            <?php

            namespace FoldOther;

            class Caller
            {
                public function call(): \FoldFixture\Child\Reached
                {
                    return new \FoldFixture\Child\Reached();
                }
            }
            PHP : <<<'PHP'
            <?php

            namespace FoldOther;

            class Caller
            {
            }
            PHP);

        if ($variant === 'type') {
            self::write($root . '/Child/Unreferenced.php', <<<'PHP'
                <?php

                namespace FoldFixture\Child;

                interface Unreferenced
                {
                    public function nothingCallsThis(): void;
                }

                trait UnusedTrait
                {
                }

                enum Implementing implements Unreferenced
                {
                    case Value;

                    public function nothingCallsThis(): void
                    {
                    }
                }

                enum Bare
                {
                    case Value;
                }
                PHP);
        }

        $container = (new ContainerFactory())->create();
        $configurationPipeline = $container->get(ConfigurationPipelineInterface::class);
        \assert($configurationPipeline instanceof ConfigurationPipelineInterface);
        $document = LayeredDocument::of([], AbsolutePath::fromString($root), ...LayeredDocument::sectionsOf($configurationPipeline));

        /** @var ArchitecturePolicyConfiguratorInterface $architecturePolicy */
        $architecturePolicy = $container->get(ArchitecturePolicyConfiguratorInterface::class);
        $architecturePolicy->replace($architecturePolicy->resolve($document));

        /** @var ComputedMetricConfiguratorInterface $computedMetrics */
        $computedMetrics = $container->get(ComputedMetricConfiguratorInterface::class);
        $computedMetrics->replace($computedMetrics->resolve($document));

        $execution = $container->get(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class, $execution);
        $channels = $container->get(\Qualimetrix\Analysis\Finding\Contract\Channel\ChannelUniverseInterface::class);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\Contract\Channel\ChannelUniverseInterface::class, $channels);
        $rules = $container->get(\Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface::class);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface::class, $rules);
        $rules->replace(\Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::ready(
            \Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration::fromDocument($document),
            $execution->allRules(),
            channels: $channels,
        ));

        /** @var AnalysisPipelineInterface $pipeline */
        $pipeline = $container->get(AnalysisPipelineInterface::class);

        $result = $pipeline->analyze(new RunConfiguration(
            pathExcludes: [],
            projectRoot: AbsolutePath::fromString($root),
            generatedFilePolicy: GeneratedFilePolicy::Include,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(
                universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(
                    projectRoot: AbsolutePath::fromString($root),
                    pathsAuthored: true,
                    denominator: [],
                    prunedTargets: [],
                    reasons: [],
                    namespaceMapUsable: true,
                    pathResolutions: [],
                    manifestFacts: (new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader())->read(AbsolutePath::fromString($root)),
                ),
                paths: [AbsolutePath::fromString($root)],
                scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered,
                uncoveredRoots: [],
            ),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        ));

        self::removeDirectory($root);
        self::$runs[$variant] = $result->measured->repository;

        return $result->measured->repository;
    }

    private static function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Failed to write ' . $path);
        }
    }

    private static function removeDirectory(string $root): void
    {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($root);
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $root): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
