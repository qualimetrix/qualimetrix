<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Security\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Security\SecurityPatternOptions;
use Qualimetrix\Analysis\Evidence\Security\XssRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(XssRule::class)]
#[CoversClass(SecurityPatternOptions::class)]
final class XssRuleTest extends TestCase
{
    #[Test]
    public function itHasCorrectNameAndCategory(): void
    {
        $rule = new XssRule(new SecurityPatternOptions());

        self::assertSame('security.xss', $rule->getName());
        self::assertSame('Detects potential XSS vulnerabilities', $rule->getDescription());
    }

    #[Test]
    public function itReturnsNoFindingsWhenDisabled(): void
    {
        $rule = new XssRule(new SecurityPatternOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())->withEntry('security.xss', ['subjectKind' => 'file', 'line' => 1, 'superglobal' => '']),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itReturnsNoFindingsWhenNoFindings(): void
    {
        $rule = new XssRule(new SecurityPatternOptions());

        $context = $this->createContext(new MetricBag());

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itCreatesFindingForSingleFinding(): void
    {
        $rule = new XssRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.xss', ['subjectKind' => 'file', 'line' => 8, 'superglobal' => '']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(8, $findings[0]->location->line);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame('security.xss', $findings[0]->ruleName);
        self::assertSame('Potential XSS — use htmlspecialchars() or equivalent before outputting user input', $findings[0]->message);
        self::assertSame('file:src/View/Template.php', $findings[0]->subject->toCanonical());
        self::assertSame('Escape output with htmlspecialchars() or use a template engine with auto-escaping.', $findings[0]->recommendation);
        self::assertTrue($findings[0]->location->precise);
    }

    #[Test]
    public function itCreatesMultipleFindingsForMultipleFindings(): void
    {
        $rule = new XssRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.xss', ['subjectKind' => 'file', 'line' => 5, 'superglobal' => ''])
                ->withEntry('security.xss', ['subjectKind' => 'file', 'line' => 12, 'superglobal' => '']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        self::assertSame(5, $findings[0]->location->line);
        self::assertSame(12, $findings[1]->location->line);
    }

    #[Test]
    public function itIncludesSuperglobalInFindingMessage(): void
    {
        $rule = new XssRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.xss', ['subjectKind' => 'file', 'line' => 8, 'superglobal' => '_POST']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertStringContainsString('($_POST)', $findings[0]->message);
        self::assertStringContainsString('XSS', $findings[0]->message);
    }

    #[Test]
    public function itRejectsWrongOptionsTypeInConstructor(): void
    {
        self::expectException(InvalidArgumentException::class);

        $options = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);
        new XssRule($options);
    }

    private function createContext(MetricBag $metrics): AnalysisContext
    {
        $filePath = SymbolPath::forFile(RelativePath::fromString('src/View/Template.php'));
        $fileInfo = new SymbolInfo(
            symbolPath: $filePath,
            file: RelativePath::fromString('src/View/Template.php'),
            line: null,
        );

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$fileInfo]);
        $repository->method('get')
            ->willReturn($metrics);

        return new AnalysisContext(metrics: $repository);
    }
}
