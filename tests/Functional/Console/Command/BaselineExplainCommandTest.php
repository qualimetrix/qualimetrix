<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineEntryParser;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Baseline\BoundaryExplanationService;
use Qualimetrix\Configuration\RuleOptionsFactory;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Infrastructure\Console\Command\BaselineConfiguredThresholds;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Tests\Support\Console\StubBaselineRun;
use Qualimetrix\Tests\Support\Console\TempDirectory;
use Qualimetrix\Tests\Support\Time\FixedClock;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;
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
    private const string CCN_CHANNEL = 'complexity.cyclomatic#complexity.cyclomatic.method';
    private const string CBO_CHANNEL = 'coupling.cbo#coupling.cbo.class';
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
            [self::SYMBOL_FILE => [new ThresholdOverride('complexity.cyclomatic', 40, 60, 1, 99)]],
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

        $withZero = $this->execute($findings, [], [], ['complexity.cyclomatic' => ['method' => ['warning' => 0, 'error' => 5]]]);
        $withNothing = $this->execute($findings, [], [], [], registerRules: false);

        self::assertStringContainsString('qmx.yaml:      0', $withZero->getDisplay());
        self::assertStringContainsString('qmx.yaml:      (not resolvable from configuration)', $withNothing->getDisplay());
        self::assertStringContainsString('baseline:      (none)', $withNothing->getDisplay());
        self::assertStringContainsString('annotation:    (none)', $withNothing->getDisplay());
    }

    /**
     * §13.5: `coupling.cbo` changes meaning with its `scope` option, so a
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

    #[Test]
    public function itRejectsAChannelThatIsNotAChannelKey(): void
    {
        $tester = $this->execute([], ['--channel' => 'complexity.cyclomatic']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('is not a valid channel key', $tester->getDisplay());
    }

    /**
     * @param list<Violation> $measured
     * @param array<string, mixed> $options
     * @param array<string, list<ThresholdOverride>> $overrides
     * @param array<string, mixed> $ruleOptions
     */
    private function execute(
        array $measured,
        array $options = [],
        array $overrides = [],
        array $ruleOptions = [],
        bool $registerRules = true,
    ): CommandTester {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare(self::CBO_CHANNEL, ChannelDeclaration::magnitude(WorseDirection::Higher));

        $registry = new RuleOptionsRegistry();
        $registry->setConfigFileOptions($ruleOptions);

        $command = new BaselineExplainCommand(
            new StubBaselineRun($measured, ['src'], AbsolutePath::fromString($this->tempDir), $overrides),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            new BoundaryExplanationService(),
            new BaselineConfiguredThresholds(
                self::ruleRegistry($registerRules ? [ComplexityRule::class] : []),
                new RuleOptionsFactory($registry),
            ),
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'symbol' => SymbolPath::forMethod('App', 'OrderService', 'calculate')->toCanonical(),
            'paths' => ['src'],
            ...$options,
        ]);

        return $tester;
    }

    /**
     * The same run, asked about a class-level symbol.
     *
     * @param list<Violation> $measured
     * @param array<string, mixed> $options
     */
    private function executeForClass(array $measured, array $options): CommandTester
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare(self::CBO_CHANNEL, ChannelDeclaration::magnitude(WorseDirection::Higher));

        $command = new BaselineExplainCommand(
            new StubBaselineRun($measured, ['src'], AbsolutePath::fromString($this->tempDir)),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            new BoundaryExplanationService(),
            new BaselineConfiguredThresholds(self::ruleRegistry([]), new RuleOptionsFactory(new RuleOptionsRegistry())),
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'symbol' => SymbolPath::forClass('App', 'OrderService')->toCanonical(),
            'paths' => ['src'],
            ...$options,
        ]);

        return $tester;
    }

    /**
     * @param list<class-string<RuleInterface>> $classes
     */
    private static function ruleRegistry(array $classes): RuleRegistryInterface
    {
        return new class ($classes) implements RuleRegistryInterface {
            /**
             * @param list<class-string<RuleInterface>> $classes
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
        return new BaselineIdentity($symbol->toCanonical(), ViolationChannel::fromKey($channelKey));
    }

    private static function finding(SymbolPath $symbol, string $channelKey, float $magnitude): Violation
    {
        $channel = ViolationChannel::fromKey($channelKey);

        return new Violation(
            location: new Location(RelativePath::fromString(self::SYMBOL_FILE), 12),
            symbolPath: $symbol,
            ruleName: $channel->ruleName,
            violationCode: $channel->violationCode,
            message: 'finding',
            severity: Severity::Warning,
            metricValue: $magnitude,
        );
    }
}
