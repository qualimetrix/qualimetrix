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
 * A behavioural case proves one seam. This proves there is no second seam:
 * every way of reaching the process error stream that the enumeration found —
 * `getErrorOutput()`, a section built by hand, a raw write to `STDERR` — is
 * spelled the same way in the source and can be counted. A new writer added
 * anywhere in `src/` fails here rather than silently reintroducing a second
 * owner, which is the defect this package removed.
 *
 * Every exception is a row with a reason. Adding a row is a decision about
 * ownership, and it is meant to be argued for in review.
 */
#[CoversClass(ErrorStream::class)]
final class ErrorStreamSoleOwnerTest extends TestCase
{
    /**
     * @var array<string, string> path relative to `src/` => why it is not the
     *                            owner's business
     */
    private const array ALLOWED = [
        'Infrastructure/Console/ErrorStream.php'
            => 'the owner itself',
        'Infrastructure/Git/GitClient.php'
            => 'Symfony\\Component\\Process::getErrorOutput() returns a captured string, not a stream',
        'Infrastructure/Parallel/WorkerBootstrap.php'
            => 'a worker subprocess writing to its own STDERR, which amphp reads through a pipe',
    ];

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
            $path = self::sourceRoot() . '/' . $relative;
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

                if ($token[1] === 'fwrite' && self::writesToStandardError($tokens, $i)) {
                    $found[$token[2]] = 'fwrite(STDERR)';
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

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function writesToStandardError(array $tokens, int $index): bool
    {
        for ($i = $index + 1, $limit = min($index + 8, \count($tokens)); $i < $limit; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)
                && \in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)
                && strtoupper(ltrim($token[1], '\\')) === 'STDERR') {
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
        $root = self::sourceRoot();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            yield substr($file->getPathname(), \strlen($root) + 1) => $file->getPathname();
        }
    }

    private static function sourceRoot(): string
    {
        return \dirname(__DIR__, 4) . '/src';
    }
}
