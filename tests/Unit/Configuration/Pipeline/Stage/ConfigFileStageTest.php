<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Configuration\Pipeline\Stage;

use AiMessDetector\Configuration\Loader\ConfigLoaderInterface;
use AiMessDetector\Configuration\Pipeline\ConfigurationContext;
use AiMessDetector\Configuration\Pipeline\Stage\ConfigFileStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

#[CoversClass(ConfigFileStage::class)]
final class ConfigFileStageTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/config_file_stage_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function hasPriorityTwenty(): void
    {
        $stage = new ConfigFileStage($this->createMock(ConfigLoaderInterface::class));

        self::assertSame(20, $stage->priority());
    }

    #[Test]
    public function hasNameConfigFile(): void
    {
        $stage = new ConfigFileStage($this->createMock(ConfigLoaderInterface::class));

        self::assertSame('config_file', $stage->name());
    }

    #[Test]
    public function returnsNullWhenNoConfigFileExists(): void
    {
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::never())->method('load');

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNull($layer);
    }

    #[Test]
    public function loadsAimdYamlWhenExists(): void
    {
        touch($this->tempDir . '/aimd.yaml');

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::once())
            ->method('load')
            ->with($this->tempDir . '/aimd.yaml')
            ->willReturn(['paths' => ['src']]);

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('aimd.yaml', $layer->source);
        self::assertSame(['src'], $layer->values['paths']);
    }

    #[Test]
    public function normalizesNestedConfigToDotNotation(): void
    {
        touch($this->tempDir . '/aimd.yaml');

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->method('load')->willReturn([
            'cache' => [
                'dir' => '/custom/cache',
                'enabled' => false,
            ],
            'namespace' => [
                'strategy' => 'psr4',
            ],
        ]);

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('/custom/cache', $layer->values['cache.dir']);
        self::assertFalse($layer->values['cache.enabled']);
        self::assertSame('psr4', $layer->values['namespace.strategy']);
    }

    #[Test]
    public function normalizesAllSupportedFields(): void
    {
        touch($this->tempDir . '/aimd.yaml');

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->method('load')->willReturn([
            'paths' => ['src', 'lib'],
            'exclude' => ['vendor', 'tests'],
            'cache' => [
                'dir' => '.cache',
                'enabled' => true,
            ],
            'format' => 'json',
            'namespace' => [
                'strategy' => 'tokenizer',
                'composerJson' => 'custom-composer.json',
            ],
            'aggregation' => [
                'prefixes' => ['App\\', 'Domain\\'],
                'autoDepth' => 2,
            ],
            'rules' => [
                'complexity' => ['threshold' => 10],
            ],
            'disabledRules' => ['size'],
            'onlyRules' => ['complexity', 'maintainability'],
        ]);

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame(['src', 'lib'], $layer->values['paths']);
        self::assertSame(['vendor', 'tests'], $layer->values['excludes']);
        self::assertSame('.cache', $layer->values['cache.dir']);
        self::assertTrue($layer->values['cache.enabled']);
        self::assertSame('json', $layer->values['format']);
        self::assertSame('tokenizer', $layer->values['namespace.strategy']);
        self::assertSame('custom-composer.json', $layer->values['namespace.composer_json']);
        self::assertSame(['App\\', 'Domain\\'], $layer->values['aggregation.prefixes']);
        self::assertSame(2, $layer->values['aggregation.auto_depth']);
        self::assertSame(['complexity' => ['threshold' => 10]], $layer->values['rules']);
        self::assertSame(['size'], $layer->values['disabled_rules']);
        self::assertSame(['complexity', 'maintainability'], $layer->values['only_rules']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
