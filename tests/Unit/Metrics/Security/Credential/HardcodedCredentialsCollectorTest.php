<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Security\Credential;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Metrics\Security\Credential\HardcodedCredentialsCollector;
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
    public function itAssignsClassInitializersToTheirExactClassAndTopLevelCodeToTheFile(): void
    {
        $metrics = $this->collectMetrics(<<<'PHP'
<?php
$password = 'top-secret';
class Credentials { public string $password = 'class-secret'; }
PHP);
        $entries = $metrics->entries('security.hardcodedCredentials');

        self::assertSame('file', $entries[0]['subjectKind']);
        self::assertSame('declaration', $entries[1]['subjectKind']);
        self::assertSame('class', $entries[1]['logicalKind']);
        self::assertSame('Credentials', $entries[1]['class']);
    }

    #[Test]
    public function itKeepsAnonymousClassAndEnumCaseFindingsFileOwnedWhileNamedConstantsAreClassOwned(): void
    {
        $metrics = $this->collectMetrics(<<<'PHP'
<?php
$anonymous = new class { public string $password = 'anonymous-secret'; };
class Named { public const PASSWORD = 'constant-secret'; }
enum Tokens: string { case PASSWORD = 'case-secret'; }
PHP);
        $entries = $metrics->entries('security.hardcodedCredentials');

        self::assertSame('file', $entries[0]['subjectKind']);
        self::assertSame('declaration', $entries[1]['subjectKind']);
        self::assertSame('Named', $entries[1]['class']);
        self::assertSame('declaration', $entries[2]['subjectKind']);
        self::assertSame('Tokens', $entries[2]['class']);
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

    #[Test]
    public function itDeliberatelyDoesNotProvideCallableMetrics(): void
    {
        self::assertNotContains(\Qualimetrix\Core\Metric\CallableMetricsProviderInterface::class, class_implements($this->collector));
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
