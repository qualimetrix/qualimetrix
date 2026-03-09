<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Debt;

use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemediationTimeRegistry::class)]
final class RemediationTimeRegistryTest extends TestCase
{
    private RemediationTimeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new RemediationTimeRegistry();
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function knownRulesProvider(): iterable
    {
        yield 'complexity.cyclomatic' => ['complexity.cyclomatic', 30];
        yield 'complexity.cognitive' => ['complexity.cognitive', 30];
        yield 'complexity.npath' => ['complexity.npath', 30];
        yield 'complexity.wmc' => ['complexity.wmc', 30];
        yield 'coupling.cbo' => ['coupling.cbo', 45];
        yield 'coupling.class-rank' => ['coupling.class-rank', 30];
        yield 'coupling.instability' => ['coupling.instability', 30];
        yield 'coupling.distance' => ['coupling.distance', 30];
        yield 'design.inheritance' => ['design.inheritance', 30];
        yield 'design.noc' => ['design.noc', 20];
        yield 'design.type-coverage' => ['design.type-coverage', 15];
        yield 'design.lcom' => ['design.lcom', 45];
        yield 'size.class-count' => ['size.class-count', 30];
        yield 'size.method-count' => ['size.method-count', 20];
        yield 'size.property-count' => ['size.property-count', 15];
        yield 'maintainability.index' => ['maintainability.index', 60];
        yield 'code-smell.boolean-argument' => ['code-smell.boolean-argument', 10];
        yield 'code-smell.debug-code' => ['code-smell.debug-code', 5];
        yield 'code-smell.empty-catch' => ['code-smell.empty-catch', 10];
        yield 'code-smell.eval' => ['code-smell.eval', 15];
        yield 'code-smell.exit' => ['code-smell.exit', 10];
        yield 'code-smell.goto' => ['code-smell.goto', 15];
        yield 'code-smell.superglobals' => ['code-smell.superglobals', 15];
        yield 'code-smell.error-suppression' => ['code-smell.error-suppression', 10];
        yield 'code-smell.count-in-loop' => ['code-smell.count-in-loop', 10];
        yield 'code-smell.long-parameter-list' => ['code-smell.long-parameter-list', 20];
        yield 'code-smell.unreachable-code' => ['code-smell.unreachable-code', 10];
        yield 'security.hardcoded-credentials' => ['security.hardcoded-credentials', 30];
        yield 'security.sql-injection' => ['security.sql-injection', 60];
        yield 'security.xss' => ['security.xss', 45];
        yield 'security.command-injection' => ['security.command-injection', 60];
        yield 'security.sensitive-parameter' => ['security.sensitive-parameter', 10];
        yield 'architecture.circular-dependency' => ['architecture.circular-dependency', 120];
    }

    #[DataProvider('knownRulesProvider')]
    public function testKnownRuleReturnsCorrectMinutes(string $ruleName, int $expectedMinutes): void
    {
        self::assertSame($expectedMinutes, $this->registry->getMinutes($ruleName));
    }

    public function testUnknownRuleReturnsDefault(): void
    {
        self::assertSame(15, $this->registry->getMinutes('unknown.rule'));
    }

    public function testAnotherUnknownRuleReturnsDefault(): void
    {
        self::assertSame(15, $this->registry->getMinutes('custom.my-rule'));
    }
}
