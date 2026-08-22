<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\ChannelPresentationView;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * `ChannelPresentationView` joins {@see ChannelIdentityInterface::producerOf()}
 * with the producing rule's own {@see RuleMetadata} and its declared
 * documentation page — see
 * `docs/internal/plans/sarif-channel-descriptions.md`, package P2.
 *
 * The `computed.*` / `health.*` description preference is layered on separately by
 * {@see \Qualimetrix\Infrastructure\Rule\ComputedMetricChannelPresentation}
 * (see that class's own test), because this view cannot depend on
 * `ComputedMetricDefinitionCatalogInterface` without closing a dependency
 * cycle back onto this capability.
 */
#[CoversClass(ChannelPresentationView::class)]
final class ChannelPresentationViewTest extends TestCase
{
    #[Test]
    public function itJoinsTheProducersDescriptionAndDeclaredDocsPage(): void
    {
        $view = $this->view(
            producerByCode: ['complexity.cyclomatic.function' => 'complexity.cyclomatic'],
            rules: [$this->rule('complexity.cyclomatic', 'Flags overly complex callables.')],
            docsPageByRule: ['complexity.cyclomatic' => 'rules/complexity.md'],
        );

        $presentation = $view->presentationFor('complexity.cyclomatic.function');

        self::assertNotNull($presentation);
        self::assertSame('Flags overly complex callables.', $presentation->description);
        self::assertSame('rules/complexity.md', $presentation->docsPage);
    }

    #[Test]
    public function itReturnsNullForACodeNoChannelCarries(): void
    {
        $view = $this->view(producerByCode: [], rules: [], docsPageByRule: []);

        self::assertNull($view->presentationFor('no.such.channel'));
    }

    /**
     * A blank description is not display text. Sabotaging the join to answer
     * an empty string instead of falling back to null is exactly the
     * one-point break this test would catch — see the package report for the
     * before/after failure output.
     */
    #[Test]
    public function itReturnsNullWhenTheResolvedDescriptionIsEmpty(): void
    {
        $view = $this->view(
            producerByCode: ['design.data-class' => 'design.data-class'],
            rules: [$this->rule('design.data-class', '')],
            docsPageByRule: ['design.data-class' => 'rules/design.md'],
        );

        self::assertNull($view->presentationFor('design.data-class'));
    }

    /**
     * Every producer the universe can name must have contributed a DOCS_PAGE
     * entry to the map {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}
     * builds; a producer missing from that map means the compiler pass and
     * the rule registry have drifted apart, which must fail loud rather than
     * silently answer null.
     */
    #[Test]
    public function itFailsLoudWhenTheProducingRuleHasNoDeclaredDocsPage(): void
    {
        $view = $this->view(
            producerByCode: ['complexity.cyclomatic.function' => 'complexity.cyclomatic'],
            rules: [$this->rule('complexity.cyclomatic', 'Flags overly complex callables.')],
            docsPageByRule: [],
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/complexity\.cyclomatic/');

        $view->presentationFor('complexity.cyclomatic.function');
    }

    /**
     * @param array<string, string> $producerByCode violation code => producing rule name
     * @param list<RuleMetadata> $rules
     * @param array<string, string> $docsPageByRule
     */
    private function view(array $producerByCode, array $rules, array $docsPageByRule): ChannelPresentationView
    {
        $identity = self::createStub(ChannelIdentityInterface::class);
        $identity->method('producerOf')->willReturnCallback(
            static fn(string $violationCode): ?string => $producerByCode[$violationCode] ?? null,
        );

        $ruleExecution = self::createStub(RuleExecutionInterface::class);
        $ruleExecution->method('allRules')->willReturn($rules);

        return new ChannelPresentationView($identity, $ruleExecution, $docsPageByRule);
    }

    private function rule(string $name, string $description): RuleMetadata
    {
        return new RuleMetadata(
            name: $name,
            optionsClass: FixtureChannelPresentationRuleOptions::class,
            category: RuleCategory::CodeSmell,
            description: $description,
            aliases: [],
            active: true,
        );
    }
}

/**
 * Minimal RuleOptionsInterface stub — RuleMetadata requires an options
 * class-string, and the tests above never construct or read it.
 *
 * @internal
 */
final readonly class FixtureChannelPresentationRuleOptions implements RuleOptionsInterface
{
    public static function fromArray(array $config): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }
}
