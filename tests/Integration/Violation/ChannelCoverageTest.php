<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Violation;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Aggregator\GlobalCollectorRunner;
use Qualimetrix\Analysis\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Collection\CollectionResult;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Pipeline\MetricEnricher;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\ChannelDeclarationRegistryInterface;
use Qualimetrix\Rules\CodeSmell\BooleanArgumentRule;
use Qualimetrix\Rules\CodeSmell\CodeSmellOptions;
use Qualimetrix\Rules\CodeSmell\GotoRule;
use Qualimetrix\Rules\Complexity\ComplexityOptions;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Rules\Maintainability\MaintainabilityOptions;
use Qualimetrix\Rules\Maintainability\MaintainabilityRule;
use Qualimetrix\Tests\Support\Pipeline\TestPipelineBuilder;
use RuntimeException;

/**
 * Suite-emission guard for this package's four exemplar channels.
 *
 * Every channel this package touches is exercised end to end through the
 * REAL rule/pipeline code (not a hand-built `Violation`) and checked
 * against the production {@see ChannelDeclarationRegistryInterface}: the
 * three declared channels must resolve to a declaration, and
 * `annotation.unsupported-threshold` — the one channel this package
 * verified as deliberately not baselineable — must resolve to `null` while
 * still being recorded in `tests/Fixtures/Channels/excluded.txt`.
 *
 * Scope, stated plainly: this is NOT "every channel the whole integration
 * suite emits" — that would fail today by construction, since only three
 * channels are statically declared and the other ~50 real channels
 * enumerated in `docs/plan/violation-magnitude-inventory.md` are not this
 * package's job to declare (see its README/report). The corpus here is
 * deliberately narrow: it covers exactly what this package declared plus
 * the one exclusion it verified. A later package that declares more
 * channels should extend this corpus alongside its declarations, the same
 * way it extends `tests/Fixtures/Channels/declared.txt`.
 */
#[CoversNothing]
final class ChannelCoverageTest extends TestCase
{
    #[Test]
    public function theGotoOccurrenceChannelIsDeclared(): void
    {
        $rule = new GotoRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);
        $metricBag = (new MetricBag())->withEntry('codeSmell.goto', ['line' => 50]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')->willReturn($metricBag);

        $violations = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theMaintainabilityIndexMagnitudeChannelIsDeclared(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);
        $metricBag = (new MetricBag())->with('mi', 10.0)->with('methodLoc', 50);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$methodInfo]);
        $repository->method('get')->willReturn($metricBag);

        $violations = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theCyclomaticComplexityMethodMagnitudeChannelIsDeclared(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);
        $metricBag = (new MetricBag())->with('ccn', 25)->with('cognitive', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')->willReturn($metricBag);

        $violations = $rule->analyzeLevel(RuleLevel::Method, new AnalysisContext($repository));
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theUnsupportedThresholdAnnotationChannelResolvesToNullAndIsRecordedAsExcluded(): void
    {
        $discovery = self::createStub(FileDiscoveryInterface::class);
        $discovery->method('discover')->willReturn(new ArrayIterator([]));

        $collectionOrchestrator = self::createStub(CollectionOrchestratorInterface::class);
        $collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(
                new CollectionResult(1, 0, thresholdOverrides: [
                    'src/Foo.php' => [
                        new ThresholdOverride('code-smell.boolean-argument', 50.0, 100.0, 10, 50),
                    ],
                ]),
                [],
            ),
        );

        // BooleanArgumentRule has a boolean-only Options class — it does not
        // implement ThresholdAwareOptionsInterface, which is exactly what
        // makes the annotation targeting it unsupported.
        $booleanArgRule = new BooleanArgumentRule(BooleanArgumentRule::getOptionsClass()::fromArray([]));

        $ruleExecutor = self::createStub(RuleExecutorInterface::class);
        $ruleExecutor->method('execute')->willReturn([]);
        $ruleExecutor->method('getAllRules')->willReturn([$booleanArgRule]);

        $configurationProvider = self::createStub(ConfigurationProviderInterface::class);
        $configurationProvider->method('getConfiguration')->willReturn(new AnalysisConfiguration());
        $configurationProvider->method('getRuleOptions')->willReturn([]);

        $metricEnricher = new MetricEnricher(
            compositeCollector: new CompositeCollector([]),
            globalCollectorRunner: new GlobalCollectorRunner([]),
            configurationProvider: $configurationProvider,
            logger: self::createStub(LoggerInterface::class),
        );

        $pipeline = TestPipelineBuilder::create()
            ->withDefaultDiscovery($discovery)
            ->withCollectionOrchestrator($collectionOrchestrator)
            ->withRuleExecutor($ruleExecutor)
            ->withConfigurationProvider($configurationProvider)
            ->withMetricEnricher($metricEnricher)
            ->build();

        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertCount(1, $result->violations);
        $channel = $result->violations[0]->channel();
        self::assertSame('annotation.unsupported-threshold', $channel->ruleName);

        $registry = (new ContainerFactory())->create()->get(ChannelDeclarationRegistryInterface::class);
        \assert($registry instanceof ChannelDeclarationRegistryInterface);

        self::assertNull(
            $registry->declarationFor($channel),
            'annotation.unsupported-threshold has no rule class to declare it on and must stay undeclared.',
        );
        self::assertContains(
            $channel->toKey(),
            self::readExcludedFixtureKeys(),
            'The channel is undeclared as expected, but tests/Fixtures/Channels/excluded.txt does not record why.',
        );
    }

    private static function assertDeclared(ViolationChannel $channel): void
    {
        $registry = (new ContainerFactory())->create()->get(ChannelDeclarationRegistryInterface::class);
        \assert($registry instanceof ChannelDeclarationRegistryInterface);

        self::assertNotNull(
            $registry->declarationFor($channel),
            \sprintf('Channel "%s" was emitted but the registry has no declaration for it.', $channel->toKey()),
        );
    }

    /**
     * @return list<string>
     */
    private static function readExcludedFixtureKeys(): array
    {
        $path = \dirname(__DIR__, 3) . '/tests/Fixtures/Channels/excluded.txt';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read fixture file %s.', $path));
        }

        $keys = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+--\s+/', $line, 2);
            $channelKey = $parts === false ? $line : $parts[0];
            $keys[] = trim($channelKey);
        }

        return $keys;
    }
}
