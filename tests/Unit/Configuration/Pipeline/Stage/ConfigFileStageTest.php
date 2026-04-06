<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\Stage\ConfigFileStage;
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
        $stage = new ConfigFileStage(self::createStub(ConfigLoaderInterface::class));

        self::assertSame(20, $stage->priority());
    }

    #[Test]
    public function hasNameConfigFile(): void
    {
        $stage = new ConfigFileStage(self::createStub(ConfigLoaderInterface::class));

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
        touch($this->tempDir . '/qmx.yaml');

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::once())
            ->method('load')
            ->with($this->tempDir . '/qmx.yaml')
            ->willReturn(['paths' => ['src']]);

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('qmx.yaml', $layer->source);
        self::assertSame(['src'], $layer->values['paths']);
    }

    #[Test]
    public function normalizesNestedConfigToDotNotation(): void
    {
        touch($this->tempDir . '/qmx.yaml');

        $loader = self::createStub(ConfigLoaderInterface::class);
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
        touch($this->tempDir . '/qmx.yaml');

        $loader = self::createStub(ConfigLoaderInterface::class);
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
            'excludePaths' => ['src/Entity/*', 'src/DTO/*'],
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
        self::assertSame(['src/Entity/*', 'src/DTO/*'], $layer->values['exclude_paths']);
    }

    #[Test]
    public function normalizesExcludePathsFromConfig(): void
    {
        touch($this->tempDir . '/qmx.yaml');

        $loader = self::createStub(ConfigLoaderInterface::class);
        $loader->method('load')->willReturn([
            'excludePaths' => ['vendor/', 'tests/'],
        ]);

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame(['vendor/', 'tests/'], $layer->values['exclude_paths']);
    }

    #[Test]
    public function loadsAimdYmlWhenYamlDoesNotExist(): void
    {
        touch($this->tempDir . '/qmx.yml');

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::once())
            ->method('load')
            ->with($this->tempDir . '/qmx.yml')
            ->willReturn(['paths' => ['lib']]);

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('qmx.yml', $layer->source);
        self::assertSame(['lib'], $layer->values['paths']);
    }

    #[Test]
    public function prefersYamlOverYml(): void
    {
        // Both files exist — qmx.yaml should be preferred
        touch($this->tempDir . '/qmx.yaml');
        touch($this->tempDir . '/qmx.yml');

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::once())
            ->method('load')
            ->with($this->tempDir . '/qmx.yaml')
            ->willReturn(['paths' => ['src']]);

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('qmx.yaml', $layer->source);
    }

    #[Test]
    public function loadsFromExplicitConfigPath(): void
    {
        $configFile = $this->tempDir . '/custom-config.yaml';
        touch($configFile);

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::once())
            ->method('load')
            ->with($configFile)
            ->willReturn(['paths' => ['lib']]);

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir, $configFile);

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('custom-config.yaml', $layer->source);
        self::assertSame(['lib'], $layer->values['paths']);
    }

    #[Test]
    public function explicitConfigPathOverridesAutoDetection(): void
    {
        // Create both auto-detected and explicit config files
        touch($this->tempDir . '/qmx.yaml');
        $customConfig = $this->tempDir . '/custom.yaml';
        touch($customConfig);

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::once())
            ->method('load')
            ->with($customConfig)
            ->willReturn(['format' => 'json']);

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir, $customConfig);

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('custom.yaml', $layer->source);
    }

    #[Test]
    public function throwsWhenExplicitConfigPathDoesNotExist(): void
    {
        $missingPath = $this->tempDir . '/nonexistent.yaml';

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::never())->method('load');

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir, $missingPath);

        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessage('Configuration file not found');

        $stage->apply($context);
    }

    #[Test]
    public function autoDetectsWhenNoExplicitConfigPath(): void
    {
        // No qmx.yaml, no explicit path — should return null
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::never())->method('load');

        $stage = new ConfigFileStage($loader);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNull($layer);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff((scandir($dir) !== false ? scandir($dir) : []), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
