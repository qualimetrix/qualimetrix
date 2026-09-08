<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Domain\Layer;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Layer\CapturePattern;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;

#[CoversClass(TemplateLayerDefinition::class)]
#[CoversClass(CapturePattern::class)]
#[CoversClass(ExcludeSpec::class)]
final class TemplateLayerDefinitionTest extends TestCase
{
    #[Test]
    public function itCollectsTheSingleVariableFromNameAndPatterns(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        self::assertSame('domain-{module}', $template->nameTemplate());
        self::assertSame(['module'], $template->variables());
    }

    #[Test]
    public function itReturnsMultipleVariablesAsASortedDistinctList(): void
    {
        $template = new TemplateLayerDefinition(
            'cluster-{tenant}-{module}',
            new MembershipSpec(patterns: ['App\\{tenant}\\Module\\{module}\\Domain\\**']),
        );

        // Sorted alphabetically: module, tenant
        self::assertSame(['module', 'tenant'], $template->variables());
    }

    #[Test]
    public function itRejectsAnEmptyNameTemplate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name template must not be empty');

        new TemplateLayerDefinition(
            '',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );
    }

    #[Test]
    public function itRejectsANameTemplateWithoutVariables(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('references no capture variables');

        new TemplateLayerDefinition(
            'domain',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );
    }

    #[Test]
    public function itRejectsANameVariableNotBoundByAnyPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('variable(s) "module" referenced in name template');

        new TemplateLayerDefinition(
            'domain-{module}',
            // pattern does not bind {module}
            new MembershipSpec(patterns: ['App\\Service\\**']),
        );
    }

    #[Test]
    public function itAcceptsANameVariableBoundByOneOfMultiplePatterns(): void
    {
        // First pattern is non-capturing filter; second is the binding source.
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: [
                'App\\Module\\**',
                'App\\Module\\{module}\\Domain\\**',
            ]),
        );

        self::assertSame(['module'], $template->variables());
    }

    #[Test]
    public function itRejectsInvalidCaptureGrammarInTheName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name template');

        new TemplateLayerDefinition(
            'domain-{1invalid}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain']),
        );
    }

    #[Test]
    public function itRejectsInvalidCaptureGrammarInAPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pattern');

        new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Sub\\{module}']),
        );
    }

    #[Test]
    public function itRecognisesCaptureVariablesInAString(): void
    {
        self::assertTrue(TemplateLayerDefinition::containsCaptureVariable('domain-{module}'));
        self::assertTrue(TemplateLayerDefinition::containsCaptureVariable('App\\{tenant}\\**'));
        self::assertFalse(TemplateLayerDefinition::containsCaptureVariable('App\\Service\\**'));
        self::assertFalse(TemplateLayerDefinition::containsCaptureVariable('plain-name'));
    }

    // -------------------------------------------------------------------------
    // exclude clause variable validation (Step F)
    // -------------------------------------------------------------------------

    #[Test]
    public function itAcceptsExcludePatternsUsingADeclaredVariable(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                exclude: new ExcludeSpec(patterns: ['App\\Module\\{module}\\Domain\\Generated\\**']),
            ),
        );

        self::assertNotNull($template->membership()->exclude);
        self::assertSame(['App\\Module\\{module}\\Domain\\Generated\\**'], $template->membership()->exclude->patterns);
    }

    #[Test]
    public function itAcceptsExcludePatternsWithoutAnyCaptures(): void
    {
        // exclude.patterns without any capture variable is fine — they act as
        // a plain glob filter on the template's expanded membership.
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                exclude: new ExcludeSpec(patterns: ['App\\Module\\Shared\\**']),
            ),
        );

        self::assertNotNull($template->membership()->exclude);
        self::assertSame(['App\\Module\\Shared\\**'], $template->membership()->exclude->patterns);
    }

    #[Test]
    public function itRejectsExcludePatternsReferencingAnUndeclaredVariable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exclude clause references undeclared variable(s) "tenant"');
        $this->expectExceptionMessage('declared variables: "module"');

        new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                exclude: new ExcludeSpec(patterns: ['App\\{tenant}\\Module\\{module}\\Generated\\**']),
            ),
        );
    }

    #[Test]
    public function itRejectsExcludePatternsWithInvalidCaptureGrammar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exclude pattern');

        new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                // Unbalanced brace in exclude pattern surfaces as a config error.
                exclude: new ExcludeSpec(patterns: ['App\\Module\\{module\\Generated\\**']),
            ),
        );
    }
}
