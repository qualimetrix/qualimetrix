<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What a run treats as the project is one answer for both halves of the run:
 * the paths a run without `paths` analyses, and the denominator its scope is
 * judged against. `autoload-dev` is outside both by default and inside both
 * once the author opts in, through either door.
 */
#[CoversClass(CheckCommand::class)]
final class CheckCommandAutoloadDevScopeTest extends TestCase
{
    private const string SCOPE_WARNING = 'Analyzed paths do not cover all autoload entries (missing: tests)';

    private string $directory;

    private string $previousDirectory;

    protected function setUp(): void
    {
        $this->previousDirectory = (string) getcwd();
        $this->directory = sys_get_temp_dir() . '/qmx-autoload-dev-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/src', 0o777, true);
        mkdir($this->directory . '/tests');
        file_put_contents($this->directory . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Demo\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['DemoTests\\' => 'tests/']],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($this->directory . '/src/Demo.php', "<?php\nnamespace Demo;\nfinal class Demo {}\n");
        file_put_contents($this->directory . '/tests/DemoTest.php', "<?php\nnamespace DemoTests;\nfinal class DemoTest {}\n");
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->previousDirectory);
        foreach (['src/Demo.php', 'tests/DemoTest.php', 'composer.json', 'qmx.yaml'] as $file) {
            @unlink($this->directory . '/' . $file);
        }
        @rmdir($this->directory . '/src');
        @rmdir($this->directory . '/tests');
        @rmdir($this->directory);
    }

    #[Test]
    public function itLeavesTestCodeOutOfBothHalvesByDefault(): void
    {
        self::assertSame(1, $this->analysedFiles($this->check([])));
        self::assertStringNotContainsString(self::SCOPE_WARNING, $this->check(['paths' => ['src']])->getErrorOutput());
    }

    #[Test]
    public function itTakesTestCodeIntoBothHalvesWhenTheFlagIsGiven(): void
    {
        self::assertSame(2, $this->analysedFiles($this->check(['--include-autoload-dev' => true])));
        self::assertStringContainsString(
            self::SCOPE_WARNING,
            $this->check(['paths' => ['src'], '--include-autoload-dev' => true])->getErrorOutput(),
        );
    }

    #[Test]
    public function itTakesTestCodeIntoBothHalvesWhenTheKeyIsWritten(): void
    {
        file_put_contents($this->directory . '/qmx.yaml', "include_autoload_dev: true\n");

        self::assertSame(2, $this->analysedFiles($this->check([])));
        self::assertStringContainsString(self::SCOPE_WARNING, $this->check(['paths' => ['src']])->getErrorOutput());
    }

    /** Explicit paths are the author's, and the flag does not add to them. */
    #[Test]
    public function itDoesNotWidenPathsTheAuthorNamed(): void
    {
        self::assertSame(1, $this->analysedFiles($this->check(['paths' => ['src'], '--include-autoload-dev' => true])));
    }

    /** @param array<string, mixed> $input */
    private function check(array $input): CommandTester
    {
        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);
        /** @var RefusalPresenter $refusalPresenter */
        $refusalPresenter = $container->get(RefusalPresenter::class);
        $application = new Application(new ErrorStream(), $refusalPresenter);
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute(
            [...$input, '--format' => 'json', '--workers' => '0', '--no-cache' => true],
            ['capture_stderr_separately' => true],
        );

        return $tester;
    }

    private function analysedFiles(CommandTester $tester): int
    {
        /** @var array{summary: array{filesAnalyzed: int}} $report */
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        return $report['summary']['filesAnalyzed'];
    }
}
