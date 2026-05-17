<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Security;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\Security\SuperglobalAnalyzer;

#[CoversClass(SuperglobalAnalyzer::class)]
final class SuperglobalAnalyzerTest extends TestCase
{
    private SuperglobalAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SuperglobalAnalyzer();
    }

    // --- isDangerousSuperglobal ---

    #[Test]
    #[DataProvider('provideDangerousSuperglobals')]
    public function itReturnsTrueForDangerousSuperglobal(string $name): void
    {
        $variable = new Variable($name);

        self::assertTrue($this->analyzer->isDangerousSuperglobal($variable));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDangerousSuperglobals(): iterable
    {
        yield '$_GET' => ['_GET'];
        yield '$_POST' => ['_POST'];
        yield '$_REQUEST' => ['_REQUEST'];
        yield '$_COOKIE' => ['_COOKIE'];
    }

    #[Test]
    #[DataProvider('provideNonDangerousSuperglobals')]
    public function itReturnsFalseForNonDangerousSuperglobal(string $name): void
    {
        $variable = new Variable($name);

        self::assertFalse($this->analyzer->isDangerousSuperglobal($variable));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonDangerousSuperglobals(): iterable
    {
        yield '$_SESSION' => ['_SESSION'];
        yield '$_SERVER' => ['_SERVER'];
        yield '$_FILES' => ['_FILES'];
        yield '$_ENV' => ['_ENV'];
        yield '$GLOBALS' => ['GLOBALS'];
        yield '$regular' => ['regular'];
    }

    #[Test]
    public function itDetectsDangerousSuperglobalForArrayDimFetch(): void
    {
        $arrayAccess = new ArrayDimFetch(new Variable('_GET'), new String_('id'));

        self::assertTrue($this->analyzer->isDangerousSuperglobal($arrayAccess));
    }

    #[Test]
    public function itDetectsDangerousSuperglobalForNestedArrayDimFetch(): void
    {
        $nested = new ArrayDimFetch(
            new ArrayDimFetch(new Variable('_POST'), new String_('data')),
            new String_('sub'),
        );

        self::assertTrue($this->analyzer->isDangerousSuperglobal($nested));
    }

    #[Test]
    public function itReturnsFalseForOtherExpressions(): void
    {
        $funcCall = new FuncCall(new Name('someFunc'));

        self::assertFalse($this->analyzer->isDangerousSuperglobal($funcCall));
    }

    // --- containsSuperglobal ---

    #[Test]
    public function itContainsSuperglobalInConcat(): void
    {
        $concat = new Concat(
            new String_('prefix'),
            new ArrayDimFetch(new Variable('_GET'), new String_('x')),
        );

        self::assertTrue($this->analyzer->containsSuperglobal($concat));
    }

    #[Test]
    public function itContainsSuperglobalInNestedConcat(): void
    {
        $concat = new Concat(
            new Concat(
                new String_('a'),
                new ArrayDimFetch(new Variable('_POST'), new String_('b')),
            ),
            new String_('c'),
        );

        self::assertTrue($this->analyzer->containsSuperglobal($concat));
    }

    #[Test]
    public function itReturnsFalseForSafeConcat(): void
    {
        $concat = new Concat(
            new String_('hello'),
            new Variable('safe'),
        );

        self::assertFalse($this->analyzer->containsSuperglobal($concat));
    }

    // --- getSuperglobalName ---

    #[Test]
    public function itGetsSuperglobalNameForVariable(): void
    {
        $variable = new Variable('_GET');

        self::assertSame('_GET', $this->analyzer->getSuperglobalName($variable));
    }

    #[Test]
    public function itGetsSuperglobalNameForArrayAccess(): void
    {
        $access = new ArrayDimFetch(new Variable('_POST'), new String_('key'));

        self::assertSame('_POST', $this->analyzer->getSuperglobalName($access));
    }

    #[Test]
    public function itReturnsUnknownSuperglobalNameForOtherExpr(): void
    {
        $funcCall = new FuncCall(new Name('someFunc'));

        self::assertSame('unknown', $this->analyzer->getSuperglobalName($funcCall));
    }

    // --- findSuperglobalName ---

    #[Test]
    public function itFindsSuperglobalNameInConcat(): void
    {
        $concat = new Concat(
            new String_('prefix'),
            new ArrayDimFetch(new Variable('_REQUEST'), new String_('key')),
        );

        self::assertSame('_REQUEST', $this->analyzer->findSuperglobalName($concat));
    }

    #[Test]
    public function itFindsSuperglobalNameInLeftBranch(): void
    {
        $concat = new Concat(
            new ArrayDimFetch(new Variable('_COOKIE'), new String_('token')),
            new String_('suffix'),
        );

        self::assertSame('_COOKIE', $this->analyzer->findSuperglobalName($concat));
    }

    // --- isUnsanitizedSuperglobal ---

    #[Test]
    public function itIsUnsanitizedForDirectSuperglobal(): void
    {
        $access = new ArrayDimFetch(new Variable('_GET'), new String_('id'));

        self::assertTrue($this->analyzer->isUnsanitizedSuperglobal($access, ['htmlspecialchars']));
    }

    #[Test]
    public function itIsNotUnsanitizedForSanitizedCall(): void
    {
        $sanitized = new FuncCall(
            new Name('htmlspecialchars'),
            [new Arg(new ArrayDimFetch(new Variable('_GET'), new String_('name')))],
        );

        self::assertFalse($this->analyzer->isUnsanitizedSuperglobal($sanitized, ['htmlspecialchars']));
    }

    #[Test]
    public function itIsNotUnsanitizedForIntCast(): void
    {
        $cast = new Cast\Int_(new ArrayDimFetch(new Variable('_GET'), new String_('id')));

        self::assertFalse($this->analyzer->isUnsanitizedSuperglobal($cast, []));
    }

    #[Test]
    public function itIsNotUnsanitizedForDoubleCast(): void
    {
        $cast = new Cast\Double(new ArrayDimFetch(new Variable('_GET'), new String_('price')));

        self::assertFalse($this->analyzer->isUnsanitizedSuperglobal($cast, []));
    }

    #[Test]
    public function itIsNotUnsanitizedForIntvalCall(): void
    {
        $intval = new FuncCall(
            new Name('intval'),
            [new Arg(new ArrayDimFetch(new Variable('_GET'), new String_('id')))],
        );

        self::assertFalse($this->analyzer->isUnsanitizedSuperglobal($intval, []));
    }

    // --- containsUnsanitizedSuperglobalInExpr ---

    #[Test]
    public function itContainsUnsanitizedInConcatChain(): void
    {
        $concat = new Concat(
            new String_('prefix'),
            new ArrayDimFetch(new Variable('_GET'), new String_('val')),
        );

        self::assertTrue($this->analyzer->containsUnsanitizedSuperglobalInExpr($concat, ['htmlspecialchars']));
    }

    #[Test]
    public function itDoesNotContainUnsanitizedWhenAllSanitized(): void
    {
        $sanitized = new FuncCall(
            new Name('htmlspecialchars'),
            [new Arg(new ArrayDimFetch(new Variable('_GET'), new String_('val')))],
        );
        $concat = new Concat(new String_('prefix'), $sanitized);

        self::assertFalse($this->analyzer->containsUnsanitizedSuperglobalInExpr($concat, ['htmlspecialchars']));
    }

    // --- findUnsanitizedSuperglobalName ---

    #[Test]
    public function itFindsUnsanitizedSuperglobalNameInConcat(): void
    {
        $concat = new Concat(
            new String_('prefix'),
            new ArrayDimFetch(new Variable('_POST'), new String_('data')),
        );

        self::assertSame('_POST', $this->analyzer->findUnsanitizedSuperglobalName($concat, []));
    }

    #[Test]
    public function itReturnsNullForUnsanitizedSuperglobalNameWhenSanitized(): void
    {
        $sanitized = new FuncCall(
            new Name('escapeshellarg'),
            [new Arg(new ArrayDimFetch(new Variable('_GET'), new String_('cmd')))],
        );

        self::assertNull($this->analyzer->findUnsanitizedSuperglobalName($sanitized, ['escapeshellarg']));
    }

    // --- findSuperglobalInInterpolatedString ---

    #[Test]
    public function itFindsSuperglobalInInterpolatedString(): void
    {
        $interpolated = new InterpolatedString([
            new InterpolatedStringPart('Hello '),
            new ArrayDimFetch(new Variable('_GET'), new String_('name')),
        ]);

        self::assertSame('_GET', $this->analyzer->findSuperglobalInInterpolatedString($interpolated));
    }

    #[Test]
    public function itReturnsNullForSuperglobalInSafeInterpolatedString(): void
    {
        $interpolated = new InterpolatedString([
            new InterpolatedStringPart('Hello '),
            new Variable('name'),
        ]);

        self::assertNull($this->analyzer->findSuperglobalInInterpolatedString($interpolated));
    }

    // --- flattenConcat ---

    #[Test]
    public function itFlattensSimpleConcat(): void
    {
        $concat = new Concat(new String_('a'), new String_('b'));

        $parts = $this->analyzer->flattenConcat($concat);

        self::assertCount(2, $parts);
    }

    #[Test]
    public function itFlattensNestedConcat(): void
    {
        $concat = new Concat(
            new Concat(new String_('a'), new String_('b')),
            new String_('c'),
        );

        $parts = $this->analyzer->flattenConcat($concat);

        self::assertCount(3, $parts);
    }

    #[Test]
    public function itFlattensDeeplyNestedConcat(): void
    {
        $concat = new Concat(
            new Concat(new String_('a'), new String_('b')),
            new Concat(new String_('c'), new String_('d')),
        );

        $parts = $this->analyzer->flattenConcat($concat);

        self::assertCount(4, $parts);
    }
}
