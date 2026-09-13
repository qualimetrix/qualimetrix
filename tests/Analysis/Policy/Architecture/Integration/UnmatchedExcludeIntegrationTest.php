<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `architecture.unmatched-exclude` through the real command, because the whole
 * point of the channel is what a run *says*.
 *
 * A unit test of the predicate would pass with the channel never reaching a
 * report: the finding is built inside {@see LayerViolationRule}, but the
 * evidence it reads is filled by a walk two classes away, and the level and
 * severity it publishes at only exist once the container has assembled the
 * channel registry. So every case here runs `check` end to end and reads the
 * JSON report and the exit code.
 *
 * The pair is the test. A run where the clause removes nothing must report,
 * and a run where the same shape of clause removes a class must be silent —
 * a producer that always fires would pass the first half alone.
 */
#[CoversClass(LayerViolationRule::class)]
final class UnmatchedExcludeIntegrationTest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-unmatched-exclude-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src/Controller', 0o755, true);
        mkdir($this->fixture . '/src/Controller/Legacy', 0o755, true);

        // Without it the run reports an uncovered-autoload configuration
        // warning, whose exit code 2 would drown the 0-vs-2 distinction the
        // fail-on case rests on.
        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );

        $this->writeClass('Controller/UserController.php', 'Sample\\Controller', 'UserController');
        $this->writeClass('Controller/Legacy/OldController.php', 'Sample\\Controller\\Legacy', 'OldController');
    }

    protected function tearDown(): void
    {
        foreach ([
            '/src/Controller/Legacy/OldController.php',
            '/src/Controller/UserController.php',
            '/composer.json',
        ] as $file) {
            @unlink($this->fixture . $file);
        }

        @unlink($this->fixture . '/qmx.yaml');

        foreach (['/src/Controller/Legacy', '/src/Controller', '/src', ''] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    /**
     * Half one of the pair: the clause matches nothing, the layer holds two
     * classes, the run says so.
     */
    #[Test]
    public function itReportsAnExcludeClauseThatRemovedNothingFromALayerThatMatched(): void
    {
        $tester = $this->check($this->config("        patterns: ['Sample\\Controller\\NoSuchSubtree\\**']"));

        $findings = $this->findingsOn($tester, LayerViolationRule::UNMATCHED_EXCLUDE_NAME);

        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]['severity'] ?? null);
        self::assertStringContainsString('removed no class from it', (string) ($findings[0]['message'] ?? ''));
        self::assertStringContainsString('NoSuchSubtree', (string) ($findings[0]['message'] ?? ''));
    }

    /**
     * Half two: the same clause shape, one class actually removed, silence.
     *
     * `OldController` matches no other layer, deliberately — a tally taken
     * after the "matched nothing" branch of the class walk would still look
     * right if the excluded class were caught by some other layer.
     */
    #[Test]
    public function itStaysSilentWhenTheExcludeClauseRemovedAClass(): void
    {
        $tester = $this->check($this->config("        patterns: ['Sample\\Controller\\Legacy\\**']"));

        self::assertSame([], $this->findingsOn($tester, LayerViolationRule::UNMATCHED_EXCLUDE_NAME));
    }

    /**
     * A layer whose positive criteria caught nothing has an empty exclusion
     * count for a reason that has nothing to do with the clause: the clause
     * was never evaluated. `architecture.unreachable-layer` is what reports
     * that layer, and this channel must not say the same thing twice.
     */
    #[Test]
    public function itDoesNotReportALayerWhosePositiveCriteriaMatchedNothing(): void
    {
        $yaml = <<<'YAML'
            architecture:
              layers:
                - name: controller
                  patterns: ['Sample\Controller\**']
                - name: nothing
                  patterns: ['Sample\NoSuchLayerSubject\**']
                  exclude:
                    patterns: ['Sample\NoSuchLayerSubject\Generated\**']
              allow:
                controller: []
                nothing: []
              coverage-gap: ignore
            YAML;

        $tester = $this->check($yaml);

        self::assertSame([], $this->findingsOn($tester, LayerViolationRule::UNMATCHED_EXCLUDE_NAME));
        self::assertNotSame(
            [],
            $this->findingsOn($tester, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME),
            'The layer must still be reported — by the channel that owns that question.',
        );
    }

    /** A layer the author has said does not exist yet is not a mistake. */
    #[Test]
    public function itSkipsALayerDeclaredPending(): void
    {
        $yaml = <<<'YAML'
            architecture:
              layers:
                - name: controller
                  patterns: ['Sample\Controller\**']
                  pending: true
                  exclude:
                    patterns: ['Sample\Controller\NoSuchSubtree\**']
              allow:
                controller: []
              coverage-gap: ignore
            YAML;

        self::assertSame(
            [],
            $this->findingsOn($this->check($yaml), LayerViolationRule::UNMATCHED_EXCLUDE_NAME),
        );
    }

    /**
     * The channel is the rule's, not
     * {@see \Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface}'s,
     * and this is the run that proves it rather than the declaration that
     * claims it: a configuration-error channel fails the run regardless of
     * `fail_on`, so a fixture producing this finding under `--fail-on=none`
     * would exit 2 if the channel had been declared by
     * {@see LayerDeclarationValidator} instead.
     */
    #[Test]
    public function itLeavesTheRunGreenUnderFailOnNone(): void
    {
        $tester = $this->check($this->config("        patterns: ['Sample\\Controller\\NoSuchSubtree\\**']"));

        self::assertNotSame([], $this->findingsOn($tester, LayerViolationRule::UNMATCHED_EXCLUDE_NAME));
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
    }

    /**
     * The same fixture with `--fail-on=warning`: the finding is an ordinary
     * warning subject to the gate, which is the other half of "not a
     * configuration error" — it passes the gate under `none` and trips it
     * under `warning`. A validator channel would trip it under both, so it is
     * the pair that carries the proof, not either run alone.
     */
    #[Test]
    public function itFailsTheRunUnderFailOnWarning(): void
    {
        $tester = $this->check(
            $this->config("        patterns: ['Sample\\Controller\\NoSuchSubtree\\**']"),
            ['--fail-on' => 'warning'],
        );

        self::assertSame(2, $tester->getStatusCode(), 'Findings at or above --fail-on exit 2.');
    }

    /** The five declaration verdicts stayed five: nothing moved into the validator. */
    #[Test]
    public function itLeavesTheDeclarationValidatorWithItsFiveChannels(): void
    {
        self::assertSame(
            [
                'architecture.coverage-gap',
                'architecture.unreachable-layer',
                'architecture.potential-shadow',
                'architecture.empty-template',
                'architecture.pending-layer-matched',
            ],
            array_keys(LayerDeclarationValidator::channelDeclarations()),
        );
    }

    /**
     * A template layer is one `exclude:` clause in the file and one layer per
     * observed module after expansion. Judged instance by instance, a clause
     * doing its work in one module was reported against every module with
     * nothing to exclude — and its recommendation, "drop the clause", would
     * have broken the module where it works. The pair: the same template with
     * a clause that fires somewhere is silent, and one that fires nowhere is
     * reported once, against the declaration.
     */
    #[Test]
    public function itJudgesATemplateExcludeClauseOnceAcrossEveryInstance(): void
    {
        mkdir($this->fixture . '/src/Module/Alpha/Domain', 0o755, true);
        mkdir($this->fixture . '/src/Module/Beta/Domain', 0o755, true);
        $this->writeClass('Module/Alpha/Domain/Order.php', 'Sample\\Module\\Alpha\\Domain', 'Order');
        $this->writeClass('Module/Alpha/Domain/OrderGenerated.php', 'Sample\\Module\\Alpha\\Domain', 'OrderGenerated');
        $this->writeClass('Module/Beta/Domain/Thing.php', 'Sample\\Module\\Beta\\Domain', 'Thing');

        $working = $this->template('Generated');
        $inert = $this->template('NothingLikeThis');

        self::assertSame(
            [],
            $this->findingsOn($this->check($working), LayerViolationRule::UNMATCHED_EXCLUDE_NAME),
            'Beta holds nothing to exclude, but the clause is not the author\'s mistake — it works in Alpha.',
        );

        $reported = $this->findingsOn($this->check($inert), LayerViolationRule::UNMATCHED_EXCLUDE_NAME);

        self::assertCount(1, $reported, 'One clause, one finding, however many layers it expanded to.');
        self::assertStringContainsString('domain-{module}', (string) ($reported[0]['message'] ?? ''));

        foreach (['/src/Module/Alpha/Domain/Order.php', '/src/Module/Alpha/Domain/OrderGenerated.php', '/src/Module/Beta/Domain/Thing.php'] as $file) {
            @unlink($this->fixture . $file);
        }

        foreach (['/src/Module/Alpha/Domain', '/src/Module/Alpha', '/src/Module/Beta/Domain', '/src/Module/Beta', '/src/Module'] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    /**
     * The precondition ADR 0061 states for every channel of this row, which
     * this one shipped without: on a run narrowed below the project's
     * production autoload roots the layer's positive criteria can match inside
     * the slice while the classes the clause was written for sit outside it.
     * Paired with {@see itReportsAnExcludeClauseThatRemovedNothingFromALayerThatMatched()},
     * the same clause on a run that covers those roots.
     */
    #[Test]
    public function itStaysSilentOnARunNarrowedBelowTheAutoloadRoots(): void
    {
        $yaml = $this->config("        patterns: ['Sample\\Controller\\NoSuchSubtree\\**']");

        self::assertSame(
            [],
            $this->findingsOn(
                $this->check($yaml, paths: ['src/Controller']),
                LayerViolationRule::UNMATCHED_EXCLUDE_NAME,
            ),
        );
    }

    private function template(string $suffix): string
    {
        return <<<YAML
            architecture:
              layers:
                - name: 'domain-{module}'
                  patterns: ['Sample\\Module\\{module}\\Domain\\**']
                  exclude:
                    suffix: ['{$suffix}']
                - name: controller
                  patterns: ['Sample\\Controller\\**']
              coverage-gap: ignore
            YAML;
    }

    private function config(string $excludePatternsLine): string
    {
        return <<<YAML
            architecture:
              layers:
                - name: controller
                  patterns: ['Sample\\Controller\\**']
                  exclude:
            {$excludePatternsLine}
              allow:
                controller: []
              coverage-gap: ignore
            YAML;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findingsOn(CommandTester $tester, string $channel): array
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
     * @param array<string, mixed> $options
     * @param list<string> $paths
     */
    private function check(string $yaml, array $options = [], array $paths = ['src']): CommandTester
    {
        file_put_contents($this->fixture . '/qmx.yaml', $yaml . "\n");

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

    private function writeClass(string $relativePath, string $namespace, string $class): void
    {
        file_put_contents(
            $this->fixture . '/src/' . $relativePath,
            \sprintf("<?php\n\nnamespace %s;\n\nclass %s\n{\n    public function run(): void {}\n}\n", $namespace, $class),
        );
    }
}
