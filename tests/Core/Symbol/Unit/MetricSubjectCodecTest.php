<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;
use ReflectionMethod;

#[CoversClass(MetricSubjectCodec::class)]
final class MetricSubjectCodecTest extends TestCase
{
    #[Test]
    public function itRoundTripsEverySupportedShapeWithTheCallerFile(): void
    {
        $file = RelativePath::fromString('src/Example.php');

        self::assertSame('file:src/Example.php', MetricSubjectCodec::decode(MetricSubjectCodec::encodeFile(), $file)->toCanonical());
        self::assertSame('declaration:class:App\\Thing@src/Example.php:11', MetricSubjectCodec::decode(MetricSubjectCodec::encodeClass('App', 'Thing', 11), $file)->toCanonical());
        self::assertSame('declaration:callable:App\\Thing::run@src/Example.php:12#0', MetricSubjectCodec::decode(MetricSubjectCodec::encodeMethod('App', 'Thing', 'run', 12, 0), $file)->toCanonical());
        self::assertSame('declaration:func:App::helper@src/Example.php:13', MetricSubjectCodec::decode(MetricSubjectCodec::encodeFunction('App', 'helper', 13), $file)->toCanonical());
    }

    #[Test]
    public function itRejectsMissingUnknownAndWrongTypedComponents(): void
    {
        $file = RelativePath::fromString('src/Example.php');

        $this->expectException(InvalidArgumentException::class);
        MetricSubjectCodec::decode(['subjectKind' => 'declaration', 'logicalKind' => 'method'], $file);
    }

    #[Test]
    public function itRejectsEveryMalformedWireShapeWithoutUsingTheFileSystem(): void
    {
        $file = RelativePath::fromString('src/Authoritative.php');
        $invalid = [
            ['subjectKind' => null],
            ['subjectKind' => 'unknown'],
            ['subjectKind' => 'file', 'line' => 1],
            ['subjectKind' => 'declaration', 'logicalKind' => 'class', 'namespace' => 'App', 'class' => 'Thing', 'startFilePos' => '12'],
            ['subjectKind' => 'declaration', 'logicalKind' => 'function', 'namespace' => 'App', 'class' => 'Thing', 'member' => 'run', 'startFilePos' => 12],
            ['subjectKind' => 'declaration', 'logicalKind' => 'method', 'namespace' => 'App', 'class' => 'Thing', 'member' => 'run', 'startFilePos' => 12, 'collisionOrdinal' => -1],
        ];
        $decode = new ReflectionMethod(MetricSubjectCodec::class, 'decode');

        foreach ($invalid as $components) {
            try {
                $decode->invoke(null, $components, $file);
                self::fail('Malformed subject components must fail fast');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function itOmitsTheOrdinalNormallyAndPreservesItForSamePositionCollisions(): void
    {
        $file = RelativePath::fromString('src/Example.php');

        $ordinary = MetricSubjectCodec::encodeMethod('App', 'Thing', 'run', 12);
        self::assertArrayNotHasKey('collisionOrdinal', $ordinary);
        self::assertSame(
            'declaration:callable:App\\Thing::run@src/Example.php:12#1',
            MetricSubjectCodec::decode(MetricSubjectCodec::encodeMethod('App', 'Thing', 'run', 12, 1), $file)->toCanonical(),
        );
    }

    #[Test]
    public function itDecodesEveryEntryShapeWhileUsingTheContainerFile(): void
    {
        $file = RelativePath::fromString('src/Container.php');
        $entries = [
            ['subjectKind' => 'file'],
            MetricSubjectCodec::encodeClass('App', 'Thing', 11),
            MetricSubjectCodec::encodeMethod('App', 'Thing', 'run', 12, 1),
            MetricSubjectCodec::encodeFunction('App', 'helper', 13),
        ];

        self::assertSame(
            [
                'file:src/Container.php',
                'declaration:class:App\Thing@src/Container.php:11',
                'declaration:callable:App\Thing::run@src/Container.php:12#1',
                'declaration:func:App::helper@src/Container.php:13',
            ],
            array_map(static fn(array $entry): string => MetricSubjectCodec::decodeEntry($entry, $file)->toCanonical(), $entries),
        );
    }

    #[Test]
    public function itIgnoresUnrelatedEntryScalarsAndNeverAcceptsAnEmbeddedPath(): void
    {
        $subject = MetricSubjectCodec::decodeEntry(
            [
                ...MetricSubjectCodec::encodeMethod('App', 'Thing', 'run', 12),
                'file' => 'src/Injected.php',
                'line' => 99,
                'enabled' => true,
                'ratio' => 1.5,
            ],
            RelativePath::fromString('src/Container.php'),
        );

        self::assertSame('declaration:callable:App\Thing::run@src/Container.php:12', $subject->toCanonical());
    }

    #[Test]
    public function itDropsWrongTypedRetainedEntryComponentsBeforeGrammarValidation(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Missing metric subject component "startFilePos"'));

        MetricSubjectCodec::decodeEntry(
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'class',
                'namespace' => 'App',
                'class' => 'Thing',
                'startFilePos' => false,
            ],
            RelativePath::fromString('src/Container.php'),
        );
    }

    #[Test]
    #[DataProvider('requiredWrongScalarProvider')]
    public function itRejectsEveryRequiredRetainedBoolOrFloat(string $key, bool|float $value, string $message): void
    {
        $entry = [
            'subjectKind' => 'declaration',
            'logicalKind' => 'method',
            'namespace' => 'App',
            'class' => 'Thing',
            'member' => 'run',
            'startFilePos' => 12,
            $key => $value,
        ];

        $this->expectExceptionObject(new InvalidArgumentException($message));
        MetricSubjectCodec::decodeEntry($entry, RelativePath::fromString('src/Container.php'));
    }

    /** @return iterable<string, array{string, bool|float, string}> */
    public static function requiredWrongScalarProvider(): iterable
    {
        $messages = [
            'subjectKind' => 'Metric subject component "subjectKind" must be a string',
            'logicalKind' => 'Metric subject component "logicalKind" must be a string',
            'namespace' => 'Missing metric subject component "namespace"',
            'class' => 'Missing metric subject component "class"',
            'member' => 'Missing metric subject component "member"',
            'startFilePos' => 'Missing metric subject component "startFilePos"',
        ];
        foreach ($messages as $key => $message) {
            yield $key . '-bool' => [$key, false, $message];
            yield $key . '-float' => [$key, 1.5, $message];
        }
    }

    #[Test]
    #[DataProvider('optionalWrongScalarProvider')]
    public function itDropsOptionalRetainedBoolOrFloat(bool|float $value): void
    {
        $subject = MetricSubjectCodec::decodeEntry(
            [...MetricSubjectCodec::encodeMethod('App', 'Thing', 'run', 12), 'collisionOrdinal' => $value],
            RelativePath::fromString('src/Container.php'),
        );

        self::assertSame('declaration:callable:App\Thing::run@src/Container.php:12', $subject->toCanonical());
    }

    /** @return iterable<string, array{bool|float}> */
    public static function optionalWrongScalarProvider(): iterable
    {
        yield 'bool' => [false];
        yield 'float' => [1.5];
    }

    #[Test]
    public function itFailsFastWhenEntryGrammarIsMissingAfterWhitelisting(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Metric subject component "subjectKind" must be a string'));

        MetricSubjectCodec::decodeEntry(
            ['file' => 'src/Injected.php', 'unknown' => 'declaration'],
            RelativePath::fromString('src/Container.php'),
        );
    }
}
