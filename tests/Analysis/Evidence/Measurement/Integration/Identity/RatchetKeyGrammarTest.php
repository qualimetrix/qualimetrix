<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Integration\Identity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * No stored declaration key carries a position in the file.
 *
 * The check is a parse rather than a pattern match: `grep ':[0-9]\+"'` also
 * matches the `generated` timestamp and would pass on a baseline full of
 * offsets. The parser is exercised on hand-written keys first, because run
 * against the repository's own baseline alone it is only tested on data that
 * cannot fail.
 */
final class RatchetKeyGrammarTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?int}> */
    public static function provideDeclarationKeys(): iterable
    {
        yield 'plain method' => ['declaration:callable:App\Service::run@src/Service.php', 'src/Service.php', null];
        yield 'second declaration of one identity' => ['declaration:callable:App\Service::run@src/Service.php#1', 'src/Service.php', 1];
        yield 'closure ordinal belongs to the logical part' => ['declaration:callable:App\{closure#3}@src/Service.php', 'src/Service.php', null];
        yield 'anonymous class rank belongs to the logical part' => ['declaration:callable:App\{anonymous#2}::run@src/Service.php', 'src/Service.php', null];
        yield 'both, with a declaration ordinal of its own' => ['declaration:callable:App\{anonymous#2}::run@src/Service.php#4', 'src/Service.php', 4];
        // The split is on the LAST `@`, so a logical part carrying one — the
        // shape anonymous classes had before they were ranked — still resolves
        // to the file. The price is a file path containing `@`, which this
        // grammar deliberately does not promise to parse.
        yield 'logical part carrying an at sign' => ['declaration:callable:App\{anonymous@71}::run@src/Service.php', 'src/Service.php', null];
    }

    #[Test]
    #[DataProvider('provideDeclarationKeys')]
    public function itSplitsADeclarationKeyIntoItsFileAndItsOrdinal(string $key, string $file, ?int $ordinal): void
    {
        [$parsedFile, $parsedOrdinal] = self::parse($key);

        self::assertSame($file, $parsedFile);
        self::assertSame($ordinal, $parsedOrdinal);
    }

    #[Test]
    public function itFindsNoPositionInAnyDeclarationKeyOfTheRepositoryRatchet(): void
    {
        $keys = self::declarationKeys();

        self::assertNotSame([], $keys, 'The repository ratchet must contain declaration keys for this to prove anything');
        foreach ($keys as $key) {
            [$file] = self::parse($key);
            self::assertDoesNotMatchRegularExpression('/:\d+$/', $file, $key);
            self::assertStringEndsWith('.php', $file, $key);
        }
    }

    #[Test]
    public function itRejectsAKeyThatStillCarriesAPosition(): void
    {
        [$file] = self::parse('declaration:callable:App\Service::run@src/Service.php:1234');

        self::assertMatchesRegularExpression('/:\d+$/', $file);
    }

    /** @return list<string> */
    private static function declarationKeys(): array
    {
        $baseline = json_decode((string) file_get_contents(\dirname(__DIR__, 6) . '/qmx-baseline.json'), true);
        self::assertIsArray($baseline);
        self::assertIsArray($baseline['entries'] ?? null);

        return array_values(array_filter(
            array_keys($baseline['entries']),
            static fn(string $key): bool => str_starts_with($key, 'declaration:'),
        ));
    }

    /**
     * The file part follows the LAST `@`; an ordinal suffix is searched for
     * only in the tail after it, so a `#` inside the logical part — a closure
     * counter or an anonymous class rank — is never read as one.
     *
     * @return array{string, ?int}
     */
    private static function parse(string $key): array
    {
        $at = strrpos($key, '@');
        self::assertIsInt($at, $key);
        $tail = substr($key, $at + 1);

        if (preg_match('/^(?<file>.*)#(?<ordinal>\d+)$/', $tail, $matches) === 1) {
            return [$matches['file'], (int) $matches['ordinal']];
        }

        return [$tail, null];
    }
}
