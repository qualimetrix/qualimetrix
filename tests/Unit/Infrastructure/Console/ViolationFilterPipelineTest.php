<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Baseline\BaselineEntryParser;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\SuppressionType;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\ViolationFilterOptions;
use Qualimetrix\Infrastructure\Console\ViolationFilterPipeline;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;

/**
 * Tests for baseline, suppression, path exclusion, and git scope filters
 * in ViolationFilterPipeline (steps 1-4).
 */
#[CoversClass(ViolationFilterPipeline::class)]
final class ViolationFilterPipelineTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    // -- Baseline filter (step 1) --

    #[Test]
    public function baselineFilterRemovesMatchingViolations(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);
        $symbolKey = $violation->symbolPath->toCanonical();

        $baselinePath = $this->writeBaselineFile([
            $symbolKey => [
                ['channel' => $violation->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
            ],
        ]);

        $pipeline = $this->createPipeline();

        $options = new ViolationFilterOptions(
            baselinePath: $baselinePath,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(0, $result->violations);
        self::assertSame(1, $result->baselineFiltered);
    }

    #[Test]
    public function noBaselineFilterWhenPathIsNull(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $pipeline = $this->createPipeline();

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->baselineFiltered);
    }

    #[Test]
    public function noBaselineFilterWhenPathIsEmpty(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $pipeline = $this->createPipeline();

        $options = new ViolationFilterOptions(
            baselinePath: '',
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->baselineFiltered);
    }

    /**
     * A stale entry on an unrelated symbol is reported and changes nothing
     * else — the case v5 also handled, kept as a regression guard.
     */
    #[Test]
    public function itReportsAStaleEntryWithoutDisablingTheRestOfTheBaseline(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);
        $symbolKey = $violation->symbolPath->toCanonical();

        // Baseline with an entry matching the current violation, plus an
        // entry for an identity that does not exist in current violations.
        $baselinePath = $this->writeBaselineFile([
            $symbolKey => [
                ['channel' => $violation->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
            ],
            'class:App\\Service\\OtherClass' => [
                ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 3],
            ],
        ]);

        $result = $this->createPipeline()->filter([$violation], $this->baselineOptions($baselinePath));

        self::assertCount(0, $result->violations);
        self::assertSame(1, $result->baselineFiltered);
        self::assertSame(1, $result->staleBaselineCount);
        self::assertStringContainsString('class:App\Service\OtherClass', $result->staleBaselineKeys[0]);
    }

    /**
     * The case the per-identity key of §5.1 introduced and the one v5's
     * symbol-level predicate could not produce: one channel of a symbol is
     * repaired while another still fires. The repaired entry goes stale, and
     * its neighbour under the same symbol must keep suppressing (§5.7).
     */
    #[Test]
    public function itKeepsApplyingSiblingEntriesWhenOneChannelOfASymbolIsRepaired(): void
    {
        $stillFiring = $this->makeViolation(
            'src/Service/UserService.php',
            'App\\Service',
            'UserService',
            metricValue: 25,
        );
        $symbolKey = $stillFiring->symbolPath->toCanonical();

        // Two entries under one symbol; only the cyclomatic one still fires.
        $baselinePath = $this->writeBaselineFile([
            $symbolKey => [
                ['channel' => $stillFiring->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
                ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 2],
            ],
        ]);

        $result = $this->createPipeline()->filter([$stillFiring], $this->baselineOptions($baselinePath));

        self::assertCount(0, $result->violations, 'The surviving entry must still suppress its finding.');
        self::assertSame(1, $result->baselineFiltered);
        self::assertSame(1, $result->staleBaselineCount);
        self::assertStringContainsString('code-smell.goto', $result->staleBaselineKeys[0]);
    }

    private function baselineOptions(string $baselinePath): ViolationFilterOptions
    {
        return new ViolationFilterOptions(
            baselinePath: $baselinePath,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );
    }

    // -- Suppression filter (step 2) --

    #[Test]
    public function suppressionFilterRemovesMatchingViolations(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $pipeline = $this->createPipeline();

        // Load a file-level suppression for the violation's file
        $pipeline->loadSuppressions([
            'src/Service/UserService.php' => [
                new Suppression(
                    rule: '*',
                    reason: 'Ignoring for now',
                    line: 1,
                    type: SuppressionType::File,
                ),
            ],
        ]);

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: false,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(0, $result->violations);
        self::assertSame(1, $result->suppressionFiltered);
    }

    #[Test]
    public function suppressionFilterIsSkippedWhenDisabled(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $pipeline = $this->createPipeline();

        $pipeline->loadSuppressions([
            'src/Service/UserService.php' => [
                new Suppression(
                    rule: '*',
                    reason: 'Ignoring',
                    line: 1,
                    type: SuppressionType::File,
                ),
            ],
        ]);

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->suppressionFiltered);
    }

    // -- Path exclusion filter (step 3) --

    #[Test]
    public function excludePathsFilterRemovesMatchingViolations(): void
    {
        $v1 = $this->makeViolation('src/Service/UserService.php');
        $v2 = $this->makeViolation('vendor/library/SomeClass.php');

        $pipeline = $this->createPipeline();

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: ['vendor'],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$v1, $v2], $options);

        self::assertCount(1, $result->violations);
        self::assertSame('src/Service/UserService.php', $result->violations[0]->location->pathString());
        self::assertSame(1, $result->pathExclusionFiltered);
    }

    #[Test]
    public function excludePathsFromConfigAreApplied(): void
    {
        $v1 = $this->makeViolation('src/Service/UserService.php');
        $v2 = $this->makeViolation('generated/Proxy.php');

        $config = new AnalysisConfiguration(
            excludePaths: ['generated'],
        );
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $pipeline = new ViolationFilterPipeline(
            $this->createBaselineLoader(),
            new SuppressionFilter(),
            $configProvider,
        );

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$v1, $v2], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(1, $result->pathExclusionFiltered);
    }

    #[Test]
    public function excludePathsMergesConfigAndOptionPaths(): void
    {
        $v1 = $this->makeViolation('src/Service/UserService.php');
        $v2 = $this->makeViolation('generated/Proxy.php');
        $v3 = $this->makeViolation('vendor/library/SomeClass.php');

        $config = new AnalysisConfiguration(
            excludePaths: ['generated'],
        );
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $pipeline = new ViolationFilterPipeline(
            $this->createBaselineLoader(),
            new SuppressionFilter(),
            $configProvider,
        );

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: ['vendor'],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$v1, $v2, $v3], $options);

        self::assertCount(1, $result->violations);
        self::assertSame('src/Service/UserService.php', $result->violations[0]->location->pathString());
        self::assertSame(2, $result->pathExclusionFiltered);
    }

    #[Test]
    public function noPathExclusionWhenPathsAreEmpty(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $pipeline = $this->createPipeline();

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->pathExclusionFiltered);
    }

    // -- Namespace exclusion filter (step 4) --

    #[Test]
    public function excludeNamespacesFilterRemovesMatchingViolations(): void
    {
        $v1 = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService');
        $v2 = $this->makeViolation('src/Generated/Proxy.php', 'App\\Generated', 'Proxy');

        $config = new AnalysisConfiguration(
            excludeNamespaces: ['App\\Generated'],
        );
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $pipeline = new ViolationFilterPipeline(
            $this->createBaselineLoader(),
            new SuppressionFilter(),
            $configProvider,
        );

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$v1, $v2], $options);

        self::assertCount(1, $result->violations);
        self::assertSame('App\\Service', $result->violations[0]->symbolPath->namespace);
        self::assertSame(1, $result->namespaceExclusionFiltered);
    }

    #[Test]
    public function excludeNamespacesMatchesChildNamespaces(): void
    {
        $v1 = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService');
        $v2 = $this->makeViolation('src/Generated/Sub/Proxy.php', 'App\\Generated\\Sub', 'Proxy');

        $config = new AnalysisConfiguration(
            excludeNamespaces: ['App\\Generated'],
        );
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $pipeline = new ViolationFilterPipeline(
            $this->createBaselineLoader(),
            new SuppressionFilter(),
            $configProvider,
        );

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$v1, $v2], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(1, $result->namespaceExclusionFiltered);
    }

    #[Test]
    public function excludeNamespacesKeepsNullNamespace(): void
    {
        $vFile = new Violation(
            location: new Location(RelativePath::fromString('src/helpers.php'), 10),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/helpers.php')),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'CCN too high',
            severity: Severity::Error,
        );

        $config = new AnalysisConfiguration(
            excludeNamespaces: ['App'],
        );
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $pipeline = new ViolationFilterPipeline(
            $this->createBaselineLoader(),
            new SuppressionFilter(),
            $configProvider,
        );

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$vFile], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->namespaceExclusionFiltered);
    }

    #[Test]
    public function cliExcludeNamespaceMergesWithConfig(): void
    {
        $v1 = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService');
        $v2 = $this->makeViolation('src/Generated/Proxy.php', 'App\\Generated', 'Proxy');
        $v3 = $this->makeViolation('src/Entity/User.php', 'App\\Entity', 'User');

        $config = new AnalysisConfiguration(
            excludeNamespaces: ['App\\Generated'],
        );
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $pipeline = new ViolationFilterPipeline(
            $this->createBaselineLoader(),
            new SuppressionFilter(),
            $configProvider,
        );

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: ['App\\Entity'],
            gitScope: null,
        );

        $result = $pipeline->filter([$v1, $v2, $v3], $options);

        self::assertCount(1, $result->violations);
        self::assertSame('App\\Service', $result->violations[0]->symbolPath->namespace);
        self::assertSame(2, $result->namespaceExclusionFiltered);
    }

    #[Test]
    public function noNamespaceExclusionWhenEmpty(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $pipeline = $this->createPipeline();

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->namespaceExclusionFiltered);
    }

    #[Test]
    public function itKeepsArchitectureRuleViolationsInExcludedNamespaces(): void
    {
        $architectureViolation = $this->makeViolation(
            'src/Foo/Service.php',
            'App\\Foo',
            'Service',
            LayerViolationRule::NAME,
        );
        $ordinaryViolation = $this->makeViolation(
            'src/Foo/Other.php',
            'App\\Foo',
            'Other',
            'complexity.cyclomatic',
        );

        $pipeline = $this->createPipelineWithExcludedNamespaces(['App\\Foo']);

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$architectureViolation, $ordinaryViolation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(LayerViolationRule::NAME, $result->violations[0]->ruleName);
        self::assertSame(1, $result->namespaceExclusionFiltered);
    }

    // -- Git scope filter (step 5) --

    #[Test]
    public function gitScopeFilterIsSkippedWhenNull(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $pipeline = $this->createPipeline();

        $options = new ViolationFilterOptions(
            baselinePath: null,
            disableSuppression: true,
            excludePaths: [],
            excludeNamespaces: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->gitScopeFiltered);
    }

    // -- Helper methods --

    private function makeViolation(
        string $file,
        string $namespace = 'App',
        string $class = 'TestClass',
        string $ruleName = 'complexity.cyclomatic',
        int|float|null $metricValue = null,
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString($file), 10),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: $ruleName,
            violationCode: $ruleName . '.method',
            message: 'CCN too high',
            severity: Severity::Error,
            metricValue: $metricValue,
        );
    }

    private function createPipeline(): ViolationFilterPipeline
    {
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')
            ->willReturn(new AnalysisConfiguration());

        return new ViolationFilterPipeline(
            $this->createBaselineLoader(),
            new SuppressionFilter(),
            $configProvider,
        );
    }

    /**
     * @param list<string> $excludeNamespaces
     */
    private function createPipelineWithExcludedNamespaces(array $excludeNamespaces): ViolationFilterPipeline
    {
        $config = new AnalysisConfiguration(excludeNamespaces: $excludeNamespaces);
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        return new ViolationFilterPipeline(
            $this->createBaselineLoader(),
            new SuppressionFilter(),
            $configProvider,
        );
    }

    /**
     * A loader wired with the channels these tests need declared — see
     * {@see StubChannelDeclarationRegistry::withDefaults()}.
     */
    private function createBaselineLoader(): BaselineLoader
    {
        return new BaselineLoader(new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()));
    }

    /**
     * Writes a temporary version 10 baseline JSON file.
     *
     * @param array<string, list<array<string, mixed>>> $entries symbol key => list of entry objects
     *                                                           (each in the `channel`/`magnitudes`/`count` shape)
     */
    private function writeBaselineFile(array $entries): string
    {
        $tmpBase = (string) tempnam(sys_get_temp_dir(), 'qmx_baseline_');
        $path = $tmpBase . '.json';
        $this->tempFiles[] = $tmpBase;
        $this->tempFiles[] = $path;

        $data = [
            'version' => 10,
            'generated' => (new DateTimeImmutable())->format('c'),
            'scope' => ['src'],
            'entries' => $entries,
        ];

        file_put_contents($path, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));

        return $path;
    }
}
