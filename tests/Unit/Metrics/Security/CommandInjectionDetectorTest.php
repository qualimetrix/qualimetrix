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
use Qualimetrix\Metrics\Security\CommandInjectionDetector;
use Qualimetrix\Metrics\Security\SuperglobalAnalyzer;

#[CoversClass(CommandInjectionDetector::class)]
final class CommandInjectionDetectorTest extends TestCase
{
    private CommandInjectionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new CommandInjectionDetector(new SuperglobalAnalyzer());
    }

    // --- True positives: direct superglobal in command functions ---

    #[DataProvider('provideCommandFunctions')]
    public function testDetectsDirectSuperglobalInCommandFunction(string $functionName): void
    {
        $funcCall = $this->createFuncCall($functionName, [$this->createGetAccess('cmd')]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(1, $locations);
        self::assertSame('command_injection', $locations[0]->type);
        self::assertStringContainsString($functionName . '()', $locations[0]->context);
        self::assertStringContainsString('_GET', $locations[0]->context);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCommandFunctions(): iterable
    {
        yield 'exec' => ['exec'];
        yield 'system' => ['system'];
        yield 'passthru' => ['passthru'];
        yield 'shell_exec' => ['shell_exec'];
        yield 'proc_open' => ['proc_open'];
        yield 'popen' => ['popen'];
    }

    // --- True positives: interpolated string ---

    public function testDetectsInterpolatedSuperglobalInExec(): void
    {
        $interpolated = new InterpolatedString([
            new InterpolatedStringPart('ls '),
            $this->createGetAccess('dir'),
        ]);
        $funcCall = $this->createFuncCall('exec', [$interpolated]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(1, $locations);
    }

    // --- True positives: concatenation ---

    public function testDetectsConcatSuperglobalInExec(): void
    {
        $concat = new Concat(
            new String_('ls '),
            $this->createGetAccess('dir'),
        );
        $funcCall = $this->createFuncCall('exec', [$concat]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(1, $locations);
    }

    // --- True negatives: sanitized ---

    public function testNoDetectionForEscapeshellarg(): void
    {
        $sanitized = new FuncCall(
            new Name('escapeshellarg'),
            [new Arg($this->createGetAccess('cmd'))],
        );
        $funcCall = $this->createFuncCall('exec', [$sanitized]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    public function testNoDetectionForEscapeshellcmd(): void
    {
        $sanitized = new FuncCall(
            new Name('escapeshellcmd'),
            [new Arg($this->createPostAccess('cmd'))],
        );
        $funcCall = $this->createFuncCall('system', [$sanitized]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    public function testNoDetectionForIntCastInConcat(): void
    {
        $cast = new Cast\Int_($this->createGetAccess('pid'));
        $concat = new Concat(new String_('kill '), $cast);
        $funcCall = $this->createFuncCall('exec', [$concat]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    // --- True negatives: safe inputs ---

    public function testNoDetectionForSafeVariable(): void
    {
        $funcCall = $this->createFuncCall('exec', [new Variable('safeCommand')]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    public function testNoDetectionForLiteral(): void
    {
        $funcCall = $this->createFuncCall('exec', [new String_('ls -la')]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    public function testNoDetectionForNonDangerousSuperglobal(): void
    {
        $sessionAccess = new ArrayDimFetch(new Variable('_SESSION'), new String_('cmd'));
        $funcCall = $this->createFuncCall('exec', [$sessionAccess]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    // --- Edge cases ---

    public function testNoDetectionForNonCommandFunction(): void
    {
        $funcCall = $this->createFuncCall('array_map', [$this->createGetAccess('cmd')]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    public function testNoDetectionForDynamicFunctionName(): void
    {
        $funcCall = new FuncCall(new Variable('func'));

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    public function testDetectsNestedArrayAccessInSuperglobal(): void
    {
        $nested = new ArrayDimFetch(
            $this->createGetAccess('cmd'),
            new String_('sub'),
        );
        $funcCall = $this->createFuncCall('system', [$nested]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(1, $locations);
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

    /**
     * @param list<\PhpParser\Node\Expr> $args
     */
    private function createFuncCall(string $name, array $args): FuncCall
    {
        return new FuncCall(
            new Name($name),
            array_map(static fn($arg) => new Arg($arg), $args),
        );
    }
}
