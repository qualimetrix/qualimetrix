<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Security;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Metrics\Security\SensitiveParameterCollector;
use SplFileInfo;

#[CoversClass(SensitiveParameterCollector::class)]
final class SensitiveParameterCollectorTest extends TestCase
{
    private SensitiveParameterCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new SensitiveParameterCollector();
    }

    #[Test]
    public function itReturnsCorrectName(): void
    {
        self::assertSame('sensitive-parameter', $this->collector->getName());
    }

    #[Test]
    public function itProvidesExpectedMetrics(): void
    {
        self::assertSame(['security.sensitiveParameter'], $this->collector->provides());
    }

    #[Test]
    public function itCollectsWithFindings(): void
    {
        $code = <<<'PHP'
<?php
function login(string $password, string $apiKey) {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->entryCount('security.sensitiveParameter'));
        $entries = $metrics->entries('security.sensitiveParameter');
        self::assertCount(2, $entries);
        self::assertArrayHasKey('line', $entries[0]);
        self::assertArrayHasKey('line', $entries[1]);
    }

    #[Test]
    public function itCollectsWithNoFindings(): void
    {
        $code = <<<'PHP'
<?php
function foo(string $name, int $age) {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->entryCount('security.sensitiveParameter'));
    }

    #[Test]
    public function itSkipsParametersWithSensitiveParameterAttribute(): void
    {
        $code = <<<'PHP'
<?php
function login(#[\SensitiveParameter] string $password) {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->entryCount('security.sensitiveParameter'));
    }

    #[Test]
    public function itResetsState(): void
    {
        $code1 = '<?php function login(string $password) {}';
        $this->collectMetrics($code1);

        $this->collector->reset();

        $code2 = '<?php function foo(string $name) {}';
        $metrics = $this->collectMetrics($code2);

        self::assertSame(0, $metrics->entryCount('security.sensitiveParameter'));
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
