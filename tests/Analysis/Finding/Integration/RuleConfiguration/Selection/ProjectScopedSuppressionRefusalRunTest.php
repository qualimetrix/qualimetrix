<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration\RuleConfiguration\Selection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\RuleConfiguration\Selection\RuleEnablementResolver;
use Qualimetrix\Infrastructure\Console\Baseline\Generation\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\Console\Directives\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\GraphExport\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\RuleListing\RulesCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A per-rule `suppress_namespaces` / `suppress_paths` list on a producer whose
 * channels are all project-scoped, through the real container and the commands
 * that resolve the finding configuration.
 *
 * The refusal lives in the configuration stage, so a test of the judgement
 * alone would pass with the file scope never wired into the resolver.
 */
#[CoversClass(RuleEnablementResolver::class)]
final class ProjectScopedSuppressionRefusalRunTest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-project-scoped-suppression-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);
        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );
        file_put_contents($this->fixture . '/src/Service.php', "<?php\n\nnamespace Sample;\n\nclass Service {}\n");
    }

    protected function tearDown(): void
    {
        foreach (['/src/Service.php', '/composer.json', '/qmx.yaml', '/baseline.json', '/baseline.json.lock'] as $file) {
            @unlink($this->fixture . $file);
        }
        foreach (['/src', ''] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    /** @return iterable<string, array{class-string<Command>, array<string, mixed>}> */
    public static function commands(): iterable
    {
        yield 'check' => [CheckCommand::class, ['paths' => ['src'], '--workers' => '0', '--no-cache' => true]];
        yield 'directives' => [DirectivesCommand::class, ['paths' => ['src']]];
        yield 'baseline:generate' => [BaselineGenerateCommand::class, ['baseline' => 'baseline.json', 'paths' => ['src'], '--workers' => '0', '--no-cache' => true]];
    }

    /**
     * @param class-string<Command> $command
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('commands')]
    public function itRefusesTheCycleRuleSuppressionAtEveryCommandThatResolvesTheConfiguration(string $command, array $arguments): void
    {
        $tester = $this->execute($command, $arguments, "rules:\n  architecture.circular-dependency:\n    suppress_namespaces:\n      - {subtree: Sample}\n");

        $output = $tester->getDisplay() . $tester->getErrorOutput();
        self::assertSame(3, $tester->getStatusCode(), $output);
        self::assertStringContainsString('Option "suppress_namespaces" for rule "architecture.circular-dependency"', $output);
        self::assertStringContainsString('project-level', $output);
        self::assertFileDoesNotExist($this->fixture . '/baseline.json');
    }

    /**
     * `rules` judges the document from its own call site and lets the refusal
     * reach the application's exit-code ladder instead of returning a status.
     */
    #[Test]
    public function itRefusesTheCycleRuleSuppressionInTheRuleListing(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Option "suppress_namespaces" for rule "architecture.circular-dependency"');

        $this->execute(RulesCommand::class, [], "rules:\n  architecture.circular-dependency:\n    suppress_namespaces:\n      - {subtree: Sample}\n");
    }

    /** The graph export never interprets rule options, so the same file is accepted there. */
    #[Test]
    public function itLeavesGraphExportUnaffected(): void
    {
        $tester = $this->execute(
            GraphExportCommand::class,
            ['paths' => ['src'], '--workers' => '0', '--no-cache' => true],
            "rules:\n  architecture.circular-dependency:\n    suppress_namespaces:\n      - {subtree: Sample}\n",
        );

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
    }

    /** @return iterable<string, array{string, string}> */
    public static function authoredLists(): iterable
    {
        yield 'cycle paths' => ['architecture.circular-dependency', 'suppress_paths'];
        yield 'unused baseline entry paths' => ['baseline.unused-entry', 'suppress_paths'];
        yield 'unused baseline entry namespaces' => ['baseline.unused-entry', 'suppress_namespaces'];
    }

    #[Test]
    #[DataProvider('authoredLists')]
    public function itRefusesTheOtherProjectOnlyProducerAndOptionPairs(string $rule, string $option): void
    {
        $selector = $option === 'suppress_paths' ? 'src' : 'Sample';
        $tester = $this->execute(CheckCommand::class, ['paths' => ['src'], '--workers' => '0', '--no-cache' => true], \sprintf("rules:\n  %s:\n    %s:\n      - {subtree: %s}\n", $rule, $option, $selector));

        $output = $tester->getDisplay() . $tester->getErrorOutput();
        self::assertSame(3, $tester->getStatusCode(), $output);
        self::assertStringContainsString(\sprintf('Option "%s" for rule "%s"', $option, $rule), $output);
    }

    #[Test]
    public function itRefusesTheOptionWrittenThroughRuleOpt(): void
    {
        $tester = $this->execute(
            CheckCommand::class,
            ['paths' => ['src'], '--workers' => '0', '--no-cache' => true, '--rule-opt' => ['architecture.circular-dependency:suppress-namespaces=subtree:Sample']],
            "cache:\n  enabled: false\n",
        );

        $output = $tester->getDisplay() . $tester->getErrorOutput();
        self::assertSame(3, $tester->getStatusCode(), $output);
        self::assertStringContainsString('Option "suppress_namespaces" for rule "architecture.circular-dependency"', $output);
    }

    /** The mixed producer keeps its keys: one of its channels is about a class. */
    #[Test]
    public function itAcceptsAProducerThatHasAFileScopedChannel(): void
    {
        $tester = $this->execute(CheckCommand::class, ['paths' => ['src'], '--workers' => '0', '--no-cache' => true], "rules:\n  cohesion.lcom:\n    suppress_namespaces:\n      - {subtree: Sample}\n");

        self::assertNotSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
    }

    #[Test]
    public function itAcceptsAnEmptyList(): void
    {
        $tester = $this->execute(CheckCommand::class, ['paths' => ['src'], '--workers' => '0', '--no-cache' => true], "rules:\n  architecture.circular-dependency:\n    suppress_namespaces: []\n    suppress_paths: null\n");

        self::assertNotSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $arguments
     */
    private function execute(string $commandClass, array $arguments, string $yaml): CommandTester
    {
        file_put_contents($this->fixture . '/qmx.yaml', $yaml);
        $command = (new ContainerFactory())->create()->get($commandClass);
        self::assertInstanceOf($commandClass, $command);
        $tester = new CommandTester($command);
        $previous = (string) getcwd();
        chdir($this->fixture);

        try {
            $tester->execute(
                $arguments,
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        return $tester;
    }
}
