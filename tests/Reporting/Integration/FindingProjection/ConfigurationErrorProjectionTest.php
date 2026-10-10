<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Integration\FindingProjection;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryRule;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Path\AbsolutePath;
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
use Qualimetrix\Infrastructure\Git\ReportingGitScopeQuery;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubRuleCoverage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The half of "a configuration error is never suppressed, never baselined and
 * always gates" that lives in the projection: **no stage may remove one**.
 *
 * The promise used to hold against `fail_on` alone. Every filtering stage
 * except the baseline's would happily drop such a finding, and dropping it
 * took the non-zero exit code with it — a run that could not do what the
 * configuration asked reported success, with nothing in the output to say
 * why. So the guard is asserted per stage rather than once: one case for each
 * way a finding can leave the pipeline, which is also what makes a stage
 * added later visible as a gap.
 *
 * The fixture channel is declared a configuration error here rather than
 * borrowed from a real rule, so a failure is about the pipeline and not about
 * which rules happened to be registered.
 */
#[CoversClass(FindingProjector::class)]
final class ConfigurationErrorProjectionTest extends TestCase
{
    private const string CONFIG_ERROR_CHANNEL = 'annotation.unresolved-directive';

    private const string FILE = 'src/Service/UserService.php';

    private const string NAMESPACE = 'App\\Service';

    private ChannelPublication $publication;

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempDirs = [];

    /** @var array<string, list<Suppression>> */
    private array $suppressions = [];

    protected function setUp(): void
    {
        $configuration = ResolvedOptionsFixture::ready(FindingConfiguration::none(), [
            new RuleMetadata(UnusedEntryRule::NAME, UnusedEntryRule::getOptionsClass(), UnusedEntryRule::getDescription(), [], false),
        ]);
        $this->publication = new ChannelPublication($configuration->enablement ?? throw new LogicException('Fixture requires resolved publication.'));
        $this->suppressions = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        foreach ($this->tempDirs as $dir) {
            self::removeDirectory($dir);
        }
    }

    /**
     * The directive that says "silence everything in this file" is the
     * bluntest suppression there is, and it must not reach this finding.
     */
    #[Test]
    public function itSurvivesAnInlineSuppressionCoveringEveryChannelOfTheFile(): void
    {
        $configurationError = $this->makeConfigurationError();
        $ordinary = $this->makeOrdinaryFinding();

        $this->suppressions = [
            self::FILE => [
                new Suppression(rule: '*', reason: 'Generated', line: 1, type: SuppressionType::File, position: 0),
            ],
        ];

        $result = $this->project([$ordinary, $configurationError], new FindingProjectionOptions());

        self::assertSame([$configurationError], $result->findings);
        self::assertSame([$ordinary], $result->removedBy(FindingFilterStage::Suppression));
    }

    /**
     * A directive naming the configuration-error channel itself is the
     * pointed version of the same attempt.
     */
    #[Test]
    public function itSurvivesAnInlineSuppressionNamingTheChannelItself(): void
    {
        $configurationError = $this->makeConfigurationError();

        $this->suppressions = [
            self::FILE => [
                new Suppression(
                    rule: self::CONFIG_ERROR_CHANNEL,
                    reason: 'Silencing the diagnostic',
                    line: 1,
                    type: SuppressionType::File,
                    position: 0,
                ),
            ],
        ];

        $result = $this->project([$configurationError], new FindingProjectionOptions());

        self::assertSame([$configurationError], $result->findings);
        self::assertSame([], $result->removedBy(FindingFilterStage::Suppression));
    }

    #[Test]
    public function itSurvivesPathExclusion(): void
    {
        $configurationError = $this->makeConfigurationError();
        $ordinary = $this->makeOrdinaryFinding();

        $result = $this->project(
            [$ordinary, $configurationError],
            new FindingProjectionOptions(suppressPaths: [
                new PathPattern(SelectorDefinition::fromKindAndValue(SelectorKind::Subtree->value, 'src')),
            ]),
        );

        self::assertSame([$configurationError], $result->findings);
        self::assertSame([$ordinary], $result->removedBy(FindingFilterStage::PathExclusion));
    }

    #[Test]
    public function itSurvivesNamespaceExclusion(): void
    {
        $configurationError = $this->makeConfigurationError();
        $ordinary = $this->makeOrdinaryFinding();

        $result = $this->project(
            [$ordinary, $configurationError],
            new FindingProjectionOptions(suppressNamespaces: [
                new NamespacePattern(SelectorDefinition::fromKindAndValue(SelectorKind::Subtree->value, self::NAMESPACE)),
            ]),
        );

        self::assertSame([$configurationError], $result->findings);
        self::assertSame([$ordinary], $result->removedBy(FindingFilterStage::NamespaceExclusion));
    }

    /**
     * The baseline stage refuses these findings on its own; the assertion is
     * kept so the stage is covered by the same per-stage sweep as the rest.
     */
    #[Test]
    public function itSurvivesABaselineEntryClaimingToAcceptIt(): void
    {
        $configurationError = $this->makeConfigurationError();

        $result = $this->project([$configurationError], new FindingProjectionOptions(
            baselineDocument: (new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->preflight($this->writeBaselineFile([
                $configurationError->subject->toCanonical() => [
                    ['channel' => $configurationError->channel()->code, 'count' => 1],
                ],
            ])),
        ));

        self::assertSame($configurationError, $result->findings[1]);
        self::assertCount(2, $result->findings);
        $audit = $result->findings[0];
        self::assertSame('baseline.unused-entry', $audit->channel()->code);
        self::assertSame(\Qualimetrix\Analysis\Finding\Contract\Severity::Warning, $audit->severity);
        $entry = $result->inertEntries[0];
        self::assertSame(\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey::semantic('baseline-unused-entry', ['cause' => 'inert', 'selector' => $entry->selector->value])->value, $audit->occurrenceKey?->value);
        self::assertStringContainsString($entry->describe(), $audit->message);
        self::assertStringContainsString($entry->reason->description(), $audit->message);
        self::assertNotContains($audit, $result->measuredFindings);
        self::assertSame([], $result->removedBy(FindingFilterStage::Baseline));
    }

    /**
     * Narrowing the report to a git range is the one stage that is purely
     * about presentation, which is exactly why it must not decide whether the
     * run failed: a diff-scoped CI job would otherwise pass over a broken
     * configuration the same job fails on when run whole.
     */
    #[Test]
    public function itSurvivesAGitScopeThatContainsNothing(): void
    {
        $configurationError = $this->makeConfigurationError();
        $ordinary = $this->makeOrdinaryFinding();

        $result = $this->project(
            [$ordinary, $configurationError],
            new FindingProjectionOptions(gitScope: $this->createEmptyGitScope()),
        );

        self::assertSame([$configurationError], $result->findings);
        self::assertSame([$ordinary], $result->removedBy(FindingFilterStage::GitScope));
    }

    /**
     * Not in the measured set either: no entry can ever bound one, so
     * capturing it would only ever write an entry the next run reports as
     * inert.
     */
    #[Test]
    public function itIsNotPartOfTheSetABaselineMeasures(): void
    {
        $configurationError = $this->makeConfigurationError();
        $ordinary = $this->makeOrdinaryFinding();

        $result = $this->project([$ordinary, $configurationError], new FindingProjectionOptions());

        self::assertSame([$ordinary], $result->measuredFindings);
    }

    private function makeConfigurationError(): Finding
    {
        return $this->makeFinding(self::CONFIG_ERROR_CHANNEL, self::CONFIG_ERROR_CHANNEL);
    }

    private function makeOrdinaryFinding(): Finding
    {
        return $this->makeFinding('code-smell.goto', 'code-smell.goto');
    }

    private function makeFinding(string $ruleName, string $code): Finding
    {
        $path = RelativePath::fromString(self::FILE);
        $symbol = SymbolPath::forClass(self::NAMESPACE, 'UserService');

        return new Finding(
            location: new Location($path, 10),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, $path, DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: $ruleName,
            code: $code,
            message: 'Something is wrong',
            severity: Severity::Error,
        );
    }

    /** @param list<Finding> $findings */
    private function project(array $findings, FindingProjectionOptions $options): FindingProjectionResult
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare(
            self::CONFIG_ERROR_CHANNEL,
            ChannelDeclaration::occurrence(SymbolLevel::Class_)->asConfigurationError(),
        );

        $projector = new FindingProjector(
            new SuppressionFilter(),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            $declarations,
            new ReportingGitScopeQuery(),
            unusedEntryAudit: new UnusedEntryAudit((function () {
                $execution = self::createStub(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
                $execution->method('publishable')->willReturnCallback(static fn(array $findings): array => $findings);

                return $execution;
            })()),
            fileScope: \Qualimetrix\Infrastructure\DependencyInjection\Configurator\DeclaredChannelFileScope::create(),
        );

        if ($options->baselineDocument !== null) {
            $baseline = (new BaselineLoader(new BaselineEntryParser($declarations)))->load($options->baselineDocument);
            $files = array_values(array_map(
                static fn(Finding $finding): string => $finding->location->file?->value() ?? 'src/Foo.php',
                $findings,
            ));
            $options = $options->withRunCoverage(
                StubRuleCoverage::completeFor($baseline, $files),
                StubRuleCoverage::everyRuleRan(),
            );
        }

        return $projector->project($findings, $this->suppressions, $options, $this->publication);
    }

    /** @param array<string, list<array<string, mixed>>> $entries */
    private function writeBaselineFile(array $entries): string
    {
        $tmpBase = (string) tempnam(sys_get_temp_dir(), 'qmx_baseline_');
        $path = $tmpBase . '.json';
        $this->tempFiles[] = $tmpBase;
        $this->tempFiles[] = $path;

        file_put_contents($path, json_encode([
            'version' => 14,
            'generated' => (new DateTimeImmutable())->format('c'),
            'scope' => ['src'],
            'exclusions' => ['patterns' => [], 'generated' => 'excluded'],
            'entries' => $entries,
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));

        return $path;
    }

    private function createEmptyGitScope(): GitScopeRequest
    {
        $dir = sys_get_temp_dir() . '/qmx-config-error-git-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $realPath = realpath($dir);
        if ($realPath === false) {
            throw new RuntimeException('Failed to resolve path: ' . $dir);
        }

        $this->tempDirs[] = $realPath;

        foreach (['git init', 'git config user.email "test@example.com"', 'git config user.name "Test User"'] as $command) {
            $process = Process::fromShellCommandline($command, $realPath);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException(\sprintf('Command failed: %s', $process->getErrorOutput()));
            }
        }

        return new GitScopeRequest(
            reference: 'staged',
            projectRoot: AbsolutePath::fromString($realPath),
            includeParentNamespaces: true,
        );
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
