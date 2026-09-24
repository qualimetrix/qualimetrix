<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Console\ArtifactFile;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(ArtifactFile::class)]
final class ArtifactFileTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-artifact-file-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o755, true);
    }

    protected function tearDown(): void
    {
        chmod($this->directory, 0o755);
        self::remove($this->directory);
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

    /**
     * An existing target is the same file after the write: a bind-mounted
     * file cannot be renamed over, and a hard link and the owner survive only
     * the write that keeps the inode.
     */
    #[Test]
    public function itWritesAnExistingTargetInPlaceAndLeavesNoOtherFile(): void
    {
        $target = $this->directory . '/report.json';
        file_put_contents($target, 'old content, longer than the new');
        $inode = fileinode($target);
        $file = new ArtifactFile($target, '--output');

        $file->refuseUnwritable();
        $file->write('new');

        clearstatcache();
        self::assertSame('new', file_get_contents($target));
        self::assertSame($inode, fileinode($target), 'The target was replaced by another file.');
        self::assertSame(['report.json'], $this->entries());
    }

    #[Test]
    public function itWritesThroughAHardLinkSoBothNamesReadTheArtifact(): void
    {
        $target = $this->directory . '/report.json';
        file_put_contents($target, 'old');
        link($target, $this->directory . '/second-name.json');
        $file = new ArtifactFile($target, '--output');

        $file->refuseUnwritable();
        $file->write('new');

        self::assertSame('new', file_get_contents($this->directory . '/second-name.json'));
    }

    /** Writing a file in place needs the file, not its directory. */
    #[Test]
    public function itWritesAWritableFileInADirectoryItCannotWrite(): void
    {
        self::skipAsRoot();

        $sealed = $this->directory . '/sealed';
        mkdir($sealed);
        file_put_contents($sealed . '/report.json', 'old');
        chmod($sealed, 0o555);
        $file = new ArtifactFile($sealed . '/report.json', '--output');

        try {
            $file->refuseUnwritable();
            $file->write('new');
        } finally {
            chmod($sealed, 0o755);
        }

        self::assertSame('new', file_get_contents($sealed . '/report.json'));
    }

    #[Test]
    public function itRefusesAnExistingFileItCannotWrite(): void
    {
        self::skipAsRoot();

        $target = $this->directory . '/report.json';
        file_put_contents($target, 'old');
        chmod($target, 0o444);

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('is not writable');

        (new ArtifactFile($target, '--output'))->refuseUnwritable();
    }

    #[Test]
    public function itRefusesADirectoryNamingTheOptionThatNamedIt(): void
    {
        mkdir($this->directory . '/out');

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Option --profile names "' . $this->directory . '/out", which is a directory.');

        (new ArtifactFile($this->directory . '/out', '--profile'))->refuseUnwritable();
    }

    #[Test]
    public function itRefusesATargetWhoseDirectoryDoesNotExist(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('does not exist or does not allow creating a file');

        (new ArtifactFile($this->directory . '/missing/report.json', '--output'))->refuseUnwritable();
    }

    /**
     * Creating a name needs the directory written and searched; a directory
     * that can only be written lets `>` refuse at once, and the precheck with it.
     */
    #[Test]
    public function itRefusesANewNameInADirectoryItCannotSearchBeforeAnyWork(): void
    {
        self::skipAsRoot();

        $locked = $this->directory . '/locked';
        mkdir($locked);
        chmod($locked, 0o200);

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('does not allow creating a file');

        (new ArtifactFile($locked . '/report.json', '--output'))->refuseUnwritable();
    }

    /** The write the precheck could not see is refused by the write itself, with the system's reason. */
    #[Test]
    public function itRefusesAWriteThatFailsAfterThePrecheck(): void
    {
        self::skipAsRoot();

        $target = $this->directory . '/out/report.json';
        mkdir(\dirname($target));
        $file = new ArtifactFile($target, '--output');
        $file->refuseUnwritable();
        chmod(\dirname($target), 0o555);

        try {
            $file->write('content');
            self::fail('A write that fails must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Failed to create the --output file', $refusal->getMessage());
            self::assertStringContainsString('Permission denied', $refusal->getMessage());
            self::assertSame('--output', $refusal->origin()->locator());
        }

        self::assertFileDoesNotExist($target);
    }

    #[Test]
    public function itCreatesANewTargetAndLeavesNoOtherFile(): void
    {
        $file = new ArtifactFile($this->directory . '/report.json', '--output');

        $file->refuseUnwritable();
        $file->write('new');

        self::assertSame('new', file_get_contents($this->directory . '/report.json'));
        self::assertSame(['report.json'], $this->entries());
    }

    /**
     * A size limit the artifact crosses fails the write midway: a name the
     * write created is removed, and an existing file is left partly written.
     *
     * @return iterable<string, array{bool}>
     */
    public static function provideMidwayFailures(): iterable
    {
        yield 'a new name' => [false];
        yield 'an existing file' => [true];
    }

    #[Test]
    #[DataProvider('provideMidwayFailures')]
    public function itRefusesAWriteThatFailsMidway(bool $existing): void
    {
        $target = $this->directory . '/report.json';
        if ($existing) {
            file_put_contents($target, 'old');
        }

        $run = $this->runInChild($target, 'str_repeat("x", 100000)', "trap '' XFSZ; ulimit -f 1; ");

        self::assertSame(3, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertStringStartsWith('write: Failed to write the --output file', $run['stderr']);
        if ($existing) {
            self::assertFileExists($target);
            self::assertLessThan(100000, filesize($target));
        } else {
            self::assertSame([], $this->entries());
        }
    }

    /** A replaced file keeps the permissions it was given, not the ones a fresh file gets. */
    #[Test]
    public function itKeepsThePermissionsOfTheFileItReplaces(): void
    {
        $target = $this->directory . '/report.json';
        file_put_contents($target, 'old');
        chmod($target, 0o600);
        $file = new ArtifactFile($target, '--output');

        $file->refuseUnwritable();
        $file->write('new');

        clearstatcache();
        self::assertSame('new', file_get_contents($target));
        self::assertSame(0o600, fileperms($target) & 0o777);
    }

    /** A link names where the artifact goes; the link itself stays a link. */
    #[Test]
    public function itWritesThroughASymlinkToTheFileItNames(): void
    {
        mkdir($this->directory . '/real');
        file_put_contents($this->directory . '/real/graph.dot', 'old');
        symlink('real/graph.dot', $this->directory . '/current.dot');
        $file = new ArtifactFile($this->directory . '/current.dot', '--output');

        $file->refuseUnwritable();
        $file->write('new');

        self::assertTrue(is_link($this->directory . '/current.dot'), 'The link was replaced by a file.');
        self::assertSame('new', file_get_contents($this->directory . '/real/graph.dot'));
        self::assertSame(['graph.dot'], array_values(array_diff((array) scandir($this->directory . '/real'), ['.', '..'])));
    }

    /**
     * A dangling link creates the file it names, in whatever directory that
     * is — the link's own directory need not be writable, as for `>`.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideDanglingLinks(): iterable
    {
        yield 'beside the link' => ['graph.dot', 'graph.dot', false];
        yield 'in another directory' => ['../real/graph.dot', 'real/graph.dot', false];
        yield 'from a directory that cannot be written' => ['../real/graph.dot', 'real/graph.dot', true];
    }

    #[Test]
    #[DataProvider('provideDanglingLinks')]
    public function itCreatesTheFileADanglingSymlinkNames(string $link, string $created, bool $sealLinkDirectory): void
    {
        if ($sealLinkDirectory) {
            self::skipAsRoot();
        }

        mkdir($this->directory . '/links');
        mkdir($this->directory . '/real');
        symlink($link, $this->directory . '/links/current.dot');
        if ($sealLinkDirectory) {
            chmod($this->directory . '/links', 0o555);
        }
        $file = new ArtifactFile($this->directory . '/links/current.dot', '--output');

        $file->refuseUnwritable();
        $file->write('new');

        self::assertTrue(is_link($this->directory . '/links/current.dot'), 'The link was replaced by a file.');
        self::assertSame('new', file_get_contents($this->directory . '/links/' . $link));
        self::assertFileExists($this->directory . '/' . ($created === 'graph.dot' ? 'links/graph.dot' : $created));
    }

    /**
     * `fs.protected_symlinks` forbids following a link in a sticky,
     * world-writable directory that neither the follower nor the directory's
     * owner owns. `>` is refused there, and so must the artifact be: PHP's
     * own open resolves the link in userspace, where the kernel's rule does
     * not reach. Setting up a link another user owns needs root.
     *
     * @return iterable<string, array{bool}>
     */
    public static function provideForeignLinks(): iterable
    {
        yield 'to an existing file' => [true];
        yield 'to a file to create' => [false];
    }

    #[Test]
    #[DataProvider('provideForeignLinks')]
    public function itDoesNotWriteThroughALinkTheKernelRefusesToFollow(bool $existing): void
    {
        if (@file_get_contents('/proc/sys/fs/protected_symlinks') !== "1\n") {
            self::markTestSkipped('This system does not protect symbolic links in sticky directories.');
        }
        if (!\function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('A link owned by another user can only be set up by root.');
        }
        if ($existing && \ZEND_THREAD_SAFE) {
            self::markTestSkipped('A thread-safe PHP resolves the path before asking the kernel whether it exists.');
        }

        $sticky = $this->directory . '/shared';
        mkdir($sticky);
        chmod($sticky, 0o1777);
        $victim = $this->directory . '/victim.json';
        if ($existing) {
            file_put_contents($victim, 'precious');
        }
        symlink($victim, $sticky . '/report.json');
        self::assertTrue(lchown($sticky . '/report.json', 4242));
        $file = new ArtifactFile($sticky . '/report.json', '--output');

        try {
            $file->refuseUnwritable();
            $file->write('overwritten');
            self::fail('A link the kernel refuses to follow was written through.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Permission denied', $refusal->getMessage());
        }

        clearstatcache();
        if ($existing) {
            self::assertSame('precious', file_get_contents($victim));
        } else {
            self::assertFileDoesNotExist($victim);
        }
    }

    /**
     * Whether a link leads to a name that can be created is the write's to
     * learn, not the precheck's: the precheck does not follow links itself.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideLinksToNoFile(): iterable
    {
        yield 'a dangling link to a directory name' => ['link-to-nodir'];
        yield 'a link to a file name ending in a slash' => ['link-to-afile'];
        yield 'a link loop' => ['loop-a'];
    }

    #[Test]
    #[DataProvider('provideLinksToNoFile')]
    public function itRefusesALinkThatLeadsToNoFileItCanCreate(string $name): void
    {
        file_put_contents($this->directory . '/afile', 'x');
        // PHP's symlink() refuses a target naming a file with a trailing slash on macOS.
        foreach (['nodir/' => 'link-to-nodir', 'afile/' => 'link-to-afile', 'loop-b' => 'loop-a', 'loop-a' => 'loop-b'] as $linkTarget => $link) {
            self::assertSame(0, ChildProcess::run(['ln', '-s', $linkTarget, $this->directory . '/' . $link])['exitCode']);
        }
        $file = new ArtifactFile($this->directory . '/' . $name, '--output');

        try {
            $file->refuseUnwritable();
            $file->write('new');
            self::fail('A link that leads to no file was written through.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('--output', $refusal->origin()->locator());
        }

        self::assertFileDoesNotExist($this->directory . '/nodir');
        self::assertSame('x', file_get_contents($this->directory . '/afile'));
    }

    #[Test]
    public function itWritesToACharacterDeviceInPlace(): void
    {
        $file = new ArtifactFile('/dev/null', '--output');

        $file->refuseUnwritable();
        $file->write('discarded');

        self::assertSame('char', filetype('/dev/null'));
    }

    /**
     * A named pipe is written to, so its reader gets the artifact. The test
     * holds the pipe open for reading and writing, so the write does not wait
     * for a reader and a pipe replaced by a file reads empty instead of hanging.
     */
    #[Test]
    public function itWritesIntoANamedPipeForItsReader(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('Named pipes need the posix extension.');
        }

        $fifo = $this->directory . '/graph.pipe';
        self::assertTrue(posix_mkfifo($fifo, 0o600));
        $reader = fopen($fifo, 'r+');
        self::assertIsResource($reader);
        $file = new ArtifactFile($fifo, '--output');

        try {
            $file->refuseUnwritable();
            $file->write('through the pipe');
            stream_set_blocking($reader, false);
            $received = fread($reader, 8192);
        } finally {
            fclose($reader);
        }

        self::assertSame('through the pipe', $received);
        self::assertSame('fifo', filetype($fifo));
    }

    /**
     * A stream of the process named by one of the supported spellings is
     * written through its descriptor. On Linux, opening `/dev/stdout` by its
     * path fails when stdout is a pipe, and reopens the file when it is
     * redirected to one — truncating what the stream already carried. The
     * shell's own writes on both sides of the artifact tell a write into the
     * stream from a write to the file's name.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideDescriptorTargets(): iterable
    {
        foreach (['/dev/stdout', '/dev/fd/1', '/proc/self/fd/1'] as $target) {
            yield $target . ' redirected to a file' => [$target, 'file'];
            yield $target . ' piped' => [$target, 'stdout'];
        }

        foreach (['/dev/stderr', '/dev/fd/2'] as $target) {
            yield $target . ' piped' => [$target, 'stderr'];
        }
    }

    #[Test]
    #[DataProvider('provideDescriptorTargets')]
    public function itWritesToTheProcessStreamWhateverItIs(string $target, string $stream): void
    {
        if (str_starts_with($target, '/proc/') && !is_dir('/proc/self/fd')) {
            self::markTestSkipped('This system has no /proc/self/fd.');
        }

        $captured = $this->directory . '/stream.txt';
        $run = $stream === 'file'
            ? $this->runInChild($target, '"payload"', '{ printf head; ', '; printf tail; } > ' . escapeshellarg($captured))
            : $this->runInChild($target, '"payload"');

        self::assertSame(0, $run['exitCode'], $run['stderr']);
        match ($stream) {
            'file' => self::assertSame('headpayloadtail', file_get_contents($captured)),
            'stdout' => self::assertSame('payload', $run['stdout']),
            default => self::assertSame('payload', $run['stderr']),
        };
    }

    /**
     * Another spelling of the process's own stream is opened by its path,
     * which PHP on Linux cannot do for a pipe; the refusal names the
     * spellings that are written through the stream.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideUnsupportedStreamSpellings(): iterable
    {
        yield 'through /proc/thread-self' => ['/proc/thread-self/fd/1'];
        yield 'through a parent step' => ['/dev/fd/../fd/1'];
        yield 'through a link of its own' => ['{dir}/stdout-link'];
    }

    #[Test]
    #[DataProvider('provideUnsupportedStreamSpellings')]
    public function itNamesTheSupportedSpellingsWhenAnotherSpellingOfAPipeCannotBeOpened(string $target): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Only PHP on Linux fails to open a pipe by its path.');
        }

        symlink('/dev/stdout', $this->directory . '/stdout-link');
        $run = $this->runInChild(str_replace('{dir}', $this->directory, $target), '"payload"');

        self::assertSame(3, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertStringContainsString('/dev/stdout, /dev/stderr, /dev/fd/N or /proc/self/fd/N', $run['stderr']);
    }

    /** A descriptor the process does not hold is not a file to create. */
    #[Test]
    public function itRefusesADescriptorTheProcessDoesNotHoldBeforeAnyWork(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('/dev/fd/97');

        (new ArtifactFile('/dev/fd/97', '--output'))->refuseUnwritable();
    }

    /**
     * A descriptor held open only for reading takes no write; the system
     * that publishes a descriptor's open mode lets the precheck say so.
     */
    #[Test]
    public function itRefusesADescriptorHeldOnlyForReadingBeforeAnyWork(): void
    {
        if (!is_dir('/proc/self/fdinfo')) {
            self::markTestSkipped('This system does not publish the open mode of a descriptor.');
        }

        file_put_contents($this->directory . '/input.txt', 'input');
        $run = $this->runInChild('/dev/fd/3', '"payload"', '', ' 3< ' . escapeshellarg($this->directory . '/input.txt'));

        self::assertSame(3, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertStringStartsWith('precheck: ', $run['stderr']);
        self::assertStringContainsString('only for reading', $run['stderr']);
        self::assertSame('input', file_get_contents($this->directory . '/input.txt'));
    }

    /**
     * A parent may hand its stdout over in non-blocking mode, which a
     * descriptor shares with every copy of it. The write restores blocking
     * mode, as the console output does, so a slow reader gets the whole
     * artifact instead of the first pipe buffer and a refusal.
     */
    #[Test]
    public function itWritesTheWholeArtifactIntoANonBlockingStream(): void
    {
        $child = $this->childScript('/dev/stdout', 'str_repeat("x", 300000)');
        $parent = \sprintf(
            'stream_set_blocking(STDOUT, false); $child = proc_open([%s, "-r", %s], [1 => STDOUT], $pipes); exit(proc_close($child));',
            var_export(\PHP_BINARY, true),
            var_export($child, true),
        );

        $run = ChildProcess::run(['sh', '-c', '"$0" -r "$1" | { sleep 1; wc -c; }', \PHP_BINARY, $parent]);

        self::assertSame('300000', trim($run['stdout']), $run['stderr']);
    }

    /** @return array{stdout: string, stderr: string, exitCode: int} */
    private function runInChild(string $target, string $content, string $before = '', string $after = ''): array
    {
        return ChildProcess::run([
            'sh',
            '-c',
            $before . '"$0" -r "$1"' . $after,
            \PHP_BINARY,
            $this->childScript($target, $content),
        ]);
    }

    /**
     * A script that prechecks and writes the target, and tells on stderr
     * which of the two refused it.
     */
    private function childScript(string $target, string $content): string
    {
        return \sprintf(
            'require %s; $file = new %s(%s, "--output");'
            . ' try { $file->refuseUnwritable(); } catch (%s $r) { fwrite(STDERR, "precheck: " . $r->getMessage()); exit(3); }'
            . ' try { $file->write(%s); } catch (%4$s $r) { fwrite(STDERR, "write: " . $r->getMessage()); exit(3); }',
            var_export(\dirname(__DIR__, 4) . '/vendor/autoload.php', true),
            '\\' . ArtifactFile::class,
            var_export($target, true),
            '\\' . ConfigurationRefusal::class,
            $content,
        );
    }

    private static function skipAsRoot(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permissions do not bind root.');
        }
    }

    /** @return list<string> */
    private function entries(): array
    {
        $entries = scandir($this->directory);
        self::assertIsArray($entries);

        return array_values(array_diff($entries, ['.', '..']));
    }
}
