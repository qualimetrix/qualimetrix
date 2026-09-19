<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RuleOptionKeys;

use BackedEnum;
use Closure;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassMode;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveOptions;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Every `oneOfIgnoringCase` declaration in the tree, paired with the reader
 * that actually consumes it, must agree — on the set of words itself, on
 * each word's own spelling, on that word with its case flipped, and on one
 * word outside the set.
 *
 * This is a two-witness check: one witness is the DECLARATION
 * (`acceptedOptionKeys()->shapeOf($key)->wordsDeclared()`); the other is not
 * a second hand-written word list but the backed enum each reader itself
 * builds its accepted set from (`Severity::cases()`,
 * `UnassignedClassMode::cases()`) together with the READER's own behaviour
 * (`$optionsClass::fromArray()`, called directly so no seam sits in front of
 * it). A word list copied out of the reader by hand would drift the same way
 * the declaration itself was found to.
 *
 * `coupling.cbo`'s `scope` is deliberately not one of the cases here: it
 * declares {@see RuleOptionShape::oneOf()} (case-sensitive) in front of a
 * reader that also compares strictly, so the two already agree by
 * construction and folding would be the wrong thing to test for it.
 *
 * "Every declaration in the tree" is a hand-written provider, so
 * {@see itPairsEveryOneOfIgnoringCaseDeclarationInTheTreeWithAReader()} reads
 * the tree and holds the provider to it: a fourth declaration added without a
 * row is the silent gap the sentence above would otherwise be covering for.
 */
#[CoversClass(RuleOptionShape::class)]
final class RuleOptionWordSetDeclarationAgreementTest extends TestCase
{
    /**
     * The declaration's word set must be exactly the reader's enum, no more
     * and no fewer — catches a word dropped from (or added to) the
     * declaration that the reader's own vocabulary does not agree with.
     *
     * @param class-string<RuleOptionsInterface> $optionsClass
     * @param Closure(string): array<string, mixed> $buildConfig
     * @param class-string<BackedEnum> $enumClass
     */
    #[Test]
    #[DataProvider('provideDeclaredReaders')]
    public function itDeclaresExactlyTheWordsTheEnumHolds(
        string $optionsClass,
        string $key,
        Closure $buildConfig,
        string $enumClass,
    ): void {
        $declared = self::wordsOf(self::shapeOf($optionsClass, $key));
        $fromEnum = self::wordsOf($enumClass);

        sort($declared);
        sort($fromEnum);

        self::assertSame($fromEnum, $declared, \sprintf(
            '%s\'s declaration for "%s" must name exactly %s\'s cases',
            $optionsClass,
            $key,
            $enumClass,
        ));
    }

    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     * @param Closure(string): array<string, mixed> $buildConfig
     * @param class-string<BackedEnum> $enumClass
     */
    #[Test]
    #[DataProvider('provideDeclaredReaders')]
    public function itAcceptsEveryEnumWordExactlyAsWritten(
        string $optionsClass,
        string $key,
        Closure $buildConfig,
        string $enumClass,
    ): void {
        $shape = self::shapeOf($optionsClass, $key);

        foreach (self::wordsOf($enumClass) as $word) {
            self::assertTrue($shape->matches($word), \sprintf('declaration should accept "%s"', $word));
            self::assertTrue(
                self::readerAccepts($optionsClass, $buildConfig($word)),
                \sprintf('%s::fromArray() should accept "%s"', $optionsClass, $word),
            );
        }
    }

    /**
     * The declaration's fold and the reader's fold must be the SAME answer
     * for a case-flipped spelling of every word the enum holds: both accept
     * it, or both refuse it. Either side disagreeing with the other is the
     * exact promise-wider/narrower-than-effect defect this class exists to
     * catch.
     *
     * @param class-string<RuleOptionsInterface> $optionsClass
     * @param Closure(string): array<string, mixed> $buildConfig
     * @param class-string<BackedEnum> $enumClass
     */
    #[Test]
    #[DataProvider('provideDeclaredReaders')]
    public function itAgreesWithItsReaderOnACaseFlippedSpelling(
        string $optionsClass,
        string $key,
        Closure $buildConfig,
        string $enumClass,
    ): void {
        $shape = self::shapeOf($optionsClass, $key);

        foreach (self::wordsOf($enumClass) as $word) {
            $flipped = strtoupper($word);

            self::assertSame(
                $shape->matches($flipped),
                self::readerAccepts($optionsClass, $buildConfig($flipped)),
                \sprintf(
                    'declaration and reader disagree on "%s" (case-flipped "%s") for %s::$%s',
                    $flipped,
                    $word,
                    $optionsClass,
                    $key,
                ),
            );
        }
    }

    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     * @param Closure(string): array<string, mixed> $buildConfig
     * @param class-string<BackedEnum> $enumClass
     */
    #[Test]
    #[DataProvider('provideDeclaredReaders')]
    public function itRefusesAWordOutsideTheDeclaredSet(
        string $optionsClass,
        string $key,
        Closure $buildConfig,
        string $enumClass,
    ): void {
        $shape = self::shapeOf($optionsClass, $key);

        self::assertFalse($shape->matches('bogus-word'));
        self::assertFalse(
            self::readerAccepts($optionsClass, $buildConfig('bogus-word')),
            \sprintf('%s::fromArray() should refuse an undeclared word', $optionsClass),
        );
    }

    /**
     * The provider is a hand list; this is the tree. Every
     * `RuleOptionShape::oneOfIgnoringCase()` declaration in `src/` must own a
     * row above, and every row must name a declaration that is still there.
     *
     * The scan reads source text rather than reflecting the options classes,
     * because a class the provider forgot is a class nothing here would load.
     */
    #[Test]
    public function itPairsEveryOneOfIgnoringCaseDeclarationInTheTreeWithAReader(): void
    {
        $declared = [];

        foreach (self::provideDeclaredReaders() as [$optionsClass, $key]) {
            $declared[] = $optionsClass . '::' . $key;
        }

        sort($declared);

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($entry->getPathname());

            if (preg_match_all(
                "/'([^']+)'\\s*=>\\s*RuleOptionShape::oneOfIgnoringCase\\(/",
                $source,
                $matches,
            ) === 0) {
                continue;
            }

            if (preg_match('/^namespace\\s+([\\w\\\\]+);/m', $source, $namespace) !== 1
                || preg_match('/^(?:final\\s+)?(?:readonly\\s+)?class\\s+(\\w+)/m', $source, $class) !== 1) {
                self::fail(\sprintf('%s declares a word set but no single class this scan can name.', $entry->getPathname()));
            }

            foreach ($matches[1] as $key) {
                $found[] = $namespace[1] . '\\' . $class[1] . '::' . $key;
            }
        }

        sort($found);

        self::assertSame($declared, $found, \sprintf(
            'provideDeclaredReaders() and the tree disagree about which option keys declare a'
            . ' case-folding word set. Only in the provider: %s. Only in the tree: %s.',
            self::listOrNone(array_diff($declared, $found)),
            self::listOrNone(array_diff($found, $declared)),
        ));
    }

    /**
     * @param array<int, string> $items
     */
    private static function listOrNone(array $items): string
    {
        return $items === [] ? '(none)' : implode(', ', $items);
    }

    /**
     * @return iterable<string, array{class-string<RuleOptionsInterface>, string, Closure(string): array<string, mixed>, class-string<BackedEnum>}>
     */
    public static function provideDeclaredReaders(): iterable
    {
        yield 'annotation.directive unused-directive-severity' => [
            InlineDirectiveOptions::class,
            'unused-directive-severity',
            static fn(string $word): array => ['unused_directive_severity' => $word],
            Severity::class,
        ];

        yield 'architecture.unassigned-class mode' => [
            UnassignedClassOptions::class,
            'mode',
            static fn(string $word): array => ['mode' => $word],
            UnassignedClassMode::class,
        ];

        yield 'architecture.layer-violation severity' => [
            LayerViolationOptions::class,
            'severity',
            static fn(string $word): array => ['severity' => $word],
            Severity::class,
        ];
    }

    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    private static function shapeOf(string $optionsClass, string $key): RuleOptionShape
    {
        $shape = $optionsClass::acceptedOptionKeys()->shapeOf(ConfigKeySpelling::normalize($key));
        self::assertNotNull($shape, \sprintf('%s does not declare a shape for "%s"', $optionsClass, $key));

        return $shape;
    }

    /**
     * @param RuleOptionShape|class-string<BackedEnum> $source
     *
     * @return list<string>
     */
    private static function wordsOf(RuleOptionShape|string $source): array
    {
        if ($source instanceof RuleOptionShape) {
            $words = $source->wordsDeclared();
            self::assertNotNull($words, 'shape under test is not a closed word set');

            return $words->words;
        }

        return array_map(static fn(BackedEnum $case): string => (string) $case->value, $source::cases());
    }

    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     * @param array<string, mixed> $config
     */
    private static function readerAccepts(string $optionsClass, array $config): bool
    {
        try {
            $optionsClass::fromArray($config);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
