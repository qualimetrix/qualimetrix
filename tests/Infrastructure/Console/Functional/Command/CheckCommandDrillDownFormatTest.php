<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A `--namespace`/`--class` selection lists part of the run's findings, and
 * the report has to say so. `gitlab` and `checkstyle` have no place for that:
 * every entry they publish is a finding to their consumer, so a note there
 * turned a clean selection into one issue, and no note turned a failing run
 * into a clean report. The pair is refused before the analysis runs.
 */
#[CoversClass(CheckCommand::class)]
final class CheckCommandDrillDownFormatTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-drill-down-format-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/src', 0o755, true);
        file_put_contents($this->directory . '/src/A.php', "<?php\n\nnamespace App;\n\nfinal class A\n{\n}\n");
        file_put_contents($this->directory . '/qmx.yaml', "format: summary\n");
    }

    protected function tearDown(): void
    {
        foreach (['src/A.php', 'qmx.yaml', 'checkstyle.yaml'] as $file) {
            if (file_exists($this->directory . '/' . $file)) {
                unlink($this->directory . '/' . $file);
            }
        }
        rmdir($this->directory . '/src');
        rmdir($this->directory);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function provideSelectionsTheFormatCannotMark(): iterable
    {
        foreach (['gitlab', 'checkstyle'] as $format) {
            yield $format . ' --namespace' => [$format, '--namespace', 'subtree:App'];
            yield $format . ' --class' => [$format, '--class', 'App\A'];
        }
    }

    #[Test]
    #[DataProvider('provideSelectionsTheFormatCannotMark')]
    public function itRefusesASelectionTheFormatCannotMarkBeforeAnalysis(string $format, string $selector, string $value): void
    {
        [$tester, $pipeline] = $this->tester();

        $exit = $tester->execute(
            ['paths' => [$this->directory . '/src'], '--config' => $this->directory . '/qmx.yaml', '--format' => $format, $selector => $value],
            ['capture_stderr_separately' => true],
        );
        $said = $tester->getDisplay() . $tester->getErrorOutput();

        self::assertSame(3, $exit, $said);
        self::assertSame(0, $pipeline->calls, 'The analysis ran before the pair was refused.');
        // gitlab spells the refusal as a JSON envelope, which escapes the quotes.
        self::assertStringContainsString($format, $said);
        self::assertStringContainsString('has no place to say the report is a partial view', $said);
        self::assertStringContainsString($selector, $said);
    }

    /** The format a configuration file selects is held to the same rule as one written on the command line. */
    #[Test]
    public function itRefusesTheSelectionUnderAFormatTheConfigurationSelects(): void
    {
        file_put_contents($this->directory . '/checkstyle.yaml', "format: checkstyle\n");
        [$tester, $pipeline] = $this->tester();

        $exit = $tester->execute(
            ['paths' => [$this->directory . '/src'], '--config' => $this->directory . '/checkstyle.yaml', '--namespace' => 'subtree:App'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $exit, $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(0, $pipeline->calls);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function provideLawfulNeighbours(): iterable
    {
        yield 'gitlab without a selection' => [['--format' => 'gitlab']];
        yield 'checkstyle without a selection' => [['--format' => 'checkstyle']];
        yield 'a selection under github, which says it in a notice' => [['--format' => 'github', '--namespace' => 'subtree:App']];
        yield 'a selection under sarif, which says it in a notification' => [['--format' => 'sarif', '--namespace' => 'subtree:App']];
    }

    /** @param array<string, string> $options */
    #[Test]
    #[DataProvider('provideLawfulNeighbours')]
    public function itStillRunsTheLawfulNeighbours(array $options): void
    {
        [$tester, $pipeline] = $this->tester();

        $exit = $tester->execute(
            ['paths' => [$this->directory . '/src'], '--config' => $this->directory . '/qmx.yaml', ...$options],
            ['capture_stderr_separately' => true],
        );

        self::assertNotSame(3, $exit, $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(1, $pipeline->calls);
    }

    /** @return array{CommandTester, object{calls: int}} */
    private function tester(): array
    {
        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $original */
        $original = $container->get(CheckCommand::class);
        $reflection = new ReflectionClass(CheckCommand::class);
        $property = static fn(string $name): mixed => $reflection->getProperty($name)->getValue($original);

        /** @var AnalysisPipelineInterface $analyzer */
        $analyzer = $property('analyzer');
        $pipeline = new class ($analyzer) implements AnalysisPipelineInterface {
            public int $calls = 0;

            public function __construct(private readonly AnalysisPipelineInterface $delegate) {}

            public function analyze(RunConfiguration $configuration, ?FileDiscoveryInterface $customFileDiscovery = null): AnalysisResult
            {
                ++$this->calls;

                return $this->delegate->analyze($configuration, $customFileDiscovery);
            }
        };

        $command = new CheckCommand(
            $pipeline,
            $property('findingFilterOrchestrator'),
            $property('runtimeConfigurator'),
            $property('resultPresenter'),
            $property('ruleInputValidator'),
            $property('checkScopeResolver'),
            $property('configurationInputAdapter'),
            $property('configurationResolvers'),
            $property('refusalPresenter'),
        );

        return [new CommandTester($command), $pipeline];
    }
}
