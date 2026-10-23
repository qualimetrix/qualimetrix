<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use DateTimeImmutable;
use PhpParser\Node\Scalar\Float_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Cache\AstEntryCodec;
use ReflectionClass;
use stdClass;

#[CoversClass(AstEntryCodec::class)]
final class AstEntryCodecTest extends TestCase
{
    #[Test]
    public function itPreservesAstPrecisionAndRestoresTheProcessSetting(): void
    {
        $before = \ini_get('serialize_precision');
        ini_set('serialize_precision', '5');
        try {
            $codec = new AstEntryCodec();
            $nodes = [new Float_(0.12345678901234568)];
            $bytes = $codec->encode($nodes);
            self::assertNotNull($bytes);
            $decoded = $codec->decode($bytes);
            self::assertEquals($nodes, $decoded);
            self::assertSame('5', \ini_get('serialize_precision'));
            $parsed = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php /** comment */ class Sample { public function f(): int { return 1; } }');
            self::assertNotNull($parsed);
            $bytes = $codec->encode(array_values($parsed));
            self::assertNotNull($bytes);
            self::assertEquals($parsed, $codec->decode($bytes));
            self::assertGreaterThan(140, \count(AstEntryCodec::allowedClasses()));
        } finally {
            ini_set('serialize_precision', (string) $before);
        }
    }

    #[Test]
    public function itRefusesForeignObjectsAndInvalidRootsIncludingNestedAttributes(): void
    {
        $codec = new AstEntryCodec();
        $node = new Float_(1.0);
        $node->setAttribute('foreign', new stdClass());
        $badComment = new Float_(1.0);
        $badComment->setAttribute('comments', [(new ReflectionClass(\PhpParser\Comment::class))->newInstanceWithoutConstructor()]);
        $uninitialized = (new ReflectionClass(\PhpParser\Node\Expr\Eval_::class))->newInstanceWithoutConstructor();
        $cycle = new \PhpParser\Node\Expr\UnaryMinus(new \PhpParser\Node\Scalar\Int_(1));
        $cycle->expr = $cycle;
        $attributeCycle = new Float_(1.0);
        $attributeCycle->setAttribute('cycle', $attributeCycle);
        foreach ([[$cycle], [$attributeCycle], new stdClass(), [new stdClass()], ['named' => new Float_(1.0)], [$node], 'broken', [new DateTimeImmutable()], [$uninitialized], [$badComment]] as $value) {
            self::assertNull($codec->decode(serialize($value)));
        }
        self::assertNull($codec->decode('not serialized'));
        self::assertSame([], $codec->decode(serialize([])));
    }
    #[Test]
    public function itRetainsSharedNodesWithoutTreatingThemAsAnActiveCycle(): void
    {
        $shared = new \PhpParser\Node\Scalar\Int_(1);
        $nodes = [new \PhpParser\Node\Expr\BinaryOp\Plus($shared, $shared)];
        $codec = new AstEntryCodec();
        $bytes = $codec->encode($nodes);
        self::assertNotNull($bytes);
        $decoded = $codec->decode($bytes);
        self::assertNotNull($decoded);
        $sum = $decoded[0];
        self::assertInstanceOf(\PhpParser\Node\Expr\BinaryOp\Plus::class, $sum);
        self::assertSame($sum->left, $sum->right);
        self::assertEquals($nodes, (new \PhpParser\NodeTraverser())->traverse($decoded));
    }

    #[Test]
    public function itAllowsEveryNodeAndCommentClassObservedInStoredAstEntries(): void
    {
        $classes = file(__DIR__ . '/ast-entry-classes.txt', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($classes);
        self::assertNotEmpty($classes);
        $allowed = AstEntryCodec::allowedClasses();
        foreach ($classes as $class) {
            self::assertContains($class, $allowed);
        }
    }

}
