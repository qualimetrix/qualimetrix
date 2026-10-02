<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Git\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Git\ChangedFile;
use Qualimetrix\Infrastructure\Git\ChangeStatus;
use Qualimetrix\Infrastructure\Git\GitClient;
use Qualimetrix\Infrastructure\Git\NameStatusRecord;
use RuntimeException;
use Stringable;
use Symfony\Component\Process\Process;

/**
 * What a real git says a file is called, against what this run makes of it.
 *
 * `core.quotePath` defaults to on, so the textual `git diff --name-status`
 * wraps any path holding a byte above 0x7F, a quote, a backslash or a control
 * character in C quotes with octal escapes. Read literally, such a row named
 * a file that does not exist — and because the escaped form still looked like
 * a path under the project root, nothing warned: `--report=git:staged` simply
 * reported nothing and exited 0.
 *
 * Every case below is driven through a repository git actually wrote, because
 * the defect lived in the difference between what git prints and what the
 * fixtures assumed it prints.
 */
#[CoversClass(GitClient::class)]
#[CoversClass(ChangedFile::class)]
#[CoversClass(NameStatusRecord::class)]
final class GitPathEncodingTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/qmx-git-encoding-' . bin2hex(random_bytes(6));

        if (!mkdir($dir) || !is_dir($dir)) {
            throw new RuntimeException('Cannot create the fixture repository: ' . $dir);
        }

        $resolved = realpath($dir);

        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve the fixture repository: ' . $dir);
        }

        $this->repoRoot = $resolved;
        $this->exec('git init');
        $this->exec('git config user.email "test@example.com"');
        $this->exec('git config user.name "Test User"');
        $this->exec('git checkout -b main');
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->repoRoot);
    }

    /**
     * Names git quotes, and names it does not, in one list: a fix that only
     * covered the quoted half would leave the plain half to prove it.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNamesThatMustSurvive(): iterable
    {
        yield 'non-ascii' => ['Тест.php'];
        yield 'space' => ['with space.php'];
        yield 'trailing space before the extension' => ['trailing .php'];
        yield 'double quote' => ['say "hi".php'];
        yield 'leading dash' => ['-dash.php'];
        yield 'newline' => ["two\nlines.php"];
        yield 'plain ascii' => ['Plain.php'];
    }

    #[Test]
    #[DataProvider('provideNamesThatMustSurvive')]
    public function itCarriesAStagedNameThroughUnchanged(string $name): void
    {
        file_put_contents($this->repoRoot . '/' . $name, "<?php\n");
        $this->exec('git add -A');

        $logger = new RecordingLogger();
        $client = new GitClient(AbsolutePath::fromString($this->repoRoot), $logger);
        $changed = $client->getChangedFiles('staged');

        self::assertCount(1, $changed);
        self::assertSame($name, $changed[0]->path->value());
        self::assertSame(ChangeStatus::Added, $changed[0]->status);
        self::assertSame([], $logger->warnings());
    }

    /**
     * `core.quotePath` is the setting the old reader was silently a function
     * of. Turning it off used to change the answer; now it changes nothing,
     * which is what "independent of the setting" has to mean to be checkable.
     */
    #[Test]
    public function itAnswersTheSameWithQuotePathOff(): void
    {
        file_put_contents($this->repoRoot . '/Тест.php', "<?php\n");
        file_put_contents($this->repoRoot . '/with space.php', "<?php\n");
        $this->exec('git add -A');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $withQuoting = array_map(static fn(ChangedFile $f): string => $f->path->value(), $client->getChangedFiles('staged'));

        $this->exec('git config core.quotePath false');
        $withoutQuoting = array_map(static fn(ChangedFile $f): string => $f->path->value(), $client->getChangedFiles('staged'));

        sort($withQuoting);
        sort($withoutQuoting);

        self::assertSame(['with space.php', 'Тест.php'], $withQuoting);
        self::assertSame($withQuoting, $withoutQuoting);
    }

    /**
     * A rename carries two names, and the quoted form put both on one line
     * separated by a tab — a separator a quoted name may itself contain. Both
     * halves are asserted, because a reader that lost only the source would
     * still report the file and still be wrong about where it came from.
     */
    #[Test]
    public function itCarriesBothNamesOfARenameThroughUnchanged(): void
    {
        file_put_contents(
            $this->repoRoot . '/Старое имя.php',
            "<?php\n\nclass Moved { public function work(): string { return 'unchanged body'; } }\n",
        );
        $this->exec('git add -A');
        $this->exec('git commit -m initial');
        $this->exec(\sprintf('git mv %s %s', escapeshellarg('Старое имя.php'), escapeshellarg('Новое имя.php')));
        $this->exec('git add -A');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $changed = $client->getChangedFiles('staged');

        self::assertCount(1, $changed);
        self::assertSame(ChangeStatus::Renamed, $changed[0]->status);
        self::assertSame('Новое имя.php', $changed[0]->path->value());
        self::assertSame('Старое имя.php', $changed[0]->oldPath?->value());
    }

    /**
     * Git's NUL-separated output preserves a literal POSIX backslash. The
     * spelling that resembles an octal escape is also a literal file name.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideLiteralBackslashNames(): iterable
    {
        yield 'backslash' => ['back\\slash.php'];
        yield 'text spelling an octal escape for the separator' => ['a\\057b.php'];
    }

    #[Test]
    #[DataProvider('provideLiteralBackslashNames')]
    public function itCarriesALiteralBackslashNameWithoutAWarning(string $name): void
    {
        file_put_contents($this->repoRoot . '/' . $name, "<?php\n");
        file_put_contents($this->repoRoot . '/Plain.php', "<?php\n");
        $this->exec('git add -A');

        $logger = new RecordingLogger();
        $client = new GitClient(AbsolutePath::fromString($this->repoRoot), $logger);
        $changed = $client->getChangedFiles('staged');

        $names = array_map(static fn(ChangedFile $f): string => $f->path->value(), $changed);
        sort($names);
        self::assertSame(['Plain.php', $name], $names);
        self::assertSame([], $logger->warnings());
    }

    /**
     * The report uses the exact staged file name for both non-ASCII and
     * literal-backslash names.
     */
    #[Test]
    #[DataProvider('provideCliNames')]
    public function itReportsAFindingForAStagedNameThroughTheCli(string $name): void
    {
        file_put_contents(
            $this->repoRoot . '/' . $name,
            "<?php\n\nclass Tested { public function run(string \$code): void { eval(\$code); } }\n",
        );
        $this->exec('git add -A');

        $binary = \dirname(__DIR__, 4) . '/bin/qmx';
        $process = new Process(
            [\PHP_BINARY, $binary, 'check', '.', '--report=git:staged', '--format=json', '--workers=0', '--no-cache'],
            $this->repoRoot,
        );
        $process->run();

        $report = json_decode($process->getOutput(), true);

        self::assertIsArray($report, 'the run did not produce a JSON report: ' . $process->getErrorOutput());
        // A fatal prints a valid report envelope carrying `error` and exits 1,
        // so the shape is checked before the code.
        self::assertArrayNotHasKey('error', $report, 'the run failed instead of reporting');
        self::assertSame(2, $process->getExitCode(), 'findings in the staged file must fail the run');

        $violations = $report['violations'] ?? null;

        self::assertIsArray($violations);
        self::assertContains(
            $name,
            array_column($violations, 'file'),
            'the staged file produced no finding under its literal name',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function provideCliNames(): iterable
    {
        yield 'non-ASCII' => ['Тест.php'];
        yield 'literal backslash' => ['back\\slash.php'];
    }

    private function exec(string $command): void
    {
        $process = Process::fromShellCommandline($command, $this->repoRoot);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(\sprintf('Command failed: %s — %s', $command, $process->getErrorOutput()));
        }
    }

    private function removeRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->removeRecursive($child) : @unlink($child);
        }

        @rmdir($path);
    }
}

/**
 * Keeps what the client said, so a test can assert a diagnostic exists rather
 * than assume it does.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ((string) $level === 'warning') {
            $this->warnings[] = (string) $message;
        }
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
