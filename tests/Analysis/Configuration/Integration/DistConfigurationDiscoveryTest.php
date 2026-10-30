<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Core\Path\AbsolutePath;

/** A dist file passed over by a local file is told apart by the identity of the files read. */
#[CoversClass(ConfigFileStage::class)]
final class DistConfigurationDiscoveryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-dist-discovery-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $entries = scandir($this->directory);
        foreach ($entries === false ? [] : $entries as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($this->directory . '/' . $file);
            }
        }
        @rmdir($this->directory);
    }

    #[Test]
    public function itIgnoresTheDistNameCollisionBehindALocalFile(): void
    {
        touch($this->directory . '/qmx.dist.yaml');
        touch($this->directory . '/qmx.dist.yml');
        file_put_contents($this->directory . '/qmx.yaml', "imports: [qmx.dist.yaml, qmx.dist.yml]\n");

        $layer = (new ConfigFileStage(new YamlConfigLoader()))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertSame('qmx.yaml', $layer->source);
        self::assertSame([], $layer->diagnostics);
    }

    #[Test]
    public function itKeepsTheImportedDistFileSilent(): void
    {
        file_put_contents($this->directory . '/qmx.dist.yaml', "fail_on: error\n");
        file_put_contents($this->directory . '/qmx.yaml', "imports: [./qmx.dist.yaml]\n");

        $layer = (new ConfigFileStage(new YamlConfigLoader()))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertSame(['qmx.dist.yaml', 'qmx.yaml'], array_map(static fn($authored): ?string => $authored->origin->locator(), $layer->authored));
        self::assertSame([], $layer->diagnostics);
    }

    #[Test]
    public function itWarnsAboutADistFileTheLocalFileDoesNotImport(): void
    {
        file_put_contents($this->directory . '/qmx.dist.yaml', "fail_on: error\n");
        file_put_contents($this->directory . '/qmx.yaml', "fail_on: warning\n");

        $layer = (new ConfigFileStage(new YamlConfigLoader()))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertCount(1, $layer->authored);
        self::assertCount(1, $layer->diagnostics);
        self::assertSame(
            'Configuration file "qmx.dist.yaml" is not used: "qmx.yaml" takes precedence and does not import it. Add "qmx.dist.yaml" to the "imports" of "qmx.yaml" to build on it.',
            $layer->diagnostics[0]->message,
        );
        self::assertSame('qmx.dist.yaml', $layer->diagnostics[0]->sources[0]->origin->locator());
    }

    #[Test]
    public function itLeavesTheDistFileToAnExplicitConfigPath(): void
    {
        file_put_contents($this->directory . '/qmx.dist.yaml', "fail_on: error\n");
        file_put_contents($this->directory . '/custom.yaml', "fail_on: warning\n");

        $layer = (new ConfigFileStage(new YamlConfigLoader()))->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory), $this->directory . '/custom.yaml'),
        );

        self::assertNotNull($layer);
        self::assertSame([], $layer->diagnostics);
    }

    #[Test]
    public function itDoesNotWarnAboutADistFileNamedAsAPresetBehindALocalFile(): void
    {
        file_put_contents($this->directory . '/qmx.dist.yaml', "fail_on: error\n");
        file_put_contents($this->directory . '/qmx.yaml', "fail_on: warning\n");

        $layer = (new ConfigFileStage(new YamlConfigLoader()))->apply(new ConfigurationResolutionRequest(
            AbsolutePath::fromString($this->directory),
            presetNames: ['./qmx.dist.yaml'],
        ));

        self::assertNotNull($layer);
        self::assertSame([], $layer->diagnostics);
    }
}
