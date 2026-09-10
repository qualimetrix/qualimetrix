<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration\SuppressionBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionOptions;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionRule;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The three `suppression.*` channels through the real command, because the
 * value they are about is lost twice on its way to a report and a unit test of
 * the predicate would notice neither.
 *
 * The two: the configured values are merged with `--suppress-path` and
 * `--suppress-namespace` inside the console, and the findings are assembled at
 * the reporting seam, after the pipeline has finished, by a collaborator the
 * container has to have wired into the orchestrator. A test calling the audit
 * directly would pass with the channels reaching no report at all.
 *
 * **The pair is the test.** A value that binds and one that misses produced
 * byte-identical output before these channels existed, so "the miss reports"
 * is worthless alone — a producer that always fired would satisfy it. Every
 * reporting case here has a silent twin differing in exactly one way.
 */
#[CoversClass(UnboundSuppressionRule::class)]
#[CoversClass(UnboundSuppressionAudit::class)]
final class UnboundSuppressionIntegrationTest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-unbound-suppression-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src/Deep', 0o755, true);

        // The coverage precondition reads the production autoload roots, so
        // without this the whole tree would read as a narrowed run and every
        // case below would be silent for the wrong reason.
        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );

        file_put_contents($this->fixture . '/src/Service.php', <<<'PHP'
            <?php

            namespace Sample;

            class Service
            {
                public function value(): int
                {
                    return 1;
                }
            }
            PHP);

        file_put_contents($this->fixture . '/src/Deep/Old.php', <<<'PHP'
            <?php

            namespace Sample\Deep;

            class Old
            {
                public function value(): int
                {
                    return 2;
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['/src/Service.php', '/src/Deep/Old.php', '/composer.json', '/qmx.yaml'] as $file) {
            @unlink($this->fixture . $file);
        }

        foreach (['/src/Deep', '/src', ''] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    /** Half one of the `suppress_paths` pair: the value names nothing, and the run says so. */
    #[Test]
    public function itReportsAConfiguredSuppressPathThatNamedNothing(): void
    {
        $findings = $this->onChannel($this->check("suppress_paths:\n  - src/NoSuchDir\n"), UnboundSuppressionOptions::UNMATCHED_PATH);

        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]['severity'] ?? null);
        self::assertStringContainsString('src/NoSuchDir', (string) ($findings[0]['message'] ?? ''));
    }

    /** Half two: the same key with a path the run really analysed. */
    #[Test]
    public function itStaysSilentWhenTheConfiguredSuppressPathNamedSomething(): void
    {
        self::assertSame([], $this->onChannel($this->check("suppress_paths:\n  - src/Deep\n"), UnboundSuppressionOptions::UNMATCHED_PATH));
    }

    /** The same pair for `suppress_namespaces`, whose universe is the declared namespaces rather than the files. */
    #[Test]
    public function itReportsAConfiguredSuppressNamespaceThatNamedNothingAndNotOneThatDid(): void
    {
        $miss = $this->onChannel($this->check("suppress_namespaces:\n  - Sample\\Gone\n"), UnboundSuppressionOptions::UNMATCHED_NAMESPACE);
        $hit = $this->onChannel($this->check("suppress_namespaces:\n  - Sample\\Deep\n"), UnboundSuppressionOptions::UNMATCHED_NAMESPACE);

        self::assertCount(1, $miss);
        self::assertStringContainsString('Sample\\Gone', (string) ($miss[0]['message'] ?? ''));
        self::assertSame([], $hit);
    }

    /**
     * The command-line half of the same two doors. `--suppress-path` and
     * `--suppress-namespace` are merged into the configured values before the
     * seam sees them, so a cure reading only the configuration file would pass
     * every case above and stay silent here.
     */
    #[Test]
    public function itReportsTheCommandLineSuppressionFlagsThatNamedNothingAndNotOnesThatDid(): void
    {
        $miss = $this->check(options: [
            '--suppress-path' => ['src/NoSuchDir'],
            '--suppress-namespace' => ['Sample\\Gone'],
        ]);
        $hit = $this->check(options: [
            '--suppress-path' => ['src/Deep'],
            '--suppress-namespace' => ['Sample\\Deep'],
        ]);

        self::assertCount(1, $this->onChannel($miss, UnboundSuppressionOptions::UNMATCHED_PATH));
        self::assertCount(1, $this->onChannel($miss, UnboundSuppressionOptions::UNMATCHED_NAMESPACE));
        self::assertSame([], $this->onChannel($hit, UnboundSuppressionOptions::UNMATCHED_PATH));
        self::assertSame([], $this->onChannel($hit, UnboundSuppressionOptions::UNMATCHED_NAMESPACE));
    }

    /** The per-rule ledger, both halves, on both of its keys. */
    #[Test]
    public function itReportsAPerRuleLedgerPatternThatNamedNothingAndNotOneThatDid(): void
    {
        $miss = $this->onChannel(
            $this->check("rules:\n  complexity.ccn:\n    suppress_paths: ['src/Gone']\n    suppress_namespaces: ['Sample\\Gone']\n"),
            UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER,
        );
        $hit = $this->onChannel(
            $this->check("rules:\n  complexity.ccn:\n    suppress_paths: ['src/Deep']\n    suppress_namespaces: ['Sample\\Deep']\n"),
            UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER,
        );

        $messages = implode(' | ', array_map(static fn(array $f): string => (string) ($f['message'] ?? ''), $miss));

        self::assertCount(2, $miss, $messages);
        self::assertStringContainsString('complexity.ccn', $messages);
        self::assertStringContainsString('src/Gone', $messages);
        self::assertStringContainsString('Sample\\Gone', $messages);
        self::assertSame([], $hit);
    }

    /**
     * **The round's own distinction, and the reason `neverMatched` could not
     * simply be reused.**
     *
     * `src/Deep` binds — it names a directory this run really analysed — and
     * removes nothing, because the run produces no finding under it. That is
     * effect-zero, an honest state a paid-down suppression reaches, and the
     * `suppressed` format lists it. `src/NoSuchDir` binds to nothing at all.
     * Both appear in `neverMatched`; only the second is this channel's, and
     * this case is what says the two counts are separate rather than one count
     * read twice.
     */
    #[Test]
    public function itSeparatesASuppressorThatBoundNothingFromOneThatMerelyRemovedNothing(): void
    {
        $yaml = "suppress_paths:\n  - src/Deep\n  - src/NoSuchDir\nonly_rules:\n  - " . UnboundSuppressionRule::NAME . "\n  - code-smell.goto\n";

        $findings = $this->onChannel($this->check($yaml), UnboundSuppressionOptions::UNMATCHED_PATH);

        self::assertCount(1, $findings, 'Only the value that bound to nothing is this channel\'s.');
        self::assertStringContainsString('src/NoSuchDir', (string) ($findings[0]['message'] ?? ''));

        $neverMatched = $this->neverMatchedSuppressors($this->check($yaml, format: 'suppressed'));

        self::assertContains('src/Deep', $neverMatched, 'The effect-zero suppressor must still be in neverMatched.');
        self::assertContains('src/NoSuchDir', $neverMatched);
    }

    /**
     * The same distinction on the other universe, because the split is a claim
     * about both: `Sample\Deep` is a namespace this run declared and holds no
     * finding once every other producer is off, so it removes nothing and
     * lands in `neverMatched` — while staying silent here. `Sample\Gone` names
     * no declared namespace at all and is reported.
     */
    #[Test]
    public function itSeparatesTheTwoZeroesForNamespacesToo(): void
    {
        $yaml = "suppress_namespaces:\n  - Sample\\Deep\n  - Sample\\Gone\nonly_rules:\n  - "
            . UnboundSuppressionRule::NAME . "\n  - code-smell.goto\n";

        $findings = $this->onChannel($this->check($yaml), UnboundSuppressionOptions::UNMATCHED_NAMESPACE);

        self::assertCount(1, $findings, 'Only the value that bound to nothing is this channel\'s.');
        self::assertStringContainsString('Sample\\Gone', (string) ($findings[0]['message'] ?? ''));

        $neverMatched = $this->neverMatchedSuppressors($this->check($yaml, format: 'suppressed'));

        self::assertContains('Sample\\Deep', $neverMatched, 'The effect-zero suppressor must still be in neverMatched.');
        self::assertContains('Sample\\Gone', $neverMatched);
    }

    /**
     * The precondition, from the side that could hide a broken cure: the same
     * missed value on a run narrowed below the autoload roots must be silent,
     * because there it binds nothing for a reason the author did not choose.
     *
     * Paired with {@see itReportsAConfiguredSuppressPathThatNamedNothing()},
     * which is the same value on a run that does cover them. One observation
     * without the other does not distinguish a working precondition from a
     * channel that never speaks.
     */
    #[Test]
    public function itStaysSilentOnARunNarrowedBelowTheAutoloadRoots(): void
    {
        self::assertSame(
            [],
            $this->onChannel(
                $this->check("suppress_paths:\n  - src/NoSuchDir\n", paths: ['src/Deep']),
                UnboundSuppressionOptions::UNMATCHED_PATH,
            ),
        );
    }

    /** An empty list is not a miss: there is no authored value to judge. */
    #[Test]
    public function itStaysSilentOnAnEmptySuppressionList(): void
    {
        $tester = $this->check("suppress_paths: []\nsuppress_namespaces: []\n");

        self::assertSame([], $this->onChannel($tester, UnboundSuppressionOptions::UNMATCHED_PATH));
        self::assertSame([], $this->onChannel($tester, UnboundSuppressionOptions::UNMATCHED_NAMESPACE));
    }

    /**
     * A finding about `suppress_paths` must not be removed by a sibling
     * `suppress_paths` entry that does bind: the channels are declared
     * project-scoped, and the global path and namespace filters exempt exactly
     * those. Here `src` suppresses everything the code produced, and the
     * report still carries the complaint about `src/NoSuchDir`.
     */
    #[Test]
    public function itDoesNotSuppressItsOwnFindingWithTheSuppressionItReportsOn(): void
    {
        $findings = $this->onChannel(
            $this->check("suppress_paths:\n  - src\n  - src/NoSuchDir\nsuppress_namespaces:\n  - Sample\n"),
            UnboundSuppressionOptions::UNMATCHED_PATH,
        );

        self::assertCount(1, $findings);
        self::assertStringContainsString('src/NoSuchDir', (string) ($findings[0]['message'] ?? ''));
    }

    /**
     * The channels are the rule's, not
     * {@see \Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface}'s,
     * and this is the run that proves it rather than the declaration claiming
     * it: a configuration-error channel fails the run regardless of `fail_on`,
     * so this fixture would exit 2 under `--fail-on=none`.
     */
    #[Test]
    public function itLeavesTheRunGreenUnderFailOnNone(): void
    {
        $tester = $this->check(...$this->onlyThisChannel());

        self::assertNotSame([], $this->onChannel($tester, UnboundSuppressionOptions::UNMATCHED_PATH));
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
    }

    /**
     * The other half of "not a configuration error": under `--fail-on=warning`
     * the finding trips the gate like any warning, at warning's own exit code.
     * A validator channel would trip it under both settings, so it is the pair
     * that carries the proof and neither run alone.
     *
     * Both halves run with `--only-rule` on this producer, so the code read is
     * this finding's and not the highest severity some neighbouring rule
     * happened to reach on the fixture.
     */
    #[Test]
    public function itFailsTheRunUnderFailOnWarning(): void
    {
        [$yaml, $paths, $options] = [...$this->onlyThisChannel(), []];
        $tester = $this->check($yaml, $paths, [...$options, '--fail-on' => 'warning']);

        self::assertSame(1, $tester->getStatusCode(), 'A warning meeting --fail-on exits with warning\'s own code.');
    }

    /**
     * `--disable-rule` reaching the channels is the proof that the assembled
     * findings really go through `publishable()` — registry, selection,
     * severity and baseline — rather than being appended to the report behind
     * it. Both granularities are checked, because the producer's name is not
     * one of its channels: silencing the producer silences all three, and
     * naming one channel leaves its siblings speaking.
     */
    #[Test]
    public function itIsSilencedByDisablingTheProducerAndByDisablingOneChannel(): void
    {
        $yaml = "suppress_paths:\n  - src/NoSuchDir\nsuppress_namespaces:\n  - Sample\\Gone\n";

        $producerOff = $this->check($yaml, options: ['--disable-rule' => [UnboundSuppressionRule::NAME]]);
        self::assertSame([], $this->onChannel($producerOff, UnboundSuppressionOptions::UNMATCHED_PATH));
        self::assertSame([], $this->onChannel($producerOff, UnboundSuppressionOptions::UNMATCHED_NAMESPACE));

        $oneOff = $this->check($yaml, options: ['--disable-rule' => [UnboundSuppressionOptions::UNMATCHED_PATH]]);
        self::assertSame([], $this->onChannel($oneOff, UnboundSuppressionOptions::UNMATCHED_PATH));
        self::assertCount(1, $this->onChannel($oneOff, UnboundSuppressionOptions::UNMATCHED_NAMESPACE));
    }

    /**
     * The rule's own options are the second switch, and reaching them is what
     * the audit's lazy registration buys: constructed eagerly it would hold an
     * Options object from before the console applied this, and would still
     * read `enabled: true`.
     */
    #[Test]
    public function itIsSilencedByDisablingTheRuleThroughItsOptions(): void
    {
        $yaml = "suppress_paths:\n  - src/NoSuchDir\nrules:\n  " . UnboundSuppressionRule::NAME . ":\n    enabled: false\n";

        self::assertSame([], $this->onChannel($this->check($yaml), UnboundSuppressionOptions::UNMATCHED_PATH));
    }

    /**
     * `baseline:generate` runs the pipeline through a different seam, and the
     * findings assembled at the reporting seam do not reach it — which is the
     * decision, not an accident: a warning about the author's own stale
     * configuration must not become accepted debt in the file that author
     * generates with one command. The cost is that this channel cannot be
     * ratcheted, and this case is what would notice the day it changes.
     */
    #[Test]
    public function itIsNotWrittenIntoAGeneratedBaseline(): void
    {
        file_put_contents($this->fixture . '/qmx.yaml', "suppress_paths:\n  - src/NoSuchDir\n");

        $command = (new ContainerFactory())->create()->get(BaselineGenerateCommand::class);
        self::assertInstanceOf(BaselineGenerateCommand::class, $command);

        $baselinePath = $this->fixture . '/baseline.json';
        $tester = new CommandTester($command);
        $previous = (string) getcwd();
        chdir($this->fixture);

        try {
            $tester->execute(['baseline' => 'baseline.json', 'paths' => ['src']]);
        } finally {
            chdir($previous);
        }

        $written = (string) file_get_contents($baselinePath);
        @unlink($baselinePath);

        self::assertStringNotContainsString('suppression.unmatched-', $written);
    }

    /**
     * The miss, with every other producer switched off, so an exit code is
     * about these channels alone.
     *
     * @return array{string, list<string>, array<string, mixed>}
     */
    private function onlyThisChannel(): array
    {
        return [
            "suppress_paths:\n  - src/NoSuchDir\n",
            ['src'],
            ['--only-rule' => [UnboundSuppressionRule::NAME]],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function onChannel(CommandTester $tester, string $channel): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('violations', $payload);
        $violations = $payload['violations'];
        self::assertIsList($violations);

        $matched = [];
        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            if (($violation['rule'] ?? null) === $channel) {
                $matched[] = $violation;
            }
        }

        return $matched;
    }

    /**
     * @return list<string>
     */
    private function neverMatchedSuppressors(CommandTester $tester): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $entries = $payload['neverMatched'] ?? [];
        self::assertIsList($entries);

        $suppressors = [];
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            $suppressors[] = (string) ($entry['suppressor'] ?? '');
        }

        return $suppressors;
    }

    /**
     * @param list<string> $paths
     * @param array<string, mixed> $options
     */
    private function check(
        string $yaml = "cache:\n  enabled: false\n",
        array $paths = ['src'],
        array $options = [],
        string $format = 'json',
    ): CommandTester {
        file_put_contents($this->fixture . '/qmx.yaml', $yaml);

        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);

        $tester = new CommandTester($command);

        // The project root comes from the process working directory, exactly
        // as `--working-dir` sets it on the real binary.
        $previous = (string) getcwd();
        chdir($this->fixture);

        try {
            $tester->execute(
                [
                    'paths' => $paths,
                    '--workers' => '0',
                    '--format' => $format,
                    '--fail-on' => 'none',
                    ...$options,
                ],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        return $tester;
    }
}
