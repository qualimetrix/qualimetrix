<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Security;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\Security\SecurityPatternCollector;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(SecurityPatternCollector::class)]
final class SecurityPatternCollectorTest extends TestCase
{
    private SecurityPatternCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new SecurityPatternCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('security-pattern', $this->collector->getName());
    }

    public function testProvides(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('security.sql_injection.count', $provides);
        self::assertContains('security.xss.count', $provides);
        self::assertContains('security.command_injection.count', $provides);
    }

    public function testCollectWithMultiplePatterns(): void
    {
        $code = <<<'PHP'
<?php
echo $_GET["name"];
exec($_POST["cmd"]);
$q = "SELECT * FROM t WHERE id = " . $_GET["id"];
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('security.xss.count'));
        self::assertSame(1, $metrics->get('security.command_injection.count'));
        self::assertSame(1, $metrics->get('security.sql_injection.count'));

        // Check line numbers
        self::assertSame(2, $metrics->get('security.xss.line.0'));
        self::assertSame(3, $metrics->get('security.command_injection.line.0'));
        self::assertSame(4, $metrics->get('security.sql_injection.line.0'));
    }

    public function testCollectWithNoFindings(): void
    {
        $code = <<<'PHP'
<?php
echo htmlspecialchars($_GET["name"]);
$name = "safe";
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('security.xss.count'));
        self::assertSame(0, $metrics->get('security.command_injection.count'));
        self::assertSame(0, $metrics->get('security.sql_injection.count'));
    }

    public function testReset(): void
    {
        $code1 = '<?php echo $_GET["name"];';
        $this->collectMetrics($code1);

        $this->collector->reset();

        $code2 = '<?php echo "safe";';
        $metrics = $this->collectMetrics($code2);

        self::assertSame(0, $metrics->get('security.xss.count'));
    }

    private function collectMetrics(string $code): MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}
