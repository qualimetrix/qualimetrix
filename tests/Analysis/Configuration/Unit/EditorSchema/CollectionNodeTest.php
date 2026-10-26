<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\EditorSchema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\EditorSchema\NodeProjection;

#[CoversClass(NodeProjection::class)]
final class CollectionNodeTest extends TestCase
{
    use EditorSchemaAssertions;

    /** @return iterable<string, array{NodeSchema}> */
    public static function provideCollections(): iterable
    {
        yield 'replaced list' => [NodeSchema::list(NodeSchema::scalar(ScalarForm::String))];
        yield 'accumulated set' => [NodeSchema::set(NodeSchema::scalar(ScalarForm::String))];
    }

    /** @return iterable<string, array{string}> */
    public static function provideAcceptedForms(): iterable
    {
        yield 'list' => ['[src, tests]'];
        yield 'unwritten' => ['~'];
        yield 'empty list' => ['[]'];
        yield 'empty map' => ['{}'];
        yield 'map keyed 0..n, read as a list' => ['{0: src, 1: tests}'];
        yield 'map keyed by other integers, wider than the engine' => ['{1: src}'];
    }

    #[Test]
    #[DataProvider('provideAcceptedForms')]
    public function itAcceptsEveryFormTheLoaderReadsAsAList(string $value): void
    {
        foreach (self::provideCollections() as [$node]) {
            self::assertAccepted(self::documentWith($node), 'key: ' . $value);
        }
    }

    #[Test]
    #[DataProvider('provideCollections')]
    public function itRefusesTildeAsAnItem(NodeSchema $node): void
    {
        self::assertRefusedAt(self::documentWith($node), 'key: [src, ~]', '/key/1');
        self::assertRefusedAt(self::documentWith($node), 'key: {0: ~}', '/key/0');
    }

    #[Test]
    #[DataProvider('provideCollections')]
    public function itRefusesWhatIsNotAList(NodeSchema $node): void
    {
        self::assertRefusedAt(self::documentWith($node), 'key: src', '/key');
        self::assertRefusedAt(self::documentWith($node), 'key: {path: src}', '/key/path');
        self::assertRefusedAt(self::documentWith($node), 'key: [5]', '/key/0');
    }

    #[Test]
    public function itAcceptsTildeAsAnItemWhoseFormItsOwnerJudges(): void
    {
        self::assertAccepted(self::documentWith(NodeSchema::list(NodeSchema::opaque())), 'key: [~, {any: thing}, 5]');
    }

    #[Test]
    public function itRefusesTildeAsAMapItem(): void
    {
        $schema = self::documentWith(NodeSchema::list(NodeSchema::map(['subtree' => NodeSchema::scalar(ScalarForm::String)])));

        self::assertAccepted($schema, 'key: [{subtree: App}]');
        self::assertRefusedAt($schema, 'key: [~]', '/key/0');
    }

    #[Test]
    public function itAcceptsOneBareElementWhereTheListAdmitsIt(): void
    {
        $schema = self::documentWith(NodeSchema::list(NodeSchema::scalar(ScalarForm::String))->admittingBareElement());

        self::assertAccepted($schema, 'key: get');
        self::assertAccepted($schema, 'key: [get, is]');
        self::assertAccepted($schema, 'key: ~');
        self::assertRefusedAt($schema, 'key: 5', '/key');
    }
}
