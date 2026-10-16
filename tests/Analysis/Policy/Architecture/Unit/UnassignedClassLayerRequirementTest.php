<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Policy\Architecture\UnassignedClass\UnassignedClassLayerRequirement;
use Qualimetrix\Analysis\Policy\Architecture\UnassignedClass\UnassignedClassOptions;
use Qualimetrix\Analysis\Policy\Architecture\UnassignedClass\UnassignedClassRule;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(UnassignedClassLayerRequirement::class)]
final class UnassignedClassLayerRequirementTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function inactiveOrDeclared(): iterable
    {
        yield 'default ignore' => [[]];
        yield 'explicit ignore' => [['rules' => [UnassignedClassRule::NAME => ['mode' => 'ignore']]]];
        yield 'disabled warn' => [['rules' => [UnassignedClassRule::NAME => ['mode' => 'warn', 'enabled' => false]]]];
        yield 'producer disabled' => [['rules' => [UnassignedClassRule::NAME => ['mode' => 'error']], 'disabled_rules' => [UnassignedClassRule::NAME]]];
        yield 'group disabled' => [['rules' => [UnassignedClassRule::NAME => ['mode' => 'error']], 'disabled_rules' => ['architecture.*']]];
        yield 'concrete declaration' => [['rules' => [UnassignedClassRule::NAME => ['mode' => 'warn']], 'architecture' => ['layers' => [['name' => 'domain', 'patterns' => ['App\\**']]]]]];
        yield 'template declaration' => [['rules' => [UnassignedClassRule::NAME => ['mode' => 'error']], 'architecture' => ['layers' => [['name' => 'domain-{module}', 'patterns' => ['App\\{module}\\**']]]]]];
    }

    /** @param array<string, mixed> $values */
    #[Test]
    #[DataProvider('inactiveOrDeclared')]
    public function itAcceptsAnInactiveModeOrAnAuthoredLayer(array $values): void
    {
        (new UnassignedClassLayerRequirement())->assertSatisfied(self::ready([['source' => 'config', 'values' => $values]]));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itNamesThePresetModeAndTheMissingMergedLayer(): void
    {
        $configuration = self::ready([
            ['source' => 'preset', 'values' => ['rules' => [UnassignedClassRule::NAME => ['mode' => 'error']]]],
            ['source' => 'config', 'values' => ['architecture' => ['coverage-gap' => 'ignore']]],
        ]);
        try {
            (new UnassignedClassLayerRequirement())->assertSatisfied($configuration);
            self::fail('An enabled active mode needs layers.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame([ConfigurationSource::Preset, ConfigurationSource::Resolved], array_map(static fn($source) => $source->source(), $refusal->sources()));
            self::assertSame('fixture', $refusal->sources()[0]->locator());
            self::assertSame('architecture.layers', $refusal->sources()[1]->locator());
            self::assertSame(['architecture', 'layers'], $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itNamesTheAuthoredEmptyListInsteadOfInventingAnAbsentWriter(): void
    {
        $configuration = self::ready([
            ['source' => 'preset', 'values' => ['rules' => [UnassignedClassRule::NAME => ['mode' => 'warn']]]],
            ['source' => 'config', 'values' => ['architecture' => ['layers' => []]]],
        ]);
        try {
            (new UnassignedClassLayerRequirement())->assertSatisfied($configuration);
            self::fail('An empty declared list cannot provide a layer.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame([ConfigurationSource::Preset, ConfigurationSource::ConfigFile], array_map(static fn($source) => $source->source(), $refusal->sources()));
            self::assertSame('/project/qmx.yaml', $refusal->sources()[1]->locator());
        }
    }

    #[Test]
    public function itRefusesAnIncompleteFindingConfiguration(): void
    {
        $this->expectException(LogicException::class);
        (new UnassignedClassLayerRequirement())->assertSatisfied(FindingConfiguration::none());
    }

    /** @param list<array{source: string, values: array<string, mixed>}> $sources */
    private static function ready(array $sources): FindingConfiguration
    {
        $metadata = [new RuleMetadata(UnassignedClassRule::NAME, UnassignedClassOptions::class, '', [], false)];
        $document = ResolvedOptionsFixture::document($sources, AbsolutePath::fromString('/project'), $metadata);
        return ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
    }
}
