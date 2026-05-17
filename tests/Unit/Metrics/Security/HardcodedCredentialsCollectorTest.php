<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Security;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Metrics\Security\HardcodedCredentialsCollector;
use SplFileInfo;

#[CoversClass(HardcodedCredentialsCollector::class)]
final class HardcodedCredentialsCollectorTest extends TestCase
{
    private HardcodedCredentialsCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new HardcodedCredentialsCollector();
    }

    #[Test]
    public function itReturnsCorrectName(): void
    {
        self::assertSame('hardcoded-credentials', $this->collector->getName());
    }

    #[Test]
    public function itProvidesExpectedMetrics(): void
    {
        self::assertSame(['security.hardcodedCredentials'], $this->collector->provides());
    }

    #[Test]
    public function itCollectsWithTwoFindings(): void
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

    #[Test]
    public function itCollectsWithNoFindings(): void
    {
        $code = <<<'PHP'
<?php
$password = getenv("DB_PASSWORD");
$username = "admin";
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->entryCount('security.hardcodedCredentials'));
    }

    #[Test]
    public function itResetsState(): void
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
