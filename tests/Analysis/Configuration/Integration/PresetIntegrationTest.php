<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

final class PresetIntegrationTest extends TestCase
{
    #[Test]
    public function itRetainsBuiltInPresetDocumentsInRequestedOrder(): void
    {
        $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $pipeline->addStage(new PresetStage(new YamlConfigLoader(), new PresetResolver()));

        $document = $pipeline->resolve(
            new ConfigurationResolutionRequest(AbsolutePath::fromString(sys_get_temp_dir()), null, ['strict', 'ci']),
        );

        self::assertSame(['preset:strict,ci'], $document->appliedSources());
        self::assertCount(1, $document->ruleContributions());
        self::assertSame('error', $document->resolved()->get('fail_on')?->plain());
    }

    #[Test]
    public function itDeduplicatesPresetNamesBeforeLoading(): void
    {
        $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $pipeline->addStage(new PresetStage(new YamlConfigLoader(), new PresetResolver()));

        $document = $pipeline->resolve(
            new ConfigurationResolutionRequest(AbsolutePath::fromString(sys_get_temp_dir()), null, ['legacy,legacy']),
        );

        self::assertSame(['preset:legacy'], $document->appliedSources());
        self::assertCount(1, $document->disabledRuleContributions());
    }
}
