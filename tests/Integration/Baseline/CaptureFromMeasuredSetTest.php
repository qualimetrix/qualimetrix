<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Baseline;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntryParser;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineIdentity;
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
use Qualimetrix\Infrastructure\Console\MeasuredViolationSet;
use Qualimetrix\Infrastructure\Console\ViolationFilterOptions;
use Qualimetrix\Infrastructure\Console\ViolationFilterPipeline;
use Qualimetrix\Tests\Support\Time\FixedClock;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;

/**
 * What capture is allowed to record.
 *
 * Capture reads the measured set (§5.5), so an entry is never written for a
 * finding the same run suppressed or excluded: such an entry could never be
 * matched again, would be reported as inert forever, and nothing short of
 * hand-editing could retire it.
 */
final class CaptureFromMeasuredSetTest extends TestCase
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

    #[Test]
    public function itWritesNoEntryForAFindingRemovedByAnIgnoreTag(): void
    {
        $ignored = self::finding('src/Legacy/Service.php', 'App\\Legacy', 'Service');
        $reported = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');

        $pipeline = $this->createPipeline();
        $pipeline->loadSuppressions([
            'src/Legacy/Service.php' => [
                new Suppression(rule: '*', reason: 'Reviewed', line: 1, type: SuppressionType::File),
            ],
        ]);

        $baseline = $this->capture($pipeline->filter([$ignored, $reported], new ViolationFilterOptions())->measuredViolations);

        self::assertSame(1, $baseline->count());
        self::assertTrue($baseline->hasIdentity(BaselineIdentity::forViolation($reported)));
        self::assertFalse($baseline->hasIdentity(BaselineIdentity::forViolation($ignored)));
    }

    #[Test]
    public function itWritesNoEntryForAFindingRemovedByPathExclusion(): void
    {
        $excluded = self::finding('generated/Proxy.php', 'App\\Generated', 'Proxy');
        $reported = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludePaths: ['generated']));

        $baseline = $this->capture($pipeline->filter([$excluded, $reported], new ViolationFilterOptions())->measuredViolations);

        self::assertSame(1, $baseline->count());
        self::assertFalse($baseline->hasIdentity(BaselineIdentity::forViolation($excluded)));
    }

    /**
     * Written on an ordinary channel on purpose: `architecture.*` is exempt
     * from `exclude_namespaces` and behaves the opposite way — see below.
     */
    #[Test]
    public function itWritesNoEntryForAnOrdinaryFindingRemovedByNamespaceExclusion(): void
    {
        $excluded = self::finding('src/Generated/Proxy.php', 'App\\Generated', 'Proxy');
        $reported = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludeNamespaces: ['App\\Generated']));

        $baseline = $this->capture($pipeline->filter([$excluded, $reported], new ViolationFilterOptions())->measuredViolations);

        self::assertSame(1, $baseline->count());
        self::assertFalse($baseline->hasIdentity(BaselineIdentity::forViolation($excluded)));
    }

    /**
     * `exclude_namespaces` does not silence layer-policy enforcement, so an
     * architecture finding inside an excluded namespace stays in the measured
     * set and is captured — the baseline is its sanctioned route.
     */
    #[Test]
    public function itWritesAnEntryForAnArchitectureFindingInsideAnExcludedNamespace(): void
    {
        $architecture = self::finding(
            'src/Generated/Proxy.php',
            'App\\Generated',
            'Proxy',
            LayerViolationRule::NAME,
            LayerViolationRule::NAME,
        );

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludeNamespaces: ['App\\Generated']));

        $baseline = $this->capture($pipeline->filter([$architecture], new ViolationFilterOptions())->measuredViolations);

        self::assertSame(1, $baseline->count());
        self::assertTrue($baseline->hasIdentity(BaselineIdentity::forViolation($architecture)));
    }

    /**
     * The round trip a group with one ignored member has to survive: capture
     * records what the run measured, and the next run measures the same
     * thing, so nothing is reported. With the baseline judging the raw
     * analysis output, capture would have recorded one member fewer than the
     * ceiling saw, and the very next `check` would have promoted the whole
     * group to Error.
     */
    #[Test]
    public function itKeepsAGroupWithOneIgnoredMemberAcceptedAcrossGenerateAndCheck(): void
    {
        $file = SymbolPath::forFile(RelativePath::fromString('src/Legacy/Service.php'));
        $kept = self::occurrenceFinding($file, line: 10);
        $ignoredMember = self::occurrenceFinding($file, line: 40);

        $pipeline = $this->createPipeline();
        $suppressions = [
            'src/Legacy/Service.php' => [
                new Suppression(
                    rule: 'code-smell.goto',
                    reason: 'Reviewed',
                    line: 39,
                    type: SuppressionType::NextLine,
                ),
            ],
        ];

        // generate
        $pipeline->loadSuppressions($suppressions);
        $measured = $pipeline->filter([$kept, $ignoredMember], new ViolationFilterOptions())->measuredViolations;
        $captured = $this->capture($measured);

        self::assertSame([$kept], $measured);
        self::assertSame(
            1,
            $captured->entries[0]->count,
            'The entry must record the one member the run measured, not the two the analysis produced.',
        );

        $baselinePath = $this->writeBaseline($captured);

        // check
        $pipeline->loadSuppressions($suppressions);
        $result = $pipeline->filter([$kept, $ignoredMember], new ViolationFilterOptions($baselinePath));

        self::assertSame([], $result->violations);
        self::assertSame(0, $result->staleEntryCount());
    }

    /**
     * @param list<Violation> $measured
     */
    private function capture(array $measured): Baseline
    {
        $generator = new BaselineGenerator(StubChannelDeclarationRegistry::withDefaults(), new FixedClock());

        return $generator->generate($measured, ['src'])->baseline;
    }

    private function writeBaseline(Baseline $baseline): string
    {
        $entries = [];

        foreach ($baseline->entries as $entry) {
            $entries[$entry->identity->symbolKey][] = array_filter(
                [
                    'channel' => $entry->identity->channel->toKey(),
                    'magnitudes' => $entry->magnitudes,
                    'count' => $entry->count,
                ],
                static fn(mixed $value): bool => $value !== null,
            );
        }

        // tempnam() creates the file it names, and the loader wants a `.json`
        // suffix — so two paths exist and both have to be cleaned up.
        $reserved = (string) tempnam(sys_get_temp_dir(), 'qmx_capture_');
        $path = $reserved . '.json';
        $this->tempFiles[] = $reserved;
        $this->tempFiles[] = $path;

        file_put_contents($path, json_encode([
            'version' => Baseline::VERSION,
            'generated' => (new DateTimeImmutable())->format('c'),
            'scope' => ['src'],
            'entries' => $entries,
        ], \JSON_THROW_ON_ERROR));

        return $path;
    }

    private function createPipeline(?AnalysisConfiguration $configuration = null): ViolationFilterPipeline
    {
        $configurationProvider = self::createStub(ConfigurationProviderInterface::class);
        $configurationProvider->method('getConfiguration')->willReturn($configuration ?? new AnalysisConfiguration());

        $declarations = StubChannelDeclarationRegistry::withDefaults();

        return new ViolationFilterPipeline(
            new BaselineLoader(new BaselineEntryParser($declarations)),
            $declarations,
            new MeasuredViolationSet(
                self::createStub(AnalysisPipelineInterface::class),
                new SuppressionFilter(),
                $configurationProvider,
            ),
        );
    }

    private static function finding(
        string $file,
        string $namespace,
        string $class,
        string $ruleName = 'complexity.cyclomatic',
        string $violationCode = 'complexity.cyclomatic.method',
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString($file), 10),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: $ruleName,
            violationCode: $violationCode,
            message: 'finding',
            severity: Severity::Warning,
            metricValue: 25,
        );
    }

    private static function occurrenceFinding(SymbolPath $symbolPath, int $line): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Legacy/Service.php'), $line, precise: true),
            symbolPath: $symbolPath,
            ruleName: 'code-smell.goto',
            violationCode: 'code-smell.goto',
            message: 'goto statement detected',
            severity: Severity::Warning,
            metricValue: 1.0,
        );
    }
}
