<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionAddress;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;

#[CoversClass(RuleOptionShape::class)]
#[CoversClass(RuleOptionSurface::class)]
final class RuleOptionSchemaConversionTest extends TestCase
{
    #[Test]
    public function itGivesNestedAliasesAndRuleOptionsTheSameDeclaredNumericTarget(): void
    {
        $surface = RuleOptionSurface::of(ComplexityOptions::class);
        $address = $surface->locate('callable.warning');
        self::assertNotNull($address);
        $target = $surface->schemaAt($address);

        self::assertSame(MergePolicy::LastWriterWins, $target->policy);
        self::assertSame([ScalarForm::Integer], $target->scalar->forms);
        self::assertSame(0, $target->scalar->minimum);
        self::assertSame($target->describe(), $surface->schemaAt(new RuleOptionAddress('callable', 'warning'))->describe());
    }

    #[Test]
    public function itUsesDeclaredChildrenForAWholeLevelBlock(): void
    {
        $surface = RuleOptionSurface::of(ComplexityOptions::class);
        $block = $surface->schemaAt(new RuleOptionAddress(null, 'callable'));

        self::assertSame(MergePolicy::DeepMerge, $block->policy);
        self::assertArrayHasKey('warning', $block->map->keys->fields());
        self::assertArrayHasKey('error', $block->map->keys->fields());
        self::assertSame(0, $block->map->keys->fields()['warning']->scalar->minimum);
    }

    #[Test]
    public function itExposesFrameworkAndClassValidatedIngressFormsFromTheirOwners(): void
    {
        $framework = FrameworkOptionKeys::declared();
        self::assertSame(FrameworkOptionKeys::all(), $framework->acceptedForDisplay());
        $paths = RuleOptionSurface::of(ComplexityOptions::class)->schemaAt(new RuleOptionAddress(null, FrameworkOptionKeys::PATHS));
        self::assertSame(MergePolicy::Replace, $paths->policy);
        self::assertSame(MergePolicy::ByName, $paths->collection?->element->policy);

        $distance = RuleOptionSurface::of(DistanceOptions::class);
        $address = $distance->locate('include-namespaces');
        self::assertNotNull($address);
        $target = $distance->schemaAt($address);
        self::assertSame(MergePolicy::Replace, $target->policy);
        self::assertSame(MergePolicy::ByName, $target->collection?->element->policy);
    }
}
