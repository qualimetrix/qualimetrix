<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Baseline\ViolationHasher;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\SuppressionType;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\ViolationFilterOptions;
use Qualimetrix\Infrastructure\Console\ViolationFilterPipeline;

/**
 * Tests for baseline, suppression, path exclusion, and git scope filters
 * in ViolationFilterPipeline (steps 1-4).
 */
#[CoversClass(ViolationFilterPipeline::class)]
final class ViolationFilterPipelineTest extends TestCase
{
    private ViolationHasher $hasher;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->hasher = new ViolationHasher();
    }

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
        $violation = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService');
        $hash = $this->hasher->hash($violation);
        $canonical = $violation->symbolPath->toCanonical();

        $baselinePath = $this->writeBaselineFile([
            $canonical => [['rule' => 'complexity.cyclomatic', 'hash' => $hash]],
        ]);

        $pipeline = $this->createPipeline();

        $options = new ViolationFilterOptions(
            baselinePath: $baselinePath,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
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
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
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
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->baselineFiltered);
    }

    #[Test]
    public function staleBaselineBlocksFilteringUnlessIgnored(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService');
        $hash = $this->hasher->hash($violation);
        $canonical = $violation->symbolPath->toCanonical();

        // Baseline with entries for a canonical that doesn't exist in current violations
        $baselinePath = $this->writeBaselineFile([
            $canonical => [['rule' => 'complexity.cyclomatic', 'hash' => $hash]],
            'stale::canonical' => [['rule' => 'some.rule', 'hash' => 'aaaa1111bbbb2222']],
        ]);

        $pipeline = $this->createPipeline();

        // Without ignoreStaleBaseline — filtering should NOT be applied
        $options = new ViolationFilterOptions(
            baselinePath: $baselinePath,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        // Violation is NOT filtered because stale keys prevent baseline application
        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->baselineFiltered);
        self::assertNotEmpty($result->staleBaselineKeys);
        self::assertSame(1, $result->staleBaselineCount);
    }

    #[Test]
    public function staleBaselineIgnoredWhenFlagSet(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService');
        $hash = $this->hasher->hash($violation);
        $canonical = $violation->symbolPath->toCanonical();

        $baselinePath = $this->writeBaselineFile([
            $canonical => [['rule' => 'complexity.cyclomatic', 'hash' => $hash]],
            'stale::canonical' => [['rule' => 'some.rule', 'hash' => 'aaaa1111bbbb2222']],
        ]);

        $pipeline = $this->createPipeline();

        // With ignoreStaleBaseline — filtering IS applied despite stale entries
        $options = new ViolationFilterOptions(
            baselinePath: $baselinePath,
            ignoreStaleBaseline: true,
            disableSuppression: true,
            excludePaths: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(0, $result->violations);
        self::assertSame(1, $result->baselineFiltered);
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
            ignoreStaleBaseline: false,
            disableSuppression: false,
            excludePaths: [],
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
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
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
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: ['vendor'],
            gitScope: null,
        );

        $result = $pipeline->filter([$v1, $v2], $options);

        self::assertCount(1, $result->violations);
        self::assertSame('src/Service/UserService.php', $result->violations[0]->location->file);
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
        $configProvider = $this->createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $pipeline = new ViolationFilterPipeline(
            new BaselineLoader(),
            new ViolationHasher(),
            new SuppressionFilter(),
            $configProvider,
        );

        $options = new ViolationFilterOptions(
            baselinePath: null,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
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
        $configProvider = $this->createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $pipeline = new ViolationFilterPipeline(
            new BaselineLoader(),
            new ViolationHasher(),
            new SuppressionFilter(),
            $configProvider,
        );

        $options = new ViolationFilterOptions(
            baselinePath: null,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: ['vendor'],
            gitScope: null,
        );

        $result = $pipeline->filter([$v1, $v2, $v3], $options);

        self::assertCount(1, $result->violations);
        self::assertSame('src/Service/UserService.php', $result->violations[0]->location->file);
        self::assertSame(2, $result->pathExclusionFiltered);
    }

    #[Test]
    public function noPathExclusionWhenPathsAreEmpty(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $pipeline = $this->createPipeline();

        $options = new ViolationFilterOptions(
            baselinePath: null,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
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
        $configProvider = $this->createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $pipeline = new ViolationFilterPipeline(
            new BaselineLoader(),
            new ViolationHasher(),
            new SuppressionFilter(),
            $configProvider,
        );

        $options = new ViolationFilterOptions(
            baselinePath: null,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
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
        $configProvider = $this->createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $pipeline = new ViolationFilterPipeline(
            new BaselineLoader(),
            new ViolationHasher(),
            new SuppressionFilter(),
            $configProvider,
        );

        $options = new ViolationFilterOptions(
            baselinePath: null,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
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
            location: new Location('src/helpers.php', 10),
            symbolPath: SymbolPath::forFile('src/helpers.php'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'CCN too high',
            severity: Severity::Error,
        );

        $config = new AnalysisConfiguration(
            excludeNamespaces: ['App'],
        );
        $configProvider = $this->createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($config);

        $pipeline = new ViolationFilterPipeline(
            new BaselineLoader(),
            new ViolationHasher(),
            new SuppressionFilter(),
            $configProvider,
        );

        $options = new ViolationFilterOptions(
            baselinePath: null,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$vFile], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->namespaceExclusionFiltered);
    }

    #[Test]
    public function noNamespaceExclusionWhenEmpty(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $pipeline = $this->createPipeline();

        $options = new ViolationFilterOptions(
            baselinePath: null,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->namespaceExclusionFiltered);
    }

    // -- Git scope filter (step 5) --

    #[Test]
    public function gitScopeFilterIsSkippedWhenNull(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $pipeline = $this->createPipeline();

        $options = new ViolationFilterOptions(
            baselinePath: null,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
            gitScope: null,
        );

        $result = $pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->gitScopeFiltered);
    }

    // -- Helper methods --

    private function makeViolation(string $file, string $namespace = 'App', string $class = 'TestClass'): Violation
    {
        return new Violation(
            location: new Location($file, 10),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'CCN too high',
            severity: Severity::Error,
        );
    }

    private function createPipeline(): ViolationFilterPipeline
    {
        $configProvider = $this->createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')
            ->willReturn(new AnalysisConfiguration());

        return new ViolationFilterPipeline(
            new BaselineLoader(),
            new ViolationHasher(),
            new SuppressionFilter(),
            $configProvider,
        );
    }

    /**
     * Writes a temporary baseline JSON file.
     *
     * @param array<string, list<array{rule: string, hash: string}>> $violations
     */
    private function writeBaselineFile(array $violations): string
    {
        $tmpBase = (string) tempnam(sys_get_temp_dir(), 'qmx_baseline_');
        $path = $tmpBase . '.json';
        $this->tempFiles[] = $tmpBase;
        $this->tempFiles[] = $path;

        $data = [
            'version' => 5,
            'generated' => (new DateTimeImmutable())->format('c'),
            'violations' => $violations,
        ];

        file_put_contents($path, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));

        return $path;
    }
}
