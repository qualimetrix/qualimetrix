<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\OccurrenceKey;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Security\SecurityPatternFinding;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @phpstan-import-type SecurityPatternEntry from SecurityPatternFinding
 */
#[CoversClass(SecurityPatternFinding::class)]
final class SecurityPatternFindingTest extends TestCase
{
    #[Test]
    public function itKeepsTheApprovedInternalSurfaceClosed(): void
    {
        $class = new ReflectionClass(SecurityPatternFinding::class);
        $constructor = $class->getConstructor();

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        $docComment = $class->getDocComment();
        self::assertStringContainsString('@internal', $docComment !== false ? $docComment : '');
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        self::assertSame(
            ['location', 'subject', 'superglobal'],
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
     * @param SecurityPatternEntry $entry
     */
    #[Test]
    #[DataProvider('subjectEntries')]
    public function itProjectsEverySupportedSubjectShape(
        array $entry,
        string $expectedSubject,
        int $expectedLine,
        string $expectedSuperglobal,
    ): void {
        $file = RelativePath::fromString('src/Controller.php');
        $fileSymbol = SymbolPath::forFile($file);

        $violation = SecurityPatternFinding::fromEntry($entry, $file)->toViolation(
            $fileSymbol,
            'security.example',
            'example',
            Severity::Error,
            'Potential vulnerability',
            'Use a safe API.',
        );

        self::assertSame($expectedSubject, $violation->subject->toCanonical());
        self::assertSame($fileSymbol, $violation->symbolPath);
        self::assertSame('src/Controller.php', $violation->location->pathString());
        self::assertSame($expectedLine, $violation->location->line);
        self::assertTrue($violation->location->precise);
        self::assertSame('security.example', $violation->ruleName);
        self::assertSame('security.example', $violation->violationCode);
        self::assertSame($expectedSuperglobal === '' ? 'Potential vulnerability' : \sprintf('Potential vulnerability ($%s)', $expectedSuperglobal), $violation->message);
        self::assertSame(Severity::Error, $violation->severity);
        self::assertSame(1.0, $violation->metricValue);
        self::assertSame('Use a safe API.', $violation->recommendation);
        self::assertSame(
            OccurrenceKey::semantic('example', ['type' => 'example', 'superglobal' => $expectedSuperglobal])->value,
            $violation->occurrenceKey?->value,
        );
    }

    /**
     * @return iterable<string, array{SecurityPatternEntry, string, int, string}>
     */
    public static function subjectEntries(): iterable
    {
        yield 'file' => [
            ['subjectKind' => 'file', 'line' => 7],
            'file:src/Controller.php',
            7,
            '',
        ];
        yield 'class declaration' => [
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'class',
                'namespace' => 'App',
                'class' => 'Controller',
                'startFilePos' => 10,
                'line' => 11,
                'superglobal' => '_GET',
            ],
            'declaration:class:App\\Controller@src/Controller.php:10',
            11,
            '_GET',
        ];
        yield 'method declaration' => [
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'method',
                'namespace' => 'App',
                'class' => 'Controller',
                'member' => 'run',
                'startFilePos' => 20,
                'collisionOrdinal' => 1,
                'line' => 21,
                'superglobal' => '_POST',
            ],
            'declaration:callable:App\\Controller::run@src/Controller.php:20#1',
            21,
            '_POST',
        ];
        yield 'function declaration' => [
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'function',
                'namespace' => 'App',
                'member' => 'run',
                'startFilePos' => 30,
                'line' => 31,
            ],
            'declaration:func:App::run@src/Controller.php:30',
            31,
            '',
        ];
    }

    /**
     * @param SecurityPatternEntry $entry
     */
    #[Test]
    #[DataProvider('invalidSubjectEntries')]
    public function itRejectsInvalidEntries(array $entry, string $expectedMessage): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage($expectedMessage);

        SecurityPatternFinding::fromEntry(
            $entry,
            RelativePath::fromString('src/Controller.php'),
        );
    }

    /**
     * @return iterable<string, array{SecurityPatternEntry, string}>
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
        yield 'missing function member' => [
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'function',
                'namespace' => 'App',
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
                'class' => 'Controller',
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
                'class' => 'Controller',
                'startFilePos' => 1,
                'collisionOrdinal' => -1,
                'line' => 1,
            ],
            'Metric subject component "collisionOrdinal" must be a non-negative integer',
        ];
    }

    #[Test]
    public function itPreservesScalarCastsForLocationAndEvidence(): void
    {
        $file = RelativePath::fromString('src/Controller.php');
        $violation = SecurityPatternFinding::fromEntry([
            'subjectKind' => 'file',
            'line' => 8.9,
            'superglobal' => 12,
        ], $file)->toViolation(
            SymbolPath::forFile($file),
            'rule',
            'pattern',
            Severity::Error,
            'message',
            null,
        );

        self::assertSame('src/Controller.php', $violation->location->pathString());
        self::assertSame(8, $violation->location->line);
        self::assertSame('message ($12)', $violation->message);
        self::assertSame(
            OccurrenceKey::semantic('pattern', ['type' => 'pattern', 'superglobal' => '12'])->value,
            $violation->occurrenceKey?->value,
        );
    }

    #[Test]
    public function itNormalizesEmptySuperglobalAndKeepsCanonicalOccurrencesStable(): void
    {
        $file = RelativePath::fromString('src/Controller.php');
        $fileSymbol = SymbolPath::forFile($file);
        $absent = SecurityPatternFinding::fromEntry(['subjectKind' => 'file', 'line' => 8], $file)
            ->toViolation($fileSymbol, 'rule', 'pattern', Severity::Error, 'message', null);
        $empty = SecurityPatternFinding::fromEntry(['subjectKind' => 'file', 'line' => 8, 'superglobal' => ''], $file)
            ->toViolation($fileSymbol, 'rule', 'pattern', Severity::Error, 'message', null);
        $get = SecurityPatternFinding::fromEntry(['subjectKind' => 'file', 'line' => 8, 'superglobal' => '_GET'], $file)
            ->toViolation($fileSymbol, 'rule', 'pattern', Severity::Error, 'message', null);

        self::assertSame($absent->occurrenceKey?->value, $empty->occurrenceKey?->value);
        self::assertNotSame($absent->occurrenceKey?->value, $get->occurrenceKey?->value);
        self::assertSame('message', $absent->message);
        self::assertSame('message ($_GET)', $get->message);
    }
}
