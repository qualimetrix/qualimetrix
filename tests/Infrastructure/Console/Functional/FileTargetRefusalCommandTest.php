<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignment;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentInspectorInterface;
use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Infrastructure\Console\Support\SplitStreamConsoleOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Application::class)]
#[CoversClass(LayerAssignmentCommand::class)]
final class FileTargetRefusalCommandTest extends TestCase
{
    #[Test]
    public function itClassifiesEnvironmentFailureAtApplicationExit(): void
    {
        $failure = self::failure();
        $errorStream = new ErrorStream();
        $application = new Application($errorStream, new RefusalPresenter($errorStream), new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader());
        $application->setAutoExit(false);
        $application->addCommand(new class ($failure) extends Command {
            public function __construct(private readonly Exception $failure)
            {
                parent::__construct('environment-failure');
            }

            protected function configure(): void
            {
                $this->addOption('format', null, InputOption::VALUE_REQUIRED);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw $this->failure;
            }
        });

        $json = new SplitStreamConsoleOutput(stderrDecorated: false);
        $exit = $application->doRun(new ArrayInput(['command' => 'environment-failure', '--format' => 'json']), $json);

        self::assertSame(3, $exit);
        self::assertSame('', $json->errorOutputContent());
        self::assertSame([
            'error' => 'Environment error: storage unavailable',
            'exit_code' => 3,
            'position' => null,
            'source' => null,
        ], json_decode($json->standardOutputContent(), true, flags: \JSON_THROW_ON_ERROR));

        $text = new SplitStreamConsoleOutput(stderrDecorated: false);
        self::assertSame(3, $application->doRun(new ArrayInput(['command' => 'environment-failure']), $text));
        self::assertSame('', $text->standardOutputContent());
        self::assertStringContainsString('Environment error: storage unavailable', $text->errorOutputContent());
    }

    #[Test]
    public function itClassifiesEnvironmentFailureAtLayerAssignmentExit(): void
    {
        $originalCwd = getcwd();
        self::assertIsString($originalCwd);
        $root = sys_get_temp_dir() . '/qmx-layer-environment-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0755, true);
        file_put_contents($root . '/src/Example.php', "<?php\nnamespace Example; final class Subject {}\n");
        file_put_contents($root . '/qmx.yaml', "paths: ['{$root}/src']\narchitecture:\n  layers:\n    - name: example\n      patterns: ['Example\\**']\n  allow:\n    example: []\n  coverage-gap: ignore\n");
        chdir($root);

        try {
            $container = (new ContainerFactory())->configure();
            $inspector = new class implements LayerAssignmentInspectorInterface {
                public function inspect(DependencyGraphInterface $graph, iterable $classUniverse, SymbolPath $subject): LayerAssignment
                {
                    throw FileTargetRefusalCommandTest::failure();
                }
            };
            $container->register('test.layer-inspector', $inspector::class)->setSynthetic(true)->setPublic(true);
            $container->setAlias(LayerAssignmentInspectorInterface::class, 'test.layer-inspector')->setPublic(true);
            $container->compile();
            $container->set('test.layer-inspector', $inspector);
            $command = $container->get(LayerAssignmentCommand::class);
            self::assertInstanceOf(LayerAssignmentCommand::class, $command);

            $tester = new CommandTester($command);
            $exit = $tester->execute([
                'fqn' => 'Example\\Subject',
                '--config' => $root . '/qmx.yaml',
                '--format' => 'json',
            ], ['capture_stderr_separately' => true]);

            self::assertSame(3, $exit, $tester->getDisplay() . $tester->getErrorOutput());
            self::assertSame('', $tester->getErrorOutput());
            self::assertSame([
                'error' => 'Environment error: storage unavailable',
                'exit_code' => 3,
                'position' => null,
                'source' => null,
            ], json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR));
        } finally {
            chdir($originalCwd);
            self::removeDirectory($root);
        }
    }

    public static function failure(): Exception&EnvironmentFailureInterface
    {
        return new class ('storage unavailable') extends Exception implements EnvironmentFailureInterface {};
    }

    private static function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
