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
    public function itWritesAnExistingTargetInPlaceAndLeavesNoTemporaryFile(): void
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
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory permissions do not bind root.');
        }

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
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('File permissions do not bind root.');
        }

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
        $this->expectExceptionMessage('does not exist or is not writable');

        (new ArtifactFile($this->directory . '/missing/report.json', '--output'))->refuseUnwritable();
    }

    /** The write the precheck could not see is refused by the write itself. */
    #[Test]
    public function itRefusesAWriteThatFailsAfterThePrecheck(): void
    {
        $target = $this->directory . '/report.json';
        $file = new ArtifactFile($target, '--output');
        $file->refuseUnwritable();
        mkdir($target . '.tmp.' . getmypid());

        try {
            $file->write('content');
            self::fail('A write that fails must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Failed to write the --output file', $refusal->getMessage());
            self::assertSame('--output', $refusal->origin()->locator());
        } finally {
            rmdir($target . '.tmp.' . getmypid());
        }

        self::assertFileDoesNotExist($target);
    }

    #[Test]
    public function itCreatesANewTargetAndLeavesNoTemporaryFile(): void
    {
        $file = new ArtifactFile($this->directory . '/report.json', '--output');

        $file->refuseUnwritable();
        $file->write('new');

        self::assertSame('new', file_get_contents($this->directory . '/report.json'));
        self::assertSame(['report.json'], $this->entries());
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

    #[Test]
    public function itCreatesTheFileADanglingSymlinkNames(): void
    {
        symlink('graph.dot', $this->directory . '/current.dot');
        $file = new ArtifactFile($this->directory . '/current.dot', '--output');

        $file->refuseUnwritable();
        $file->write('new');

        self::assertTrue(is_link($this->directory . '/current.dot'), 'The link was replaced by a file.');
        self::assertSame('new', file_get_contents($this->directory . '/graph.dot'));
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
     * A descriptor the process holds is written through that descriptor. On
     * Linux, opening `/dev/stdout` by its path fails when stdout is a pipe,
     * and reopens the file when it is redirected to one — truncating what the
     * stream already carried. The shell's own writes on both sides of the
     * artifact tell a write into the stream from a write to the file's name.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideDescriptorTargets(): iterable
    {
        foreach (['/dev/stdout', '/dev/fd/1', '/proc/self/fd/1', '{dir}/stdout-link'] as $target) {
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

        symlink('/dev/stdout', $this->directory . '/stdout-link');
        $target = str_replace('{dir}', $this->directory, $target);
        $captured = $this->directory . '/stream.txt';
        $script = \sprintf(
            'require %s; $file = new %s(%s, "--output"); $file->refuseUnwritable(); $file->write("payload");',
            var_export(\dirname(__DIR__, 4) . '/vendor/autoload.php', true),
            '\\' . ArtifactFile::class,
            var_export($target, true),
        );

        $run = $stream === 'file'
            ? ChildProcess::run(['sh', '-c', '{ printf head; "$0" -r "$1"; printf tail; } > "$2"', \PHP_BINARY, $script, $captured])
            : ChildProcess::run([\PHP_BINARY, '-r', $script]);

        self::assertSame(0, $run['exitCode'], $run['stderr']);
        match ($stream) {
            'file' => self::assertSame('headpayloadtail', file_get_contents($captured)),
            'stdout' => self::assertSame('payload', $run['stdout']),
            default => self::assertSame('payload', $run['stderr']),
        };
    }

    /** A descriptor the process does not hold is not a file to create. */
    #[Test]
    public function itRefusesADescriptorTheProcessDoesNotHoldBeforeAnyWork(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('/dev/fd/97');

        (new ArtifactFile('/dev/fd/97', '--output'))->refuseUnwritable();
    }

    /** @return iterable<string, array{string}> */
    public static function provideDirectoryNames(): iterable
    {
        yield 'a directory that does not exist' => ['nodir/'];
        yield 'an existing file' => ['afile/'];
        yield 'an existing directory' => ['adir/'];
        yield 'a dangling link to a directory name' => ['link-to-nodir'];
        yield 'a link to a file name ending in a slash' => ['link-to-afile'];
    }

    /** A trailing slash names a directory, which the write cannot become — whether written or reached through a link. */
    #[Test]
    #[DataProvider('provideDirectoryNames')]
    public function itRefusesAPathEndingInASlashBeforeAnyWork(string $name): void
    {
        file_put_contents($this->directory . '/afile', 'x');
        mkdir($this->directory . '/adir');
        // PHP's symlink() refuses a target naming a file with a trailing slash on macOS.
        foreach (['nodir/' => 'link-to-nodir', 'afile/' => 'link-to-afile'] as $linkTarget => $link) {
            self::assertSame(0, ChildProcess::run(['ln', '-s', $linkTarget, $this->directory . '/' . $link])['exitCode']);
        }

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('directory');

        (new ArtifactFile($this->directory . '/' . $name, '--output'))->refuseUnwritable();
    }

    /** @return list<string> */
    private function entries(): array
    {
        $entries = scandir($this->directory);
        self::assertIsArray($entries);

        return array_values(array_diff($entries, ['.', '..']));
    }
}
