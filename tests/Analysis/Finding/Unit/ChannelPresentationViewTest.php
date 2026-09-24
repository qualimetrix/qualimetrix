<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\ChannelPresentationView;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * `ChannelPresentationView` joins {@see ChannelIdentityInterface::producerOf()}
 * with the channel's own declared description, the producing rule's own
 * {@see RuleMetadata} and its declared documentation page.
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
            producerByCode: ['complexity.cyclomatic.function' => 'complexity.ccn'],
            rules: [$this->rule('complexity.ccn', 'Flags overly complex callables.')],
            docsPageByRule: ['complexity.ccn' => 'rules/complexity.md'],
        );

        $presentation = $view->presentationFor('complexity.cyclomatic.function');

        self::assertNotNull($presentation);
        self::assertSame('Flags overly complex callables.', $presentation->description);
        self::assertSame('rules/complexity.md', $presentation->docsPage);
    }

    /**
     * A channel not named after its producer carries its own description, and
     * that text — not the producer's — is the channel's display text. The
     * page stays the producer's: the channel is documented on its producer's
     * page.
     */
    #[Test]
    public function itPrefersTheChannelsOwnDeclaredDescriptionOverItsProducers(): void
    {
        $view = $this->view(
            producerByCode: [
                'architecture.layer-violation' => 'architecture.layer-violation',
                'architecture.doubted-assignment' => 'architecture.layer-violation',
            ],
            rules: [$this->rule('architecture.layer-violation', 'Detects forbidden layer dependencies.')],
            docsPageByRule: ['architecture.layer-violation' => 'rules/architecture.md'],
            declarationByCode: [
                'architecture.layer-violation' => ChannelDeclaration::occurrence(SymbolLevel::Class_),
                'architecture.doubted-assignment' => ChannelDeclaration::occurrence(SymbolLevel::Project)
                    ->describedAs('Counts assignments in doubt.'),
            ],
        );

        $own = $view->presentationFor('architecture.doubted-assignment');
        self::assertNotNull($own);
        self::assertSame('Counts assignments in doubt.', $own->description);
        self::assertSame('rules/architecture.md', $own->docsPage);

        $producers = $view->presentationFor('architecture.layer-violation');
        self::assertNotNull($producers);
        self::assertSame('Detects forbidden layer dependencies.', $producers->description);
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
            producerByCode: ['complexity.cyclomatic.function' => 'complexity.ccn'],
            rules: [$this->rule('complexity.ccn', 'Flags overly complex callables.')],
            docsPageByRule: [],
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/complexity\.cyclomatic/');

        $view->presentationFor('complexity.cyclomatic.function');
    }

    /**
     * @param array<string, string> $producerByCode finding code => producing rule name
     * @param list<RuleMetadata> $rules
     * @param array<string, string> $docsPageByRule
     * @param array<string, ChannelDeclaration> $declarationByCode
     */
    private function view(
        array $producerByCode,
        array $rules,
        array $docsPageByRule,
        array $declarationByCode = [],
    ): ChannelPresentationView {
        $identity = self::createStubForIntersectionOfInterfaces([
            ChannelIdentityInterface::class,
            ChannelDeclarationRegistryInterface::class,
        ]);
        $identity->method('producerOf')->willReturnCallback(
            static fn(string $code): ?string => $producerByCode[$code] ?? null,
        );
        $identity->method('declarationFor')->willReturnCallback(
            static fn(FindingChannel $channel): ?ChannelDeclaration => $declarationByCode[$channel->code] ?? null,
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

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([]);
    }
}
