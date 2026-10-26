<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\EditorSchema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;
use Qualimetrix\Analysis\Configuration\EditorSchema\NodeProjection;
use Qualimetrix\Analysis\Configuration\EditorSchema\ShorthandProjection;

#[CoversClass(ShorthandProjection::class)]
#[CoversClass(NodeProjection::class)]
final class ShorthandNodeTest extends TestCase
{
    use EditorSchemaAssertions;

    #[Test]
    public function itGivesTheShorthandTheFormOfItsFirstTarget(): void
    {
        $schema = self::documentWith(self::pair());

        self::assertSame(['integer', 'null'], $schema['properties']['key']['properties']['threshold']['type']);
        self::assertAccepted($schema, 'key: {threshold: 5}');
        self::assertRefusedAt($schema, 'key: {threshold: five}', '/key/threshold');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideSameMapWrites(): iterable
    {
        yield 'both written' => ['{threshold: 5, warning: 5}', false];
        yield 'target unwritten' => ['{threshold: 5, warning: ~}', true];
        yield 'shorthand unwritten' => ['{threshold: ~, warning: 5}', true];
        yield 'another spelling of each' => ['{voThreshold: 5, vo_warning: 3}', false];
        yield 'an unrelated key' => ['{vo-threshold: 5, warning: 3}', true];
    }

    #[Test]
    #[DataProvider('provideSameMapWrites')]
    public function itRefusesAShorthandBesideItsTargetOnlyWhenNeitherIsTilde(string $value, bool $accepted): void
    {
        $node = NodeSchema::map(
            ['warning' => self::integer(), 'error' => self::integer(), 'vo-warning' => self::integer(), 'vo-error' => self::integer()],
            Shorthand::spreading('threshold', ['warning', 'error']),
            Shorthand::spreading('vo-threshold', ['vo-warning', 'vo-error']),
        );

        self::assertVerdict(self::documentWith($node), 'key: ' . $value, $accepted);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideDottedWrites(): iterable
    {
        yield 'both written' => ['{scope: a, class: {scope: b}}', false];
        yield 'target unwritten' => ['{scope: a, class: {scope: ~}}', true];
        yield 'shorthand unwritten' => ['{scope: ~, class: {scope: b}}', true];
        yield 'intermediate map written []' => ['{scope: a, class: []}', true];
        yield 'intermediate map written {}' => ['{scope: a, class: {}}', true];
        yield 'intermediate map unwritten' => ['{scope: a, class: ~}', true];
    }

    #[Test]
    #[DataProvider('provideDottedWrites')]
    public function itFollowsADottedTargetThroughMapsOnly(string $value, bool $accepted): void
    {
        $node = NodeSchema::map(
            ['class' => NodeSchema::map(['scope' => NodeSchema::scalar(ScalarForm::String)])],
            Shorthand::spreading('scope', ['class.scope']),
        );

        self::assertVerdict(self::documentWith($node), 'key: ' . $value, $accepted);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideChainedWrites(): iterable
    {
        yield 'the chained shorthand' => ['{threshold: 5, callable: {threshold: 3}}', false];
        yield 'one of its leaves' => ['{threshold: 5, callable: {warning: 3}}', false];
        yield 'a leaf unwritten' => ['{threshold: 5, callable: {warning: ~}}', true];
        yield 'an empty intermediate' => ['{threshold: 5, callable: []}', true];
    }

    #[Test]
    #[DataProvider('provideChainedWrites')]
    public function itRefusesEveryKeyThatWritesALeafOfAChainedShorthand(string $value, bool $accepted): void
    {
        $node = NodeSchema::map(['callable' => self::pair()], Shorthand::spreading('threshold', ['callable.threshold']));

        self::assertVerdict(self::documentWith($node), 'key: ' . $value, $accepted);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideOverlappingShorthands(): iterable
    {
        yield 'two shorthands reaching one leaf' => ['{threshold: 5, warning: 3}', false];
        yield 'a nested leaf of the outer one' => ['{threshold: 5, class: {warning: 3}}', false];
        yield 'a nested shorthand reaching its leaf' => ['{warning: 5, class: {threshold: 3}}', false];
        yield 'a leaf it does not reach' => ['{warning: 5, class: {error: 3}}', true];
    }

    #[Test]
    #[DataProvider('provideOverlappingShorthands')]
    public function itRefusesShorthandsWhoseLeavesOverlap(string $value, bool $accepted): void
    {
        $node = NodeSchema::map(
            ['class' => self::pair(), 'namespace' => self::pair()],
            Shorthand::spreading('threshold', ['class.threshold', 'namespace.threshold']),
            Shorthand::spreading('warning', ['class.warning', 'namespace.warning']),
        );

        self::assertVerdict(self::documentWith($node), 'key: ' . $value, $accepted);
    }

    #[Test]
    public function itLeavesAShorthandToAMapOutOfTheConflicts(): void
    {
        $node = NodeSchema::map(
            ['options' => NodeSchema::map(['depth' => self::integer()])],
            Shorthand::spreading('settings', ['options']),
        );

        self::assertArrayNotHasKey('allOf', self::documentWith($node)['properties']['key']);
    }

    private static function pair(): NodeSchema
    {
        return NodeSchema::map(['warning' => self::integer(), 'error' => self::integer()], Shorthand::spreading('threshold', ['warning', 'error']));
    }

    private static function integer(): NodeSchema
    {
        return NodeSchema::scalar(ScalarForm::Integer);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function assertVerdict(array $schema, string $yaml, bool $accepted): void
    {
        if ($accepted) {
            self::assertAccepted($schema, $yaml);

            return;
        }

        self::assertRefusedAt($schema, $yaml, '/key');
    }
}
