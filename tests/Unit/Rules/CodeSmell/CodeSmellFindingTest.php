<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\CodeSmell;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\OccurrenceKey;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\CodeSmell\CodeSmellFinding;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @phpstan-import-type CodeSmellEntry from CodeSmellFinding
 */
#[CoversClass(CodeSmellFinding::class)]
final class CodeSmellFindingTest extends TestCase
{
    #[Test]
    public function itKeepsTheApprovedInternalSurfaceClosed(): void
    {
        $class = new ReflectionClass(CodeSmellFinding::class);
        $constructor = $class->getConstructor();

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        $docComment = $class->getDocComment();
        self::assertStringContainsString('@internal', $docComment !== false ? $docComment : '');
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        self::assertSame(
            ['location', 'subject', 'extra', 'hasExtra', 'promoted', 'hasPromoted'],
            array_map(static fn($parameter): string => $parameter->getName(), $constructor->getParameters()),
        );

        $publicMethods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($publicMethods);
        self::assertSame(['fromEntry', 'toViolation'], $publicMethods);
        self::assertSame([], $class->getProperties(ReflectionProperty::IS_PUBLIC));
    }

    /**
     * @param CodeSmellEntry $entry
     */
    #[Test]
    #[DataProvider('subjectEntries')]
    public function itProjectsEverySupportedSubjectShape(
        array $entry,
        string $expectedSubject,
        int $expectedLine,
        string $expectedExtra,
        bool $expectedHasExtra,
        bool $expectedPromoted,
        bool $expectedHasPromoted,
    ): void {
        $file = RelativePath::fromString('src/Example.php');
        $fileSymbol = SymbolPath::forFile($file);

        $violation = CodeSmellFinding::fromEntry($entry, $file)->toViolation(
            $fileSymbol,
            'code-smell.example',
            'example',
            Severity::Warning,
            'Example smell',
            'Fix the example.',
        );

        self::assertSame($expectedSubject, $violation->subject->toCanonical());
        self::assertSame($fileSymbol, $violation->symbolPath);
        self::assertSame('src/Example.php', $violation->location->pathString());
        self::assertSame($expectedLine, $violation->location->line);
        self::assertTrue($violation->location->precise);
        self::assertSame('code-smell.example', $violation->ruleName);
        self::assertSame('code-smell.example', $violation->violationCode);
        self::assertSame('Example smell', $violation->message);
        self::assertSame(Severity::Warning, $violation->severity);
        self::assertSame(1.0, $violation->metricValue);
        self::assertSame('Fix the example.', $violation->recommendation);
        self::assertSame(
            OccurrenceKey::semantic('example', [
                'type' => 'example',
                'extra' => $expectedExtra,
                'hasExtra' => $expectedHasExtra,
                'promoted' => $expectedPromoted,
                'hasPromoted' => $expectedHasPromoted,
            ])->value,
            $violation->occurrenceKey?->value,
        );
    }

    /**
     * @return iterable<string, array{CodeSmellEntry, string, int, string, bool, bool, bool}>
     */
    public static function subjectEntries(): iterable
    {
        yield 'file' => [
            ['subjectKind' => 'file', 'line' => 7],
            'file:src/Example.php',
            7,
            '',
            false,
            false,
            false,
        ];
        yield 'class declaration' => [
            ['subjectKind' => 'declaration', 'logicalKind' => 'class', 'namespace' => 'App', 'class' => 'Example', 'startFilePos' => 10, 'line' => 11],
            'declaration:class:App\\Example@src/Example.php:10',
            11,
            '',
            false,
            false,
            false,
        ];
        yield 'method declaration' => [
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'method',
                'namespace' => 'App',
                'class' => 'Example',
                'member' => 'run',
                'startFilePos' => 20,
                'collisionOrdinal' => 1,
                'line' => 21,
                'extra' => 'flag',
            ],
            'declaration:callable:App\\Example::run@src/Example.php:20#1',
            21,
            'flag',
            true,
            false,
            false,
        ];
        yield 'function declaration' => [
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'function',
                'namespace' => 'App',
                'member' => 'run',
                'startFilePos' => 30,
                'line' => 31,
                'promoted' => false,
            ],
            'declaration:func:App::run@src/Example.php:30',
            31,
            '',
            false,
            false,
            true,
        ];
    }

    /**
     * @param CodeSmellEntry $entry
     */
    #[Test]
    #[DataProvider('invalidSubjectEntries')]
    public function itRejectsInvalidEntries(array $entry, string $expectedMessage): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage($expectedMessage);

        CodeSmellFinding::fromEntry(
            $entry,
            RelativePath::fromString('src/Example.php'),
        );
    }

    /**
     * @return iterable<string, array{CodeSmellEntry, string}>
     */
    public static function invalidSubjectEntries(): iterable
    {
        yield 'missing subject kind' => [
            ['line' => 1],
            'Metric subject component "subjectKind" must be a string',
        ];
        yield 'integer subject kind' => [
            ['subjectKind' => 1, 'line' => 1],
            'Metric subject component "subjectKind" must be a string',
        ];
        yield 'boolean subject kind' => [
            ['subjectKind' => true, 'line' => 1],
            'Metric subject component "subjectKind" must be a string',
        ];
        yield 'float subject kind' => [
            ['subjectKind' => 1.5, 'line' => 1],
            'Metric subject component "subjectKind" must be a string',
        ];
        yield 'unknown subject kind' => [
            ['subjectKind' => 'unknown', 'line' => 1],
            'Metric subject component subjectKind must be file or declaration',
        ];
        yield 'missing logical kind' => [
            ['subjectKind' => 'declaration', 'line' => 1],
            'Metric subject component "logicalKind" must be a string',
        ];
        yield 'integer logical kind' => [
            ['subjectKind' => 'declaration', 'logicalKind' => 1, 'line' => 1],
            'Metric subject component "logicalKind" must be a string',
        ];
        yield 'boolean logical kind' => [
            ['subjectKind' => 'declaration', 'logicalKind' => true, 'line' => 1],
            'Metric subject component "logicalKind" must be a string',
        ];
        yield 'float logical kind' => [
            ['subjectKind' => 'declaration', 'logicalKind' => 1.5, 'line' => 1],
            'Metric subject component "logicalKind" must be a string',
        ];
        yield 'unknown logical kind' => [
            ['subjectKind' => 'declaration', 'logicalKind' => 'unknown', 'line' => 1],
            'Metric subject component logicalKind must be class, method, or function',
        ];
        yield 'boolean namespace' => [
            ['subjectKind' => 'declaration', 'logicalKind' => 'class', 'namespace' => true, 'line' => 1],
            'Missing metric subject component "namespace"',
        ];
        yield 'missing method member' => [
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'method',
                'namespace' => 'App',
                'class' => 'Example',
                'startFilePos' => 1,
                'line' => 1,
            ],
            'Missing metric subject component "member"',
        ];
        yield 'string source position' => [
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'class',
                'namespace' => 'App',
                'class' => 'Example',
                'startFilePos' => '1',
                'line' => 1,
            ],
            'Metric subject component "startFilePos" must be a non-negative integer',
        ];
        yield 'negative collision ordinal' => [
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'class',
                'namespace' => 'App',
                'class' => 'Example',
                'startFilePos' => 1,
                'collisionOrdinal' => -1,
                'line' => 1,
            ],
            'Metric subject component "collisionOrdinal" must be a non-negative integer',
        ];
    }

    #[Test]
    public function itPreservesScalarCastsAndOptionalKeyPresence(): void
    {
        $file = RelativePath::fromString('src/Example.php');
        $violation = CodeSmellFinding::fromEntry([
            'subjectKind' => 'file',
            'line' => '8.9',
            'extra' => 12,
            'promoted' => '0',
        ], $file)->toViolation(
            SymbolPath::forFile($file),
            'rule',
            'smell',
            Severity::Warning,
            'message',
            null,
        );

        self::assertSame('src/Example.php', $violation->location->pathString());
        self::assertSame(8, $violation->location->line);
        self::assertSame(
            OccurrenceKey::semantic('smell', [
                'type' => 'smell',
                'extra' => '12',
                'hasExtra' => true,
                'promoted' => false,
                'hasPromoted' => true,
            ])->value,
            $violation->occurrenceKey?->value,
        );
    }

    #[Test]
    public function itDistinguishesPresenceFlagsAndKeepsCanonicalOccurrencesStable(): void
    {
        $file = RelativePath::fromString('src/Example.php');
        $fileSymbol = SymbolPath::forFile($file);
        $withoutOptionalFields = CodeSmellFinding::fromEntry(['subjectKind' => 'file', 'line' => 8], $file)
            ->toViolation($fileSymbol, 'rule', 'smell', Severity::Warning, 'message', null);
        $withEmptyExtra = CodeSmellFinding::fromEntry(['subjectKind' => 'file', 'line' => 8, 'extra' => ''], $file)
            ->toViolation($fileSymbol, 'rule', 'smell', Severity::Warning, 'message', null);
        $withFalsePromoted = CodeSmellFinding::fromEntry(['subjectKind' => 'file', 'line' => 8, 'promoted' => false], $file)
            ->toViolation($fileSymbol, 'rule', 'smell', Severity::Warning, 'message', null);
        $ordered = CodeSmellFinding::fromEntry(['subjectKind' => 'file', 'line' => 8, 'extra' => 'flag', 'promoted' => true], $file)
            ->toViolation($fileSymbol, 'rule', 'smell', Severity::Warning, 'message', null);
        $reordered = CodeSmellFinding::fromEntry(['promoted' => true, 'extra' => 'flag', 'line' => 8, 'subjectKind' => 'file'], $file)
            ->toViolation($fileSymbol, 'rule', 'smell', Severity::Warning, 'message', null);

        self::assertNotSame($withoutOptionalFields->occurrenceKey?->value, $withEmptyExtra->occurrenceKey?->value);
        self::assertNotSame($withoutOptionalFields->occurrenceKey?->value, $withFalsePromoted->occurrenceKey?->value);
        self::assertSame(
            $ordered->occurrenceKey?->value,
            $reordered->occurrenceKey?->value,
        );
    }
}
