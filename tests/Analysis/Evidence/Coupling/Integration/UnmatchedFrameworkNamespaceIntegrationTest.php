<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\UnmatchedFrameworkNamespaceRule;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `coupling.unmatched-framework-namespace` through the real command, because
 * the whole point of the channel is what a run *says*.
 *
 * A unit test of the predicate would pass with the channel never reaching a
 * report: the prefixes are read from a service the console configures, the
 * universe comes from a dependency graph built two phases earlier, and the
 * rule receives that service only because a compiler pass binds it. So every
 * case here runs `check` end to end and reads the JSON report and the exit
 * code.
 *
 * **The pair is the test, and the discriminator is not the finding.** A prefix
 * that binds and a prefix that misses produced byte-identical reports before
 * this channel existed — which is what made the door silent in the first
 * place, and what makes "the miss reports" worthless on its own: a producer
 * that always fires would pass it. {@see itMovesTheApplicationScopeOnlyOnAHit()}
 * is the independent witness that the "hit" fixture really is a hit, and it
 * reads `--format=metrics`, where the move shows up as `coupling.cbo-app`
 * falling and `coupling.ce-framework` rising.
 */
#[CoversClass(UnmatchedFrameworkNamespaceRule::class)]
final class UnmatchedFrameworkNamespaceIntegrationTest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-unmatched-framework-ns-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);

        // Without it the run reports an uncovered-autoload configuration
        // warning, whose exit code 2 would drown the 0-vs-2 distinction the
        // fail-on cases rest on.
        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );

        file_put_contents($this->fixture . '/src/Service.php', <<<'PHP'
            <?php

            namespace Sample;

            use Symfony\Component\Console\Command\Command;
            use Symfony\Component\Console\Input\InputInterface;
            use Symfony\Component\Console\Output\OutputInterface;

            class Service
            {
                public function run(Command $command, InputInterface $input, OutputInterface $output): void {}
            }
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['/src/Service.php', '/composer.json', '/qmx.yaml'] as $file) {
            @unlink($this->fixture . $file);
        }

        foreach (['/src', ''] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    /** Half one of the pair: the prefix matches nothing, and the run says so. */
    #[Test]
    public function itReportsAFrameworkPrefixThatMatchedNothing(): void
    {
        $findings = $this->findingsOnChannel($this->check($this->config("['Nope\\Missing']")));

        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]['severity'] ?? null);
        self::assertStringContainsString('Nope\Missing', (string) ($findings[0]['message'] ?? ''));
        self::assertStringContainsString('coupling.cbo-app', (string) ($findings[0]['message'] ?? ''));
    }

    /** Half two: the same option with a prefix the code really uses, silence. */
    #[Test]
    public function itStaysSilentWhenTheFrameworkPrefixMatched(): void
    {
        self::assertSame([], $this->findingsOnChannel($this->check($this->config("['Symfony']"))));
    }

    /**
     * The witness that the "hit" fixture is a hit rather than a second miss
     * this channel happens not to report.
     *
     * `coupling.cbo-app` counts three Symfony classes on the miss and none on
     * the hit; `coupling.ce-framework` is the mirror of it. Read from
     * `--format=metrics`, which is a projection the finding never enters — so
     * the two observations are independent.
     */
    #[Test]
    public function itMovesTheApplicationScopeOnlyOnAHit(): void
    {
        $miss = $this->classMetrics($this->check($this->config("['Nope\\Missing']"), ['--format' => 'metrics']));
        $hit = $this->classMetrics($this->check($this->config("['Symfony']"), ['--format' => 'metrics']));

        self::assertSame(3, $miss['coupling.cbo-app'] ?? null, 'The miss must leave every class in the application scope.');
        self::assertSame(0, $miss['coupling.ce-framework'] ?? null);
        self::assertSame(0, $hit['coupling.cbo-app'] ?? null, 'The hit must move all three out of it.');
        self::assertSame(3, $hit['coupling.ce-framework'] ?? null);
    }

    /** No prefixes declared is not a prefix that failed: there is nothing to report. */
    #[Test]
    public function itStaysSilentWhenNoFrameworkNamespacesAreDeclared(): void
    {
        self::assertSame([], $this->findingsOnChannel($this->check("coupling:\n  frameworkNamespaces: []\n")));
        self::assertSame([], $this->findingsOnChannel($this->check("cache:\n  enabled: false\n")));
    }

    /**
     * The run every project-wide `qmx.yaml` would otherwise be punished for:
     * one self-contained file, checked with a configuration whose prefixes are
     * perfectly correct for the project as a whole. The file depends on
     * nothing at all, so the graph carries no edge, no prefix could have
     * classified anything, and the channel must stay silent.
     */
    #[Test]
    public function itStaysSilentOnARunWhoseGraphCarriesNoDependency(): void
    {
        file_put_contents($this->fixture . '/src/Standalone.php', <<<'PHP'
            <?php

            namespace Sample;

            class Standalone
            {
                public function value(): int
                {
                    return 42;
                }
            }
            PHP);
        unlink($this->fixture . '/src/Service.php');

        try {
            self::assertSame([], $this->findingsOnChannel($this->check($this->config("['Symfony', 'Nope\\Missing']"))));
        } finally {
            @unlink($this->fixture . '/src/Standalone.php');
        }
    }

    /** Each unbound prefix is its own mistake, and each gets its own finding. */
    #[Test]
    public function itReportsEveryUnboundPrefixSeparately(): void
    {
        $findings = $this->findingsOnChannel($this->check($this->config("['Nope\\Missing', 'Symfony', 'Doctrine\\ORM']")));

        $messages = array_map(static fn(array $finding): string => (string) ($finding['message'] ?? ''), $findings);

        self::assertCount(2, $findings, 'The bound prefix must not be reported: ' . implode(' | ', $messages));
        self::assertStringContainsString('Nope\Missing', $messages[0]);
        self::assertStringContainsString('Doctrine\ORM', $messages[1]);
    }

    /**
     * The channel is the rule's, not
     * {@see \Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface}'s,
     * and this is the run that proves it rather than the declaration that
     * claims it: a configuration-error channel fails the run regardless of
     * `fail_on`, so a fixture producing this finding under `--fail-on=none`
     * would exit 2 had the channel been declared by a validator.
     */
    #[Test]
    public function itLeavesTheRunGreenUnderFailOnNone(): void
    {
        $tester = $this->check($this->config("['Nope\\Missing']"));

        self::assertNotSame([], $this->findingsOnChannel($tester));
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
    }

    /**
     * The same fixture under `--fail-on=warning`: an ordinary warning subject
     * to the gate, which is the other half of "not a configuration error". A
     * validator channel would trip the gate under both, so it is the pair that
     * carries the proof and not either run alone.
     */
    #[Test]
    public function itFailsTheRunUnderFailOnWarning(): void
    {
        $tester = $this->check($this->config("['Nope\\Missing']"), ['--fail-on' => 'warning']);

        self::assertSame(2, $tester->getStatusCode(), 'Findings at or above --fail-on exit 2.');
    }

    /**
     * `--disable-rule` reaching the channel is the proof that it travels the
     * ordinary publication path — registry, severity gate, baseline and all —
     * rather than being appended to the report behind it.
     */
    #[Test]
    public function itIsSilencedByDisablingTheProducingRule(): void
    {
        $tester = $this->check($this->config("['Nope\\Missing']"), ['--disable-rule' => [UnmatchedFrameworkNamespaceRule::NAME]]);

        self::assertSame([], $this->findingsOnChannel($tester));
    }

    private function config(string $prefixList): string
    {
        return "coupling:\n  frameworkNamespaces: {$prefixList}\n";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findingsOnChannel(CommandTester $tester): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('violations', $payload);
        $violations = $payload['violations'];
        self::assertIsList($violations);

        $matched = [];
        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            if (($violation['rule'] ?? null) === UnmatchedFrameworkNamespaceRule::NAME) {
                $matched[] = $violation;
            }
        }

        return $matched;
    }

    /**
     * The class-level metrics of the single fixture class.
     *
     * @return array<string, int|float>
     */
    private function classMetrics(CommandTester $tester): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        $found = self::findClassMetrics($payload, 'Sample\\Service');
        self::assertNotNull($found, 'The metrics projection must carry the fixture class.');

        return $found;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return ?array<string, int|float>
     */
    private static function findClassMetrics(array $payload, string $name): ?array
    {
        foreach ($payload as $value) {
            if (!\is_array($value)) {
                continue;
            }

            if (($value['name'] ?? null) === $name && \is_array($value['metrics'] ?? null)) {
                /** @var array<string, int|float> $metrics */
                $metrics = $value['metrics'];

                return $metrics;
            }

            $nested = self::findClassMetrics($value, $name);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $options */
    private function check(string $yaml, array $options = []): CommandTester
    {
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
                    'paths' => ['src'],
                    '--workers' => '0',
                    '--format' => 'json',
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
