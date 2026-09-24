<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Security\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Security\SecurityPatternVisitor;

#[CoversClass(SecurityPatternVisitor::class)]
final class SecurityPatternVisitorTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSqlInjectionCases')]
    public function itDetectsSqlInjection(string $code, int $expectedCount): void
    {
        $locations = $this->analyze($code, 'sql_injection');
        self::assertCount($expectedCount, $locations, \sprintf(
            'Expected %d SQL injection(s), found %d for code: %s',
            $expectedCount,
            \count($locations),
            $code,
        ));
    }

    #[Test]
    #[DataProvider('provideXssCases')]
    public function itDetectsXss(string $code, int $expectedCount): void
    {
        $locations = $this->analyze($code, 'xss');
        self::assertCount($expectedCount, $locations, \sprintf(
            'Expected %d XSS(s), found %d for code: %s',
            $expectedCount,
            \count($locations),
            $code,
        ));
    }

    #[Test]
    #[DataProvider('provideCommandInjectionCases')]
    public function itDetectsCommandInjection(string $code, int $expectedCount): void
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

        yield 'exec with coalesced superglobal' => [
            'code' => '<?php exec($_GET["cmd"] ?? "ls");',
            'expectedCount' => 1,
        ];

        yield 'exec with concat of coalesced superglobal' => [
            'code' => '<?php exec("ls " . ($_GET["d"] ?? "."));',
            'expectedCount' => 1,
        ];

        yield 'system with string-cast superglobal' => [
            'code' => '<?php system((string) $_POST["cmd"]);',
            'expectedCount' => 1,
        ];

        yield 'exec with superglobal in a ternary branch' => [
            'code' => '<?php exec($c ? $_GET["cmd"] : "ls");',
            'expectedCount' => 1,
        ];

        yield 'backticks with braced superglobal' => [
            'code' => '<?php $o = `ls {$_GET[\'d\']}`;',
            'expectedCount' => 1,
        ];

        yield 'backticks with simple superglobal interpolation' => [
            'code' => '<?php $o = `ls $_GET[d]`;',
            'expectedCount' => 1,
        ];

        yield 'backticks without a superglobal' => [
            'code' => '<?php $o = `ls -la {$dir}`;',
            'expectedCount' => 0,
        ];

        yield 'first-class callable of exec' => [
            'code' => '<?php $run = exec(...);',
            'expectedCount' => 0,
        ];

        yield 'exec with escaped coalesced superglobal' => [
            'code' => '<?php exec("ls " . escapeshellarg($_GET["d"] ?? "."));',
            'expectedCount' => 0,
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

    #[Test]
    public function itResetsClearsState(): void
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

    #[Test]
    public function itForgetsTheReportedReadsOnReset(): void
    {
        $visitor = new SecurityPatternVisitor();
        $ast = (new ParserFactory())->createForHostVersion()->parse('<?php $q = "SELECT * FROM t WHERE id = " . $_GET["id"];') ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        $traverser->traverse($ast);
        $visitor->reset();
        $traverser->traverse($ast);

        self::assertCount(1, $visitor->getLocationsByType('sql_injection'));
    }

    #[Test]
    public function itGetsLocationsByType(): void
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

    #[Test]
    #[DataProvider('provideSqlInjectionThroughValueWrappersCases')]
    public function itDetectsSqlInjectionThroughValuePassingWrappers(string $code, int $expectedCount): void
    {
        self::assertCount($expectedCount, $this->analyze($code, 'sql_injection'), $code);
    }

    /**
     * @return iterable<string, array{code: string, expectedCount: int}>
     */
    public static function provideSqlInjectionThroughValueWrappersCases(): iterable
    {
        yield 'concat with coalesced superglobal' => [
            'code' => '<?php $q = "SELECT * FROM t WHERE id = " . ($_GET["id"] ?? 0);',
            'expectedCount' => 1,
        ];

        yield 'concat with superglobal in a ternary branch' => [
            'code' => '<?php $q = "SELECT * FROM t WHERE id = " . ($c ? $_GET["id"] : 0);',
            'expectedCount' => 1,
        ];

        yield 'concat with string-cast superglobal' => [
            'code' => '<?php $q = "SELECT * FROM t WHERE id = " . (string) $_GET["id"];',
            'expectedCount' => 1,
        ];

        yield 'mysqli_query with coalesced superglobal' => [
            'code' => '<?php mysqli_query($db, $_POST["q"] ?? "");',
            'expectedCount' => 1,
        ];

        yield 'sprintf with coalesced superglobal' => [
            'code' => '<?php $s = sprintf("SELECT * FROM t WHERE id = %s", $_GET["id"] ?? 0);',
            'expectedCount' => 1,
        ];

        yield 'first-class callable of mysqli_query' => [
            'code' => '<?php $query = mysqli_query(...);',
            'expectedCount' => 0,
        ];

        yield 'concat with intval of coalesced superglobal' => [
            'code' => '<?php $q = "SELECT * FROM t WHERE id = " . intval($_GET["id"] ?? 0);',
            'expectedCount' => 0,
        ];

        yield 'concat with superglobal only as an array key' => [
            'code' => '<?php $q = "SELECT * FROM t WHERE id = " . $ids[$_GET["k"]];',
            'expectedCount' => 0,
        ];
    }

    #[Test]
    #[DataProvider('provideXssThroughValueWrappersCases')]
    public function itDetectsXssThroughValuePassingWrappers(string $code, int $expectedCount): void
    {
        self::assertCount($expectedCount, $this->analyze($code, 'xss'), $code);
    }

    /**
     * @return iterable<string, array{code: string, expectedCount: int}>
     */
    public static function provideXssThroughValueWrappersCases(): iterable
    {
        yield 'echo coalesced superglobal' => [
            'code' => '<?php echo $_GET["name"] ?? "guest";',
            'expectedCount' => 1,
        ];

        yield 'echo coalesce with the superglobal as fallback' => [
            'code' => '<?php echo $name ?? $_GET["name"];',
            'expectedCount' => 1,
        ];

        yield 'echo superglobal in a ternary branch' => [
            'code' => '<?php echo $ok ? $_GET["a"] : "b";',
            'expectedCount' => 1,
        ];

        yield 'echo short ternary on a superglobal' => [
            'code' => '<?php echo $_GET["a"] ?: "b";',
            'expectedCount' => 1,
        ];

        yield 'echo string-cast superglobal' => [
            'code' => '<?php echo (string) $_GET["a"];',
            'expectedCount' => 1,
        ];

        yield 'echo error-suppressed superglobal' => [
            'code' => '<?php echo @$_GET["a"];',
            'expectedCount' => 1,
        ];

        yield 'echo superglobal in a match arm' => [
            'code' => '<?php echo match ($m) { 1 => $_GET["a"], default => "" };',
            'expectedCount' => 1,
        ];

        yield 'echo assignment of a superglobal' => [
            'code' => '<?php echo $name = $_GET["name"];',
            'expectedCount' => 1,
        ];

        yield 'print coalesced superglobal' => [
            'code' => '<?php print $_GET["a"] ?? "";',
            'expectedCount' => 1,
        ];

        yield 'echo concat with coalesced superglobal' => [
            'code' => '<?php echo "Hi " . ($_GET["n"] ?? "x");',
            'expectedCount' => 1,
        ];

        yield 'echo superglobal only as a ternary condition' => [
            'code' => '<?php echo $_GET["a"] ? "yes" : "no";',
            'expectedCount' => 0,
        ];

        yield 'echo sanitized coalesced superglobal' => [
            'code' => '<?php echo htmlspecialchars($_GET["a"] ?? "");',
            'expectedCount' => 0,
        ];

        yield 'echo coalesce with a sanitized superglobal as fallback' => [
            'code' => '<?php echo $name ?? htmlspecialchars($_GET["a"]);',
            'expectedCount' => 0,
        ];

        yield 'echo int cast of coalesced superglobal' => [
            'code' => '<?php echo (int) ($_GET["a"] ?? 0);',
            'expectedCount' => 0,
        ];

        yield 'echo superglobal only as an array key' => [
            'code' => '<?php echo $labels[$_GET["k"]];',
            'expectedCount' => 0,
        ];

        yield 'echo isset of a superglobal' => [
            'code' => '<?php echo isset($_GET["a"]) ? "1" : "0";',
            'expectedCount' => 0,
        ];
    }

    /**
     * A detector that looks through a wrapper reaches the same superglobal read
     * that a detector of a node nested in that wrapper reaches again; the read
     * is still one finding.
     */
    #[Test]
    #[DataProvider('provideOneFindingPerReadCases')]
    public function itReportsASuperglobalReadOnceWhateverWrapsIt(string $type, string $code, int $expectedCount): void
    {
        self::assertCount($expectedCount, $this->analyze($code, $type), $code);
    }

    /**
     * @return iterable<string, array{type: string, code: string, expectedCount: int}>
     */
    public static function provideOneFindingPerReadCases(): iterable
    {
        $query = '"SELECT * FROM t WHERE n = \'{$_POST[\'n\']}\'"';

        foreach (self::valuePassingWrappers($query) as $form => $argument) {
            yield "sql function with an interpolated query {$form}" => [
                'type' => 'sql_injection',
                'code' => "<?php mysqli_query(\$link, {$argument});",
                'expectedCount' => 1,
            ];
            yield "sprintf with an interpolated query {$form}" => [
                'type' => 'sql_injection',
                'code' => "<?php \$q = sprintf(\"SELECT * FROM t %s\", {$argument});",
                'expectedCount' => 1,
            ];
        }

        yield 'concatenation of an interpolated query' => [
            'type' => 'sql_injection',
            'code' => '<?php $q = "SELECT * FROM t " . "WHERE n = {$_POST[\'n\']}";',
            'expectedCount' => 1,
        ];
        yield 'sql function with a concatenation of an interpolated query' => [
            'type' => 'sql_injection',
            'code' => '<?php mysqli_query($link, "SELECT * FROM t " . "WHERE n = {$_POST[\'n\']}");',
            'expectedCount' => 1,
        ];
        yield 'sprintf with a concatenated query' => [
            'type' => 'sql_injection',
            'code' => '<?php $q = sprintf("SELECT * FROM t %s", " WHERE n = " . $_GET["n"]);',
            'expectedCount' => 1,
        ];
        yield 'sql function whose two branches build one query each' => [
            'type' => 'sql_injection',
            'code' => '<?php mysqli_query($link, $c ? "SELECT * FROM a WHERE x = {$_GET[\'a\']}" : "SELECT * FROM b WHERE y = {$_GET[\'b\']}");',
            'expectedCount' => 1,
        ];

        // A call the outer detector does not look through leaves the inner query to its own detector.
        yield 'interpolated query behind a call inside an sql function' => [
            'type' => 'sql_injection',
            'code' => '<?php mysqli_query($link, trim("SELECT * FROM t WHERE n = {$_GET[\'n\']}"));',
            'expectedCount' => 1,
        ];
        yield 'concatenated query behind a call inside an sql function' => [
            'type' => 'sql_injection',
            'code' => '<?php mysqli_query($link, trim("SELECT * FROM t WHERE n = " . $_GET["n"]));',
            'expectedCount' => 1,
        ];
        yield 'concatenated query behind a call inside a concatenation' => [
            'type' => 'sql_injection',
            'code' => '<?php $q = $prefix . trim("SELECT * FROM t WHERE n = " . $_GET["n"]);',
            'expectedCount' => 1,
        ];
        yield 'concatenated query in a ternary branch inside a concatenation' => [
            'type' => 'sql_injection',
            'code' => '<?php $q = $prefix . ($c ? "SELECT * FROM t WHERE n = " . $_GET["n"] : "");',
            'expectedCount' => 1,
        ];
        yield 'interpolated query concatenated to a variable' => [
            'type' => 'sql_injection',
            'code' => '<?php $q = $prefix . "SELECT * FROM t WHERE id = {$_GET[\'id\']}";',
            'expectedCount' => 1,
        ];
        // A read the reporting query reaches only through a call belongs to another query.
        yield 'subquery with another superglobal behind a call inside a reporting concatenation' => [
            'type' => 'sql_injection',
            'code' => '<?php $q = "SELECT * FROM t WHERE a = " . $_GET["a"] . " AND b IN (" . implode(",", ["SELECT id FROM u WHERE n = \'{$_POST[\'n\']}\'"]) . ")";',
            'expectedCount' => 2,
        ];
        yield 'subquery with another superglobal behind a call inside a reporting sql function' => [
            'type' => 'sql_injection',
            'code' => '<?php mysqli_query($link, "SELECT * FROM t WHERE a = {$_GET[\'a\']} AND b IN (" . implode(",", ["SELECT id FROM u WHERE n = " . $_POST["n"]]) . ")");',
            'expectedCount' => 2,
        ];
        yield 'subquery without a superglobal behind a call inside a reporting concatenation' => [
            'type' => 'sql_injection',
            'code' => '<?php $q = "SELECT * FROM t WHERE a = " . $_GET["a"] . " AND b IN (" . implode(",", ["SELECT id FROM u"]) . ")";',
            'expectedCount' => 1,
        ];
        yield 'two statements, two findings' => [
            'type' => 'sql_injection',
            'code' => '<?php mysqli_query($link, "SELECT * FROM a WHERE x = {$_GET[\'a\']}"); $q = "DELETE FROM b WHERE y = " . $_GET["b"];',
            'expectedCount' => 2,
        ];

        foreach (self::valuePassingWrappers('"Hello {$_GET[\'n\']}"') as $form => $argument) {
            yield "echo of an interpolated string {$form}" => [
                'type' => 'xss',
                'code' => "<?php echo {$argument};",
                'expectedCount' => 1,
            ];
            yield "print of an interpolated string {$form}" => [
                'type' => 'xss',
                'code' => "<?php print {$argument};",
                'expectedCount' => 1,
            ];
        }

        foreach (self::valuePassingWrappers('"ls {$_GET[\'d\']}"') as $form => $argument) {
            yield "command function with an interpolated command {$form}" => [
                'type' => 'command_injection',
                'code' => "<?php exec({$argument});",
                'expectedCount' => 1,
            ];
        }

        yield 'command function with a backtick command' => [
            'type' => 'command_injection',
            'code' => '<?php exec(`ls {$_GET[\'d\']}`);',
            'expectedCount' => 1,
        ];
    }

    #[Test]
    public function itNamesTheReadEachQueryReportsWhenASubqueryHidesBehindACall(): void
    {
        $locations = $this->analyze(
            '<?php $q = "SELECT * FROM t WHERE a = " . $_GET["a"] . " AND b IN (" . implode(",", ["SELECT id FROM u WHERE n = \'{$_POST[\'n\']}\'"]) . ")";',
            'sql_injection',
        );

        self::assertSame(
            ['$_GET concatenated with SQL query', '$_POST interpolated in SQL query'],
            array_map(static fn($location): string => $location->context, $locations),
        );
    }

    /**
     * Every wrapper the superglobal search looks through, around one value.
     *
     * @return array<string, string>
     */
    private static function valuePassingWrappers(string $value): array
    {
        return [
            'as is' => $value,
            'behind ??' => "\$fallback ?? {$value}",
            'in a ternary branch' => "\$c ? {$value} : ''",
            'behind a (string) cast' => "(string) {$value}",
            'concatenated' => "{$value} . ''",
            'behind @' => "@{$value}",
            'in a match arm' => "match (\$m) { default => {$value} }",
            'assigned' => "\$v = {$value}",
        ];
    }

    /**
     * @return list<\Qualimetrix\Analysis\Evidence\Security\SecurityPatternLocation>
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
