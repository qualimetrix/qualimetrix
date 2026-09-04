<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The enumeration of error-stream writers, turned into a guard.
 *
 * A behavioural case proves one seam. This proves there is no second seam, in
 * both of the forms a second seam can take.
 *
 * A second *writer* is a way of reaching the process error stream that does not
 * go through the owner: `getErrorOutput()`, a section built by hand, or any of
 * the raw sinks PHP offers. The scan is of spellings, so the vocabulary is the
 * guarantee: it must cover every standard way of writing to `STDERR`, not the
 * three that happened to exist when the enumeration was made.
 *
 * A second *instance* is the same defect with everything spelled correctly —
 * two `ErrorStream` objects each keeping a private section list, redrawing
 * around nothing. Only the composition root may construct one, so `new
 * ErrorStream` anywhere in the shipped code is that defect, and the constructors
 * take the owner as a required argument so it cannot be reintroduced by
 * omission.
 *
 * Both the library and the entry point are scanned: `bin/qmx` is shipped code
 * and writes to `STDERR` before an autoloader exists.
 *
 * Every exception is a row with a reason. Adding a row is a decision about
 * ownership, and it is meant to be argued for in review.
 */
#[CoversClass(ErrorStream::class)]
final class ErrorStreamSoleOwnerTest extends TestCase
{
    /**
     * @var array<string, string> path relative to the repository root => why it
     *                            is not the owner's business
     */
    private const array ALLOWED = [
        'src/Infrastructure/Console/ErrorStream.php'
            => 'the owner itself',
        'src/Infrastructure/Git/GitClient.php'
            => 'Symfony\\Component\\Process::getErrorOutput() returns a captured string, not a stream',
        'src/Infrastructure/Parallel/WorkerBootstrap.php'
            => 'a worker subprocess writing to its own STDERR, which amphp reads through a pipe',
        'bin/qmx'
            => 'the missing-autoloader message: it is written before any class of this project can be loaded',
    ];

    /**
     * Every function in this list takes the stream or its name as an argument,
     * so one look-ahead recognises all of them.
     *
     * @var list<string>
     */
    private const array STREAM_WRITES = ['fwrite', 'fputs', 'fprintf', 'vfprintf', 'file_put_contents'];

    #[Test]
    public function itFindsNoSecondWriterIntoTheErrorStream(): void
    {
        $found = [];

        foreach (self::sourceFiles() as $relative => $path) {
            $writers = self::errorStreamWritersIn((string) file_get_contents($path));
            if ($writers === []) {
                continue;
            }

            if (\array_key_exists($relative, self::ALLOWED)) {
                continue;
            }

            foreach ($writers as $line => $what) {
                $found[] = \sprintf('%s:%d %s', $relative, $line, $what);
            }
        }

        self::assertSame([], $found, 'these reach the error stream without going through its owner');
    }

    #[Test]
    public function itKeepsEveryAllowedExceptionReal(): void
    {
        // An allowance that stopped matching anything is a claim about the code
        // that the code no longer makes.
        $stale = [];

        foreach (array_keys(self::ALLOWED) as $relative) {
            $path = self::repositoryRoot() . '/' . $relative;
            if (!is_file($path) || self::errorStreamWritersIn((string) file_get_contents($path)) === []) {
                $stale[] = $relative;
            }
        }

        self::assertSame([], $stale, 'these allowances no longer describe anything in the source');
    }

    /** @return array<int, string> line => what was found */
    private static function errorStreamWritersIn(string $code): array
    {
        $tokens = token_get_all($code);
        $found = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (\is_array($token) && $token[0] === \T_STRING) {
                if ($token[1] === 'getErrorOutput' && self::isCall($tokens, $i)) {
                    $found[$token[2]] = 'getErrorOutput()';
                }

                if ($token[1] === 'ConsoleSectionOutput' && self::isConstructed($tokens, $i)) {
                    $found[$token[2]] = 'new ConsoleSectionOutput';
                }

                if (\in_array($token[1], self::STREAM_WRITES, true)
                    && self::writesToStandardError($tokens, $i)
                ) {
                    $found[$token[2]] = $token[1] . '(STDERR)';
                }

                if ($token[1] === 'error_log' && self::isCall($tokens, $i) === false) {
                    $found[$token[2]] = 'error_log()';
                }

                if ($token[1] === 'ErrorStream' && self::isConstructed($tokens, $i)) {
                    $found[$token[2]] = 'new ErrorStream (a second owner)';
                }
            }
        }

        return $found;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function isCall(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return \is_array($token)
                && \in_array($token[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true);
        }

        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function isConstructed(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_NS_SEPARATOR], true)) {
                continue;
            }

            return \is_array($token) && $token[0] === \T_NEW;
        }

        return false;
    }

    /**
     * The stream is always the first argument, spelled either as the `STDERR`
     * constant or as a `php://stderr` wrapper.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function writesToStandardError(array $tokens, int $index): bool
    {
        for ($i = $index + 1, $limit = min($index + 8, \count($tokens)); $i < $limit; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)
                && \in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)
                && strtoupper(ltrim($token[1], '\\')) === 'STDERR') {
                return true;
            }
            if (\is_array($token)
                && $token[0] === \T_CONSTANT_ENCAPSED_STRING
                && str_contains(strtolower($token[1]), 'php://stderr')) {
                return true;
            }
            if ($token === ',') {
                return false;
            }
        }

        return false;
    }

    /** @return iterable<string, string> */
    private static function sourceFiles(): iterable
    {
        $root = self::repositoryRoot();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            yield substr($file->getPathname(), \strlen($root) + 1) => $file->getPathname();
        }

        yield 'bin/qmx' => $root . '/bin/qmx';
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
