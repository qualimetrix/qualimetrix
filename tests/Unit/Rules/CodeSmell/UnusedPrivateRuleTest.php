<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\CodeSmell;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\CodeSmell\UnusedPrivateOptions;
use AiMessDetector\Rules\CodeSmell\UnusedPrivateRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnusedPrivateRule::class)]
#[CoversClass(UnusedPrivateOptions::class)]
final class UnusedPrivateRuleTest extends TestCase
{
    #[Test]
    public function nameAndDescriptionAreCorrect(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        self::assertSame('code-smell.unused-private', $rule->getName());
        self::assertSame('Detects unused private methods, properties, and constants', $rule->getDescription());
        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    #[Test]
    public function optionsClassIsCorrect(): void
    {
        self::assertSame(UnusedPrivateOptions::class, UnusedPrivateRule::getOptionsClass());
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noUnusedMembersProducesNoViolations(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'Clean');
        $classInfo = new SymbolInfo($symbolPath, 'src/Clean.php', 5);

        $metricBag = (new MetricBag())
            ->with('unusedPrivate.total', 0);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function unusedMethodProducesViolation(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'Smelly');
        $classInfo = new SymbolInfo($symbolPath, 'src/Smelly.php', 5);

        $metricBag = (new MetricBag())
            ->with('unusedPrivate.total', 1)
            ->withEntry('unusedPrivate.method', ['line' => 15, 'name' => 'doLoadMappingFile']);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(15, $violations[0]->location->line);
        self::assertSame('Unused private method `doLoadMappingFile`', $violations[0]->message);
        self::assertSame('code-smell.unused-private', $violations[0]->ruleName);
        self::assertSame(1, $violations[0]->metricValue);
    }

    #[Test]
    public function unusedPropertyProducesViolation(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'PropClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/PropClass.php', 5);

        $metricBag = (new MetricBag())
            ->with('unusedPrivate.total', 1)
            ->withEntry('unusedPrivate.property', ['line' => 10, 'name' => 'cache']);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame('Unused private property `cache`', $violations[0]->message);
    }

    #[Test]
    public function unusedConstantProducesViolation(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'ConstClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/ConstClass.php', 5);

        $metricBag = (new MetricBag())
            ->with('unusedPrivate.total', 1)
            ->withEntry('unusedPrivate.constant', ['line' => 8, 'name' => 'MAX_RETRIES']);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame('Unused private constant `MAX_RETRIES`', $violations[0]->message);
        self::assertSame(8, $violations[0]->location->line);
    }

    #[Test]
    public function multipleUnusedMembersProduceMultipleViolations(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'ManyUnused');
        $classInfo = new SymbolInfo($symbolPath, 'src/ManyUnused.php', 5);

        $metricBag = (new MetricBag())
            ->with('unusedPrivate.total', 4)
            ->withEntry('unusedPrivate.method', ['line' => 10, 'name' => 'foo'])
            ->withEntry('unusedPrivate.method', ['line' => 15, 'name' => 'bar'])
            ->withEntry('unusedPrivate.property', ['line' => 7, 'name' => 'baz'])
            ->withEntry('unusedPrivate.constant', ['line' => 8, 'name' => 'QUX']);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(4, $violations);

        // Check messages by type
        $messages = array_map(fn($v) => $v->message, $violations);
        self::assertCount(2, array_filter($messages, fn($m) => str_starts_with($m, 'Unused private method')));
        self::assertCount(1, array_filter($messages, fn($m) => str_starts_with($m, 'Unused private property')));
        self::assertCount(1, array_filter($messages, fn($m) => str_starts_with($m, 'Unused private constant')));
    }

    #[Test]
    public function optionsFromArray(): void
    {
        $options = UnusedPrivateOptions::fromArray(['enabled' => false]);
        self::assertFalse($options->isEnabled());

        $options = UnusedPrivateOptions::fromArray([]);
        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function optionsSeverity(): void
    {
        $options = new UnusedPrivateOptions();

        self::assertSame(Severity::Warning, $options->getSeverity(1));
        self::assertSame(Severity::Warning, $options->getSeverity(5));
        self::assertNull($options->getSeverity(0));
    }
}
