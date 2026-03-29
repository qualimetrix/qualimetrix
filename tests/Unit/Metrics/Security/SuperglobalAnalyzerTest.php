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

    #[DataProvider('provideDangerousSuperglobals')]
    public function testIsDangerousSuperglobalReturnsTrueForDangerous(string $name): void
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

    #[DataProvider('provideNonDangerousSuperglobals')]
    public function testIsDangerousSuperglobalReturnsFalseForNonDangerous(string $name): void
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

    public function testIsDangerousSuperglobalForArrayDimFetch(): void
    {
        $arrayAccess = new ArrayDimFetch(new Variable('_GET'), new String_('id'));

        self::assertTrue($this->analyzer->isDangerousSuperglobal($arrayAccess));
    }

    public function testIsDangerousSuperglobalForNestedArrayDimFetch(): void
    {
        $nested = new ArrayDimFetch(
            new ArrayDimFetch(new Variable('_POST'), new String_('data')),
            new String_('sub'),
        );

        self::assertTrue($this->analyzer->isDangerousSuperglobal($nested));
    }

    public function testIsDangerousSuperglobalReturnsFalseForOtherExpressions(): void
    {
        $funcCall = new FuncCall(new Name('someFunc'));

        self::assertFalse($this->analyzer->isDangerousSuperglobal($funcCall));
    }

    // --- containsSuperglobal ---

    public function testContainsSuperglobalInConcat(): void
    {
        $concat = new Concat(
            new String_('prefix'),
            new ArrayDimFetch(new Variable('_GET'), new String_('x')),
        );

        self::assertTrue($this->analyzer->containsSuperglobal($concat));
    }

    public function testContainsSuperglobalInNestedConcat(): void
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

    public function testContainsSuperglobalReturnsFalseForSafeConcat(): void
    {
        $concat = new Concat(
            new String_('hello'),
            new Variable('safe'),
        );

        self::assertFalse($this->analyzer->containsSuperglobal($concat));
    }

    // --- getSuperglobalName ---

    public function testGetSuperglobalNameForVariable(): void
    {
        $variable = new Variable('_GET');

        self::assertSame('_GET', $this->analyzer->getSuperglobalName($variable));
    }

    public function testGetSuperglobalNameForArrayAccess(): void
    {
        $access = new ArrayDimFetch(new Variable('_POST'), new String_('key'));

        self::assertSame('_POST', $this->analyzer->getSuperglobalName($access));
    }

    public function testGetSuperglobalNameReturnsUnknownForOtherExpr(): void
    {
        $funcCall = new FuncCall(new Name('someFunc'));

        self::assertSame('unknown', $this->analyzer->getSuperglobalName($funcCall));
    }

    // --- findSuperglobalName ---

    public function testFindSuperglobalNameInConcat(): void
    {
        $concat = new Concat(
            new String_('prefix'),
            new ArrayDimFetch(new Variable('_REQUEST'), new String_('key')),
        );

        self::assertSame('_REQUEST', $this->analyzer->findSuperglobalName($concat));
    }

    public function testFindSuperglobalNameInLeftBranch(): void
    {
        $concat = new Concat(
            new ArrayDimFetch(new Variable('_COOKIE'), new String_('token')),
            new String_('suffix'),
        );

        self::assertSame('_COOKIE', $this->analyzer->findSuperglobalName($concat));
    }

    // --- isUnsanitizedSuperglobal ---

    public function testIsUnsanitizedForDirectSuperglobal(): void
    {
        $access = new ArrayDimFetch(new Variable('_GET'), new String_('id'));

        self::assertTrue($this->analyzer->isUnsanitizedSuperglobal($access, ['htmlspecialchars']));
    }

    public function testIsNotUnsanitizedForSanitizedCall(): void
    {
        $sanitized = new FuncCall(
            new Name('htmlspecialchars'),
            [new Arg(new ArrayDimFetch(new Variable('_GET'), new String_('name')))],
        );

        self::assertFalse($this->analyzer->isUnsanitizedSuperglobal($sanitized, ['htmlspecialchars']));
    }

    public function testIsNotUnsanitizedForIntCast(): void
    {
        $cast = new Cast\Int_(new ArrayDimFetch(new Variable('_GET'), new String_('id')));

        self::assertFalse($this->analyzer->isUnsanitizedSuperglobal($cast, []));
    }

    public function testIsNotUnsanitizedForDoubleCast(): void
    {
        $cast = new Cast\Double(new ArrayDimFetch(new Variable('_GET'), new String_('price')));

        self::assertFalse($this->analyzer->isUnsanitizedSuperglobal($cast, []));
    }

    public function testIsNotUnsanitizedForIntvalCall(): void
    {
        $intval = new FuncCall(
            new Name('intval'),
            [new Arg(new ArrayDimFetch(new Variable('_GET'), new String_('id')))],
        );

        self::assertFalse($this->analyzer->isUnsanitizedSuperglobal($intval, []));
    }

    // --- containsUnsanitizedSuperglobalInExpr ---

    public function testContainsUnsanitizedInConcatChain(): void
    {
        $concat = new Concat(
            new String_('prefix'),
            new ArrayDimFetch(new Variable('_GET'), new String_('val')),
        );

        self::assertTrue($this->analyzer->containsUnsanitizedSuperglobalInExpr($concat, ['htmlspecialchars']));
    }

    public function testDoesNotContainUnsanitizedWhenAllSanitized(): void
    {
        $sanitized = new FuncCall(
            new Name('htmlspecialchars'),
            [new Arg(new ArrayDimFetch(new Variable('_GET'), new String_('val')))],
        );
        $concat = new Concat(new String_('prefix'), $sanitized);

        self::assertFalse($this->analyzer->containsUnsanitizedSuperglobalInExpr($concat, ['htmlspecialchars']));
    }

    // --- findUnsanitizedSuperglobalName ---

    public function testFindUnsanitizedSuperglobalNameInConcat(): void
    {
        $concat = new Concat(
            new String_('prefix'),
            new ArrayDimFetch(new Variable('_POST'), new String_('data')),
        );

        self::assertSame('_POST', $this->analyzer->findUnsanitizedSuperglobalName($concat, []));
    }

    public function testFindUnsanitizedSuperglobalNameReturnsNullWhenSanitized(): void
    {
        $sanitized = new FuncCall(
            new Name('escapeshellarg'),
            [new Arg(new ArrayDimFetch(new Variable('_GET'), new String_('cmd')))],
        );

        self::assertNull($this->analyzer->findUnsanitizedSuperglobalName($sanitized, ['escapeshellarg']));
    }

    // --- findSuperglobalInInterpolatedString ---

    public function testFindSuperglobalInInterpolatedString(): void
    {
        $interpolated = new InterpolatedString([
            new InterpolatedStringPart('Hello '),
            new ArrayDimFetch(new Variable('_GET'), new String_('name')),
        ]);

        self::assertSame('_GET', $this->analyzer->findSuperglobalInInterpolatedString($interpolated));
    }

    public function testFindSuperglobalInInterpolatedStringReturnsNullForSafe(): void
    {
        $interpolated = new InterpolatedString([
            new InterpolatedStringPart('Hello '),
            new Variable('name'),
        ]);

        self::assertNull($this->analyzer->findSuperglobalInInterpolatedString($interpolated));
    }

    // --- flattenConcat ---

    public function testFlattenConcatSimple(): void
    {
        $concat = new Concat(new String_('a'), new String_('b'));

        $parts = $this->analyzer->flattenConcat($concat);

        self::assertCount(2, $parts);
    }

    public function testFlattenConcatNested(): void
    {
        $concat = new Concat(
            new Concat(new String_('a'), new String_('b')),
            new String_('c'),
        );

        $parts = $this->analyzer->flattenConcat($concat);

        self::assertCount(3, $parts);
    }

    public function testFlattenConcatDeeplyNested(): void
    {
        $concat = new Concat(
            new Concat(new String_('a'), new String_('b')),
            new Concat(new String_('c'), new String_('d')),
        );

        $parts = $this->analyzer->flattenConcat($concat);

        self::assertCount(4, $parts);
    }
}
