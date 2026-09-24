<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * A directory the author named as a path and removed with their own `exclude:`
 * used to be skipped by discovery without a word: `check legacy` reported
 * success over zero files. Discovery cannot tell a written root from a composer
 * default, so the refusal is made where the provenance is still known.
 */
#[CoversClass(RunConfigurationResolver::class)]
final class WrittenRootRemovedByExcludeRefusalTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-written-root-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0o777, true);
        mkdir($this->root . '/legacy/old', 0o777, true);
        mkdir($this->root . '/lib/vendor', 0o777, true);
        file_put_contents($this->root . '/legacy/L.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        unlink($this->root . '/legacy/L.php');
        foreach (['lib/vendor', 'lib', 'legacy/old', 'legacy', 'src', ''] as $directory) {
            rmdir($this->root . '/' . $directory);
        }
    }

    #[Test]
    public function itRefusesAWrittenRootTheAuthorsExcludeRemoves(): void
    {
        $refusal = $this->refusalFor(['legacy'], ['subtree' => 'legacy']);

        self::assertStringContainsString('"legacy"', $refusal->getMessage());
        self::assertStringContainsString('subtree:legacy', $refusal->getMessage());
    }

    #[Test]
    public function itRefusesTheExcludedRootAmongLawfulOnesAndNamesOnlyIt(): void
    {
        $refusal = $this->refusalFor(['src', 'legacy'], ['subtree' => 'legacy']);

        self::assertStringContainsString('"legacy"', $refusal->getMessage());
        self::assertStringNotContainsString('"src"', $refusal->getMessage());
    }

    #[Test]
    public function itRefusesAWrittenRootInsideASubtreeTheAuthorExcluded(): void
    {
        $refusal = $this->refusalFor(['legacy/old'], ['subtree' => 'legacy']);

        self::assertStringContainsString('"legacy/old"', $refusal->getMessage());
    }

    #[Test]
    public function itRefusesARootWrittenInTheDocumentToo(): void
    {
        $this->expectException(ConfigurationRefusal::class);

        $this->resolve([
            ['source' => 'qmx.yaml', 'values' => [ConfigSchema::PATHS => ['legacy'], ConfigSchema::EXCLUDES => [['regex' => 'leg.*']]]],
        ]);
    }

    #[Test]
    public function itKeepsAComposerDefaultTheAuthorExcludedSilently(): void
    {
        $configuration = $this->resolve([
            ['source' => 'composer.json', 'values' => [ConfigSchema::DISCOVERED_AUTOLOAD_PATHS => ['src', 'legacy']]],
            ['source' => 'qmx.yaml', 'values' => [ConfigSchema::EXCLUDES => [['subtree' => 'legacy']]]],
        ]);

        self::assertSame(
            [$this->root . '/src', $this->root . '/legacy'],
            array_map(static fn(AbsolutePath $path): string => $path->value(), $configuration->paths),
        );
    }

    #[Test]
    public function itWalksAWrittenRootBelowADirectoryExcludedOnlyExactly(): void
    {
        $configuration = $this->resolveWritten(['legacy/old'], ['exact' => 'legacy']);

        self::assertSame([$this->root . '/legacy/old'], array_map(static fn(AbsolutePath $path): string => $path->value(), $configuration->paths));
    }

    #[Test]
    public function itAcceptsAWrittenFileInsideAnExcludedDirectory(): void
    {
        $configuration = $this->resolveWritten(['legacy/L.php'], ['subtree' => 'legacy']);

        self::assertSame([$this->root . '/legacy/L.php'], array_map(static fn(AbsolutePath $path): string => $path->value(), $configuration->paths));
    }

    #[Test]
    public function itLeavesABuiltInExclusionToDiscovery(): void
    {
        $configuration = $this->resolveWritten(['lib/vendor'], ['subtree' => 'build']);

        self::assertSame([$this->root . '/lib/vendor'], array_map(static fn(AbsolutePath $path): string => $path->value(), $configuration->paths));
    }

    /**
     * @param list<string> $paths
     * @param array<string, string> $exclude
     */
    private function refusalFor(array $paths, array $exclude): ConfigurationRefusal
    {
        try {
            $this->resolveWritten($paths, $exclude);
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        }

        self::fail('A written root the author excluded was accepted.');
    }

    /**
     * @param list<string> $paths
     * @param array<string, string> $exclude
     */
    private function resolveWritten(array $paths, array $exclude): RunConfiguration
    {
        return $this->resolve([
            ['source' => 'qmx.yaml', 'values' => [ConfigSchema::EXCLUDES => [$exclude]]],
            ['source' => 'cli', 'values' => [ConfigSchema::PATHS => $paths]],
        ]);
    }

    /** @param list<array{source: string, values: array<string, mixed>}> $sources */
    private function resolve(array $sources): RunConfiguration
    {
        return (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerReader())))
            ->resolve(new ConfigurationDocument($sources, AbsolutePath::fromString($this->root)));
    }
}
