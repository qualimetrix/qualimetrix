<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\AuthoredShape;

#[CoversClass(AuthoredNode::class)]
final class AuthoredNodeTest extends TestCase
{
    #[Test]
    public function itReadsAParsedTreeKeepingTheAuthoredKeysAndTilde(): void
    {
        $node = AuthoredNode::fromPlain(['Fail-On' => null, 'paths' => ['src'], 'cache' => []]);

        self::assertSame(AuthoredShape::Mapping, $node->shape);
        self::assertSame(['Fail-On', 'paths', 'cache'], array_keys($node->children));
        self::assertTrue($node->children['Fail-On']->isUnwritten());
        self::assertSame(AuthoredShape::Sequence, $node->children['paths']->shape);
        self::assertSame(AuthoredShape::EmptyCollection, $node->children['cache']->shape);
        self::assertSame(['Fail-On' => null, 'paths' => ['src'], 'cache' => []], $node->plain());
    }

    #[Test]
    public function itGivesTheLocatorToTheNodeItWasReadFor(): void
    {
        self::assertSame('--fail-on', AuthoredNode::fromPlain('error', '--fail-on')->locator);
    }
}
