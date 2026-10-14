<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Tests\TestSupport\WorkingDirectory\SuiteWorkingDirectoryExtension;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SuiteRunsOutsideTheRepositoryTest extends TestCase
{
    /** @var list<string> */
    private array $preservedRoots = [];

    #[Test]
    public function itRunsInAnExecutionWindowOutsideTheRepository(): void
    {
        $root = realpath(\dirname(__DIR__, 2));
        self::assertIsString($root);
        $cwd = getcwd();
        $processRoot = \constant('QMX_TEST_PROCESS_ROOT');
        self::assertIsString($processRoot);
        self::assertIsString($cwd);
        self::assertSame($processRoot . '/cwd', $cwd);
        self::assertFalse(str_starts_with($cwd, $root . '/'));

        $configuration = simplexml_load_file($root . '/phpunit.xml.dist');
        self::assertNotFalse($configuration);
        self::assertSame('tests/bootstrap.php', (string) $configuration['bootstrap']);
        self::assertSame('true', (string) $configuration['failOnPhpunitWarning']);
        self::assertSame(SuiteWorkingDirectoryExtension::class, (string) $configuration->extensions->bootstrap['class']);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function provideLawfulInvocations(): iterable
    {
        yield 'relative test path' => [['CaseTest.php'], ''];
        yield 'relative listing' => [['--list-tests', 'CaseTest.php'], ''];
        yield 'relative junit' => [['--log-junit=rel.xml', 'CaseTest.php'], ''];
        yield 'selected suite' => [['--testsuite=Example'], ''];
        yield 'filter and random order' => [['--filter=itRuns', '--order-by=random', '--stop-on-defect'], ''];
        yield 'isolated child preserves globals' => [['--process-isolation'], ''];
        yield 'isolated child drops globals' => [['--process-isolation'], '#[\\PHPUnit\\Framework\\Attributes\\PreserveGlobalState(false)]'];
        yield 'declared cache residue' => [[], 'cache'];
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('provideLawfulInvocations')]
    public function itKeepsNativePhpunitInvocationPathsAndChildLifecycles(array $arguments, string $mode): void
    {
        $this->withProject($mode, function (string $directory) use ($arguments): void {
            $process = $this->invoke($directory, $arguments);
            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            if (\in_array('--list-tests', $arguments, true)) {
                self::assertStringContainsString('ExampleCaseTest::itRuns', $process->getOutput());
                self::assertFileDoesNotExist($directory . '/root.txt');
                return;
            }
            $processRoot = trim((string) file_get_contents($directory . '/root.txt'));
            self::assertNotSame('', $processRoot);
            self::assertDirectoryDoesNotExist($processRoot, 'Only the parent removes the root, after the child finishes');
            if (\in_array('--log-junit=rel.xml', $arguments, true)) {
                self::assertFileExists($directory . '/rel.xml');
            }
        });
    }

    /** @return iterable<string, array{list<string>, string, string}> */
    public static function provideRefusedInvocations(): iterable
    {
        yield 'left file' => [[], 'file', 'rel.txt'];
        yield 'warning bypass still refuses' => [['--do-not-fail-on-phpunit-warning'], 'file', 'rel.txt'];
        yield 'isolated child left file' => [['--process-isolation'], 'file', 'rel.txt'];
        yield 'class hook leaked cwd' => [[], 'leak', 'ExampleCaseTest leaked cwd'];
        yield 'extensions disabled' => [['--no-extensions'], 'bypass', 'was not bootstrapped'];
        yield 'configuration disabled with bootstrap' => [
            ['--no-configuration', '--bootstrap=' . \dirname(__DIR__, 2) . '/tests/bootstrap.php', 'CaseTest.php'],
            'bypass', 'was not bootstrapped',
        ];
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('provideRefusedInvocations')]
    public function itRefusesNativeExecutionResidueAndExtensionBypasses(array $arguments, string $mode, string $message): void
    {
        $this->withProject($mode, function (string $directory) use ($arguments, $message): void {
            $process = $this->invoke($directory, $arguments);
            $output = $process->getOutput() . $process->getErrorOutput();
            self::assertSame(1, $process->getExitCode(), $output);
            self::assertStringContainsString($message, $output);
        });
    }

    /** @param list<string> $arguments */
    private function invoke(string $directory, array $arguments): Process
    {
        $process = new Process([
            \PHP_BINARY, \dirname(__DIR__, 2) . '/vendor/bin/phpunit',
            '--do-not-cache-result', '--no-coverage', ...$arguments,
        ], $directory);
        $process->setTimeout(20);
        $process->run();
        preg_match_all('/Preserved PHPUnit process root: ([^\r\n]+)/', $process->getErrorOutput(), $matches);
        $this->preservedRoots = [...$this->preservedRoots, ...$matches[1]];
        return $process;
    }

    private function withProject(string $mode, callable $assertions): void
    {
        $directory = sys_get_temp_dir() . '/qmx-suite-case-' . bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $root = \dirname(__DIR__, 2);
        $configuration = '<phpunit bootstrap="' . $root . '/tests/bootstrap.php" failOnPhpunitWarning="true">'
            . '<extensions><bootstrap class="' . SuiteWorkingDirectoryExtension::class . '"/></extensions>'
            . '<testsuites><testsuite name="Example"><file>CaseTest.php</file></testsuite></testsuites></phpunit>';
        file_put_contents($directory . '/phpunit.xml', $configuration);
        $attribute = str_starts_with($mode, '#[') ? $mode : '';
        $hook = $mode === 'leak' ? 'public static function setUpBeforeClass(): void { chdir(__DIR__); }' : '';
        $write = match ($mode) {
            'file' => 'file_put_contents("rel.txt", "left");',
            'cache' => 'mkdir(".qmx-cache");',
            default => '',
        };
        $cwdAssertion = \in_array($mode, ['bypass', 'leak'], true)
            ? '' : 'self::assertSame(QMX_TEST_PROCESS_ROOT . "/cwd", getcwd());';
        $body = '<?php ' . $attribute . ' final class ExampleCaseTest extends \\PHPUnit\\Framework\\TestCase {'
            . $hook . ' #[\\PHPUnit\\Framework\\Attributes\\Test] public function itRuns(): void {'
            . 'self::assertDirectoryExists(QMX_TEST_PROCESS_ROOT);'
            . $cwdAssertion
            . 'file_put_contents(__DIR__ . "/root.txt", QMX_TEST_PROCESS_ROOT);' . $write . '}}';
        file_put_contents($directory . '/CaseTest.php', $body);
        try {
            $assertions($directory);
        } finally {
            // Failed runs preserve their roots for diagnosis; this fixture owns them.
            $rootRecord = $directory . '/root.txt';
            if (is_file($rootRecord)) {
                (new Filesystem())->remove(trim((string) file_get_contents($rootRecord)));
            }
            foreach ($this->preservedRoots as $preservedRoot) {
                (new Filesystem())->remove($preservedRoot);
            }
            $this->preservedRoots = [];
            (new Filesystem())->remove($directory);
        }
    }
}
