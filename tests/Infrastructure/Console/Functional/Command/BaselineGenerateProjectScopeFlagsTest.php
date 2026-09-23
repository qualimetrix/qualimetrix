<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\BaselineCommandDefinition;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `--include-autoload-dev` and `--include-generated` decide what the project
 * is, so a baseline captured with one measures the same set `check` measures
 * with it — and a baseline captured without it does not, which `check` then
 * reports. Accepting either flag without it reaching the measured set would
 * be the inert-option failure the shared baseline input exists to prevent.
 *
 * The test code is declared through `classmap`, not PSR-4: the run's default
 * paths must reach every autoload form the scope is judged against.
 */
#[CoversClass(BaselineCommandDefinition::class)]
#[CoversClass(BaselineGenerateCommand::class)]
final class BaselineGenerateProjectScopeFlagsTest extends TestCase
{
    private const string CHANNEL = 'code-smell.eval';

    private string $tempDir;
    private string $baselinePath;

    protected function setUp(): void
    {
        $created = sys_get_temp_dir() . '/qmx-baseline-autoload-dev-' . bin2hex(random_bytes(8));
        mkdir($created, 0o777, true);
        $this->tempDir = (string) realpath($created);
        $this->baselinePath = $this->tempDir . '/baseline.json';

        mkdir($this->tempDir . '/src');
        mkdir($this->tempDir . '/tests');
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Demo\\' => 'src/']],
            'autoload-dev' => ['classmap' => ['tests/']],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($this->tempDir . '/src/Demo.php', "<?php\nnamespace Demo;\nfinal class Demo {}\n");
        file_put_contents($this->tempDir . '/tests/Fixture.php', "<?php\nfinal class Fixture { public function run(): mixed { return eval('return 1;'); } }\n");
    }

    protected function tearDown(): void
    {
        self::remove($this->tempDir);
    }

    #[Test]
    public function itCapturesWithTheFlagWhatCheckMeasuresWithIt(): void
    {
        $generate = $this->execute(BaselineGenerateCommand::class, [
            'baseline' => $this->baselinePath,
            '--include-autoload-dev' => true,
            '--only-rule' => [self::CHANNEL],
        ]);
        self::assertSame(0, $generate->getStatusCode(), $generate->getDisplay());
        self::assertContains(self::CHANNEL, self::capturedChannels($this->baselinePath));

        $check = $this->check();

        self::assertSame(0, $check->getStatusCode(), $check->getDisplay());
        self::assertStringNotContainsString(self::CHANNEL, $check->getDisplay());
        self::assertStringContainsString('in 2 file(s)', $check->getDisplay());
    }

    /**
     * The counterpart, so the test above cannot pass on a project with
     * nothing to report: captured without the flag, the test code's finding
     * is outside the baseline and `check` with the flag reports it.
     */
    #[Test]
    public function itLeavesTestCodeOutOfACaptureTakenWithoutTheFlag(): void
    {
        $generate = $this->execute(BaselineGenerateCommand::class, [
            'baseline' => $this->baselinePath,
            '--only-rule' => [self::CHANNEL],
        ]);
        self::assertSame(0, $generate->getStatusCode(), $generate->getDisplay());
        self::assertNotContains(self::CHANNEL, self::capturedChannels($this->baselinePath));

        $check = $this->check();

        self::assertNotSame(0, $check->getStatusCode(), $check->getDisplay());
        self::assertStringContainsString(self::CHANNEL, $check->getDisplay());
    }

    #[Test]
    public function itCapturesGeneratedCodeOnlyUnderTheFlag(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Generated.php',
            "<?php\n/**\n * @generated\n */\nnamespace Demo;\nfinal class Generated { public function run(): mixed { return eval('return 2;'); } }\n",
        );

        foreach ([true, false] as $flag) {
            $generate = $this->execute(BaselineGenerateCommand::class, [
                'baseline' => $this->baselinePath,
                'paths' => ['src'],
                '--only-rule' => [self::CHANNEL],
                '--force' => true,
                ...($flag ? ['--include-generated' => true] : []),
            ]);
            self::assertSame(0, $generate->getStatusCode(), $generate->getDisplay());

            if ($flag) {
                self::assertContains(self::CHANNEL, self::capturedChannels($this->baselinePath));
            } else {
                self::assertNotContains(self::CHANNEL, self::capturedChannels($this->baselinePath));
            }
        }
    }

    private function check(): CommandTester
    {
        return $this->execute(CheckCommand::class, [
            '--include-autoload-dev' => true,
            '--only-rule' => [self::CHANNEL],
            '--baseline' => $this->baselinePath,
            '--format' => 'text',
            '--no-progress' => true,
        ]);
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $input
     */
    private function execute(string $commandClass, array $input): CommandTester
    {
        $workingDirectory = getcwd();
        self::assertNotFalse($workingDirectory);
        chdir($this->tempDir);

        try {
            /** @var Command $command */
            $command = (new ContainerFactory())->create()->get($commandClass);

            $tester = new CommandTester($command);
            $tester->execute($input);

            return $tester;
        } finally {
            chdir($workingDirectory);
        }
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            $entries = scandir($path);
            foreach ($entries === false ? [] : $entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);

            return;
        }

        @unlink($path);
    }

    /** @return list<string> */
    private static function capturedChannels(string $path): array
    {
        /** @var array{entries: array<string, list<array{channel: string}>>} $data */
        $data = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        $channels = [];
        foreach ($data['entries'] as $forSymbol) {
            foreach ($forSymbol as $entry) {
                $channels[] = $entry['channel'];
            }
        }

        return $channels;
    }
}
