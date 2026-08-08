<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineMigrateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Support\Console\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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
     * @param 'baseline:generate'|'baseline:update'|'baseline:cleanup'|'baseline:migrate' $commandName
     * @param 'all-failed'|'partial' $coverage
     */
    #[Test]
    #[DataProvider('provideWritingCommandsAndCoverage')]
    public function itLeavesEveryExistingDestinationByteUnchangedOnIncompleteAnalysis(
        string $commandName,
        string $coverage,
    ): void {
        $before = $commandName === 'baseline:migrate'
            ? (string) json_encode([
                'version' => 5,
                'generated' => '2026-01-01T00:00:00+00:00',
                'violations' => [],
            ], \JSON_THROW_ON_ERROR)
            : '{"sentinel":"must remain byte-identical"}';
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
        foreach (['baseline:generate', 'baseline:update', 'baseline:cleanup', 'baseline:migrate'] as $command) {
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
            'symbol' => 'class:Good',
            'paths' => [$coverage === 'all-failed' ? $this->tempDir . '/Broken.php' : $this->tempDir],
            '--channel' => 'complexity.cyclomatic#complexity.cyclomatic.method',
        ]);

        self::assertSame(4, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Analysis incomplete:', $tester->getDisplay());
        self::assertStringNotContainsString('Unknown symbol', $tester->getDisplay());
        self::assertStringNotContainsString('Symbol:', $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function provideCoverage(): iterable
    {
        yield 'all failed' => ['all-failed'];
        yield 'partial' => ['partial'];
    }

    /** @param array<string, mixed> $input */
    private function execute(string $commandName, array $input): CommandTester
    {
        $commandClass = match ($commandName) {
            'baseline:generate' => BaselineGenerateCommand::class,
            'baseline:update' => BaselineUpdateCommand::class,
            'baseline:cleanup' => BaselineCleanupCommand::class,
            'baseline:migrate' => BaselineMigrateCommand::class,
            'baseline:explain' => BaselineExplainCommand::class,
            default => throw new LogicException('Unsupported baseline command fixture: ' . $commandName),
        };

        /** @var Command $command */
        $command = (new ContainerFactory())->create()->get($commandClass);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }
}
