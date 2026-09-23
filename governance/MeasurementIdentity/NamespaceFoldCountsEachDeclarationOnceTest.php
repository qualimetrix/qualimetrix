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
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Every namespace-collected metric folded to the project must be an own-scope
 * value.
 *
 * The project fold admits every namespace that carries the key, parent as well
 * as leaf. That is sound for a value describing only what the namespace itself
 * declares, and unsound for a subtree rollup: a class under `P\C` would be
 * inside `P\C`'s value and inside `P`'s, and the project average would weigh it
 * twice. The fold cannot tell the two apart — both are floats on a namespace
 * bag — so nothing but this refuses a rollup that grows a project aggregation.
 * The leaf-only rule the fold replaced was a partial guard against exactly
 * that, and it left 41 of 166 namespaces out of the average to get it.
 *
 * The property is measured, not spelled: the fixture's subtree changes along
 * every axis the folded value is built from while the parent's own
 * declarations do not, and a rollup moves where an own-scope value stays. The
 * key's name is not evidence — `-own` is a convention a new definition can
 * satisfy while carrying a subtree number.
 *
 * The axes are read off the one folded key's inputs: `coupling.distance-own`
 * is built from `coupling.abstractness-own` (the namespace's type counts) and
 * `coupling.instability-own` (its `ce-own` and `ca-own`), so a subtree can gain
 * a type, an outgoing edge or an incoming edge. A folded key built from
 * anything else — a method, a line, a finding — is outside what this fixture
 * perturbs, and passes it without being measured.
 *
 * Two channels, because one would be its own oracle. Which keys are folded is
 * read from the declarations in `src/`; whether each holds is read from runs
 * over a fixture. A declaration this file cannot read is refused rather than skipped: the
 * silent half of a sweep is the half that drops a member.
 */
final class NamespaceFoldCountsEachDeclarationOnceTest extends TestCase
{
    /** @var list<string>|null */
    private static ?array $foldedKeys = null;

    /** @var array<string, MetricRepositoryInterface> */
    private static array $runs = [];

    #[Test]
    public function itFindsAtLeastOneFoldedNamespaceMetric(): void
    {
        // Without a floor the two sweeps below pass over an empty set and
        // report agreement they never measured.
        self::assertNotSame([], self::foldedKeys(), 'No namespace-collected metric declares a project aggregation');
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

        foreach (self::foldedKeys() as $key) {
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

    /**
     * The other half of "counted once": the fold's sample size is the number of
     * namespaces carrying the key, and every fixture namespace declares a type.
     */
    #[Test]
    public function itFoldsEveryNamespaceThatCarriesTheKey(): void
    {
        $project = self::runOver('plain')->get(SymbolPath::forProject());

        foreach (self::foldedKeys() as $key) {
            self::assertSame(
                3,
                $project->get($key . '.count'),
                \sprintf('"%s" is carried by all three fixture namespaces; the fold reached a different number', $key),
            );
        }
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
                PHP);
        }

        $container = (new ContainerFactory())->create();
        $document = new ConfigurationDocument([], AbsolutePath::fromString($root));

        /** @var ArchitecturePolicyConfiguratorInterface $architecturePolicy */
        $architecturePolicy = $container->get(ArchitecturePolicyConfiguratorInterface::class);
        $architecturePolicy->replace($architecturePolicy->resolve($document));

        /** @var ComputedMetricConfiguratorInterface $computedMetrics */
        $computedMetrics = $container->get(ComputedMetricConfiguratorInterface::class);
        $computedMetrics->replace($computedMetrics->resolve($document));

        /** @var AnalysisPipelineInterface $pipeline */
        $pipeline = $container->get(AnalysisPipelineInterface::class);

        $result = $pipeline->analyze(new RunConfiguration(
            [AbsolutePath::fromString($root)],
            [],
            AbsolutePath::fromString((string) getcwd()),
            GeneratedFilePolicy::Include,
            coversProjectScope: true,
            authoredPathExcludes: [],
        ));

        self::removeDirectory($root);
        self::$runs[$variant] = $result->metrics;

        return $result->metrics;
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
