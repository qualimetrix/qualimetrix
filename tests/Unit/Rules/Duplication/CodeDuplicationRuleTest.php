<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Duplication;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Duplication\DuplicateBlock;
use Qualimetrix\Core\Duplication\DuplicateLocation;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Duplication\CodeDuplicationOptions;
use Qualimetrix\Rules\Duplication\CodeDuplicationRule;

#[CoversClass(CodeDuplicationRule::class)]
#[CoversClass(CodeDuplicationOptions::class)]
#[CoversClass(DuplicateBlock::class)]
#[CoversClass(DuplicateLocation::class)]
final class CodeDuplicationRuleTest extends TestCase
{
    #[Test]
    public function nameAndDescriptionAreCorrect(): void
    {
        $rule = new CodeDuplicationRule(new CodeDuplicationOptions());

        self::assertSame('duplication.code-duplication', $rule->getName());
        self::assertSame('Detects duplicated code blocks', $rule->getDescription());
        self::assertSame(RuleCategory::Duplication, $rule->getCategory());
    }

    #[Test]
    public function optionsClassIsCorrect(): void
    {
        self::assertSame(CodeDuplicationOptions::class, CodeDuplicationRule::getOptionsClass());
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
    {
        $rule = new CodeDuplicationRule(new CodeDuplicationOptions(enabled: false));

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext(
            $repository,
            duplicateBlocks: [
                new DuplicateBlock(
                    [new DuplicateLocation('a.php', 1, 10), new DuplicateLocation('b.php', 1, 10)],
                    10,
                    50,
                ),
            ],
        );

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noDuplicatesProducesNoViolations(): void
    {
        $rule = new CodeDuplicationRule(new CodeDuplicationOptions());

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function duplicateBlockProducesViolation(): void
    {
        $rule = new CodeDuplicationRule(new CodeDuplicationOptions());

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext(
            $repository,
            duplicateBlocks: [
                new DuplicateBlock(
                    locations: [
                        new DuplicateLocation('src/A.php', 10, 25),
                        new DuplicateLocation('src/B.php', 30, 45),
                    ],
                    lines: 16,
                    tokens: 80,
                ),
            ],
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);

        $v = $violations[0];
        self::assertSame('duplication.code-duplication', $v->ruleName);
        self::assertSame('src/A.php', $v->location->file);
        self::assertSame(10, $v->location->line);
        self::assertSame(Severity::Warning, $v->severity);
        self::assertSame(16, $v->metricValue);
        self::assertStringContainsString('16 lines', $v->message);
        self::assertStringContainsString('2 occurrences', $v->message);
        self::assertStringContainsString('src/B.php:30-45', $v->message);
    }

    #[Test]
    public function largeDuplicateIsError(): void
    {
        $rule = new CodeDuplicationRule(new CodeDuplicationOptions());

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext(
            $repository,
            duplicateBlocks: [
                new DuplicateBlock(
                    locations: [
                        new DuplicateLocation('a.php', 1, 60),
                        new DuplicateLocation('b.php', 1, 60),
                    ],
                    lines: 60,
                    tokens: 300,
                ),
            ],
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function multipleBlocksProduceMultipleViolations(): void
    {
        $rule = new CodeDuplicationRule(new CodeDuplicationOptions());

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext(
            $repository,
            duplicateBlocks: [
                new DuplicateBlock(
                    [new DuplicateLocation('a.php', 1, 10), new DuplicateLocation('b.php', 1, 10)],
                    10,
                    50,
                ),
                new DuplicateBlock(
                    [new DuplicateLocation('c.php', 5, 20), new DuplicateLocation('d.php', 5, 20)],
                    16,
                    80,
                ),
            ],
        );

        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
    }

    #[Test]
    public function multipleLocationsInMessage(): void
    {
        $rule = new CodeDuplicationRule(new CodeDuplicationOptions());

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext(
            $repository,
            duplicateBlocks: [
                new DuplicateBlock(
                    locations: [
                        new DuplicateLocation('a.php', 1, 10),
                        new DuplicateLocation('b.php', 5, 14),
                        new DuplicateLocation('c.php', 20, 29),
                    ],
                    lines: 10,
                    tokens: 50,
                ),
            ],
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('3 occurrences', $violations[0]->message);
        self::assertStringContainsString('b.php:5-14', $violations[0]->message);
        self::assertStringContainsString('c.php:20-29', $violations[0]->message);
    }

    #[Test]
    public function optionsFromArray(): void
    {
        $options = CodeDuplicationOptions::fromArray([
            'enabled' => false,
            'min_lines' => 10,
            'min_tokens' => 100,
            'warning' => 8,
            'error' => 40,
        ]);
        self::assertFalse($options->isEnabled());
        self::assertSame(10, $options->min_lines);
        self::assertSame(100, $options->min_tokens);
        self::assertSame(8, $options->warning);
        self::assertSame(40, $options->error);

        // camelCase support
        $options = CodeDuplicationOptions::fromArray([
            'minLines' => 15,
            'minTokens' => 120,
        ]);
        self::assertSame(15, $options->min_lines);
        self::assertSame(120, $options->min_tokens);
    }

    #[Test]
    public function optionsSeverityWithDefaults(): void
    {
        $options = new CodeDuplicationOptions();

        self::assertNull($options->getSeverity(0));
        self::assertNull($options->getSeverity(4));
        self::assertSame(Severity::Warning, $options->getSeverity(5));
        self::assertSame(Severity::Warning, $options->getSeverity(49));
        self::assertSame(Severity::Error, $options->getSeverity(50));
        self::assertSame(Severity::Error, $options->getSeverity(100));
    }

    #[Test]
    public function optionsSeverityWithCustomThresholds(): void
    {
        $options = new CodeDuplicationOptions(warning: 10, error: 30);

        self::assertNull($options->getSeverity(9));
        self::assertSame(Severity::Warning, $options->getSeverity(10));
        self::assertSame(Severity::Warning, $options->getSeverity(29));
        self::assertSame(Severity::Error, $options->getSeverity(30));
    }
}
