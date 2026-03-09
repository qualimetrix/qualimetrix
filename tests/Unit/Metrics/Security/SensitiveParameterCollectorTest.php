<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Security;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\Security\SensitiveParameterCollector;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(SensitiveParameterCollector::class)]
final class SensitiveParameterCollectorTest extends TestCase
{
    private SensitiveParameterCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new SensitiveParameterCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('sensitive-parameter', $this->collector->getName());
    }

    public function testProvides(): void
    {
        self::assertSame(['security.sensitiveParameter.count'], $this->collector->provides());
    }

    public function testCollectWithFindings(): void
    {
        $code = <<<'PHP'
<?php
function login(string $password, string $apiKey) {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('security.sensitiveParameter.count'));
        self::assertNotNull($metrics->get('security.sensitiveParameter.line.0'));
        self::assertNotNull($metrics->get('security.sensitiveParameter.line.1'));
    }

    public function testCollectWithNoFindings(): void
    {
        $code = <<<'PHP'
<?php
function foo(string $name, int $age) {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('security.sensitiveParameter.count'));
    }

    public function testCollectWithAttribute(): void
    {
        $code = <<<'PHP'
<?php
function login(#[\SensitiveParameter] string $password) {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('security.sensitiveParameter.count'));
    }

    public function testReset(): void
    {
        $code1 = '<?php function login(string $password) {}';
        $this->collectMetrics($code1);

        $this->collector->reset();

        $code2 = '<?php function foo(string $name) {}';
        $metrics = $this->collectMetrics($code2);

        self::assertSame(0, $metrics->get('security.sensitiveParameter.count'));
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
