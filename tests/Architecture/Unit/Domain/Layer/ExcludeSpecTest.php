<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Domain\Layer;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\Layer\ExcludeSpec;
use Qualimetrix\Architecture\Domain\Layer\MatchMode;
use stdClass;

#[CoversClass(ExcludeSpec::class)]
final class ExcludeSpecTest extends TestCase
{
    #[Test]
    public function defaultsToAnyMatchMode(): void
    {
        $spec = new ExcludeSpec(patterns: ['App\\Legacy\\**']);

        self::assertSame(MatchMode::Any, $spec->mode);
    }

    #[Test]
    public function acceptsExplicitAllMatchMode(): void
    {
        $spec = new ExcludeSpec(patterns: ['App\\Legacy\\**'], mode: MatchMode::All);

        self::assertSame(MatchMode::All, $spec->mode);
    }

    #[Test]
    public function exposesAllCriterionLists(): void
    {
        $spec = new ExcludeSpec(
            patterns: ['App\\Legacy\\**'],
            suffix: ['Bridge'],
            attributes: ['App\\Deprecated'],
            implements: ['App\\Adapter'],
            extends: ['App\\BaseLegacy'],
            mode: MatchMode::All,
        );

        self::assertSame(['App\\Legacy\\**'], $spec->patterns);
        self::assertSame(['Bridge'], $spec->suffix);
        self::assertSame(['App\\Deprecated'], $spec->attributes);
        self::assertSame(['App\\Adapter'], $spec->implements);
        self::assertSame(['App\\BaseLegacy'], $spec->extends);
    }

    /**
     * @return iterable<string, array{0: array<string, list<string>>}>
     */
    public static function singleCriterionProvider(): iterable
    {
        yield 'patterns' => [['patterns' => ['App\\Foo']]];
        yield 'suffix' => [['suffix' => ['Bridge']]];
        yield 'attributes' => [['attributes' => ['App\\Deprecated']]];
        yield 'implements' => [['implements' => ['App\\Adapter']]];
        yield 'extends' => [['extends' => ['App\\BaseClass']]];
    }

    /**
     * @param array<string, list<string>> $criteria
     */
    #[DataProvider('singleCriterionProvider')]
    #[Test]
    public function acceptsSingleNonEmptyCriterion(array $criteria): void
    {
        $spec = new ExcludeSpec(
            patterns: $criteria['patterns'] ?? [],
            suffix: $criteria['suffix'] ?? [],
            attributes: $criteria['attributes'] ?? [],
            implements: $criteria['implements'] ?? [],
            extends: $criteria['extends'] ?? [],
        );

        // At least one criterion must be non-empty — constructor does not throw.
        self::assertSame(MatchMode::Any, $spec->mode);
    }

    #[Test]
    public function throwsWhenEveryCriterionListIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ExcludeSpec must declare at least one non-empty criterion list');

        new ExcludeSpec();
    }

    /**
     * @return iterable<string, array{0: string, 1: list<mixed>}>
     */
    public static function nonStringEntryProvider(): iterable
    {
        yield 'patterns null' => ['patterns', [null]];
        yield 'patterns int' => ['patterns', [42]];
        yield 'suffix object' => ['suffix', [new stdClass()]];
        yield 'attributes bool' => ['attributes', [true]];
        yield 'implements array' => ['implements', [['nested']]];
        yield 'extends float' => ['extends', [3.14]];
    }

    /**
     * @param list<mixed> $values
     */
    #[DataProvider('nonStringEntryProvider')]
    #[Test]
    public function throwsOnNonStringEntry(string $kind, array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ExcludeSpec ' . $kind . '[0] must be a string,');

        new ExcludeSpec(...[$kind => $values]);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function criterionKindProvider(): iterable
    {
        yield 'patterns' => ['patterns'];
        yield 'suffix' => ['suffix'];
        yield 'attributes' => ['attributes'];
        yield 'implements' => ['implements'];
        yield 'extends' => ['extends'];
    }

    #[DataProvider('criterionKindProvider')]
    #[Test]
    public function throwsOnEmptyStringEntry(string $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ExcludeSpec ' . $kind . '[0] must not be empty.');

        new ExcludeSpec(...[$kind => ['']]);
    }
}
