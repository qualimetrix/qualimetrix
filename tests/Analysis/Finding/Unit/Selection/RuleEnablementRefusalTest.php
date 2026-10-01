<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Selection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(RuleEnablementResolver::class)]
final class RuleEnablementRefusalTest extends TestCase
{
    #[Test]
    public function itRefusesContradictoryStrongestStatementsWithinAnAuthoredLayer(): void
    {
        self::assertRefusal(
            [
                ['source' => 'config', 'values' => [
                    'rules' => ['complexity.alpha' => ['enabled' => true]],
                    'disabled_rules' => ['complexity.alpha'],
                ]],
            ],
            self::metadata(),
            'Layer configuration file "/project/qmx.yaml" both enables and disables "complexity.alpha": rules.complexity.alpha.enabled: true; disabled_rules[0]: complexity.alpha.',
            [ConfigurationSource::ConfigFile],
        );
    }

    #[Test]
    public function itRefusesALowerLayerContradictionEvenWhenAnUpperLayerOverridesIt(): void
    {
        self::assertRefusal(
            [
                ['source' => 'config', 'values' => [
                    'rules' => ['complexity.alpha' => ['enabled' => true]],
                    'disabled_rules' => ['complexity.alpha'],
                ]],
                ['source' => 'preset', 'values' => ['rules' => ['complexity.alpha' => ['enabled' => true]]]],
            ],
            self::metadata(),
            'Layer configuration file "/project/qmx.yaml" both enables and disables "complexity.alpha": rules.complexity.alpha.enabled: true; disabled_rules[0]: complexity.alpha.',
            [ConfigurationSource::ConfigFile],
        );
    }

    #[Test]
    public function itRefusesAnOnlyFilterWithNoLiveSelectableCell(): void
    {
        self::assertRefusal(
            [
                ['source' => 'config', 'values' => [
                    'rules' => ['complexity.alpha' => ['enabled' => false]],
                    'only_rules' => ['complexity.alpha'],
                ]],
            ],
            self::metadata(),
            'Rule selection is empty: "complexity.alpha": rules.complexity.alpha.enabled: false (configuration file "/project/qmx.yaml"); only_rules / --only-rule narrows and does not enable.',
            [ConfigurationSource::ConfigFile],
        );
    }

    #[Test]
    public function itNamesBothEqualRankDisableWritersBehindAnEmptyFilter(): void
    {
        $metadata = [new RuleMetadata('complexity.alpha.beta', CodeSmellOptions::class, 'Beta', [], false)];
        self::assertRefusal(
            [
                ['source' => 'config', 'values' => [
                    'disabled_rules' => ['complexity.*', 'complexity.alpha.*'],
                    'only_rules' => ['complexity.alpha.beta'],
                ]],
            ],
            $metadata,
            'Rule selection is empty: "complexity.alpha.beta": disabled_rules[0]: complexity.* (configuration file "/project/qmx.yaml"), disabled_rules[1]: complexity.alpha.* (configuration file "/project/qmx.yaml"); only_rules / --only-rule narrows and does not enable.',
            [ConfigurationSource::ConfigFile],
        );
    }

    #[Test]
    public function itNamesTheDefaultMutedModeWhenItMakesTheOnlyFilterEmpty(): void
    {
        $metadata = [new RuleMetadata('architecture.unassigned-class', UnassignedClassOptions::class, 'Unassigned', [], false)];
        self::assertRefusal(
            [
                ['source' => 'config', 'values' => ['only_rules' => ['architecture.unassigned-class']]],
            ],
            $metadata,
            'Rule selection is empty: "architecture.unassigned-class": mode of "architecture.unassigned-class" is ignore by default; only_rules / --only-rule narrows and does not enable.',
            [ConfigurationSource::ConfigFile],
        );
    }

    #[Test]
    public function itRefusesADeadFilterSelectorBesideALiveSelector(): void
    {
        self::assertRefusal(
            [
                ['source' => 'config', 'values' => ['disabled_rules' => ['complexity.alpha']]],
                ['source' => 'preset', 'values' => ['only_rules' => ['complexity.alpha', 'complexity.beta']]],
            ],
            self::metadata(),
            'Filter selector "complexity.alpha" selects nothing that can report: disabled_rules[0]: complexity.alpha (configuration file "/project/qmx.yaml").',
            [ConfigurationSource::Preset],
        );
    }

    #[Test]
    public function itRefusesAnExplicitEnableExcludedByItsLayerFilter(): void
    {
        self::assertRefusal(
            [
                ['source' => 'config', 'values' => [
                    'rules' => ['complexity.beta' => ['enabled' => true]],
                    'only_rules' => ['complexity.alpha'],
                ]],
            ],
            self::metadata(),
            '"complexity.beta" is enabled by rules.complexity.beta.enabled: true (configuration file "/project/qmx.yaml") but excluded by the rule filter of configuration file "/project/qmx.yaml".',
            [ConfigurationSource::ConfigFile],
        );
    }

    #[Test]
    public function itNamesTheUpperExplicitEnableAndLowerFilterWriters(): void
    {
        self::assertRefusal(
            [
                ['source' => 'config', 'values' => ['only_rules' => ['complexity.alpha']]],
                ['source' => 'preset', 'values' => ['rules' => ['complexity.beta' => ['enabled' => true]]]],
            ],
            self::metadata(),
            '"complexity.beta" is enabled by rules.complexity.beta.enabled: true (preset "fixture") but excluded by the rule filter of configuration file "/project/qmx.yaml".',
            [ConfigurationSource::ConfigFile, ConfigurationSource::Preset],
        );
    }

    #[Test]
    public function itRefusesExplicitEnableOfAnAuthoredIgnoreModeWithBothWriters(): void
    {
        $metadata = [new RuleMetadata('architecture.unassigned-class', UnassignedClassOptions::class, 'Unassigned', [], false)];
        self::assertRefusal(
            [
                ['source' => 'config', 'values' => ['rules' => ['architecture.unassigned-class' => ['mode' => 'ignore']]]],
                ['source' => 'preset', 'values' => ['rules' => ['architecture.unassigned-class' => ['enabled' => true]]]],
            ],
            $metadata,
            '"architecture.unassigned-class" is enabled by rules.architecture.unassigned-class.enabled: true (preset "fixture") but its mode is ignore: rules.architecture.unassigned-class.mode: ignore (configuration file "/project/qmx.yaml").',
            [ConfigurationSource::ConfigFile, ConfigurationSource::Preset],
        );
    }

    /**
     * @param list<array{source: string, values: array<string, mixed>}> $sources
     * @param list<RuleMetadata> $metadata
     * @param list<ConfigurationSource> $expectedSources
     */
    private static function assertRefusal(array $sources, array $metadata, string $message, array $expectedSources): void
    {
        $document = ResolvedOptionsFixture::document($sources, AbsolutePath::fromString('/project'), $metadata);
        try {
            ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
            self::fail('The contradictory authored configuration must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame($message, $refusal->getMessage());
            self::assertSame($expectedSources, array_map(static fn($source): ConfigurationSource => $source->source(), $refusal->sources()));
        }
    }

    /** @return list<RuleMetadata> */
    private static function metadata(): array
    {
        return [
            new RuleMetadata('complexity.alpha', CodeSmellOptions::class, 'Alpha', [], false),
            new RuleMetadata('complexity.beta', CodeSmellOptions::class, 'Beta', [], false),
        ];
    }
}
