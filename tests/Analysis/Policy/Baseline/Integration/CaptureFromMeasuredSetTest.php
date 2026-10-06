<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineFormatVersion;
use Qualimetrix\Analysis\Policy\Baseline\BaselineGenerator;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubRuleCoverage;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use Symfony\Component\Console\Tester\CommandTester;

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
    public function itNeverCapturesTheLateUnusedEntryAudit(): void
    {
        $gone = self::finding('src/Gone.php', 'App', 'Gone');
        $baselinePath = $this->writeBaseline($this->capture([$gone]));
        $result = $this->project($this->createPipeline(), [], new FindingProjectionOptions((new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->preflight($baselinePath)));
        self::assertCount(1, $result->findings);
        self::assertSame('baseline.unused-entry', $result->findings[0]->channel()->code);
        self::assertSame([], $result->measuredFindings);
        self::assertSame([], $this->capture($result->measuredFindings)->entries);
        $direct = (new BaselineGenerator(StubChannelDeclarationRegistry::withDefaults(), new FixedClock()))->generate($result->findings, ['src'], self::fixtureExclusions());
        self::assertSame([], $direct->baseline->entries);
        self::assertSame(\Qualimetrix\Analysis\Policy\Baseline\UncapturedReason::BaselineAuditChannel, $direct->uncaptured[0]->reason);
    }

    #[Test]
    public function itRecordsTheCompleteResolvedExclusionDefinitionThroughGenerate(): void
    {
        $directory = TempDirectory::create('qmx-capture-definition-');
        $previous = getcwd();
        self::assertNotFalse($previous);
        $path = $directory . '/baseline.json';

        try {
            mkdir($directory . '/src');
            mkdir($directory . '/src/Legacy');
            file_put_contents($directory . '/src/Legacy/Old.php', '<?php goto done; done:;');
            file_put_contents($directory . '/src/Current.php', '<?php goto done; done:;');
            file_put_contents($directory . '/qmx.yaml', "paths: [src]\nexclude: [{subtree: src/Nothing}, {subtree: src/Legacy}]\ncache: {enabled: false}\n");
            chdir($directory);
            $command = (new ContainerFactory())->create()->get(BaselineGenerateCommand::class);
            self::assertInstanceOf(BaselineGenerateCommand::class, $command);
            $tester = new CommandTester($command);
            $tester->execute([
                'baseline' => $path,
                '--workers' => '0',
                '--include-generated' => true,
                '--only-rule' => ['code-smell.goto'],
                '--mode' => 'suppress',
            ], ['capture_stderr_separately' => true]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $baseline = (new BaselineLoader(new BaselineEntryParser(new StubChannelDeclarationRegistry([
                'code-smell.goto' => ChannelDeclaration::occurrence(SymbolLevel::File),
            ]))))->load((new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->preflight($path));
            self::assertSame([
                'patterns' => ['subtree:src/Legacy', 'subtree:src/Nothing'],
                'generated' => 'included',
            ], $baseline->exclusions->toArray());
            self::assertCount(1, $baseline->entries);
            self::assertSame(BaselineEntryMode::Suppress, $baseline->entries[0]->mode);
            self::assertSame('file:src/Current.php', $baseline->entries[0]->identity->subjectKey);
        } finally {
            chdir($previous);
            TempDirectory::remove($directory);
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
                new Suppression(rule: '*', reason: 'Reviewed', line: 1, type: SuppressionType::File, position: 0),
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

        $pipeline = $this->createPipeline(new FindingProjectionOptions(suppressPaths: [self::path('generated')]));

        $baseline = $this->capture($this->project($pipeline, [$excluded, $reported], new FindingProjectionOptions())->measuredFindings);

        self::assertSame(1, $baseline->count());
        self::assertFalse($baseline->hasIdentity(BaselineIdentity::forFinding($excluded)));
    }

    /**
     * Written on an ordinary channel on purpose: `architecture.*` is exempt
     * from `suppress_namespaces` and behaves the opposite way — see below.
     */
    #[Test]
    public function itWritesNoEntryForAnOrdinaryFindingRemovedByNamespaceExclusion(): void
    {
        $excluded = self::finding('src/Generated/Proxy.php', 'App\\Generated', 'Proxy');
        $reported = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');

        $pipeline = $this->createPipeline(new FindingProjectionOptions(suppressNamespaces: [self::namespace('App\\Generated')]));

        $baseline = $this->capture($this->project($pipeline, [$excluded, $reported], new FindingProjectionOptions())->measuredFindings);

        self::assertSame(1, $baseline->count());
        self::assertFalse($baseline->hasIdentity(BaselineIdentity::forFinding($excluded)));
    }

    /**
     * `suppress_namespaces` does not silence layer-policy enforcement, so an
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

        $pipeline = $this->createPipeline(new FindingProjectionOptions(suppressNamespaces: [self::namespace('App\\Generated')]));

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
                    position: 0,
                    silencedLine: 39 + 1,
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
        $result = $this->project($pipeline, [$kept, $ignoredMember], new FindingProjectionOptions((new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->preflight($baselinePath)));

        self::assertSame([], $result->findings);
        self::assertSame(0, $result->staleEntryCount());
    }

    /**
     * @param list<Finding> $measured
     */
    private function capture(array $measured): Baseline
    {
        $generator = new BaselineGenerator(StubChannelDeclarationRegistry::withDefaults(), new FixedClock());

        return $generator->generate($measured, ['src'], self::fixtureExclusions())->baseline;
    }

    private function writeBaseline(Baseline $baseline): string
    {
        $entries = [];

        foreach ($baseline->entries as $entry) {
            // Mirrors BaselineWriter: "count" is derived from
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
            'version' => BaselineFormatVersion::CURRENT,
            'generated' => (new DateTimeImmutable())->format('c'),
            'scope' => ['src'],
            'exclusions' => $baseline->exclusions->toArray(),
            'entries' => $entries,
        ], \JSON_THROW_ON_ERROR));

        return $path;
    }

    private function createPipeline(?FindingProjectionOptions $configuration = null): FindingProjector
    {
        $this->configuredOptions = $configuration ?? new FindingProjectionOptions();

        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('code-smell.goto', ChannelDeclaration::occurrence(SymbolLevel::File));

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
            unusedEntryAudit: new UnusedEntryAudit((function () {
                $execution = self::createStub(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
                $execution->method('publishable')->willReturnCallback(static fn(array $findings): array => $findings);

                return $execution;
            })()),
            fileScope: \Qualimetrix\Infrastructure\DependencyInjection\Configurator\DeclaredChannelFileScope::create(),
        );
    }

    /** @param list<Finding> $findings */
    private function project(FindingProjector $projector, array $findings, FindingProjectionOptions $options): \Qualimetrix\Reporting\FindingProjection\FindingProjectionResult
    {
        $projectionOptions = new FindingProjectionOptions(
            baselineDocument: $options->baselineDocument,
            suppressPaths: [...$this->configuredOptions->suppressPaths, ...$options->suppressPaths],
            suppressNamespaces: [...$this->configuredOptions->suppressNamespaces, ...$options->suppressNamespaces],
            annotationSuppressionDisabled: $options->annotationSuppressionDisabled,
            gitScope: $options->gitScope,
        );
        if ($options->baselineDocument !== null) {
            $declarations = StubChannelDeclarationRegistry::withDefaults();
            $declarations->declare('code-smell.goto', ChannelDeclaration::occurrence(SymbolLevel::File));
            $baseline = (new BaselineLoader(new BaselineEntryParser($declarations)))->load($options->baselineDocument);
            $files = array_values(array_map(
                static fn(Finding $finding): string => $finding->location->file?->value() ?? 'src/Foo.php',
                $findings,
            ));
            $projectionOptions = $projectionOptions->withRunCoverage(
                StubRuleCoverage::completeFor($baseline, $files),
                StubRuleCoverage::everyRuleRan(),
            );
        }

        return $projector->project($findings, $this->suppressions, $projectionOptions);
    }

    private static function path(string $value): PathPattern
    {
        return new PathPattern(new SelectorDefinition(SelectorKind::Subtree, $value));
    }

    private static function namespace(string $value): NamespacePattern
    {
        return new NamespacePattern(new SelectorDefinition(SelectorKind::Subtree, $value));
    }

    private static function finding(
        string $file,
        string $namespace,
        string $class,
        string $ruleName = 'complexity.ccn',
        string $code = 'complexity.ccn',
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

    private static function fixtureExclusions(): \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions
    {
        return new \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions(
            [],
            \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Exclude,
        );
    }
}
