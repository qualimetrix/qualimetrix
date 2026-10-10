<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use RuntimeException;
use Stringable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Reference;

/** Baseline lifecycle operations must never interpret a partial measured set. */
final class BaselineIncompleteAnalysisTest extends TestCase
{
    private string $tempDir;
    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-incomplete-');
        $this->baselinePath = $this->tempDir . '/baseline.json';

        file_put_contents($this->tempDir . '/Good.php', "<?php\nfinal class Good {}\n");
        file_put_contents($this->tempDir . '/Broken.php', "<?php\nfinal class Broken {\n");
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    /**
     * @param 'baseline:generate'|'baseline:update'|'baseline:cleanup' $commandName
     * @param 'all-failed'|'partial' $coverage
     */
    #[Test]
    #[DataProvider('provideWritingCommandsAndCoverage')]
    public function itLeavesEveryExistingDestinationByteUnchangedOnIncompleteAnalysis(
        string $commandName,
        string $coverage,
    ): void {
        $before = " \n" . json_encode([
            'version' => 14,
            'generated' => '2026-09-01T00:00:00+00:00',
            'scope' => [$coverage === 'all-failed' ? $this->tempDir . '/Broken.php' : $this->tempDir],
            'exclusions' => ['patterns' => [], 'generated' => 'excluded'],
            'entries' => [],
        ], \JSON_THROW_ON_ERROR) . "\n ";
        file_put_contents($this->baselinePath, $before);

        $tester = $this->execute($commandName, [
            'baseline' => $this->baselinePath,
            'paths' => [$coverage === 'all-failed' ? $this->tempDir . '/Broken.php' : $this->tempDir],
            '--force' => true,
            ...($commandName === 'baseline:cleanup' ? ['--remove' => ['000000000000']] : []),
        ]);

        self::assertSame(4, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Analysis incomplete:', $tester->getDisplay());
        self::assertSame($before, file_get_contents($this->baselinePath));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideWritingCommandsAndCoverage(): iterable
    {
        foreach (['baseline:generate', 'baseline:update', 'baseline:cleanup'] as $command) {
            yield $command . ' all failed' => [$command, 'all-failed'];
            yield $command . ' partial' => [$command, 'partial'];
        }
    }

    #[Test]
    #[DataProvider('provideCoverage')]
    public function itDoesNotCreateAMissingGenerateDestinationEvenUnderForce(string $coverage): void
    {
        $tester = $this->execute('baseline:generate', [
            'baseline' => $this->baselinePath,
            'paths' => [$coverage === 'all-failed' ? $this->tempDir . '/Broken.php' : $this->tempDir],
            '--force' => true,
        ]);

        self::assertSame(4, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileDoesNotExist($this->baselinePath);
    }

    #[Test]
    #[DataProvider('provideCoverage')]
    public function itDoesNotClassifyASymbolWhenExplainAnalysisIsIncomplete(string $coverage): void
    {
        $tester = $this->execute('baseline:explain', [
            'subject' => 'declaration:class:Good@src/Good.php:0',
            'paths' => [$coverage === 'all-failed' ? $this->tempDir . '/Broken.php' : $this->tempDir],
            '--channel' => 'complexity.ccn',
        ]);

        self::assertSame(4, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Analysis incomplete:', $tester->getDisplay());
        self::assertStringNotContainsString('Unknown subject', $tester->getDisplay());
        self::assertStringNotContainsString('Subject:', $tester->getDisplay());
    }

    #[Test]
    public function itRefusesAllBaselineLifecycleOperationsAfterARealLateDuplicationReadFailure(): void
    {
        unlink($this->tempDir . '/Broken.php');
        $lostDirectory = $this->tempDir . '/lost';
        $gone = $lostDirectory . '/Gone.php';
        $before = " \n" . json_encode([
            'version' => 14,
            'generated' => '2026-09-01T00:00:00+00:00',
            'scope' => [$this->tempDir],
            'exclusions' => ['patterns' => [], 'generated' => 'excluded'],
            'entries' => [],
        ], \JSON_THROW_ON_ERROR) . "\n ";

        foreach (['baseline:generate', 'baseline:update', 'baseline:cleanup', 'baseline:explain', 'missing generate'] as $case) {
            mkdir($lostDirectory);
            file_put_contents($gone, "<?php\nfinal class Gone {}\n");
            file_put_contents($this->tempDir . '/Other.php', "<?php\nfinal class Other {}\n");
            if ($case === 'missing generate') {
                @unlink($this->baselinePath);
            } else {
                file_put_contents($this->baselinePath, $before);
            }

            $logger = new class ($gone, $lostDirectory) extends AbstractLogger {
                public bool $fired = false;

                public function __construct(private string $gone, private string $directory) {}

                public function log($level, string|Stringable $message, array $context = []): void
                {
                    if (!$this->fired && $level === 'info' && (string) $message === 'Collection completed') {
                        $this->fired = true;
                        unlink($this->gone);
                        rmdir($this->directory);
                    }
                }
            };
            $command = $case === 'missing generate' ? 'baseline:generate' : $case;
            $input = [
                'paths' => [$this->tempDir],
                '--only-rule' => ['duplication.clone:file'],
                '--no-cache' => true,
                '--workers' => '0',
            ];
            if ($command === 'baseline:explain') {
                $input += [
                    'subject' => 'declaration:class:Good@src/Good.php:0',
                    '--channel' => 'duplication.clone',
                ];
            } else {
                $input += [
                    'baseline' => $this->baselinePath,
                    '--force' => true,
                    ...($command === 'baseline:cleanup' ? ['--remove' => ['000000000000']] : []),
                ];
            }

            $tester = $this->execute($command, $input, $logger);

            self::assertTrue($logger->fired, $case . ': Collection did not complete before the read refusal.');
            self::assertSame(4, $tester->getStatusCode(), $case . ': ' . $tester->getDisplay());
            self::assertStringContainsString('Analysis incomplete:', $tester->getDisplay());
            self::assertStringContainsString('unreadable-file: 1', $tester->getDisplay());
            self::assertStringNotContainsString('parse:', $tester->getDisplay());
            if ($case === 'missing generate') {
                self::assertFileDoesNotExist($this->baselinePath);
            } else {
                self::assertSame($before, file_get_contents($this->baselinePath));
            }
            if ($command === 'baseline:explain') {
                self::assertStringNotContainsString('Unknown subject', $tester->getDisplay());
                self::assertStringNotContainsString('Subject:', $tester->getDisplay());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideCoverage(): iterable
    {
        yield 'all failed' => ['all-failed'];
        yield 'partial' => ['partial'];
    }

    /** @param array<string, mixed> $input */
    private function execute(string $commandName, array $input, ?AbstractLogger $logger = null): CommandTester
    {
        $commandClass = match ($commandName) {
            'baseline:generate' => BaselineGenerateCommand::class,
            'baseline:update' => BaselineUpdateCommand::class,
            'baseline:cleanup' => BaselineCleanupCommand::class,
            'baseline:explain' => BaselineExplainCommand::class,
            default => throw new LogicException('Unsupported baseline command fixture: ' . $commandName),
        };

        $factory = new ContainerFactory();
        if ($logger === null) {
            $container = $factory->create();
        } else {
            $container = $factory->configure();
            $container->register('test.late_duplication_logger')->setSynthetic(true)->setPublic(true);
            $container->getDefinition('qmx.run.analysis_pipeline')
                ->setArgument(11, new Reference('test.late_duplication_logger'));
            $container->compile();
            $container->set('test.late_duplication_logger', $logger);
        }
        /** @var Command $command */
        $command = $container->get($commandClass);
        $tester = new CommandTester($command);
        $previous = getcwd();
        if ($previous === false || !chdir($this->tempDir)) {
            throw new RuntimeException('Cannot enter the baseline fixture root');
        }

        try {
            $tester->execute($input);
        } finally {
            if (!chdir($previous)) {
                throw new RuntimeException('Cannot restore the working directory');
            }
        }

        return $tester;
    }
}
