<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineCleaner;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineEntryParser;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineUpdater;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Baseline\BoundaryExplanationService;
use Qualimetrix\Configuration\RuleOptionsFactory;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineConfiguredThresholds;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Rule\ChannelDeclarationRegistry;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Rules\ComputedMetric\ComputedMetricRule;
use Qualimetrix\Tests\Support\Console\StubBaselineRun;
use Qualimetrix\Tests\Support\Console\TempDirectory;
use Qualimetrix\Tests\Support\Time\FixedClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every command that reads a baseline file resolves the configuration first.
 *
 * The order is not housekeeping. §5.4 leaves one channel family — `computed.*`
 * and `health.*` — undeclarable at compile time, because a user defines those
 * metrics in `qmx.yaml` and both facts the ceiling needs (shape, direction)
 * exist only once configuration has resolved the definition. The declaration
 * registry answers such a lookup from
 * {@see ComputedMetricDefinitionHolder}, which the run populates.
 *
 * So a file read *before* the run is read against an empty vocabulary:
 * {@see \Qualimetrix\Baseline\BaselineEntryParser} finds no declaration for
 * the channel, and every entry on a computed metric loads inert. Nothing
 * fails; the entry simply stops meaning anything — while the `check` that
 * applies the very same file, on the very same project, applies it normally.
 * Each of the three commands then gives an answer that contradicts `check`:
 * `cleanup` offers the entry for removal, `update` declines to tighten it,
 * `explain` denies it exists.
 *
 * The run is stubbed, but the seam under test is not: the registry, the
 * loader and the parser are the production ones, and the stub does the one
 * thing a real run does that matters here — it fills the holder on its way to
 * an answer.
 */
#[CoversClass(BaselineCleanupCommand::class)]
#[CoversClass(BaselineUpdateCommand::class)]
#[CoversClass(BaselineExplainCommand::class)]
final class BaselineRunBeforeLoadTest extends TestCase
{
    private const string CHANNEL = 'computed.health#computed.debtRatio';
    private const string METRIC = 'computed.debtRatio';
    private const string SOURCE_FILE = 'src/OrderService.php';

    private string $tempDir;
    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-order-');
        $this->baselinePath = $this->tempDir . '/baseline.json';

        ComputedMetricDefinitionHolder::reset();
    }

    protected function tearDown(): void
    {
        ComputedMetricDefinitionHolder::reset();
        TempDirectory::remove($this->tempDir);
    }

    /**
     * `cleanup` must not offer an entry it simply failed to understand.
     *
     * The run reports the very finding the entry bounds, so the entry is
     * neither stale nor unreadable — unless the file was read before the
     * definition existed, in which case it is listed as one that "cannot be
     * applied" and a user is invited to delete a live acceptance.
     */
    #[Test]
    public function itLeavesAConfiguredComputedEntryAloneInCleanup(): void
    {
        $this->writeBaseline();

        $tester = $this->executeCleanup();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('No entry is a removal candidate', $tester->getDisplay());
        self::assertStringNotContainsString('cannot be applied', $tester->getDisplay());
    }

    /**
     * `update` must see the entry as an entry: an inert one is not in
     * {@see Baseline::$entries} at all, so the improvement below would be
     * silently declined.
     */
    #[Test]
    public function itTightensAConfiguredComputedEntryInUpdate(): void
    {
        $this->writeBaseline();

        $tester = $this->executeUpdate();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('updated', $tester->getDisplay());
        self::assertSame([12.0], $this->storedMagnitudes());
    }

    /**
     * `explain` must report the accepted level rather than "(none)" — the
     * answer it gives when the file holds nothing for the symbol, which is
     * indistinguishable from the answer it gives when the file holds
     * something it could not read.
     */
    #[Test]
    public function itReportsAConfiguredComputedEntryInExplain(): void
    {
        $this->writeBaseline();

        $tester = $this->executeExplain();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('accepted 25; now 12', $tester->getDisplay());
    }

    /**
     * The control: the same three commands on a channel nothing configures
     * still treat the entry as unreadable. Without this, "resolve first"
     * would be indistinguishable from "declare everything".
     *
     * @param 'cleanup'|'update'|'explain' $command
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function itStillRefusesAnEntryOnAChannelNothingDeclares(string $command): void
    {
        $this->writeBaseline();

        $tester = match ($command) {
            'cleanup' => $this->executeCleanup(configured: false),
            'update' => $this->executeUpdate(configured: false),
            'explain' => $this->executeExplain(configured: false),
        };

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        if ($command === 'explain') {
            self::assertStringContainsString('baseline:      (none)', $tester->getDisplay());

            return;
        }

        self::assertSame([25.0], $this->storedMagnitudes(), 'an entry nothing declares is written back untouched');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCommands(): iterable
    {
        yield 'cleanup' => ['cleanup'];
        yield 'update' => ['update'];
        yield 'explain' => ['explain'];
    }

    private function executeCleanup(bool $configured = true): CommandTester
    {
        $declarations = self::declarations();

        $command = new BaselineCleanupCommand(
            $this->measuredRun($configured),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            new BaselineCleaner(new FixedClock('2026-09-01T00:00:00+00:00')),
            new BaselineWriter(),
            $declarations,
        );

        return self::tester($command, ['baseline' => $this->baselinePath, 'paths' => ['src']]);
    }

    private function executeUpdate(bool $configured = true): CommandTester
    {
        $declarations = self::declarations();

        $command = new BaselineUpdateCommand(
            $this->measuredRun($configured),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            new BaselineUpdater($declarations, new FixedClock('2026-09-01T00:00:00+00:00')),
            new BaselineWriter(),
        );

        return self::tester($command, ['baseline' => $this->baselinePath, 'paths' => ['src']]);
    }

    private function executeExplain(bool $configured = true): CommandTester
    {
        $declarations = self::declarations();

        $command = new BaselineExplainCommand(
            $this->measuredRun($configured),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            new BoundaryExplanationService(),
            new BaselineConfiguredThresholds(self::emptyRuleRegistry(), new RuleOptionsFactory(new RuleOptionsRegistry())),
        );

        return self::tester($command, [
            'symbol' => self::symbol()->toCanonical(),
            'paths' => ['src'],
            '--baseline' => $this->baselinePath,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function tester(BaselineCommand $command, array $input): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /**
     * A run that resolves a `computed.*` definition on its way to an answer,
     * exactly as {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator}
     * does for a real one.
     */
    private function measuredRun(bool $configured): StubBaselineRun
    {
        return new StubBaselineRun(
            [self::finding()],
            ['src'],
            AbsolutePath::fromString($this->tempDir),
            onMeasure: static function () use ($configured): void {
                ComputedMetricDefinitionHolder::setDefinitions($configured ? [self::definition()] : []);
            },
        );
    }

    private static function declarations(): ChannelDeclarationRegistryInterface
    {
        return new ChannelDeclarationRegistry([], ComputedMetricRule::NAME);
    }

    private static function definition(): ComputedMetricDefinition
    {
        return new ComputedMetricDefinition(
            name: self::METRIC,
            formulas: ['class' => '1'],
            description: 'debt ratio',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 10.0,
        );
    }

    private function writeBaseline(): void
    {
        (new BaselineWriter())->write(
            new Baseline(
                generated: (new FixedClock())->now(),
                scope: ['src'],
                entries: [new BaselineEntry(
                    new BaselineIdentity(self::symbol()->toCanonical(), ViolationChannel::fromKey(self::CHANNEL)),
                    [25.0],
                    1,
                )],
            ),
            $this->baselinePath,
            AbsolutePath::fromString($this->tempDir),
        );
    }

    private static function symbol(): SymbolPath
    {
        return SymbolPath::forClass('App', 'OrderService');
    }

    private static function finding(): Violation
    {
        $channel = ViolationChannel::fromKey(self::CHANNEL);

        return new Violation(
            location: new Location(RelativePath::fromString(self::SOURCE_FILE), 1),
            symbolPath: self::symbol(),
            ruleName: $channel->ruleName,
            violationCode: $channel->violationCode,
            message: 'finding',
            severity: Severity::Warning,
            metricValue: 12.0,
        );
    }

    /**
     * @return list<float>
     */
    private function storedMagnitudes(): array
    {
        /** @var array{entries: array<string, list<array{magnitudes?: list<int|float>}>>} $data */
        $data = json_decode(
            (string) file_get_contents($this->baselinePath),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        $magnitudes = [];
        foreach ($data['entries'] as $forSymbol) {
            foreach ($forSymbol as $entry) {
                foreach ($entry['magnitudes'] ?? [] as $magnitude) {
                    $magnitudes[] = (float) $magnitude;
                }
            }
        }

        return $magnitudes;
    }

    private static function emptyRuleRegistry(): RuleRegistryInterface
    {
        return new class implements RuleRegistryInterface {
            public function getClasses(): array
            {
                return [];
            }

            public function getAllCliAliases(): array
            {
                return [];
            }
        };
    }
}
