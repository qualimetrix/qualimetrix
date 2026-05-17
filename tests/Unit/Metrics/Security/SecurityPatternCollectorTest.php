<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Security;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Metrics\Security\SecurityPatternCollector;
use SplFileInfo;

#[CoversClass(SecurityPatternCollector::class)]
final class SecurityPatternCollectorTest extends TestCase
{
    private SecurityPatternCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new SecurityPatternCollector();
    }

    #[Test]
    public function itReturnsCorrectName(): void
    {
        self::assertSame('security-pattern', $this->collector->getName());
    }

    #[Test]
    public function itProvidesExpectedMetrics(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('security.sql_injection', $provides);
        self::assertContains('security.xss', $provides);
        self::assertContains('security.command_injection', $provides);
    }

    #[Test]
    public function itCollectsMultipleSecurityPatterns(): void
    {
        $code = <<<'PHP'
<?php
echo $_GET["name"];
exec($_POST["cmd"]);
$q = "SELECT * FROM t WHERE id = " . $_GET["id"];
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->entryCount('security.xss'));
        self::assertSame(1, $metrics->entryCount('security.command_injection'));
        self::assertSame(1, $metrics->entryCount('security.sql_injection'));

        // Check line numbers
        $xssEntries = $metrics->entries('security.xss');
        self::assertSame(2, $xssEntries[0]['line']);

        $cmdEntries = $metrics->entries('security.command_injection');
        self::assertSame(3, $cmdEntries[0]['line']);

        $sqlEntries = $metrics->entries('security.sql_injection');
        self::assertSame(4, $sqlEntries[0]['line']);
    }

    #[Test]
    public function itCollectsWithNoFindings(): void
    {
        $code = <<<'PHP'
<?php
echo htmlspecialchars($_GET["name"]);
$name = "safe";
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->entryCount('security.xss'));
        self::assertSame(0, $metrics->entryCount('security.command_injection'));
        self::assertSame(0, $metrics->entryCount('security.sql_injection'));
    }

    #[Test]
    public function itResetsState(): void
    {
        $code1 = '<?php echo $_GET["name"];';
        $this->collectMetrics($code1);

        $this->collector->reset();

        $code2 = '<?php echo "safe";';
        $metrics = $this->collectMetrics($code2);

        self::assertSame(0, $metrics->entryCount('security.xss'));
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
