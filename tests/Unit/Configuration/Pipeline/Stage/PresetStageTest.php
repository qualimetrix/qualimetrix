<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Configuration\Preset\PresetResolver;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(PresetStage::class)]
final class PresetStageTest extends TestCase
{
    private string $tempDir;
    private PresetResolver $resolver;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/preset_stage_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->resolver = new PresetResolver();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            $this->removeDir($this->tempDir);
        }
    }

    #[Test]
    public function hasPriorityFifteen(): void
    {
        $stage = new PresetStage(
            $this->createStub(ConfigLoaderInterface::class),
            $this->resolver,
        );

        self::assertSame(15, $stage->priority());
    }

    #[Test]
    public function hasNamePreset(): void
    {
        $stage = new PresetStage(
            $this->createStub(ConfigLoaderInterface::class),
            $this->resolver,
        );

        self::assertSame('preset', $stage->name());
    }

    #[Test]
    public function returnsNullWhenNoPresetOption(): void
    {
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::never())->method('load');

        $stage = new PresetStage($loader, $this->resolver);
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        self::assertNull($stage->apply($context));
    }

    #[Test]
    public function returnsNullWhenEmptyPresetArray(): void
    {
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::never())->method('load');

        $stage = new PresetStage($loader, $this->resolver);
        $context = new ConfigurationContext(
            $this->createPresetInput([]),
            $this->tempDir,
        );

        self::assertNull($stage->apply($context));
    }

    #[Test]
    public function loadsSinglePreset(): void
    {
        // Use built-in preset name 'strict' — PresetResolver resolves it internally
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::once())
            ->method('load')
            ->willReturn([
                'failOn' => 'warning',
                'rules' => ['complexity.cyclomatic' => ['method' => ['warning' => 7]]],
            ]);

        $stage = new PresetStage($loader, $this->resolver);
        $context = new ConfigurationContext(
            $this->createPresetInput(['strict']),
            $this->tempDir,
        );

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('preset:strict', $layer->source);
        self::assertSame('warning', $layer->values['fail_on']);
        self::assertSame(
            ['complexity.cyclomatic' => ['method' => ['warning' => 7]]],
            $layer->values['rules'],
        );
    }

    #[Test]
    public function loadsMultiplePresetsViaRepeatedOption(): void
    {
        $loader = $this->createStub(ConfigLoaderInterface::class);
        $callCount = 0;
        $loader->method('load')
            ->willReturnCallback(function () use (&$callCount): array {
                $callCount++;

                return match ($callCount) {
                    1 => ['failOn' => 'warning'],
                    2 => ['failOn' => 'error'],
                    default => [],
                };
            });

        $stage = new PresetStage($loader, $this->resolver);
        $context = new ConfigurationContext(
            $this->createPresetInput(['strict', 'ci']),
            $this->tempDir,
        );

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('preset:strict,ci', $layer->source);
        self::assertSame('error', $layer->values['fail_on']);
    }

    #[Test]
    public function splitsCommaSeparatedValues(): void
    {
        $resolvedPaths = [];
        $loader = $this->createStub(ConfigLoaderInterface::class);
        $loader->method('load')
            ->willReturnCallback(function (string $path) use (&$resolvedPaths): array {
                $resolvedPaths[] = $path;

                return match (\count($resolvedPaths)) {
                    1 => ['failOn' => 'warning'],
                    2 => ['format' => 'json'],
                    default => [],
                };
            });

        $stage = new PresetStage($loader, $this->resolver);
        $context = new ConfigurationContext(
            $this->createPresetInput(['strict,ci']),
            $this->tempDir,
        );

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertCount(2, $resolvedPaths);
        self::assertSame('warning', $layer->values['fail_on']);
        self::assertSame('json', $layer->values['format']);
    }

    #[Test]
    public function deduplicatesPresetNames(): void
    {
        $loadCount = 0;
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::once())
            ->method('load')
            ->willReturnCallback(function () use (&$loadCount): array {
                $loadCount++;

                return ['failOn' => 'warning'];
            });

        $stage = new PresetStage($loader, $this->resolver);
        $context = new ConfigurationContext(
            $this->createPresetInput(['strict,strict']),
            $this->tempDir,
        );

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('preset:strict', $layer->source);
    }

    #[Test]
    public function laterPresetOverridesScalarKeys(): void
    {
        $presetA = $this->createTempPresetFile('a.yaml');
        $presetB = $this->createTempPresetFile('b.yaml');

        $loader = $this->createStub(ConfigLoaderInterface::class);
        $loader->method('load')
            ->willReturnCallback(function (string $path) use ($presetA, $presetB): array {
                return match ($path) {
                    $presetA => ['format' => 'text'],
                    $presetB => ['format' => 'json'],
                    default => [],
                };
            });

        $stage = new PresetStage($loader, $this->resolver);
        $context = new ConfigurationContext(
            $this->createPresetInput([$presetA, $presetB]),
            $this->tempDir,
        );

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('json', $layer->values['format']);
    }

    #[Test]
    public function mergesDisabledRulesWithUnionSemantics(): void
    {
        $presetA = $this->createTempPresetFile('a.yaml');
        $presetB = $this->createTempPresetFile('b.yaml');

        $loader = $this->createStub(ConfigLoaderInterface::class);
        $loader->method('load')
            ->willReturnCallback(function (string $path) use ($presetA, $presetB): array {
                return match ($path) {
                    $presetA => ['disabledRules' => ['ruleA']],
                    $presetB => ['disabledRules' => ['ruleB']],
                    default => [],
                };
            });

        $stage = new PresetStage($loader, $this->resolver);
        $context = new ConfigurationContext(
            $this->createPresetInput([$presetA, $presetB]),
            $this->tempDir,
        );

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame(['ruleA', 'ruleB'], $layer->values['disabled_rules']);
    }

    #[Test]
    public function deepMergesRulesKey(): void
    {
        $presetA = $this->createTempPresetFile('a.yaml');
        $presetB = $this->createTempPresetFile('b.yaml');

        $loader = $this->createStub(ConfigLoaderInterface::class);
        $loader->method('load')
            ->willReturnCallback(function (string $path) use ($presetA, $presetB): array {
                return match ($path) {
                    $presetA => [
                        'rules' => [
                            'complexity.cyclomatic' => ['method' => ['warning' => 7, 'error' => 15]],
                        ],
                    ],
                    $presetB => [
                        'rules' => [
                            'size.method-count' => ['class' => ['warning' => 20]],
                            'complexity.cyclomatic' => ['method' => ['warning' => 10]],
                        ],
                    ],
                    default => [],
                };
            });

        $stage = new PresetStage($loader, $this->resolver);
        $context = new ConfigurationContext(
            $this->createPresetInput([$presetA, $presetB]),
            $this->tempDir,
        );

        $layer = $stage->apply($context);

        self::assertNotNull($layer);

        $rules = $layer->values['rules'];

        // preset2 adds size.method-count
        self::assertSame(['warning' => 20], $rules['size.method-count']['class']);

        // preset2 overrides warning but preserves error from preset1
        self::assertSame(10, $rules['complexity.cyclomatic']['method']['warning']);
        self::assertSame(15, $rules['complexity.cyclomatic']['method']['error']);
    }

    #[Test]
    public function layerSourceContainsAllPresetNames(): void
    {
        $loader = $this->createStub(ConfigLoaderInterface::class);
        $loader->method('load')->willReturn(['failOn' => 'warning']);

        $stage = new PresetStage($loader, $this->resolver);
        $context = new ConfigurationContext(
            $this->createPresetInput(['strict', 'ci']),
            $this->tempDir,
        );

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('preset:strict,ci', $layer->source);
    }

    #[Test]
    public function filtersEmptyStringsAfterSplit(): void
    {
        $loadCount = 0;
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects(self::exactly(2))
            ->method('load')
            ->willReturnCallback(function () use (&$loadCount): array {
                $loadCount++;

                return match ($loadCount) {
                    1 => ['failOn' => 'warning'],
                    2 => ['format' => 'json'],
                    default => [],
                };
            });

        $stage = new PresetStage($loader, $this->resolver);
        $context = new ConfigurationContext(
            $this->createPresetInput(['strict,,ci']),
            $this->tempDir,
        );

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('preset:strict,ci', $layer->source);
    }

    /**
     * @param list<string> $presets
     */
    private function createPresetInput(array $presets): ArrayInput
    {
        $definition = new InputDefinition([
            new InputOption('preset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
        ]);

        return new ArrayInput(['--preset' => $presets], $definition);
    }

    /**
     * Creates a temporary file that PresetResolver recognizes as a file path (ends with .yaml).
     */
    private function createTempPresetFile(string $name): string
    {
        $path = $this->tempDir . '/' . $name;
        touch($path);
        $this->tempFiles[] = $path;

        return $path;
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
