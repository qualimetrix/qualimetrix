<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(ConfigurationPipeline::class)]
final class ConfigurationPipelineIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-pipeline-' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/composer.json');
        @unlink($this->directory . '/qmx.yaml');
        @rmdir($this->directory);
    }

    #[Test]
    public function itRetainsComposerConfigAndCliSourcesInPrecedenceOrder(): void
    {
        file_put_contents($this->directory . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($this->directory . '/qmx.yaml', "paths: [lib]\nexclude: [build]\nformat: text\n");

        $document = $this->pipeline()->resolve(new ConfigurationResolutionRequest(
            AbsolutePath::fromString($this->directory),
            null,
            [],
            ['paths' => ['app'], 'format' => 'json'],
        ));

        self::assertSame(['defaults', 'composer.json', 'qmx.yaml', 'cli'], $document->appliedSources());
        self::assertSame([['src'], ['lib'], ['app']], $document->contributions('paths'));
        self::assertSame(['text', 'json'], $document->contributions('format'));
        self::assertSame([['build']], $document->contributions('excludes'));
    }

    #[Test]
    public function itProvidesOnlyProvenanceForZeroConfiguration(): void
    {
        $document = $this->pipeline()->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertSame(['defaults'], $document->appliedSources());
        self::assertSame([], $document->contributions('paths'));
    }

    private function pipeline(): ConfigurationPipeline
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new CliStage());
        $pipeline->addStage(new ConfigFileStage(new YamlConfigLoader()));
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new ComposerDiscoveryStage(new ComposerReader()));

        return $pipeline;
    }
}
