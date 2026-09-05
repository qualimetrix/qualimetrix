<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Integration\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `annotation.unused-directive` is the one channel a run assembles **after**
 * rule execution, and this file is the whole grid of what may name it.
 *
 * Being assembled late used to mean being assembled past the filter every
 * other finding passes: `--disable-rule annotation.unused-directive` left it
 * reported, and an `only_rules` naming a sibling channel of the same producer
 * reported it although it named no such thing. Both halves leaked, and both
 * leaked silently — the run neither refused the spelling nor acted on it.
 *
 * Four of the seven name forms reached the channel before this file existed,
 * through three different routes, and every one of them must keep working:
 * the producer name and the group stop the producer outright, and the union of
 * its four channels stops it through
 * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector::silenceEveryChannelOf()},
 * which quantifies over the *set* of disable selectors rather than over each
 * one. The union case and the three-of-four case are therefore a pair: read
 * alone, either passes under a rewrite that asks the question per selector;
 * together they pin the quantifier, because per-selector reasoning would stop
 * the producer for three names as readily as for four.
 *
 * The five carriers of a selector — `--disable-rule`, `--only-rule`, the config
 * file's `disabled_rules` and `only_rules`, and a preset — merge into one
 * {@see \Qualimetrix\Analysis\Finding\Contract\RuleSelection} before anything
 * is validated or applied, so the grid is run against the merged selection and
 * the carriers are checked separately for landing in it. Covering one carrier
 * is not covering five.
 *
 * The finding gate cannot see any of this: its corpus carries selector cases
 * and it carries a case with this channel, but never both in one case.
 */
#[CoversClass(AnalysisPipeline::class)]
#[CoversClass(RuleExecution::class)]
final class LateChannelObeysSelectionTest extends TestCase
{
    private const string LATE = InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME;

    private const string SIBLING = InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME;

    private const string PRODUCER = InlineDirectivePolicyInterface::PRODUCER_RULE_NAME;

    /** The rule the stale suppression names; keeping it on is what makes the directive measurable. */
    private const string MEASURED = 'complexity.cyclomatic';

    /** @var list<string> */
    private const array ALL_CHANNELS = [
        InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
        InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
        InlineDirectivePolicyInterface::UNSUPPORTED_THRESHOLD_NAME,
        InlineDirectivePolicyInterface::INVALID_THRESHOLD_NAME,
    ];

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-late-channel-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/src', 0777, true);

        // One stale suppression, which is what the late channel reports on, and
        // one broken directive, which is an *early* sibling channel of the same
        // producer: the `only` half of the defect is only visible when a
        // sibling can be named without naming the late channel.
        file_put_contents($this->tempDir . '/src/Sample.php', <<<'PHP'
            <?php

            namespace LateChannel;

            class Sample
            {
                /**
                 * @qmx-ignore complexity.cyclomatic -- nothing complex here any more
                 */
                public function simple(): int
                {
                    return 1;
                }

                /**
                 * @qmx-ignore no.such.rule -- names a rule that does not exist
                 */
                public function unresolved(): int
                {
                    return 2;
                }
            }
            PHP);

        file_put_contents($this->tempDir . '/qmx.yaml', "paths:\n  - src\n");
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    /**
     * The control every other case is read against. Without it, a run that
     * reported the late channel under no circumstances would pass the whole
     * disable half.
     */
    #[Test]
    public function itPublishesTheLateChannelWhenNothingSelectsAgainstIt(): void
    {
        self::assertSame([self::LATE, self::SIBLING], $this->channelsFrom([]));
    }

    /**
     * The disable half of the grid.
     *
     * @param list<string> $selectors
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideDisableForms')]
    public function disableSelectionReachesTheLateChannelExactlyWhereTheGridSaysItDoes(
        array $selectors,
        array $expected,
    ): void {
        self::assertSame($expected, $this->channelsFrom(['--disable-rule' => $selectors]));
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function provideDisableForms(): iterable
    {
        // Changed by this package: the exact channel name used to be inert.
        yield 'exact channel name' => [[self::LATE], [self::SIBLING]];

        // Changed by this package, for the same reason and through the same
        // predicate: a level-bearing channel selector.
        yield 'channel:level' => [[self::LATE . ':file'], [self::SIBLING]];

        // Unchanged: the producer never runs, so neither channel is produced.
        yield 'producer rule name' => [[self::PRODUCER], []];
        yield 'group' => [['annotation.*'], []];
        yield 'group:level' => [['annotation.*:file'], []];

        // Unchanged, and the half of the union pair that must stay silent: no
        // single one of these names the producer, and the producer is stopped
        // only because together they cover every level of every channel it
        // emits.
        yield 'union of all four channels' => [self::ALL_CHANNELS, []];

        // Unchanged, and the half of the union pair that must stay loud. Three
        // channels leave the producer running and none of them addresses the
        // late channel, so it is still reported. A rewrite asking "does any
        // selector address a channel of this producer" would stop the producer
        // here and turn this row red.
        yield 'three of four channels, the late one omitted' => [
            [self::SIBLING, InlineDirectivePolicyInterface::UNSUPPORTED_THRESHOLD_NAME, InlineDirectivePolicyInterface::INVALID_THRESHOLD_NAME],
            [self::LATE],
        ];
    }

    /**
     * The `only` half of the grid.
     *
     * Every case keeps {@see MEASURED} selected on. Without it no authored
     * suppression is measurable, no stale verdict is reached and the report is
     * empty for a reason that has nothing to do with selection — an emptiness
     * that would make every row here pass while proving nothing.
     *
     * @param list<string> $selectors
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideOnlyForms')]
    public function onlySelectionReachesTheLateChannelExactlyWhereTheGridSaysItDoes(
        array $selectors,
        array $expected,
    ): void {
        self::assertSame($expected, $this->channelsFrom(['--only-rule' => [...$selectors, self::MEASURED]]));
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function provideOnlyForms(): iterable
    {
        // Unchanged: naming the channel publishes it, and always did.
        yield 'exact channel name' => [[self::LATE], [self::LATE]];
        yield 'channel:level' => [[self::LATE . ':file'], [self::LATE]];
        yield 'producer rule name' => [[self::PRODUCER], [self::LATE, self::SIBLING]];
        yield 'group' => [['annotation.*'], [self::LATE, self::SIBLING]];
        yield 'group:level' => [['annotation.*:file'], [self::LATE, self::SIBLING]];
        yield 'union of all four channels' => [self::ALL_CHANNELS, [self::LATE, self::SIBLING]];

        // Changed by this package, and the half the first draft of the grid did
        // not have at all: a positive selection that names a sibling channel
        // and never names the late one used to publish it regardless.
        yield 'sibling channel only' => [[self::SIBLING], [self::SIBLING]];
        yield 'three of four channels, the late one omitted' => [
            [self::SIBLING, InlineDirectivePolicyInterface::UNSUPPORTED_THRESHOLD_NAME, InlineDirectivePolicyInterface::INVALID_THRESHOLD_NAME],
            [self::SIBLING],
        ];
    }

    /**
     * The fork this package measured, decided the other way, and pins here:
     * channel selection is applied to the late finding, the per-producer
     * exclusion ledger is not.
     *
     * The ledger is reachable at the same point — measured, `suppress_paths`
     * keyed by the producer does remove this finding when applied there. What
     * it cannot do is account for the removal. A ledger lives for one
     * `execute()` call, and the run's whole account of it — the per-mechanism
     * counters, `--show-suppressed`'s retained findings, their attributions —
     * is read into the execution result before this finding exists. Measured
     * with the ledger applied: the finding left the report and the account
     * still said one `rule-path-exclusion`, naming only the early sibling. A
     * finding removed by nobody, according to the run's own books.
     *
     * So the exclusion stays inert on this channel, exactly as it is today, and
     * this row is what says the inertness is a decision rather than an
     * oversight.
     */
    #[Test]
    public function theProducersPathExclusionStillReachesTheEarlySiblingAndNotTheLateChannel(): void
    {
        self::assertSame(
            [self::LATE],
            $this->channelsFrom(['--rule-opt' => [self::PRODUCER . ':suppress_paths=**']]),
        );
    }

    /**
     * The two forms that are refused rather than applied, in both halves.
     *
     * A refusal is the one verdict this package must not turn into an
     * application: `producer:level` narrows a *producer* name by level, which
     * the grammar does not have, and `rule#code` is the retired pair spelling.
     * Both end the run at the shared validation seam long before any finding is
     * assembled, and exit 3 is what says so.
     */
    #[Test]
    #[DataProvider('provideRefusedForms')]
    public function refusedFormsStayRefused(string $option, string $selector): void
    {
        $tester = $this->execute([$option => [$selector]]);

        self::assertSame(3, $tester->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedForms(): iterable
    {
        foreach (['--disable-rule', '--only-rule'] as $option) {
            yield $option . ' producer:level' => [$option, self::PRODUCER . ':file'];
            yield $option . ' retired pair' => [$option, self::PRODUCER . '#unused-directive'];
        }
    }

    /**
     * The five carriers land in one merged selection, so the grid above — run
     * through two of them — is a statement about all five.
     *
     * Asserted on the two verdicts this package changes, one per half, because
     * a carrier that failed to reach the merged selection would show up exactly
     * there: an unreached selector reads as an absent one, and an absent one
     * republishes the late channel.
     *
     * @param array<string, mixed> $invocation
     */
    #[Test]
    #[DataProvider('provideCarriers')]
    public function everyCarrierOfASelectorReachesTheSameMergedSelection(array $invocation, string $expected): void
    {
        self::assertSame([$expected], $this->channelsFrom($invocation));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideCarriers(): iterable
    {
        yield 'cli --disable-rule' => [['--disable-rule' => [self::LATE]], self::SIBLING];
        yield 'cli --only-rule' => [['--only-rule' => [self::SIBLING, self::MEASURED]], self::SIBLING];
        yield 'config disabled_rules' => [['config' => "disabled_rules:\n  - " . self::LATE . "\n"], self::SIBLING];
        yield 'config only_rules' => [
            ['config' => "only_rules:\n  - " . self::SIBLING . "\n  - " . self::MEASURED . "\n"],
            self::SIBLING,
        ];
        yield 'preset disabledRules' => [['preset' => "disabledRules:\n  - " . self::LATE . "\n"], self::SIBLING];
        yield 'preset onlyRules' => [
            ['preset' => "onlyRules:\n  - " . self::SIBLING . "\n  - " . self::MEASURED . "\n"],
            self::SIBLING,
        ];
    }

    /**
     * The producer's four channels, in a fixed order, as this run reports them.
     *
     * `config` and `preset` are not CLI options: they name extra YAML the run
     * is given, which is how the two non-CLI carriers are exercised without a
     * second runner.
     *
     * @param array<string, mixed> $invocation
     *
     * @return list<string>
     */
    private function channelsFrom(array $invocation): array
    {
        $tester = $this->execute($invocation);

        self::assertNotSame(3, $tester->getStatusCode(), 'The run was refused: ' . $tester->getDisplay());

        $report = json_decode(
            self::extractJsonObject($tester->getDisplay()),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($report);
        self::assertIsArray($report['violations'] ?? null);

        $reported = [];
        foreach ($report['violations'] as $finding) {
            self::assertIsArray($finding);
            self::assertIsString($finding['channel'] ?? null);
            $reported[] = new FindingChannel($finding['channel'])->code;
        }

        return array_values(array_filter(
            self::ALL_CHANNELS,
            static fn(string $channel): bool => \in_array($channel, $reported, true),
        ));
    }

    /**
     * @param array<string, mixed> $invocation
     */
    private function execute(array $invocation): CommandTester
    {
        $config = $this->tempDir . '/qmx.yaml';

        if (isset($invocation['config'])) {
            self::assertIsString($invocation['config']);
            $config = $this->tempDir . '/qmx-selection.yaml';
            file_put_contents($config, "paths:\n  - src\n" . $invocation['config']);
            unset($invocation['config']);
        }

        if (isset($invocation['preset'])) {
            self::assertIsString($invocation['preset']);
            $preset = $this->tempDir . '/preset.yaml';
            file_put_contents($preset, $invocation['preset']);
            $invocation['--preset'] = [$preset];
            unset($invocation['preset']);
        }

        /** @var CheckCommand $command */
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        $application = new Application();
        $application->addCommand($command);
        $tester = new CommandTester($command);

        $tester->execute([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
            '--workers' => 0,
            '--no-cache' => true,
            '--no-progress' => true,
            '--fail-on' => 'none',
            ...$invocation,
        ]);

        return $tester;
    }

    /**
     * Configuration warnings may precede the document on the same stream.
     */
    private static function extractJsonObject(string $output): string
    {
        foreach (str_split($output) as $offset => $char) {
            if ($char !== '{') {
                continue;
            }

            $candidate = substr($output, $offset);
            json_decode($candidate, true);
            if (json_last_error() === \JSON_ERROR_NONE) {
                return $candidate;
            }
        }

        return $output;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach (array_diff($entries === false ? [] : $entries, ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
