<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Coupling\InstabilityRule;
use Qualimetrix\Analysis\Evidence\Design\DataClass\DataClassOptions;
use Qualimetrix\Analysis\Evidence\Design\DataClass\DataClassRule;
use Qualimetrix\Analysis\Evidence\Design\GodClass\GodClassOptions;
use Qualimetrix\Analysis\Evidence\Design\GodClass\GodClassRule;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityRule;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionForms;
use Qualimetrix\Analysis\Policy\Inline\Contract\RuleValidatorMapFactory;

#[CoversClass(RuleOptionForms::class)]
#[CoversClass(RuleValidatorMapFactory::class)]
final class RuleOptionFormsTest extends TestCase
{
    #[Test]
    public function itUsesIndependentDeclaredKeysForDataClassAxes(): void
    {
        $forms = RuleValidatorMapFactory::build([DataClassRule::class])[DataClassRule::NAME];
        self::assertInstanceOf(RuleOptionForms::class, $forms);

        self::assertSame('integer at least 0', $forms->formOf(DataClassRule::NAME, null, 'warning')->describe());
        self::assertSame('integer at least 0', $forms->formOf(DataClassRule::NAME, null, 'error')->describe());
        self::assertSame(['warning' => 'woc-threshold', 'error' => 'wmc-threshold'], DataClassOptions::acceptedOptionKeys()->overrideAxes());
    }

    #[Test]
    public function itFindsOnlyGodClassWarningAxis(): void
    {
        $forms = RuleValidatorMapFactory::build([GodClassRule::class])[GodClassRule::NAME];
        self::assertInstanceOf(RuleOptionForms::class, $forms);

        self::assertSame('integer at least 0', $forms->formOf(GodClassRule::NAME, null, 'warning')->describe());
        self::assertFalse($forms->hasAxis(GodClassRule::NAME, null, 'error'));
        self::assertSame(['warning' => 'min-criteria'], GodClassOptions::acceptedOptionKeys()->overrideAxes());
    }

    #[Test]
    public function itReadsBothComplexityLevelsFromTheirOwnDeclarations(): void
    {
        $forms = RuleValidatorMapFactory::build([ComplexityRule::class])[ComplexityRule::NAME];
        self::assertInstanceOf(RuleOptionForms::class, $forms);

        self::assertSame(['callable', 'class'], $forms->levels());
        self::assertSame('integer at least 0', $forms->formOf(ComplexityRule::NAME, 'callable', 'warning')->describe());
        self::assertSame('integer at least 0', $forms->formOf(ComplexityRule::NAME, 'class', 'warning')->describe());
        self::assertSame('integer at least 0', $forms->formOf(ComplexityRule::NAME, 'class', 'error')->describe());
    }

    #[Test]
    public function itKeepsNumberFormsOnFractionalRules(): void
    {
        $map = RuleValidatorMapFactory::build([MaintainabilityRule::class, InstabilityRule::class]);
        $maintainability = $map[MaintainabilityRule::NAME];
        $instability = $map[InstabilityRule::NAME];
        self::assertInstanceOf(RuleOptionForms::class, $maintainability);
        self::assertInstanceOf(RuleOptionForms::class, $instability);

        self::assertSame('number at least 0', $maintainability->formOf(MaintainabilityRule::NAME, null, 'warning')->describe());
        self::assertSame('number at least 0', $maintainability->formOf(MaintainabilityRule::NAME, null, 'error')->describe());
        self::assertSame('number at least 0', $instability->formOf(InstabilityRule::NAME, 'class', 'warning')->describe());
        self::assertSame('number at least 0', $instability->formOf(InstabilityRule::NAME, 'namespace', 'error')->describe());
    }
}
