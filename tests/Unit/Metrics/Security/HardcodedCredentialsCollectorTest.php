<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Security;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\Security\HardcodedCredentialsCollector;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(HardcodedCredentialsCollector::class)]
final class HardcodedCredentialsCollectorTest extends TestCase
{
    private HardcodedCredentialsCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new HardcodedCredentialsCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('hardcoded-credentials', $this->collector->getName());
    }

    public function testProvides(): void
    {
        self::assertSame(['security.hardcodedCredentials'], $this->collector->provides());
    }

    public function testCollectWithTwoFindings(): void
    {
        $code = <<<'PHP'
<?php
$password = "secret123";
$apiKey = "sk-abc123def";
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->entryCount('security.hardcodedCredentials'));
        $entries = $metrics->entries('security.hardcodedCredentials');
        self::assertSame(2, $entries[0]['line']);
        self::assertSame(3, $entries[1]['line']);
    }

    public function testCollectWithNoFindings(): void
    {
        $code = <<<'PHP'
<?php
$password = getenv("DB_PASSWORD");
$username = "admin";
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->entryCount('security.hardcodedCredentials'));
    }

    public function testReset(): void
    {
        $code1 = '<?php $password = "secret123";';
        $code2 = '<?php $username = "admin";';

        $this->collectMetrics($code1);
        $this->collector->reset();

        $metrics = $this->collectMetrics($code2);

        self::assertSame(0, $metrics->entryCount('security.hardcodedCredentials'));
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
