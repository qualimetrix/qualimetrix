<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Security;

use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Echo_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\Security\SuperglobalAnalyzer;
use Qualimetrix\Metrics\Security\XssDetector;

#[CoversClass(XssDetector::class)]
final class XssDetectorTest extends TestCase
{
    private XssDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new XssDetector(new SuperglobalAnalyzer());
    }

    // --- detectInEcho: direct superglobal ---

    public function testDetectsEchoOfGetSuperglobal(): void
    {
        $echo = new Echo_([$this->createGetAccess('name')]);

        $locations = $this->detector->detectInEcho($echo);

        self::assertCount(1, $locations);
        self::assertSame('xss', $locations[0]->type);
        self::assertStringContainsString('echo', $locations[0]->context);
        self::assertStringContainsString('_GET', $locations[0]->context);
    }

    public function testDetectsEchoOfPostSuperglobal(): void
    {
        $echo = new Echo_([$this->createPostAccess('data')]);

        $locations = $this->detector->detectInEcho($echo);

        self::assertCount(1, $locations);
    }

    public function testDetectsEchoOfMultipleSuperglobals(): void
    {
        $echo = new Echo_([
            $this->createGetAccess('a'),
            $this->createPostAccess('b'),
        ]);

        $locations = $this->detector->detectInEcho($echo);

        self::assertCount(2, $locations);
    }

    // --- detectInEcho: sanitized (true negatives) ---

    public function testNoDetectionForHtmlspecialcharsWrapped(): void
    {
        $sanitized = new FuncCall(
            new Name('htmlspecialchars'),
            [new \PhpParser\Node\Arg($this->createGetAccess('name'))],
        );
        $echo = new Echo_([$sanitized]);

        $locations = $this->detector->detectInEcho($echo);

        self::assertCount(0, $locations);
    }

    public function testNoDetectionForIntCast(): void
    {
        $cast = new Cast\Int_($this->createGetAccess('id'));
        $echo = new Echo_([$cast]);

        $locations = $this->detector->detectInEcho($echo);

        self::assertCount(0, $locations);
    }

    public function testNoDetectionForSafeVariable(): void
    {
        $echo = new Echo_([new Variable('name')]);

        $locations = $this->detector->detectInEcho($echo);

        self::assertCount(0, $locations);
    }

    public function testNoDetectionForNonDangerousSuperglobal(): void
    {
        // $_SESSION is not in the dangerous list
        $echo = new Echo_([new ArrayDimFetch(new Variable('_SESSION'), new String_('user'))]);

        $locations = $this->detector->detectInEcho($echo);

        self::assertCount(0, $locations);
    }

    // --- detectInEcho: interpolated string ---

    public function testDetectsEchoOfInterpolatedStringWithSuperglobal(): void
    {
        $interpolated = new InterpolatedString([
            new InterpolatedStringPart('Hello '),
            $this->createGetAccess('name'),
        ]);
        $echo = new Echo_([$interpolated]);

        $locations = $this->detector->detectInEcho($echo);

        self::assertCount(1, $locations);
    }

    public function testNoDetectionForInterpolatedStringWithSafeVariable(): void
    {
        $interpolated = new InterpolatedString([
            new InterpolatedStringPart('Hello '),
            new Variable('name'),
        ]);
        $echo = new Echo_([$interpolated]);

        $locations = $this->detector->detectInEcho($echo);

        self::assertCount(0, $locations);
    }

    // --- detectInEcho: concatenation ---

    public function testDetectsEchoOfConcatWithUnsanitizedSuperglobal(): void
    {
        $concat = new Concat(
            $this->createGetAccess('x'),
            new String_(' hello'),
        );
        $echo = new Echo_([$concat]);

        $locations = $this->detector->detectInEcho($echo);

        self::assertCount(1, $locations);
    }

    public function testNoDetectionForConcatWithSanitizedSuperglobal(): void
    {
        $sanitized = new FuncCall(
            new Name('htmlspecialchars'),
            [new \PhpParser\Node\Arg($this->createGetAccess('x'))],
        );
        $concat = new Concat($sanitized, new String_(' hello'));
        $echo = new Echo_([$concat]);

        $locations = $this->detector->detectInEcho($echo);

        self::assertCount(0, $locations);
    }

    // --- detectInPrint ---

    public function testDetectsPrintOfGetSuperglobal(): void
    {
        $print = new Print_($this->createGetAccess('name'));

        $locations = $this->detector->detectInPrint($print);

        self::assertCount(1, $locations);
        self::assertSame('xss', $locations[0]->type);
        self::assertStringContainsString('print', $locations[0]->context);
    }

    public function testNoDetectionForPrintOfSanitized(): void
    {
        $sanitized = new FuncCall(
            new Name('htmlentities'),
            [new \PhpParser\Node\Arg($this->createPostAccess('data'))],
        );
        $print = new Print_($sanitized);

        $locations = $this->detector->detectInPrint($print);

        self::assertCount(0, $locations);
    }

    public function testDetectsPrintOfInterpolatedStringWithSuperglobal(): void
    {
        $interpolated = new InterpolatedString([
            new InterpolatedStringPart('Welcome '),
            $this->createPostAccess('user'),
        ]);
        $print = new Print_($interpolated);

        $locations = $this->detector->detectInPrint($print);

        self::assertCount(1, $locations);
    }

    public function testDetectsPrintOfConcatWithUnsanitizedSuperglobal(): void
    {
        $concat = new Concat(
            new String_('Hello '),
            $this->createGetAccess('name'),
        );
        $print = new Print_($concat);

        $locations = $this->detector->detectInPrint($print);

        self::assertCount(1, $locations);
    }

    public function testNoDetectionForPrintOfSafeVariable(): void
    {
        $print = new Print_(new Variable('safe'));

        $locations = $this->detector->detectInPrint($print);

        self::assertCount(0, $locations);
    }

    public function testNoDetectionForPrintOfFloatCast(): void
    {
        $cast = new Cast\Double($this->createGetAccess('price'));
        $print = new Print_($cast);

        $locations = $this->detector->detectInPrint($print);

        self::assertCount(0, $locations);
    }

    // --- Helpers ---

    private function createGetAccess(string $key): ArrayDimFetch
    {
        return new ArrayDimFetch(new Variable('_GET'), new String_($key));
    }

    private function createPostAccess(string $key): ArrayDimFetch
    {
        return new ArrayDimFetch(new Variable('_POST'), new String_($key));
    }
}
