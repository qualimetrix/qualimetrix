<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every channel that says a configured value bound to nothing must ask first
 * whether this run could tell — and this is the test that will not let the
 * seventh such channel be added without one.
 *
 * **Why a guard and not a review habit.** `architecture.unmatched-exclude`
 * shipped without the precondition its own ADR requires, one round after the
 * precondition was introduced, and nothing failed: the channel is a rule like
 * any other, so no compiler, container or test noticed the missing question.
 * A per-channel unit test would not have either — each one passes on the
 * fixture it was written for.
 *
 * **The population is read from the product, not typed here.** Every channel
 * whose declared name contains `unmatched` is scope-conditioned; that is the
 * naming convention ADR 0052 fixes and this file guards, and
 * {@see itDeclaresTheScopeConditionedPopulation} fails when the product's set
 * and the list below drift apart in either direction — a new channel not
 * listed, or a listed channel gone.
 *
 * **What the silent half does not cover.** Two of the six place no subject of
 * their own — `coupling.unmatched-framework-namespace` names code outside the
 * project, and a rule cannot see the run's paths — so they answer the
 * project-wide question alone. They are gated here, and
 * {@see itJudgesOnlyTheValuesWhoseSubjectTheRunAnalysed} deliberately covers
 * only the four that can place a subject.
 *
 * **The pair is the proof.** One fixture, two `composer.json` files. Under
 * PSR-4 the run can judge and every one of the six channels speaks; the same
 * tree and the same configuration under a `classmap`-only manifest give the
 * product nothing to measure coverage against, and every one of the six must
 * be silent. Without the speaking half, silence would not distinguish a
 * working gate from a fixture that cannot produce the channel at all; without
 * the silent half, a channel with no gate passes.
 */
final class ScopeConditionedChannelGuardTest extends TestCase
{
    /**
     * The channels of the round, spelled out so the assertion has two sides.
     * Their producers live in five different owners, which is why the list
     * cannot be read off one class.
     */
    private const array SCOPE_CONDITIONED = [
        'architecture.unmatched-exclude',
        'coupling.unmatched-framework-namespace',
        'discovery.unmatched-exclude',
        'suppression.unmatched-namespace',
        'suppression.unmatched-path',
        'suppression.unmatched-rule-ledger',
    ];

    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-scope-conditioned-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);
        mkdir($this->fixture . '/tests', 0o755, true);

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

        // A second class depending on the first: without an edge the
        // dependency graph classifies no name, and the coupling channel is
        // silent for a reason that has nothing to do with scope.
        file_put_contents($this->fixture . '/src/Client.php', <<<'PHP'
            <?php

            namespace Sample;

            class Client
            {
                public function __construct(private Service $service) {}

                public function value(): int
                {
                    return $this->service->value();
                }
            }
            PHP);

        file_put_contents($this->fixture . '/tests/ServiceTest.php', <<<'PHP'
            <?php

            namespace Sample\Tests;

            class ServiceTest
            {
                public function value(): int
                {
                    return 1;
                }
            }
            PHP);

        // Every one of the six channels is armed by a value that binds to
        // nothing anywhere in this tree — the shape each channel exists to
        // report, so silence below can only come from the gate.
        file_put_contents($this->fixture . '/qmx.yaml', <<<'YAML'
            cache:
              enabled: false
            exclude: [gone-dir]
            suppress_paths:
              - src/Gone
            suppress_namespaces:
              - 'Sample\Gone'
            coupling:
              frameworkNamespaces: ['Nowhere\']
            architecture:
              coverage-gap: ignore
              layers:
                - name: domain
                  patterns: ['Sample\**']
                  exclude:
                    suffix: ['NothingLikeThis']
            rules:
              complexity.ccn:
                suppress_paths: ['src/AlsoGone']
            YAML);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->fixture));
    }

    /**
     * The denominator: what the product declares against what this file
     * guards. A seventh channel named `*.unmatched-*` fails here until it is
     * listed — and listing it puts it into the two runs below, which is where
     * its gate is actually measured.
     */
    #[Test]
    public function itDeclaresTheScopeConditionedPopulation(): void
    {
        $registry = (new ContainerFactory())->create()->get(ChannelDeclarationRegistryInterface::class);
        self::assertInstanceOf(ChannelDeclarationRegistryInterface::class, $registry);

        $declared = array_values(array_filter(
            array_keys($registry->staticDeclarations()),
            static fn(string $channel): bool => str_contains($channel, 'unmatched'),
        ));
        sort($declared);

        self::assertSame(
            self::SCOPE_CONDITIONED,
            $declared,
            'A channel about a value that bound to nothing must be listed here and gated in the run below.',
        );
    }

    /** The speaking half: on a run that can judge, every one of the six fires. */
    #[Test]
    public function itSpeaksOnEveryScopeConditionedChannelWhenTheRunCanJudge(): void
    {
        $spoke = $this->channelsOf($this->check(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]]));

        self::assertSame(
            self::SCOPE_CONDITIONED,
            $spoke,
            'The fixture must be able to produce every channel, or the silent half proves nothing.',
        );
    }

    /**
     * The silent half: the same tree and the same configuration under a
     * manifest whose production autoload this product cannot read. There is no
     * denominator, so there is no coverage verdict, so no channel of the round
     * may accuse anyone.
     */
    #[Test]
    public function itStaysSilentOnEveryScopeConditionedChannelWhenTheRunCannotJudge(): void
    {
        $spoke = $this->channelsOf($this->check(['autoload' => ['classmap' => ['src/']]]));

        self::assertSame([], $spoke, 'A run with nothing to measure coverage against may judge no configured value.');
    }

    /**
     * The subject half of the same question, which coverage alone cannot
     * answer: `qmx check src/` covers this project's production autoload, and
     * an entry naming `tests/` is still correct configuration it never looked
     * at. Its stale twin inside `src/` is reported by the same run, so the
     * silence is about the subject and not about the channel being off.
     */
    #[Test]
    public function itJudgesOnlyTheValuesWhoseSubjectTheRunAnalysed(): void
    {
        file_put_contents($this->fixture . '/qmx.yaml', <<<'YAML'
            cache:
              enabled: false
            exclude: [tests]
            suppress_paths:
              - tests/Gone
              - src/Gone
            suppress_namespaces:
              - 'Sample\Tests\Gone'
              - 'Sample\Gone'
            YAML);

        $tester = $this->check([
            'autoload' => ['psr-4' => ['Sample\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Sample\\Tests\\' => 'tests/']],
        ]);

        $messages = implode(' | ', array_map(
            static fn(array $violation): string => (string) ($violation['message'] ?? ''),
            $this->violations($tester),
        ));

        self::assertStringContainsString('src/Gone', $messages);
        self::assertStringContainsString('Sample\\Gone', $messages);
        self::assertStringNotContainsString('tests/Gone', $messages);
        self::assertStringNotContainsString('Sample\\Tests\\Gone', $messages);
        self::assertStringNotContainsString('"tests"', $messages);
    }

    /**
     * @return list<string> the scope-conditioned channels this run reported, sorted
     */
    private function channelsOf(CommandTester $tester): array
    {
        $spoke = [];
        foreach ($this->violations($tester) as $violation) {
            $rule = (string) ($violation['rule'] ?? '');
            if (\in_array($rule, self::SCOPE_CONDITIONED, true) && !\in_array($rule, $spoke, true)) {
                $spoke[] = $rule;
            }
        }

        sort($spoke);

        return $spoke;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function violations(CommandTester $tester): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('violations', $payload, $tester->getErrorOutput());
        $violations = $payload['violations'];
        self::assertIsList($violations);

        $rows = [];
        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            $rows[] = $violation;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $autoload
     */
    private function check(array $autoload): CommandTester
    {
        file_put_contents($this->fixture . '/composer.json', json_encode($autoload, \JSON_THROW_ON_ERROR));

        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);

        $tester = new CommandTester($command);
        $previous = (string) getcwd();
        chdir($this->fixture);

        try {
            $tester->execute(
                [
                    'paths' => ['src'],
                    '--workers' => '0',
                    '--format' => 'json',
                    '--fail-on' => 'none',
                ],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        return $tester;
    }
}
