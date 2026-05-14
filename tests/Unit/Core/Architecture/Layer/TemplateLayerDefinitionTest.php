<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Architecture\Layer;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Architecture\Layer\CapturePattern;
use Qualimetrix\Core\Architecture\Layer\MembershipSpec;
use Qualimetrix\Core\Architecture\Layer\TemplateLayerDefinition;

#[CoversClass(TemplateLayerDefinition::class)]
#[CoversClass(CapturePattern::class)]
final class TemplateLayerDefinitionTest extends TestCase
{
    #[Test]
    public function construct_singleVariable_collectsVariablesFromNameAndPatterns(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        self::assertSame('domain-{module}', $template->nameTemplate());
        self::assertSame(['module'], $template->variables());
    }

    #[Test]
    public function construct_multipleVariables_returnsSortedDistinctList(): void
    {
        $template = new TemplateLayerDefinition(
            'cluster-{tenant}-{module}',
            new MembershipSpec(patterns: ['App\\{tenant}\\Module\\{module}\\Domain\\**']),
        );

        // Sorted alphabetically: module, tenant
        self::assertSame(['module', 'tenant'], $template->variables());
    }

    #[Test]
    public function construct_emptyName_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name template must not be empty');

        new TemplateLayerDefinition(
            '',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );
    }

    #[Test]
    public function construct_nameWithoutVariables_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('references no capture variables');

        new TemplateLayerDefinition(
            'domain',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );
    }

    #[Test]
    public function construct_nameVariableNotBoundByPattern_rejected(): void
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
    public function construct_nameVariableBoundByOneOfMultiplePatterns_accepted(): void
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
    public function construct_invalidCaptureGrammarInName_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name template');

        new TemplateLayerDefinition(
            'domain-{1invalid}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain']),
        );
    }

    #[Test]
    public function construct_invalidCaptureGrammarInPattern_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pattern');

        new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Sub\\{module}']),
        );
    }

    #[Test]
    public function containsCaptureVariable_recognisesCaptures(): void
    {
        self::assertTrue(TemplateLayerDefinition::containsCaptureVariable('domain-{module}'));
        self::assertTrue(TemplateLayerDefinition::containsCaptureVariable('App\\{tenant}\\**'));
        self::assertFalse(TemplateLayerDefinition::containsCaptureVariable('App\\Service\\**'));
        self::assertFalse(TemplateLayerDefinition::containsCaptureVariable('plain-name'));
    }
}
