<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineGenerator;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;

/**
 * What capture is allowed to record.
 *
 * Capture reads the measured set (ADR 0017), so an entry is never written for a
 * finding the same run suppressed or excluded: such an entry could never be
 * matched again, would be reported as inert forever, and nothing short of
 * hand-editing could retire it.
 */
final class CaptureFromMeasuredSetTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    /** @var array<string, list<Suppression>> */
    private array $suppressions = [];

    private FindingProjectionOptions $configuredOptions;

    protected function setUp(): void
    {
        $this->suppressions = [];
        $this->configuredOptions = new FindingProjectionOptions();
    }

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
        $this->suppressions = [
            'src/Legacy/Service.php' => [
                new Suppression(rule: '*', reason: 'Reviewed', line: 1, type: SuppressionType::File),
            ],
        ];

        $baseline = $this->capture($this->project($pipeline, [$ignored, $reported], new FindingProjectionOptions())->measuredFindings);

        self::assertSame(1, $baseline->count());
        self::assertTrue($baseline->hasIdentity(BaselineIdentity::forFinding($reported)));
        self::assertFalse($baseline->hasIdentity(BaselineIdentity::forFinding($ignored)));
    }

    #[Test]
    public function itWritesNoEntryForAFindingRemovedByPathExclusion(): void
    {
        $excluded = self::finding('generated/Proxy.php', 'App\\Generated', 'Proxy');
        $reported = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludePaths: ['generated']));

        $baseline = $this->capture($this->project($pipeline, [$excluded, $reported], new FindingProjectionOptions())->measuredFindings);

        self::assertSame(1, $baseline->count());
        self::assertFalse($baseline->hasIdentity(BaselineIdentity::forFinding($excluded)));
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

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludeNamespaces: ['App\\Generated']));

        $baseline = $this->capture($this->project($pipeline, [$excluded, $reported], new FindingProjectionOptions())->measuredFindings);

        self::assertSame(1, $baseline->count());
        self::assertFalse($baseline->hasIdentity(BaselineIdentity::forFinding($excluded)));
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

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludeNamespaces: ['App\\Generated']));

        $baseline = $this->capture($this->project($pipeline, [$architecture], new FindingProjectionOptions())->measuredFindings);

        self::assertSame(1, $baseline->count());
        self::assertTrue($baseline->hasIdentity(BaselineIdentity::forFinding($architecture)));
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
        $this->suppressions = $suppressions;
        $measured = $this->project($pipeline, [$kept, $ignoredMember], new FindingProjectionOptions())->measuredFindings;
        $captured = $this->capture($measured);

        self::assertSame([$kept], $measured);
        self::assertSame(
            1,
            $captured->entries[0]->count,
            'The entry must record the one member the run measured, not the two the analysis produced.',
        );

        $baselinePath = $this->writeBaseline($captured);

        // check
        $this->suppressions = $suppressions;
        $result = $this->project($pipeline, [$kept, $ignoredMember], new FindingProjectionOptions($baselinePath));

        self::assertSame([], $result->findings);
        self::assertSame(0, $result->staleEntryCount());
    }

    /**
     * @param list<Finding> $measured
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
            // Mirrors BaselineWriter (P1.1): "count" is derived from
            // "magnitudes" and is not written alongside it.
            $entries[$entry->identity->subjectKey][] = $entry->toArray();
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

    private function createPipeline(?FindingProjectionOptions $configuration = null): FindingProjector
    {
        $this->configuredOptions = $configuration ?? new FindingProjectionOptions();

        $declarations = StubChannelDeclarationRegistry::withDefaults();

        return new FindingProjector(
            new SuppressionFilter(),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            $declarations,
            new class implements \Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface {
                public function resolve(\Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest $request): \Qualimetrix\Reporting\FindingProjection\Contract\GitScopeResult
                {
                    return new \Qualimetrix\Reporting\FindingProjection\Contract\GitScopeResult([], []);
                }
            },
        );
    }

    /** @param list<Finding> $findings */
    private function project(FindingProjector $projector, array $findings, FindingProjectionOptions $options): \Qualimetrix\Reporting\FindingProjection\FindingProjectionResult
    {
        return $projector->project($findings, $this->suppressions, new FindingProjectionOptions(
            baselinePath: $options->baselinePath,
            excludePaths: [...$this->configuredOptions->excludePaths, ...$options->excludePaths],
            excludeNamespaces: [...$this->configuredOptions->excludeNamespaces, ...$options->excludeNamespaces],
            annotationSuppressionDisabled: $options->annotationSuppressionDisabled,
            gitScope: $options->gitScope,
        ));
    }

    private static function finding(
        string $file,
        string $namespace,
        string $class,
        string $ruleName = 'complexity.cyclomatic',
        string $code = 'complexity.cyclomatic',
    ): Finding {
        return new Finding(
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass($namespace, $class), RelativePath::fromString($file), DeclarationOrdinal::fromRank(0))),
            location: new Location(RelativePath::fromString($file), 10),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: $ruleName,
            code: $code,
            message: 'finding',
            severity: Severity::Warning,
            metricValue: 25,
        );
    }

    private static function occurrenceFinding(SymbolPath $symbolPath, int $line): Finding
    {
        return new Finding(
            subject: MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Legacy/Service.php'))),
            location: new Location(RelativePath::fromString('src/Legacy/Service.php'), $line, precise: true),
            symbolPath: $symbolPath,
            ruleName: 'code-smell.goto',
            code: 'code-smell.goto',
            message: 'goto statement detected',
            severity: Severity::Warning,
            metricValue: 1.0,
        );
    }
}
