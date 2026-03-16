<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Security;

use AiMessDetector\Metrics\Security\SecurityPatternVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecurityPatternVisitor::class)]
final class SecurityPatternVisitorTest extends TestCase
{
    #[DataProvider('provideSqlInjectionCases')]
    public function testSqlInjectionDetection(string $code, int $expectedCount): void
    {
        $locations = $this->analyze($code, 'sql_injection');
        self::assertCount($expectedCount, $locations, \sprintf(
            'Expected %d SQL injection(s), found %d for code: %s',
            $expectedCount,
            \count($locations),
            $code,
        ));
    }

    #[DataProvider('provideXssCases')]
    public function testXssDetection(string $code, int $expectedCount): void
    {
        $locations = $this->analyze($code, 'xss');
        self::assertCount($expectedCount, $locations, \sprintf(
            'Expected %d XSS(s), found %d for code: %s',
            $expectedCount,
            \count($locations),
            $code,
        ));
    }

    #[DataProvider('provideCommandInjectionCases')]
    public function testCommandInjectionDetection(string $code, int $expectedCount): void
    {
        $locations = $this->analyze($code, 'command_injection');
        self::assertCount($expectedCount, $locations, \sprintf(
            'Expected %d command injection(s), found %d for code: %s',
            $expectedCount,
            \count($locations),
            $code,
        ));
    }

    /**
     * @return iterable<string, array{code: string, expectedCount: int}>
     */
    public static function provideSqlInjectionCases(): iterable
    {
        // --- True positives ---

        yield 'concat with SELECT' => [
            'code' => '<?php $q = "SELECT * FROM users WHERE id = " . $_GET["id"];',
            'expectedCount' => 1,
        ];

        yield 'concat with INSERT' => [
            'code' => '<?php $q = "INSERT INTO logs VALUES (" . $_POST["data"] . ")";',
            'expectedCount' => 1,
        ];

        yield 'concat with UPDATE' => [
            'code' => '<?php $q = "UPDATE users SET name = \'" . $_REQUEST["name"] . "\'";',
            'expectedCount' => 1,
        ];

        yield 'concat with DELETE' => [
            'code' => '<?php $q = "DELETE FROM users WHERE id = " . $_COOKIE["uid"];',
            'expectedCount' => 1,
        ];

        yield 'concat with WHERE' => [
            'code' => '<?php $q = "SELECT 1 WHERE x = " . $_GET["x"];',
            'expectedCount' => 1,
        ];

        yield 'interpolation with SELECT' => [
            'code' => '<?php $q = "SELECT * FROM users WHERE id = {$_GET[\'id\']}";',
            'expectedCount' => 1,
        ];

        yield 'mysql_query with superglobal' => [
            'code' => '<?php mysql_query("SELECT * FROM users WHERE id = " . $_GET["id"]);',
            'expectedCount' => 1, // function call detection (concat inside SQL func is not double-counted)
        ];

        yield 'mysqli_query with superglobal' => [
            'code' => '<?php mysqli_query($conn, $_POST["query"]);',
            'expectedCount' => 1,
        ];

        yield 'pg_query with superglobal' => [
            'code' => '<?php pg_query($conn, $_GET["sql"]);',
            'expectedCount' => 1,
        ];

        yield 'sprintf with SQL and superglobal' => [
            'code' => '<?php $q = sprintf("SELECT * FROM users WHERE id = %s", $_GET["id"]);',
            'expectedCount' => 1,
        ];

        yield 'sprintf with INSERT and superglobal' => [
            'code' => '<?php $q = sprintf("INSERT INTO t VALUES (%s)", $_POST["val"]);',
            'expectedCount' => 1,
        ];

        yield 'array dim fetch superglobal in concat' => [
            'code' => '<?php $q = "SELECT * FROM t WHERE x = " . $_GET["x"]["y"];',
            'expectedCount' => 1,
        ];

        yield 'multiple superglobals in one query' => [
            'code' => '<?php $q = "SELECT * FROM users WHERE id = " . $_GET["id"] . " AND name = " . $_POST["name"];',
            'expectedCount' => 1, // single concat chain
        ];

        // --- True negatives ---

        yield 'no superglobal in SQL' => [
            'code' => '<?php $q = "SELECT * FROM users WHERE id = " . $id;',
            'expectedCount' => 0,
        ];

        yield 'safe variable concat' => [
            'code' => '<?php $q = "SELECT * FROM users WHERE id = " . (int)$_GET["id"];',
            'expectedCount' => 0, // cast on $_GET, but still has SQL keyword in concat
        ];

        yield 'parameterized query' => [
            'code' => '<?php $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");',
            'expectedCount' => 0,
        ];

        yield 'no SQL keyword in string' => [
            'code' => '<?php $s = "hello " . $_GET["name"];',
            'expectedCount' => 0,
        ];

        yield '$_SESSION not dangerous' => [
            'code' => '<?php $q = "SELECT * FROM users WHERE id = " . $_SESSION["id"];',
            'expectedCount' => 0,
        ];

        yield '$_SERVER not dangerous' => [
            'code' => '<?php $q = "SELECT * FROM users WHERE id = " . $_SERVER["REMOTE_ADDR"];',
            'expectedCount' => 0,
        ];

        yield '$_FILES not dangerous' => [
            'code' => '<?php $q = "SELECT * FROM files WHERE name = " . $_FILES["f"];',
            'expectedCount' => 0,
        ];

        yield 'sprintf without SQL keyword' => [
            'code' => '<?php $s = sprintf("Hello %s", $_GET["name"]);',
            'expectedCount' => 0,
        ];

        yield 'sprintf with non-literal first arg (true negative)' => [
            'code' => '<?php $s = sprintf($template, $_GET["id"]);',
            'expectedCount' => 0,
        ];
    }

    /**
     * @return iterable<string, array{code: string, expectedCount: int}>
     */
    public static function provideXssCases(): iterable
    {
        // --- True positives ---

        yield 'echo $_GET' => [
            'code' => '<?php echo $_GET["name"];',
            'expectedCount' => 1,
        ];

        yield 'echo $_POST' => [
            'code' => '<?php echo $_POST["data"];',
            'expectedCount' => 1,
        ];

        yield 'echo $_REQUEST' => [
            'code' => '<?php echo $_REQUEST["q"];',
            'expectedCount' => 1,
        ];

        yield 'echo $_COOKIE' => [
            'code' => '<?php echo $_COOKIE["session"];',
            'expectedCount' => 1,
        ];

        yield 'print $_GET' => [
            'code' => '<?php print $_GET["name"];',
            'expectedCount' => 1,
        ];

        yield 'echo raw superglobal without key' => [
            'code' => '<?php echo $_GET;',
            'expectedCount' => 1,
        ];

        yield 'echo multiple superglobals' => [
            'code' => '<?php echo $_GET["a"], $_POST["b"];',
            'expectedCount' => 2,
        ];

        yield 'echo nested array dim fetch' => [
            'code' => '<?php echo $_GET["a"]["b"];',
            'expectedCount' => 1,
        ];

        // --- True positives: concatenation in echo/print ---

        yield 'echo concat superglobal suffix' => [
            'code' => '<?php echo $_GET["x"] . " hello";',
            'expectedCount' => 1,
        ];

        yield 'echo concat superglobal prefix' => [
            'code' => '<?php echo "prefix" . $_POST["data"];',
            'expectedCount' => 1,
        ];

        yield 'print concat superglobal' => [
            'code' => '<?php print "Hello " . $_GET["name"];',
            'expectedCount' => 1,
        ];

        yield 'echo concat with sanitized superglobal' => [
            'code' => '<?php echo htmlspecialchars($_GET["x"]) . " hello";',
            'expectedCount' => 0,
        ];

        yield 'echo concat with int cast superglobal' => [
            'code' => '<?php echo (int)$_GET["id"] . " items";',
            'expectedCount' => 0,
        ];

        yield 'print concat with sanitized superglobal' => [
            'code' => '<?php print strip_tags($_POST["html"]) . " content";',
            'expectedCount' => 0,
        ];

        // --- True negatives (sanitized) ---

        yield 'echo htmlspecialchars' => [
            'code' => '<?php echo htmlspecialchars($_GET["name"]);',
            'expectedCount' => 0,
        ];

        yield 'echo htmlentities' => [
            'code' => '<?php echo htmlentities($_POST["data"]);',
            'expectedCount' => 0,
        ];

        yield 'echo strip_tags' => [
            'code' => '<?php echo strip_tags($_REQUEST["html"]);',
            'expectedCount' => 0,
        ];

        yield 'echo intval' => [
            'code' => '<?php echo intval($_GET["id"]);',
            'expectedCount' => 0,
        ];

        yield 'echo int cast' => [
            'code' => '<?php echo (int)$_GET["id"];',
            'expectedCount' => 0,
        ];

        yield 'echo float cast' => [
            'code' => '<?php echo (float)$_GET["price"];',
            'expectedCount' => 0,
        ];

        yield 'echo safe variable' => [
            'code' => '<?php echo $name;',
            'expectedCount' => 0,
        ];

        yield '$_SESSION not dangerous for XSS' => [
            'code' => '<?php echo $_SESSION["user"];',
            'expectedCount' => 0,
        ];

        yield '$_SERVER not dangerous for XSS' => [
            'code' => '<?php echo $_SERVER["REQUEST_URI"];',
            'expectedCount' => 0,
        ];

        yield 'echo interpolated superglobal detects XSS' => [
            'code' => '<?php echo "Hello {$_GET[\'name\']}";',
            'expectedCount' => 1,
        ];

        yield 'print interpolated superglobal detects XSS' => [
            'code' => '<?php print "Welcome {$_POST[\'user\']}";',
            'expectedCount' => 1,
        ];

        yield 'echo interpolated safe variable no XSS' => [
            'code' => '<?php echo "Hello {$name}";',
            'expectedCount' => 0,
        ];

        yield 'echo interpolated $_SESSION no XSS' => [
            'code' => '<?php echo "User: {$_SESSION[\'user\']}";',
            'expectedCount' => 0,
        ];
    }

    /**
     * @return iterable<string, array{code: string, expectedCount: int}>
     */
    public static function provideCommandInjectionCases(): iterable
    {
        // --- True positives ---

        yield 'exec with $_GET' => [
            'code' => '<?php exec($_GET["cmd"]);',
            'expectedCount' => 1,
        ];

        yield 'system with $_POST' => [
            'code' => '<?php system($_POST["command"]);',
            'expectedCount' => 1,
        ];

        yield 'passthru with $_REQUEST' => [
            'code' => '<?php passthru($_REQUEST["cmd"]);',
            'expectedCount' => 1,
        ];

        yield 'shell_exec with $_COOKIE' => [
            'code' => '<?php shell_exec($_COOKIE["run"]);',
            'expectedCount' => 1,
        ];

        yield 'proc_open with $_GET' => [
            'code' => '<?php proc_open($_GET["cmd"], [], $pipes);',
            'expectedCount' => 1,
        ];

        yield 'popen with $_POST' => [
            'code' => '<?php popen($_POST["cmd"], "r");',
            'expectedCount' => 1,
        ];

        yield 'exec with concat superglobal' => [
            'code' => '<?php exec("ls " . $_GET["dir"]);',
            'expectedCount' => 1,
        ];

        yield 'system with nested array access' => [
            'code' => '<?php system($_GET["cmd"]["sub"]);',
            'expectedCount' => 1,
        ];

        yield 'exec with interpolated superglobal' => [
            'code' => '<?php exec("ls {$_GET[\'dir\']}");',
            'expectedCount' => 1,
        ];

        // --- True negatives (sanitized) ---

        yield 'exec with escapeshellarg' => [
            'code' => '<?php exec(escapeshellarg($_GET["cmd"]));',
            'expectedCount' => 0,
        ];

        yield 'system with escapeshellcmd' => [
            'code' => '<?php system(escapeshellcmd($_POST["cmd"]));',
            'expectedCount' => 0,
        ];

        yield 'exec with safe variable' => [
            'code' => '<?php exec($safeCommand);',
            'expectedCount' => 0,
        ];

        yield 'exec with literal' => [
            'code' => '<?php exec("ls -la");',
            'expectedCount' => 0,
        ];

        yield '$_SESSION not dangerous' => [
            'code' => '<?php exec($_SESSION["cmd"]);',
            'expectedCount' => 0,
        ];

        yield '$_SERVER not dangerous' => [
            'code' => '<?php exec($_SERVER["SCRIPT_NAME"]);',
            'expectedCount' => 0,
        ];

        yield 'exec with int cast' => [
            'code' => '<?php exec("kill " . (int)$_GET["pid"]);',
            'expectedCount' => 0,
        ];
    }

    public function testResetClearsState(): void
    {
        $visitor = new SecurityPatternVisitor();

        $code = '<?php echo $_GET["name"];';
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        self::assertCount(1, $visitor->getLocations());

        $visitor->reset();
        self::assertCount(0, $visitor->getLocations());
    }

    public function testGetLocationsByType(): void
    {
        $visitor = new SecurityPatternVisitor();

        $code = <<<'PHP'
<?php
echo $_GET["name"];
exec($_POST["cmd"]);
$q = "SELECT * FROM t WHERE id = " . $_GET["id"];
PHP;

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        self::assertCount(1, $visitor->getLocationsByType('xss'));
        self::assertCount(1, $visitor->getLocationsByType('command_injection'));
        self::assertCount(1, $visitor->getLocationsByType('sql_injection'));
    }

    /**
     * @return list<\AiMessDetector\Metrics\Security\SecurityPatternLocation>
     */
    private function analyze(string $code, string $type): array
    {
        $visitor = new SecurityPatternVisitor();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getLocationsByType($type);
    }
}
