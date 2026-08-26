<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleaner;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationService;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineConfiguredThresholds;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubBaselineRun;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every command that reads a baseline file resolves the configuration first.
 *
 * The order is not housekeeping. ADR 0017 leaves one channel family — `computed.*`
 * and `health.*` — undeclarable at compile time, because a user defines those
 * metrics in `qmx.yaml` and both facts the ceiling needs (shape, direction)
 * exist only once configuration has resolved the definition. The declaration
 * registry answers such a lookup from
 * the configured definition catalog, which the run populates.
 *
 * So a file read *before* the run is read against an empty vocabulary:
 * {@see \Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser} finds no declaration for
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
    private const string CHANNEL = 'computed.debtRatio';
    private const string METRIC = 'computed.debtRatio';
    private const string SOURCE_FILE = 'src/OrderService.php';

    private string $tempDir;
    private string $baselinePath;
    /** @var list<ComputedMetricDefinition> */
    private array $definitions = [];

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-order-');
        $this->baselinePath = $this->tempDir . '/baseline.json';

    }

    protected function tearDown(): void
    {
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
        $declarations = $this->declarations();

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
        $declarations = $this->declarations();

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
        $declarations = $this->declarations();

        $command = new BaselineExplainCommand(
            $this->measuredRun($configured),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            new BoundaryExplanationService(self::producerEdge()),
            new BaselineConfiguredThresholds(self::emptyRuleRegistry(), new RuleOptionsFactory(new RuleOptionsRegistry())),
        );

        return self::tester($command, [
            'subject' => self::subject()->toCanonical(),
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
            onMeasure: function () use ($configured): void {
                $this->definitions = $configured ? [self::definition()] : [];
            },
        );
    }

    private function declarations(): ChannelDeclarationRegistryInterface
    {
        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('find')->willReturnCallback(function (string $name): ?ComputedMetricDefinition {
            foreach ($this->definitions as $definition) {
                if ($definition->name === $name) {
                    return $definition;
                }
            }

            return null;
        });

        return new ChannelUniverse([], [], [], $catalog);
    }

    private static function definition(): ComputedMetricDefinition
    {
        return new ComputedMetricDefinition(
            name: self::METRIC,
            formulas: ['class' => '1'],
            description: 'debt ratio',
            levels: [SymbolLevel::Class_],
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
                    new BaselineIdentity(self::subject()->toCanonical(), new FindingChannel(self::CHANNEL)),
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

    private static function finding(): Finding
    {
        $channel = new FindingChannel(self::CHANNEL);
        $path = RelativePath::fromString(self::SOURCE_FILE);

        return new Finding(
            location: new Location($path, 1),
            subject: self::subject(),
            symbolPath: self::symbol(),
            ruleName: $channel->code,
            code: $channel->code,
            message: 'finding',
            severity: Severity::Warning,
            metricValue: 12.0,
        );
    }

    private static function subject(): MetricSubject
    {
        return MetricSubject::declaration(
            DeclarationPath::of(self::symbol(), RelativePath::fromString(self::SOURCE_FILE), DeclarationOrdinal::fromRank(0)),
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

    /**
     * A channel-to-producer edge for the fixtures below: the channel's own name,
     * minus a trailing level segment where it carries one. That is exactly the
     * relation the retired left half of a channel key encoded, so a fixture
     * naming `complexity.cyclomatic` still resolves to the rule a
     * `@qmx-threshold complexity.cyclomatic` addresses.
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
