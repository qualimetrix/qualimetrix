<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Security;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\Security\SqlInjectionDetector;
use Qualimetrix\Metrics\Security\SuperglobalAnalyzer;

#[CoversClass(SqlInjectionDetector::class)]
final class SqlInjectionDetectorTest extends TestCase
{
    private SqlInjectionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new SqlInjectionDetector(new SuperglobalAnalyzer());
    }

    // --- detectInFuncCall: SQL functions ---

    #[Test]
    public function itDetectsMysqlQueryWithSuperglobal(): void
    {
        $funcCall = $this->createFuncCall('mysql_query', [$this->createGetAccess('id')]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(1, $locations);
        self::assertSame('sql_injection', $locations[0]->type);
        self::assertStringContainsString('_GET', $locations[0]->context);
        self::assertStringContainsString('mysql_query()', $locations[0]->context);
    }

    #[Test]
    public function itDetectsMysqliQueryWithSuperglobal(): void
    {
        $funcCall = $this->createFuncCall('mysqli_query', [
            new Variable('conn'),
            $this->createPostAccess('query'),
        ]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(1, $locations);
        self::assertStringContainsString('_POST', $locations[0]->context);
    }

    #[Test]
    public function itDetectsPgQueryWithSuperglobal(): void
    {
        $funcCall = $this->createFuncCall('pg_query', [
            new Variable('conn'),
            $this->createGetAccess('sql'),
        ]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(1, $locations);
    }

    #[Test]
    public function itDoesNotDetectForSafeVariable(): void
    {
        $funcCall = $this->createFuncCall('mysql_query', [new Variable('safeQuery')]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    #[Test]
    public function itDoesNotDetectForDynamicFunctionName(): void
    {
        // $func(...) — name is not a Name node
        $funcCall = new FuncCall(new Variable('func'));

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    #[Test]
    public function itDoesNotDetectForNonSqlFunction(): void
    {
        $funcCall = $this->createFuncCall('array_map', [$this->createGetAccess('id')]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    // --- detectInFuncCall: sprintf ---

    #[Test]
    public function itDetectsSprintfWithSqlAndSuperglobal(): void
    {
        $funcCall = $this->createFuncCall('sprintf', [
            new String_('SELECT * FROM users WHERE id = %s'),
            $this->createGetAccess('id'),
        ]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(1, $locations);
        self::assertStringContainsString('sprintf()', $locations[0]->context);
    }

    #[Test]
    public function itDoesNotDetectSprintfWithoutSqlKeyword(): void
    {
        $funcCall = $this->createFuncCall('sprintf', [
            new String_('Hello %s'),
            $this->createGetAccess('name'),
        ]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    #[Test]
    public function itDoesNotDetectSprintfWithNonStringFirstArg(): void
    {
        $funcCall = $this->createFuncCall('sprintf', [
            new Variable('template'),
            $this->createGetAccess('id'),
        ]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    #[Test]
    public function itDoesNotDetectSprintfWithNoArgs(): void
    {
        $funcCall = $this->createFuncCall('sprintf', []);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    #[Test]
    public function itDoesNotDetectSprintfWithSqlButNoSuperglobal(): void
    {
        $funcCall = $this->createFuncCall('sprintf', [
            new String_('SELECT * FROM users WHERE id = %d'),
            new Variable('safeId'),
        ]);

        $locations = $this->detector->detectInFuncCall($funcCall);

        self::assertCount(0, $locations);
    }

    // --- detectInConcat ---

    #[Test]
    public function itDetectsConcatWithSqlKeywordAndSuperglobal(): void
    {
        $concat = new Concat(
            new String_('SELECT * FROM users WHERE id = '),
            $this->createGetAccess('id'),
        );

        $locations = $this->detector->detectInConcat($concat);

        self::assertCount(1, $locations);
        self::assertSame('sql_injection', $locations[0]->type);
        self::assertStringContainsString('concatenated with SQL query', $locations[0]->context);
    }

    #[Test]
    public function itDetectsConcatWithInsert(): void
    {
        $concat = new Concat(
            new String_('INSERT INTO logs VALUES ('),
            $this->createPostAccess('data'),
        );

        $locations = $this->detector->detectInConcat($concat);

        self::assertCount(1, $locations);
    }

    #[Test]
    public function itDetectsConcatWithUpdate(): void
    {
        $concat = new Concat(
            new String_("UPDATE users SET name = '"),
            $this->createRequestAccess('name'),
        );

        $locations = $this->detector->detectInConcat($concat);

        self::assertCount(1, $locations);
    }

    #[Test]
    public function itDetectsConcatWithDelete(): void
    {
        $concat = new Concat(
            new String_('DELETE FROM users WHERE id = '),
            $this->createCookieAccess('uid'),
        );

        $locations = $this->detector->detectInConcat($concat);

        self::assertCount(1, $locations);
    }

    #[Test]
    public function itDoesNotDetectConcatWithoutSqlKeyword(): void
    {
        $concat = new Concat(
            new String_('Hello '),
            $this->createGetAccess('name'),
        );

        $locations = $this->detector->detectInConcat($concat);

        self::assertCount(0, $locations);
    }

    #[Test]
    public function itDoesNotDetectConcatWithoutSuperglobal(): void
    {
        $concat = new Concat(
            new String_('SELECT * FROM users WHERE id = '),
            new Variable('safeId'),
        );

        $locations = $this->detector->detectInConcat($concat);

        self::assertCount(0, $locations);
    }

    // --- detectInInterpolation ---

    #[Test]
    public function itDetectsInterpolationWithSqlAndSuperglobal(): void
    {
        $interpolated = new InterpolatedString([
            new InterpolatedStringPart('SELECT * FROM users WHERE id = '),
            $this->createGetAccess('id'),
        ]);

        $locations = $this->detector->detectInInterpolation($interpolated);

        self::assertCount(1, $locations);
        self::assertStringContainsString('interpolated in SQL query', $locations[0]->context);
    }

    #[Test]
    public function itDoesNotDetectInterpolationWithoutSqlKeyword(): void
    {
        $interpolated = new InterpolatedString([
            new InterpolatedStringPart('Hello '),
            $this->createGetAccess('name'),
        ]);

        $locations = $this->detector->detectInInterpolation($interpolated);

        self::assertCount(0, $locations);
    }

    #[Test]
    public function itDoesNotDetectInterpolationWithoutSuperglobal(): void
    {
        $interpolated = new InterpolatedString([
            new InterpolatedStringPart('SELECT * FROM users WHERE id = '),
            new Variable('safeId'),
        ]);

        $locations = $this->detector->detectInInterpolation($interpolated);

        self::assertCount(0, $locations);
    }

    // --- isSqlFuncCall ---

    #[Test]
    public function itReturnsTrueForSqlFunctions(): void
    {
        foreach (['mysql_query', 'mysqli_query', 'pg_query', 'pg_query_params', 'sqlite_query'] as $func) {
            $funcCall = $this->createFuncCall($func, []);
            self::assertTrue($this->detector->isSqlFuncCall($funcCall), "Expected {$func} to be SQL function");
        }
    }

    #[Test]
    public function itReturnsFalseForNonSqlFunctions(): void
    {
        $funcCall = $this->createFuncCall('array_map', []);
        self::assertFalse($this->detector->isSqlFuncCall($funcCall));
    }

    #[Test]
    public function itReturnsFalseForDynamicName(): void
    {
        $funcCall = new FuncCall(new Variable('func'));
        self::assertFalse($this->detector->isSqlFuncCall($funcCall));
    }

    // --- SQL keyword matching (case-insensitive, word boundary) ---

    #[Test]
    public function itDetectsCaseInsensitiveSqlKeywords(): void
    {
        $concat = new Concat(
            new String_('select * from users where id = '),
            $this->createGetAccess('id'),
        );

        $locations = $this->detector->detectInConcat($concat);

        self::assertCount(1, $locations);
    }

    #[Test]
    public function itDoesNotDetectNonSqlContent(): void
    {
        $concat = new Concat(
            new String_('Hello world'),
            $this->createGetAccess('name'),
        );

        $locations = $this->detector->detectInConcat($concat);

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

    private function createRequestAccess(string $key): ArrayDimFetch
    {
        return new ArrayDimFetch(new Variable('_REQUEST'), new String_($key));
    }

    private function createCookieAccess(string $key): ArrayDimFetch
    {
        return new ArrayDimFetch(new Variable('_COOKIE'), new String_($key));
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
