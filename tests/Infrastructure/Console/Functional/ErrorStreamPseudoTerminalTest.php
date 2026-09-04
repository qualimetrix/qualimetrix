<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Tests\Infrastructure\Console\Support\PseudoTerminalRun;

/**
 * The whole product on a pseudo-terminal, judged by the screen it leaves.
 *
 * This is the only stand that can see the defect end to end: the frame is
 * drawn solely on a decorated stream, so every run behind a pipe — every
 * existing functional test, and the finding-equivalence gate — is blind to it
 * by construction. It is also the reason the assertion is about the replayed
 * screen and not about bytes: the byte streams of an intact run and a
 * destroyed one both contain erase sequences.
 */
final class ErrorStreamPseudoTerminalTest extends TestCase
{
    private const int COLUMNS = 120;

    private string $fixture = '';

    protected function setUp(): void
    {
        if (!PseudoTerminalRun::isSupported()) {
            self::markTestSkipped('This PHP build cannot allocate a pseudo-terminal (proc_open pty descriptors)');
        }

        $this->fixture = sys_get_temp_dir() . '/qmx-error-stream-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o777, true);

        // Above ConsoleProgressBar's ten-file floor, or no frame is drawn and
        // the case proves nothing.
        for ($i = 1; $i <= 20; ++$i) {
            file_put_contents(
                \sprintf('%s/src/C%d.php', $this->fixture, $i),
                \sprintf(
                    "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fx;\n\nfinal class C%d\n{\n"
                    . "    public function run(int \$a): int\n    {\n        if (\$a > 1) {\n"
                    . "            return \$a * %d;\n        }\n\n        return \$a + %d;\n    }\n}\n",
                    $i,
                    $i,
                    $i,
                ),
            );
        }

        file_put_contents($this->fixture . '/qmx-fixture.yaml', "paths:\n  - src\n");
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== '' && is_dir($this->fixture)) {
            self::removeDirectory($this->fixture);
        }
    }

    /** @return iterable<string, array{list<string>}> */
    public static function provideVerbosityCases(): iterable
    {
        yield 'default' => [[]];
        yield '-v' => [['-v']];
        yield '-vv' => [['-vv']];
        yield '-vvv' => [['-vvv']];
    }

    /** @param list<string> $verbosity */
    #[Test]
    #[DataProvider('provideVerbosityCases')]
    public function itLeavesEveryDiagnosticLineOnTheScreen(array $verbosity): void
    {
        $run = $this->analyse($verbosity);

        $written = self::logLinesIn($run->stderr);
        $screen = $run->screen(self::COLUMNS)->unwrappedText();

        $survivors = array_values(array_filter(
            $written,
            static fn(string $line): bool => str_contains($screen, $line),
        ));

        self::assertSame(
            $written,
            $survivors,
            'a log line written to the error stream was erased by a progress frame',
        );
    }

    /** @param list<string> $verbosity */
    #[Test]
    #[DataProvider('provideVerbosityCases')]
    public function itLeavesNoProgressFrameBehind(array $verbosity): void
    {
        $run = $this->analyse($verbosity);

        self::assertMatchesRegularExpression(
            '#\d+/20 \[#',
            $run->stderr,
            'the run drew no frame at all, so this case would pass vacuously',
        );
        self::assertDoesNotMatchRegularExpression(
            '#\d+/20 \[#',
            $run->screen(self::COLUMNS)->text(),
            'a progress frame is stranded on the final screen',
        );
    }

    /** @param list<string> $verbosity */
    #[Test]
    #[DataProvider('provideVerbosityCases')]
    public function itKeepsThePayloadFreeOfTheTerminal(array $verbosity): void
    {
        $run = $this->analyse($verbosity);

        self::assertStringNotContainsString("\x1b", $run->stdout);
        self::assertJson($run->stdout);
    }

    /** @param list<string> $verbosity */
    private function analyse(array $verbosity): PseudoTerminalRun
    {
        return PseudoTerminalRun::execute(
            array_merge([
                \PHP_BINARY,
                \dirname(__DIR__, 4) . '/bin/qmx',
                'check',
                $this->fixture . '/src',
                '--config=' . $this->fixture . '/qmx-fixture.yaml',
                '--workers=0',
                '--format=json',
            ], $verbosity),
            $this->fixture,
            columns: self::COLUMNS,
        );
    }

    /** @return list<string> */
    private static function logLinesIn(string $stderr): array
    {
        // Colour is stripped first: the replayed screen holds visible text,
        // and an `SGR` sequence inside the written bytes would never match it.
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $stderr);
        preg_match_all('/\[\d\d:\d\d:\d\d] \[[A-Z]+] [^\r\n]+/', $plain, $matches);

        /** @var list<string> $lines */
        $lines = $matches[0];

        return array_values(array_unique($lines));
    }

    private static function removeDirectory(string $directory): void
    {
        foreach ((array) scandir($directory) as $entry) {
            if (!\is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
