<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

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
        foreach (['src/A.php', 'src/script.php', 'src/withclass.php', 'src/multi.php', 'src/global.php', 'qmx.yaml', 'checkstyle.yaml'] as $file) {
            if (file_exists($this->directory . '/' . $file)) {
                unlink($this->directory . '/' . $file);
            }
        }
        rmdir($this->directory . '/src');
        (new Filesystem())->remove($this->directory . '/.qmx-cache');
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

        $exit = $this->executeInFixture(
            $tester,
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

        $exit = $this->executeInFixture(
            $tester,
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

        $exit = $this->executeInFixture(
            $tester,
            ['paths' => [$this->directory . '/src'], '--config' => $this->directory . '/qmx.yaml', ...$options],
            ['capture_stderr_separately' => true],
        );

        self::assertNotSame(3, $exit, $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(1, $pipeline->calls);
    }

    #[Test]
    public function itPublishesAndSelectsLexicalNamespacesOfFileFindings(): void
    {
        file_put_contents($this->directory . '/src/script.php', "<?php\nnamespace App\\Svc;\neval('return 1;');\n");
        file_put_contents($this->directory . '/src/withclass.php', "<?php\nnamespace App\\Svc;\nfinal class Holder {}\neval('return 2;');\n");
        file_put_contents($this->directory . '/src/multi.php', "<?php\nnamespace A { eval('return 3;'); }\nnamespace B { eval('return 4;'); }\n");
        file_put_contents($this->directory . '/src/global.php', "<?php\neval('return 5;');\n");

        [$tester] = $this->tester();
        $input = ['paths' => [$this->directory . '/src'], '--config' => $this->directory . '/qmx.yaml', '--format' => 'json', '--no-cache' => true, '--workers' => '0'];
        $this->executeInFixture($tester, $input, ['capture_stderr_separately' => true]);
        $records = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR)['violations'];
        $byFile = [];
        $multiRecords = [];
        foreach ($records as $record) {
            if ($record['rule'] === 'code-smell.eval') {
                $byFile[basename($record['file'])] = $record;
                if (basename($record['file']) === 'multi.php') {
                    $multiRecords[] = $record;
                }
            }
        }

        self::assertSame('App\\Svc', $byFile['script.php']['namespace']);
        self::assertSame(['App\\Svc'], $byFile['script.php']['namespaces']);
        self::assertSame('App\\Svc', $byFile['withclass.php']['namespace']);
        self::assertCount(2, $multiRecords);
        foreach ($multiRecords as $record) {
            self::assertSame(['A', 'B'], $record['namespaces']);
        }
        self::assertSame('', $byFile['global.php']['namespace']);

        [$filtered] = $this->tester();
        $this->executeInFixture($filtered, [...$input, '--namespace' => 'subtree:App\\Svc'], ['capture_stderr_separately' => true]);
        $filteredRecords = json_decode($filtered->getDisplay(), true, flags: \JSON_THROW_ON_ERROR)['violations'];
        $files = [];
        foreach ($filteredRecords as $record) {
            if ($record['rule'] === 'code-smell.eval') {
                $files[] = basename($record['file']);
            }
        }
        sort($files);
        self::assertSame(['script.php', 'withclass.php'], $files);
    }

    #[Test]
    public function itBindsAndSelectsANamespaceOpenedOnlyByADeclarationFreeFile(): void
    {
        file_put_contents($this->directory . '/src/script.php', "<?php\nnamespace Only\\Script;\neval('return 1;');\n");
        [$tester, $pipeline] = $this->tester();
        $exit = $this->executeInFixture($tester, [
            'paths' => [$this->directory . '/src'],
            '--config' => $this->directory . '/qmx.yaml',
            '--format' => 'json',
            '--no-cache' => true,
            '--workers' => '0',
            '--namespace' => 'exact:Only\\Script',
        ], ['capture_stderr_separately' => true]);

        self::assertNotSame(3, $exit, $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(1, $pipeline->calls);
        $records = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR)['violations'];
        $evalRecords = array_values(array_filter($records, static fn(array $record): bool => $record['rule'] === 'code-smell.eval'));
        self::assertCount(1, $evalRecords);
        self::assertSame('src/script.php', $evalRecords[0]['file']);
        self::assertSame('Only\\Script', $evalRecords[0]['namespace']);
    }

    /** @param array<string, mixed> $input
     * @param array<string, mixed> $options
     */
    private function executeInFixture(CommandTester $tester, array $input, array $options): int
    {
        $previous = getcwd();
        self::assertNotFalse($previous);
        chdir($this->directory);
        try {
            return $tester->execute($input, $options);
        } finally {
            chdir($previous);
        }
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

            public function analyze(RunConfiguration $configuration): AnalysisResult
            {
                ++$this->calls;

                return $this->delegate->analyze($configuration);
            }
        };

        $command = \Qualimetrix\Tests\Infrastructure\Console\Support\CheckCommandFixture::replace($original, [
            'analyzer' => $pipeline,
            'runtimeConfigurator' => $property('runtimeConfigurator'),
            'checkScopeResolver' => $property('checkScopeResolver'),
            'configurationInputAdapter' => $property('configurationInputAdapter'),
            'configurationResolvers' => $property('configurationResolvers'),
            'runTargetSession' => $property('runTargetSession'),
        ]);

        return [new CommandTester($command), $pipeline];
    }
}
