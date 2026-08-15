<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\Configuration\OutputFormatResolver;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ConfigurationPipeline::class)]
final class FullPipelineIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-full-pipeline-' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/composer.json');
        @unlink($this->directory . '/qmx.yaml');
        @rmdir($this->directory);
    }

    #[Test]
    public function itLetsEachOwnerFoldTheSameOrderedDocumentWithItsOwnSemantics(): void
    {
        file_put_contents($this->directory . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($this->directory . '/qmx.yaml', Yaml::dump([
            'paths' => ['lib'],
            'format' => 'text',
            'disabledRules' => ['complexity.npath'],
            'rules' => ['complexity.cyclomatic' => ['callable' => ['warning' => 12]]],
        ], 6));

        $document = $this->resolve(['paths' => ['app'], 'format' => 'json'], ['strict']);

        $run = (new RunConfigurationResolver())->resolve($document);
        $finding = (new FindingConfigurationResolver())->resolve($document, new FindingCliOverrides());

        self::assertSame([$this->directory . '/app'], array_map(
            static fn($path): string => $path->value(),
            $run->paths,
        ));
        self::assertSame('json', (new OutputFormatResolver())->resolve($document)->value);
        self::assertContains('complexity.npath', $finding->selection->disabled);
        self::assertSame(12, $finding->ruleOptions->rules['complexity.cyclomatic']['callable']['warning']);
        self::assertSame(
            ['defaults', 'composer.json', 'preset:strict', 'qmx.yaml', 'cli'],
            $document->appliedSources(),
        );
    }

    /**
     * @param array<string, mixed> $cliValues
     * @param list<string> $presets
     */
    private function resolve(array $cliValues, array $presets): ConfigurationDocument
    {
        $loader = new YamlConfigLoader();
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new ComposerDiscoveryStage(new ComposerReader()));
        $pipeline->addStage(new PresetStage($loader, new PresetResolver()));
        $pipeline->addStage(new ConfigFileStage($loader));
        $pipeline->addStage(new CliStage());

        return $pipeline->resolve(
            new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory), null, $presets, $cliValues),
        );
    }
}
