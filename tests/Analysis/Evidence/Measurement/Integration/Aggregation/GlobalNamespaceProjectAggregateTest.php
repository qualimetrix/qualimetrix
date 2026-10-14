<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Integration\Aggregation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricSubjectEvaluation;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Infrastructure\Console\Support\PreparedAnalysis;
use RuntimeException;

/**
 * Global declarations remain a coverage population even without distance.
 * A genuine outgoing edge makes their own distance measurable, including zero,
 * and the project publisher must retain that sample rather than dropping the
 * empty namespace name. Each level evaluates its own Health formula; a
 * parent/child inequality does not establish whether the fold kept its input.
 */
#[Group('integration')]
final class GlobalNamespaceProjectAggregateTest extends TestCase
{
    /** @var array<string, MetricRepositoryInterface> */
    private static array $repositories = [];

    public static function setUpBeforeClass(): void
    {
        self::$repositories['disconnected'] = self::analyze(AbsolutePath::fromString(
            \dirname(__DIR__, 2) . '/Fixtures/GlobalNamespaceOnly',
        ));
        $root = sys_get_temp_dir() . '/qmx_global_outgoing_' . bin2hex(random_bytes(8));
        if (!mkdir($root)) {
            throw new RuntimeException('Cannot create the global outgoing fixture');
        }

        try {
            $calls = [];
            $declarations = [];
            // This wakes the namespace-only outgoing-volume term, making a
            // wrong-level Health evaluation observable without fixing a score.
            for ($index = 0; $index < 51; $index++) {
                $calls[] = '        new \\GlobalFixtureDependency\\Target' . $index . '();';
                $declarations[] = 'class Target' . $index . ' {}';
            }
            self::write($root . '/Dependencies.php', "<?php\nnamespace GlobalFixtureDependency;\n" . implode("\n", $declarations));
            self::write($root . '/Global.php', "<?php\nclass GlobalNamespaceOutgoingService\n{\n    public function reach(): void\n    {\n" . implode("\n", $calls) . "\n    }\n}\nclass GlobalNamespaceIdleStorage {}\n");
            // Dependency declarations are real but outside the selected source;
            // only the global class belongs to this project's type population.
            self::$repositories['outgoing'] = self::analyze(AbsolutePath::fromString($root . '/Global.php'), AbsolutePath::fromString($root));
        } finally {
            foreach (['Global.php', 'Dependencies.php'] as $file) {
                if (is_file($root . '/' . $file)) {
                    unlink($root . '/' . $file);
                }
            }
            rmdir($root);
        }
    }

    #[Test]
    public function itKeepsGlobalDeclarationsWithoutInventingAnEdgeFreeDistance(): void
    {
        $repository = self::$repositories['disconnected'];
        $global = $repository->get(SymbolPath::forNamespace(''));
        $project = $repository->get(SymbolPath::forProject());

        self::assertSame(0, $global->get('coupling.ca-own'));
        self::assertSame(0, $global->get('coupling.ce-own'));
        self::assertSame(2, $global->get('size.class-count'));
        self::assertSame(1, $project->get('size.symbol-declaring-namespace-count'));
        self::assertTrue((new NamespaceTree($repository->getNamespaces()))->has(''));
        self::assertNull($global->get('coupling.distance'));
        self::assertNull($global->get('coupling.distance-own'));
        self::assertNull($project->get('coupling.distance-own.avg'));
        self::assertNull($project->get('coupling.distance-own.count'));
    }

    #[Test]
    public function itRetainsAMeasuredZeroAndItsGlobalNamespaceSample(): void
    {
        $repository = self::$repositories['outgoing'];
        $global = $repository->get(SymbolPath::forNamespace(''));
        $project = $repository->get(SymbolPath::forProject());

        self::assertSame(0, $global->get('coupling.ca-own'));
        self::assertGreaterThan(50, $global->get('coupling.ce-own'), 'The native outgoing plant must be observed');
        self::assertSame(2, $global->get('size.class-count'));
        self::assertSame(1, $project->get('size.symbol-declaring-namespace-count'));
        self::assertTrue((new NamespaceTree($repository->getNamespaces()))->has(''));
        self::assertSame(0.0, $global->get('coupling.distance'));
        self::assertSame(0.0, $global->get('coupling.distance-own'));
        self::assertSame($global->get('coupling.distance-own'), $project->get('coupling.distance-own.avg'));
        self::assertSame(1, $project->get('coupling.distance-own.count'));
    }

    /** @return iterable<string, array{string}> */
    public static function fixtureVariants(): iterable
    {
        yield 'no applicable distance' => ['disconnected'];
        yield 'applicable measured zero' => ['outgoing'];
    }

    #[Test]
    #[DataProvider('fixtureVariants')]
    public function itEvaluatesEachLevelsOwnOutgoingHealthInputs(string $variant): void
    {
        $repository = self::$repositories[$variant];
        $definition = ComputedMetricDefaults::getDefaults()['health.coupling'];
        $evaluation = new ComputedMetricSubjectEvaluation();
        foreach ([SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
            $path = $level === SymbolLevel::Project ? SymbolPath::forProject() : SymbolPath::forNamespace('');
            $bag = $repository->get($path);
            self::assertNotNull($bag->get('coupling.ce.avg'), 'Outgoing inputs still apply when distance is absent or zero');
            $expected = $evaluation->evaluate($definition, $level, $bag->all());
            self::assertNotNull($expected->value);
            self::assertEqualsWithDelta($expected->value, $bag->get('health.coupling'), 1e-12);
        }
        if ($variant === 'outgoing') {
            self::assertNotSame(
                $repository->get(SymbolPath::forNamespace(''))->get('health.coupling'),
                $repository->get(SymbolPath::forProject())->get('health.coupling'),
                'The namespace-only volume term must make wrong-level evaluation observable',
            );
        }
    }

    private static function analyze(AbsolutePath $path, ?AbsolutePath $workingDirectory = null): MetricRepositoryInterface
    {
        $fixture = PreparedAnalysis::start($workingDirectory ?? AbsolutePath::fromString((string) getcwd()), [$path], ['include_generated' => true]);
        try {
            $pipeline = $fixture->container()->get(AnalysisPipelineInterface::class);
            \assert($pipeline instanceof AnalysisPipelineInterface);

            return $pipeline->analyze($fixture->prepared()->runConfiguration)->measured->repository;
        } finally {
            $fixture->close();
        }
    }

    private static function write(string $path, string $source): void
    {
        if (file_put_contents($path, $source) === false) {
            throw new RuntimeException('Cannot write the global outgoing fixture');
        }
    }
}
