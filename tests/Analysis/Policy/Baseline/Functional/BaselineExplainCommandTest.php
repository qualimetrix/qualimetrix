<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListRule;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationService;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\Command\BaselineConfiguredThresholds;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubBaselineRun;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `baseline:explain` answers "which boundary is actually in force, and who
 * set it" — a question three sources answer at once.
 */
#[CoversClass(BaselineExplainCommand::class)]
#[CoversClass(BaselineConfiguredThresholds::class)]
final class BaselineExplainCommandTest extends TestCase
{
    private const string CCN_CHANNEL = 'complexity.ccn';
    private const string CBO_CHANNEL = 'coupling.cbo';
    private const string LONG_PARAMETER_LIST_CHANNEL = 'code-smell.long-parameter-list';
    private const string SYMBOL_FILE = 'src/OrderService.php';

    private string $tempDir;
    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-explain-');
        $this->baselinePath = $this->tempDir . '/baseline.json';
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    /**
     * All three sources on one line each: what the baseline accepted, what
     * `qmx.yaml` configures, and the annotation that moved the boundary for
     * this symbol alone. Any two of them agreeing is not the point — a user
     * looking at a finding they thought was suppressed needs to see which one
     * is deciding.
     */
    #[Test]
    public function itPrintsAllThreeSources(): void
    {
        $symbol = SymbolPath::forMethod('App', 'OrderService', 'calculate');

        $this->writeBaseline([
            new BaselineEntry(self::identity($symbol, self::CCN_CHANNEL), [25.0], 1),
        ]);

        $tester = $this->execute(
            [self::finding($symbol, self::CCN_CHANNEL, 31.0)],
            ['--baseline' => $this->baselinePath],
            [self::SYMBOL_FILE => [new ThresholdOverride(
                'complexity.ccn',
                40,
                60,
                1,
                self::subject($symbol),
                ControlScope::Callable,
                99,
            )]],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertStringContainsString('accepted 25; now 31', $display);
        self::assertStringContainsString('qmx.yaml:      10', $display);
        self::assertStringContainsString('warning=40 error=60', $display);
    }

    /**
     * A configured 0 and no configured threshold at all lead to opposite
     * conclusions, so they must not print the same way.
     */
    #[Test]
    public function itSpellsAnAbsentSourceDifferentlyFromAZero(): void
    {
        $symbol = SymbolPath::forMethod('App', 'OrderService', 'calculate');
        $findings = [self::finding($symbol, self::CCN_CHANNEL, 31.0)];

        $withZero = $this->execute($findings, [], [], ['complexity.ccn' => ['callable' => ['warning' => 0, 'error' => 5]]]);
        $withNothing = $this->execute($findings, [], [], [], registerRules: false);

        self::assertStringContainsString('qmx.yaml:      0', $withZero->getDisplay());
        self::assertStringContainsString('qmx.yaml:      (not resolvable from configuration)', $withNothing->getDisplay());
        self::assertStringContainsString('baseline:      (none)', $withNothing->getDisplay());
        self::assertStringContainsString('annotation:    (none)', $withNothing->getDisplay());
    }

    /**
     * ADR 0017: `coupling.cbo` changes meaning with its `scope` option, so a
     * stored magnitude can end up bounding a differently-meaning quantity.
     * Nothing detects that without storing the configuration that produced
     * the number — so both numbers are printed where a user would look.
     */
    #[Test]
    public function itPrintsTheStoredAndTheCurrentNumberOnADriftingChannel(): void
    {
        $symbol = SymbolPath::forClass('App', 'OrderService');

        $this->writeBaseline([
            new BaselineEntry(self::identity($symbol, self::CBO_CHANNEL), [12.0], 1),
        ]);

        $tester = $this->executeForClass(
            [self::finding($symbol, self::CBO_CHANNEL, 19.0)],
            ['--baseline' => $this->baselinePath, '--channel' => self::CBO_CHANNEL],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('accepted 12; now 19', $tester->getDisplay());
    }

    /**
     * One channel judged against two thresholds prints neither.
     *
     * `LongParameterListRule` emits `code-smell.long-parameter-list` for an
     * ordinary method against `warning: 4` and for a readonly value object's
     * constructor against `voWarning: 8`, and the channel carries nothing
     * that says which. Resolving it by property-name convention picks the
     * generic `warning`, so a nine-parameter VO constructor — measured
     * against 8, and therefore only just over — is explained as "qmx.yaml
     * says 4". A number a user acts on is worse wrong than missing, so the
     * ambiguity is reported as one, in the spelling already reserved for a
     * boundary that cannot be read from configuration and already distinct
     * from a configured `0`.
     */
    #[Test]
    public function itRefusesToPickOneOfTwoThresholdsAChannelIsJudgedAgainst(): void
    {
        $symbol = SymbolPath::forMethod('App', 'OrderService', '__construct');

        $tester = $this->execute(
            [self::finding($symbol, self::LONG_PARAMETER_LIST_CHANNEL, 9.0)],
            [],
            [],
            [],
            ruleClasses: [LongParameterListRule::class],
            symbol: $symbol,
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertStringContainsString('qmx.yaml:      (not resolvable from configuration)', $display);
        self::assertStringNotContainsString('qmx.yaml:      4', $display);
        self::assertStringNotContainsString('qmx.yaml:      8', $display);
        self::assertStringNotContainsString('qmx.yaml:      0', $display);
    }

    /**
     * The counterpart, so the rule above cannot be satisfied by giving up on
     * every channel: one warning boundary, one answer, printed.
     */
    #[Test]
    public function itStillResolvesAChannelWhoseOptionsHoldOneBoundary(): void
    {
        $symbol = SymbolPath::forMethod('App', 'OrderService', 'calculate');

        $tester = $this->execute([self::finding($symbol, self::CCN_CHANNEL, 31.0)]);

        self::assertStringContainsString('qmx.yaml:      10', $tester->getDisplay());
    }

    #[Test]
    public function itRejectsAChannelWrittenInTheRetiredPairForm(): void
    {
        $tester = $this->execute([], ['--channel' => 'complexity.ccn#complexity.ccn']);

        self::assertSame(3, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Write "complexity.ccn"', $tester->getErrorOutput());
    }

    #[Test]
    public function itRejectsAnUnknownChannel(): void
    {
        $tester = $this->execute([], ['--channel' => 'no.such.channel']);

        self::assertSame(3, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('declared by no rule', $tester->getErrorOutput());
        self::assertStringContainsString('no.such.channel', $tester->getErrorOutput());
    }

    #[Test]
    public function itAcceptsAChannelTheRegistryNoLongerDeclaresWhenTheLoadedBaselineStillCarriesIt(): void
    {
        $symbol = SymbolPath::forMethod('App', 'OrderService', 'calculate');
        $this->writeBaseline([
            new BaselineEntry(self::identity($symbol, 'renamed.channel'), [25.0], 1),
        ]);

        // 'renamed.channel' is declared by no rule this test registers, so
        // the entry loads inert (`InertEntryReason::UndeclaredChannel`) and
        // addressability comes from the loaded baseline alone
        // (`01-refusal-verdicts.md` §5.3): a channel a rename map has
        // already carried a baseline onto is a legitimate `--channel` before
        // the release declaring it lands, even though an inert entry has no
        // accepted magnitude of its own to print.
        $tester = $this->execute(
            [],
            ['--baseline' => $this->baselinePath, '--channel' => 'renamed.channel'],
            symbol: $symbol,
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Channel: renamed.channel', $tester->getDisplay());
        self::assertStringContainsString('baseline:      (none)', $tester->getDisplay());
    }

    #[Test]
    public function itRejectsAnUnknownSubjectEvenWhenAChannelWasExplicitlyRequested(): void
    {
        $tester = $this->execute(
            [],
            ['--channel' => self::CCN_CHANNEL],
            symbol: SymbolPath::forMethod('App', 'Missing', 'method'),
        );

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Unknown subject', $tester->getErrorOutput());
    }

    #[Test]
    public function itExplainsThatABaselineOnlySubjectIsAbsentFromTheCurrentRun(): void
    {
        $symbol = SymbolPath::forMethod('App', 'OrderService', 'calculate');
        $this->writeBaseline([
            new BaselineEntry(self::identity($symbol, self::CCN_CHANNEL), [25.0], 1),
        ]);

        $tester = $this->execute(
            [],
            ['--baseline' => $this->baselinePath, '--channel' => self::CCN_CHANNEL],
            symbol: $symbol,
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Baseline only', $tester->getDisplay());
        self::assertStringContainsString('absent from the current analysis scope or result', $tester->getDisplay());
        self::assertStringContainsString('accepted 25; now nothing reported', $tester->getDisplay());
    }

    /**
     * The case ADR 0017 gives as the reason `$symbolLocations` exists: an
     * `@qmx-threshold` that raised the limit is normally *why* the rule no
     * longer fires, so the symbol most worth explaining has no finding to
     * read a declaration site off. Without the run's metric repository,
     * `locationForSymbol()` has nothing to search and the annotation prints
     * as "(none)" even though one covers the symbol.
     */
    #[Test]
    public function itPrintsTheAnnotationForASymbolThatViolatesNothing(): void
    {
        $symbol = SymbolPath::forMethod('App', 'OrderService', 'calculate');

        $metrics = new InMemoryMetricRepository();
        $metrics->addCallable(new CallableWithMetrics(DeclarationPath::of($symbol, RelativePath::fromString(self::SYMBOL_FILE), DeclarationOrdinal::fromRank(0)), 12, CallableKind::Method, null, null, new LogicalClassPath(SymbolPath::forClass('App', 'OrderService')), new MetricBag(), 12));

        $tester = $this->execute(
            measured: [],
            options: ['--channel' => self::CCN_CHANNEL],
            overrides: [self::SYMBOL_FILE => [new ThresholdOverride(
                'complexity.ccn',
                40,
                60,
                1,
                self::subject($symbol),
                ControlScope::Callable,
                99,
            )]],
            symbol: $symbol,
            metrics: $metrics,
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('warning=40 error=60', $tester->getDisplay());
    }

    /**
     * @param list<Finding> $measured
     * @param array<string, mixed> $options
     * @param array<string, list<ThresholdOverride>> $overrides
     * @param array<string, mixed> $ruleOptions
     * @param ?list<class-string<RuleDefinitionInterface>> $ruleClasses overrides `$registerRules` when a test
     *                                                                  needs one specific rule's options
     */
    private function execute(
        array $measured,
        array $options = [],
        array $overrides = [],
        array $ruleOptions = [],
        bool $registerRules = true,
        ?array $ruleClasses = null,
        ?SymbolPath $symbol = null,
        ?MetricRepositoryInterface $metrics = null,
    ): CommandTester {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare(self::CBO_CHANNEL, ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_));
        $declarations->declare(self::LONG_PARAMETER_LIST_CHANNEL, ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Callable));

        $registry = new RuleOptionsRegistry();
        $registry->setConfigFileOptions($ruleOptions);

        $command = new BaselineExplainCommand(
            new StubBaselineRun(
                $measured,
                ['src'],
                AbsolutePath::fromString($this->tempDir),
                $overrides,
                metrics: $metrics,
            ),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            new BoundaryExplanationService(self::producerEdge()),
            new BaselineConfiguredThresholds(
                self::ruleRegistry($ruleClasses ?? ($registerRules ? [ComplexityRule::class] : [])),
                new RuleOptionsFactory($registry),
            ),
            $declarations,
        );
        $command->setRefusalPresenter(self::refusalPresenter());

        $tester = new CommandTester($command);
        $tester->execute(
            [
                'subject' => self::subject($symbol ?? SymbolPath::forMethod('App', 'OrderService', 'calculate'))->toCanonical(),
                'paths' => ['src'],
                ...$options,
            ],
            ['capture_stderr_separately' => true],
        );

        return $tester;
    }

    /**
     * The same run, asked about a class-level symbol.
     *
     * @param list<Finding> $measured
     * @param array<string, mixed> $options
     */
    private function executeForClass(array $measured, array $options): CommandTester
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare(self::CBO_CHANNEL, ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_));

        $command = new BaselineExplainCommand(
            new StubBaselineRun($measured, ['src'], AbsolutePath::fromString($this->tempDir)),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            new BoundaryExplanationService(self::producerEdge()),
            new BaselineConfiguredThresholds(self::ruleRegistry([]), new RuleOptionsFactory(new RuleOptionsRegistry())),
            $declarations,
        );
        $command->setRefusalPresenter(self::refusalPresenter());

        $tester = new CommandTester($command);
        $tester->execute(
            [
                'subject' => self::subject(SymbolPath::forClass('App', 'OrderService'))->toCanonical(),
                'paths' => ['src'],
                ...$options,
            ],
            ['capture_stderr_separately' => true],
        );

        return $tester;
    }

    /**
     * @param list<class-string<RuleDefinitionInterface>> $classes
     */
    private static function ruleRegistry(array $classes): RuleRegistryInterface
    {
        return new class ($classes) implements RuleRegistryInterface {
            /**
             * @param list<class-string<RuleDefinitionInterface>> $classes
             */
            public function __construct(private readonly array $classes) {}

            public function getClasses(): array
            {
                return $this->classes;
            }

            public function getAllCliAliases(): array
            {
                return [];
            }
        };
    }

    private static function refusalPresenter(): RefusalPresenter
    {
        return new RefusalPresenter(new ErrorStream());
    }

    /**
     * @param list<BaselineEntry> $entries
     */
    private function writeBaseline(array $entries): void
    {
        (new BaselineWriter())->write(
            new Baseline(generated: (new FixedClock())->now(), scope: ['src'], entries: $entries),
            $this->baselinePath,
            AbsolutePath::fromString($this->tempDir),
        );
    }

    private static function identity(SymbolPath $symbol, string $channelKey): BaselineIdentity
    {
        return new BaselineIdentity(self::subject($symbol)->toCanonical(), new FindingChannel($channelKey));
    }

    private static function finding(SymbolPath $symbol, string $channelKey, float $magnitude): Finding
    {
        $channel = new FindingChannel($channelKey);

        return new Finding(
            location: new Location(RelativePath::fromString(self::SYMBOL_FILE), 12),
            subject: self::subject($symbol),
            symbolPath: $symbol,
            ruleName: $channel->code,
            code: $channel->code,
            message: 'finding',
            severity: Severity::Warning,
            metricValue: $magnitude,
        );
    }

    private static function subject(SymbolPath $symbol): MetricSubject
    {
        return MetricSubject::declaration(
            DeclarationPath::of($symbol, RelativePath::fromString(self::SYMBOL_FILE), DeclarationOrdinal::fromRank(0)),
        );
    }

    /**
     * A channel-to-producer edge for the fixtures below: the channel's own name,
     * minus a trailing level segment where it carries one. That is exactly the
     * relation the retired left half of a channel key encoded, so a fixture
     * naming `complexity.ccn` still resolves to the rule a
     * `@qmx-threshold complexity.ccn` addresses.
     */
    private static function producerEdge(): ChannelIdentityInterface
    {
        $identity = self::createStub(ChannelIdentityInterface::class);
        $identity->method('producerOf')->willReturnCallback(static function (string $code): string {
            $lastDot = strrpos($code, '.');

            return $lastDot !== false && SymbolLevel::tryFrom(substr($code, $lastDot + 1)) !== null
                ? substr($code, 0, $lastDot)
                : $code;
        });

        return $identity;
    }
}
