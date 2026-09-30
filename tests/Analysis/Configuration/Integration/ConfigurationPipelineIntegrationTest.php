<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

#[CoversClass(ConfigurationPipeline::class)]
final class ConfigurationPipelineIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-pipeline-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/composer.json');
        @unlink($this->directory . '/qmx.yaml');
        @rmdir($this->directory);
    }

    #[Test]
    public function itRetainsSourcesAndExposesTheirResolvedAndDiscoveryOutputs(): void
    {
        file_put_contents($this->directory . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($this->directory . '/qmx.yaml', "paths: [lib]\nexclude: [{subtree: build}]\nfail_on: warning\n");

        $document = $this->pipeline()->resolve(new ConfigurationResolutionRequest(
            AbsolutePath::fromString($this->directory),
            null,
            [],
            ['paths' => ['app'], 'fail_on' => 'error'],
        ));

        self::assertSame(['defaults', 'composer.json', 'qmx.yaml', 'cli'], $document->appliedSources());
        self::assertSame(['app'], $document->resolved()->get('paths')?->plain());
        self::assertSame('error', $document->resolved()->get('fail_on')?->plain());
        self::assertSame([['subtree' => 'build']], $document->resolved()->get('exclude')?->plain());
        self::assertSame(['src'], $document->discoveredProductionAutoloadTargets());
        $this->expectException(LogicException::class);
        $document->resolved()->get('discovered_autoload_paths');
    }

    #[Test]
    public function itProvidesOnlyProvenanceForZeroConfiguration(): void
    {
        $document = $this->pipeline()->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertSame(['defaults'], $document->appliedSources());
        self::assertNull($document->resolved()->get('paths'));
        self::assertSame([], $document->discoveredProductionAutoloadTargets());
        self::assertSame([], $document->discoveredDevelopmentAutoloadTargets());
    }

    private function pipeline(): ConfigurationPipeline
    {
        $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $pipeline->addStage(new CliStage());
        $pipeline->addStage(new ConfigFileStage(new YamlConfigLoader()));
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new ComposerDiscoveryStage(new ComposerManifestReader()));

        return $pipeline;
    }
}
