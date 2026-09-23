<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `--config=$QMX_CONFIG` with the variable unset is a value the author typed,
 * not an omitted option. Five doors of `check` read the empty string as
 * "not given": `--config=` fell back to auto-discovering `qmx.yaml` — a run
 * under a different configuration than the one asked for — and `--output=`
 * printed the report to stdout, leaving CI without its artifact. Each is
 * refused by name now, and omitting the option still means its default.
 */
#[CoversClass(CheckCommand::class)]
final class CheckCommandEmptyOptionValueTest extends TestCase
{
    private const string FIXTURE = 'tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php';

    /** @return iterable<string, array{string, string|list<string>}> */
    public static function provideDoors(): iterable
    {
        yield '--config' => ['config', ''];
        yield '--baseline' => ['baseline', ''];
        yield '--output' => ['output', ''];
        yield '--report' => ['report', ''];
        yield '--preset' => ['preset', ['']];
        yield '--preset with an empty list element' => ['preset', ['strict,']];
        yield '--preset repeated with one empty' => ['preset', ['strict', '']];
    }

    /** @param string|list<string> $value */
    #[Test]
    #[DataProvider('provideDoors')]
    public function itRefusesAnEmptyValueByTheOptionName(string $option, string|array $value): void
    {
        $tester = $this->tester();
        $tester->execute(
            ['paths' => [self::FIXTURE], '--format' => 'json', '--' . $option => $value],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        /** @var array{error: string, exit_code: int} $envelope */
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('--' . $option, $envelope['error']);
        self::assertStringContainsString('empty', $envelope['error']);
    }

    /** @return iterable<string, array{string}> */
    public static function provideEmptyOutputSpellings(): iterable
    {
        yield 'nothing after "="' => [''];
        yield 'only whitespace after "="' => ['  '];
    }

    /**
     * The empty value is refused as empty before anything reads it as a path:
     * read first, it named the working directory, and from an unwritable one
     * the run was refused for a directory the author never wrote.
     */
    #[Test]
    #[DataProvider('provideEmptyOutputSpellings')]
    public function itRefusesAnEmptyOutputAsEmptyEvenFromAnUnwritableDirectory(string $value): void
    {
        $fixture = (string) realpath(self::FIXTURE);
        $previous = (string) getcwd();
        $directory = sys_get_temp_dir() . '/qmx-readonly-' . bin2hex(random_bytes(8));
        mkdir($directory);
        chmod($directory, 0o555);

        try {
            chdir($directory);
            $tester = $this->tester();
            $tester->execute(
                ['paths' => [$fixture], '--format' => 'json', '--output' => $value],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
            chmod($directory, 0o755);
            rmdir($directory);
        }

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        /** @var array{error: string} $envelope */
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('was written with an empty value', $envelope['error']);
    }

    /** The lawful neighbour: the omitted option still means its default. */
    #[Test]
    public function itStillRunsWhenNoneOfTheDoorsIsWritten(): void
    {
        $tester = $this->tester();
        $tester->execute(
            ['paths' => [self::FIXTURE], '--format' => 'json'],
            ['capture_stderr_separately' => true],
        );

        self::assertNotSame(3, $tester->getStatusCode(), $tester->getDisplay());
        self::assertArrayNotHasKey('error', (array) json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR));
    }

    private function tester(): CommandTester
    {
        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);
        /** @var RefusalPresenter $refusalPresenter */
        $refusalPresenter = $container->get(RefusalPresenter::class);
        $application = new Application(new ErrorStream(), $refusalPresenter);
        $application->addCommand($command);

        return new CommandTester($command);
    }
}
