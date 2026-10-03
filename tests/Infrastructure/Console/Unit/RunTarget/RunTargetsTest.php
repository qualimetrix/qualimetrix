<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\RunTarget;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 5) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(RunTargets::class)]
final class RunTargetsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-run-targets-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        self::remove($this->directory);
    }

    #[Test]
    public function itWritesAnExistingInodeThroughItsHardLinkWithoutChangingItsMode(): void
    {
        $target = $this->directory . '/report.json';
        file_put_contents($target, 'old and longer');
        chmod($target, 0o600);
        link($target, $this->directory . '/second.json');
        $inode = fileinode($target);

        $targets = self::targets();
        $targets->judge('--output', $target);
        $targets->claim();
        self::assertSame('old and longer', file_get_contents($target));
        $targets->write('--output', 'new');
        $targets->abandon();

        clearstatcache();
        self::assertSame($inode, fileinode($target));
        self::assertSame(0o600, fileperms($target) & 0o777);
        self::assertSame('new', file_get_contents($target));
        self::assertSame('new', file_get_contents($this->directory . '/second.json'));
        self::assertSame(['report.json', 'second.json'], $this->entries());
    }

    #[Test]
    public function itCreatesOnlyTheRequestedNameAndRemovesAnUnwrittenClaim(): void
    {
        $target = $this->directory . '/new.json';
        $targets = self::targets();
        $targets->judge('--output', $target);
        $targets->claim();
        self::assertFileExists($target);
        $targets->abandon();
        self::assertSame([], $this->entries());

        $targets->judge('--output', $target);
        $targets->claim();
        $targets->write('--output', 'complete');
        $targets->abandon();
        self::assertSame('complete', file_get_contents($target));
        self::assertSame(['new.json'], $this->entries());
    }

    #[Test]
    public function itReleasesTheLoggerFactoryWhenTheRunIsAbandoned(): void
    {
        $factory = self::createMock(LoggerFactoryInterface::class);
        $factory->expects(self::once())->method('reset');
        $targets = new RunTargets($factory);
        $targets->judge('--output', $this->directory . '/report.json');
        $targets->claim();

        $targets->abandon();

        self::assertSame([], $this->entries());
    }

    #[Test]
    public function itWritesThroughATrustedLinkWithoutReplacingTheLink(): void
    {
        mkdir($this->directory . '/real');
        file_put_contents($this->directory . '/real/report.json', 'old');
        symlink('real/report.json', $this->directory . '/current.json');
        $targets = self::targets();
        $targets->judge('--output', $this->directory . '/current.json');
        $targets->claim();
        $targets->write('--output', 'new');
        $targets->abandon();

        self::assertTrue(is_link($this->directory . '/current.json'));
        self::assertSame('new', file_get_contents($this->directory . '/real/report.json'));
        self::assertSame(['report.json'], $this->entries($this->directory . '/real'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideTrustedDanglingLinks(): iterable
    {
        yield 'beside link' => ['report.json', false];
        yield 'other directory' => ['../real/report.json', false];
        yield 'sealed link directory' => ['../real/report.json', true];
    }

    #[Test]
    #[DataProvider('provideTrustedDanglingLinks')]
    public function itCreatesTheReferentOfATrustedDanglingLink(string $referent, bool $sealLinks): void
    {
        if ($sealLinks && \function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory permissions do not bind root.');
        }
        mkdir($this->directory . '/links');
        mkdir($this->directory . '/real');
        symlink($referent, $this->directory . '/links/current.json');
        if ($sealLinks) {
            chmod($this->directory . '/links', 0o555);
        }
        $targets = self::targets();
        $targets->judge('--output', $this->directory . '/links/current.json');
        $targets->claim();
        $targets->write('--output', 'new');
        $targets->abandon();

        self::assertTrue(is_link($this->directory . '/links/current.json'));
        self::assertSame('new', file_get_contents($this->directory . '/links/' . $referent));
    }

    #[Test]
    public function itRefusesMissingParentsAndDirectoriesBeforeClaim(): void
    {
        foreach ([$this->directory . '/missing/report.json', $this->directory] as $spelling) {
            try {
                self::targets()->judge('--output', $spelling);
                self::fail('Invalid target was accepted: ' . $spelling);
            } catch (ConfigurationRefusal $refusal) {
                self::assertStringContainsString($spelling, $refusal->summary());
                self::assertSame('--output', $refusal->sources()[0]->locator());
            }
        }
        self::assertSame([], $this->entries());
    }

    #[Test]
    public function itRefusesAnExistingReadOnlyTargetBeforeChangingItsBytes(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('File permissions do not bind root.');
        }
        $target = $this->directory . '/report.json';
        file_put_contents($target, 'KEEP');
        chmod($target, 0o444);

        try {
            self::targets()->judge('--output', $target);
            self::fail('A read-only file must be refused before claim.');
        } catch (EnvironmentRefusal $refusal) {
            self::assertStringContainsString('--output', $refusal->summary());
            self::assertSame('KEEP', file_get_contents($target));
        }
    }

    #[Test]
    public function itRefusesANewNameInAnUnsearchableParentBeforeCreatingIt(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory permissions do not bind root.');
        }
        $parent = $this->directory . '/locked';
        mkdir($parent, 0o755);
        chmod($parent, 0o200);

        try {
            self::targets()->judge('--output', $parent . '/report.json');
            self::fail('An unsearchable parent must be refused before claim.');
        } catch (EnvironmentRefusal $refusal) {
            self::assertStringContainsString('--output', $refusal->summary());
            self::assertFileDoesNotExist($parent . '/report.json');
        } finally {
            chmod($parent, 0o755);
        }
    }

    #[Test]
    public function itRefusesAParentSealedAfterJudgementWithoutCreatingTheTarget(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory permissions do not bind root.');
        }
        $parent = $this->directory . '/late';
        mkdir($parent, 0o755);
        $target = $parent . '/report.json';
        $targets = self::targets();
        $targets->judge('--output', $target);
        chmod($parent, 0o555);

        try {
            $targets->claim();
            self::fail('A parent sealed after judgement must be refused.');
        } catch (EnvironmentRefusal $refusal) {
            self::assertStringContainsString('--output', $refusal->summary());
            self::assertStringContainsString($target, $refusal->summary());
            self::assertFileDoesNotExist($target);
        } finally {
            chmod($parent, 0o755);
            $targets->abandon();
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideUncreatableLinkReferents(): iterable
    {
        yield 'directory suffix' => ['nodir/', 'link-to-nodir'];
        yield 'file suffix' => ['afile/', 'link-to-afile'];
        yield 'link loop' => ['loop-b', 'loop-a'];
    }

    #[Test]
    #[DataProvider('provideUncreatableLinkReferents')]
    public function itRefusesALinkThatCannotNameACreatableFile(string $referent, string $name): void
    {
        file_put_contents($this->directory . '/afile', 'KEEP');
        self::assertSame(0, ChildProcess::run(['ln', '-s', $referent, $this->directory . '/' . $name])['exitCode']);
        if ($name === 'loop-a') {
            self::assertSame(0, ChildProcess::run(['ln', '-s', 'loop-a', $this->directory . '/loop-b'])['exitCode']);
        }

        try {
            $targets = self::targets();
            $targets->judge('--output', $this->directory . '/' . $name);
            $targets->claim();
            $targets->write('--output', 'changed');
            self::fail('The link cannot name a writable file.');
        } catch (RefusalInterface $refusal) {
            self::assertStringContainsString('--output', $refusal->summary());
            self::assertSame('KEEP', file_get_contents($this->directory . '/afile'));
            self::assertFileDoesNotExist($this->directory . '/nodir');
        }
    }

    #[Test]
    public function itRefusesDifferentNamesOfOneInodeBeforeClaimingEither(): void
    {
        $target = $this->directory . '/report.json';
        file_put_contents($target, 'KEEP');
        link($target, $this->directory . '/second.json');
        $targets = self::targets();
        $targets->judge('--output', $target);
        $targets->judge('--profile', $this->directory . '/second.json');

        $this->expectException(EnvironmentRefusal::class);
        $this->expectExceptionMessage('same output target');
        try {
            $targets->claim();
        } finally {
            self::assertSame('KEEP', file_get_contents($target));
        }
    }

    #[Test]
    public function itRefusesAProfileThatAliasesStandardOutput(): void
    {
        $targets = self::targets();
        $targets->reportOnStandardOutput();
        $targets->judge('--profile', 'php://stdout');

        $this->expectException(EnvironmentRefusal::class);
        $this->expectExceptionMessage('standard output');
        $targets->claim();
    }

    #[Test]
    public function itAcceptsAnAbsoluteFileUrlAndRefusesAClosedDescriptor(): void
    {
        $path = $this->directory . '/report.json';
        $targets = self::targets();
        $targets->judge('--output', 'file://' . $path);
        $targets->claim();
        $targets->write('--output', 'file url');
        $targets->abandon();
        self::assertSame('file url', file_get_contents($path));

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('/dev/fd/97');
        self::targets()->judge('--output', '/dev/fd/97');
    }

    #[Test]
    public function itWritesToACharacterDeviceWithoutChangingItsKind(): void
    {
        $targets = self::targets();
        $targets->judge('--output', '/dev/null');
        $targets->claim();
        $targets->write('--output', 'discarded');
        $targets->abandon();
        self::assertSame('char', filetype('/dev/null'));
    }

    #[Test]
    public function itWritesTheCompleteArtifactIntoAFifoWithAReader(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('Named pipes require the posix extension.');
        }
        $fifo = $this->directory . '/graph.pipe';
        self::assertTrue(posix_mkfifo($fifo, 0o600));
        $reader = fopen($fifo, 'r+');
        self::assertIsResource($reader);
        $targets = self::targets();
        try {
            $targets->judge('--output', $fifo);
            $targets->claim();
            $targets->write('--output', 'through the pipe');
            stream_set_blocking($reader, false);
            self::assertSame('through the pipe', fread($reader, 8192));
            self::assertSame('fifo', filetype($fifo));
        } finally {
            $targets->abandon();
            fclose($reader);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideProcessStreams(): iterable
    {
        foreach (['php://stdout', '/dev/stdout', '/dev/fd/1', '/dev/fd/../fd/1', '/proc/self/fd/1'] as $spelling) {
            yield $spelling . ' redirected' => [$spelling, 'file'];
            yield $spelling . ' piped' => [$spelling, 'stdout'];
        }
        yield 'stderr piped' => ['php://stderr', 'stderr'];
        yield '/dev/stderr piped' => ['/dev/stderr', 'stderr'];
        yield '/dev/fd/2 piped' => ['/dev/fd/2', 'stderr'];
    }

    #[Test]
    #[DataProvider('provideProcessStreams')]
    public function itWritesThroughTheHeldProcessStreamWithoutTruncatingIt(string $spelling, string $stream): void
    {
        if (str_starts_with($spelling, '/proc/') && !is_dir('/proc/self/fd')) {
            self::markTestSkipped('This system has no procfs descriptors.');
        }
        $captured = $this->directory . '/captured.txt';
        $script = self::childScript($spelling, '"payload"');
        $run = $stream === 'file'
            ? ChildProcess::run(['sh', '-c', '{ printf head; "$0" -r "$1"; printf tail; } > "$2"', \PHP_BINARY, $script, $captured])
            : ChildProcess::run([\PHP_BINARY, '-r', $script]);

        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        match ($stream) {
            'file' => self::assertSame('headpayloadtail', file_get_contents($captured)),
            'stdout' => self::assertSame('payload', $run['stdout']),
            default => self::assertSame('payload', $run['stderr']),
        };
    }

    #[Test]
    public function itRefusesAReadOnlyDescriptorBeforeChangingItsFile(): void
    {
        if (!is_dir('/proc/self/fdinfo')) {
            self::markTestSkipped('This system does not publish descriptor open modes.');
        }
        $input = $this->directory . '/input.txt';
        file_put_contents($input, 'KEEP');
        $script = self::childScript('/dev/fd/3', '"payload"');
        $run = ChildProcess::run(['sh', '-c', '"$0" -r "$1" 3< "$2"', \PHP_BINARY, $script, $input]);

        self::assertSame(3, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertStringContainsString('only for reading', $run['stderr']);
        self::assertSame('KEEP', file_get_contents($input));
    }

    #[Test]
    public function itCompletesANonBlockingStandardOutputToASlowReader(): void
    {
        $script = self::childScript('/dev/stdout', 'str_repeat("x", 300000)', 'stream_set_blocking(STDOUT, false);');
        $run = ChildProcess::run(['sh', '-c', '"$0" -r "$1" | { sleep 1; wc -c; }', \PHP_BINARY, $script]);

        self::assertSame(0, $run['exitCode'], $run['stderr']);
        self::assertSame('300000', trim($run['stdout']));
    }

    private static function childScript(string $spelling, string $content, string $before = ''): string
    {
        return \sprintf(
            'require %s; %s $targets = new \\%s(new \\%s());'
            . ' try { $targets->judge("--output", %s); $targets->claim(); $targets->write("--output", %s); $targets->abandon(); }'
            . ' catch (\\Throwable $failure) { fwrite(STDERR, $failure->getMessage()); exit(3); }',
            var_export(\dirname(__DIR__, 5) . '/vendor/autoload.php', true),
            $before,
            RunTargets::class,
            LoggerFactory::class,
            var_export($spelling, true),
            $content,
        );
    }

    private static function targets(): RunTargets
    {
        return new RunTargets(new LoggerFactory());
    }

    /** @return list<string> */
    private function entries(?string $directory = null): array
    {
        $entries = scandir($directory ?? $this->directory);
        self::assertIsArray($entries);

        return array_values(array_diff($entries, ['.', '..']));
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path, 0o755);
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                self::remove($path . '/' . $entry);
            }
            rmdir($path);

            return;
        }
        unlink($path);
    }
}
