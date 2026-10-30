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
            'Configuration file "qmx.dist.yaml" is not used: "qmx.yaml" takes precedence and does not import it. Add "qmx.dist.yaml" to its "imports" to build on it.',
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

    /** The imported file is told apart by identity, so another spelling or a link to it counts. */
    #[Test]
    public function itKeepsTheDistFileImportedUnderAnotherSpellingSilent(): void
    {
        file_put_contents($this->directory . '/qmx.dist.yaml', "fail_on: error\n");
        mkdir($this->directory . '/sub');
        symlink($this->directory . '/qmx.dist.yaml', $this->directory . '/link.yaml');
        foreach (['sub/../qmx.dist.yaml', 'link.yaml'] as $entry) {
            file_put_contents($this->directory . '/qmx.yml', "imports: [" . $entry . "]\n");

            $layer = (new ConfigFileStage(new YamlConfigLoader()))
                ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

            self::assertNotNull($layer);
            self::assertSame([], $layer->diagnostics, $entry);
        }
        rmdir($this->directory . '/sub');
    }

    #[Test]
    public function itNamesTheLocalFileThatPassesTheDistFileOver(): void
    {
        file_put_contents($this->directory . '/qmx.dist.yml', "fail_on: error\n");
        file_put_contents($this->directory . '/qmx.yml', "fail_on: warning\n");

        $layer = (new ConfigFileStage(new YamlConfigLoader()))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertSame(
            ['Configuration file "qmx.dist.yml" is not used: "qmx.yml" takes precedence and does not import it. Add "qmx.dist.yml" to its "imports" to build on it.'],
            array_map(static fn($diagnostic): string => $diagnostic->message, $layer->diagnostics),
        );
    }

    /** A run without the local file would refuse a dist file that is not a readable regular file. */
    #[Test]
    public function itReportsADistFileThatARunWithoutTheLocalFileRefuses(): void
    {
        file_put_contents($this->directory . '/qmx.yaml', "fail_on: warning\n");
        foreach (['directory', 'dangling link'] as $form) {
            $form === 'directory'
                ? mkdir($this->directory . '/qmx.dist.yaml')
                : symlink($this->directory . '/absent.yaml', $this->directory . '/qmx.dist.yaml');

            $layer = (new ConfigFileStage(new YamlConfigLoader()))
                ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

            self::assertNotNull($layer);
            self::assertSame(
                ['Configuration file "qmx.dist.yaml" is not a regular file or cannot be accessed, so a run without "qmx.yaml" refuses it.'],
                array_map(static fn($diagnostic): string => $diagnostic->message, $layer->diagnostics),
                $form,
            );
            $form === 'directory' ? rmdir($this->directory . '/qmx.dist.yaml') : unlink($this->directory . '/qmx.dist.yaml');
        }
    }

    /** A symlinked root resolves its imports beside its target, so the advice names the dist file by its full path. */
    #[Test]
    public function itAdvisesAnImportThatResolvesFromASymlinkedRoot(): void
    {
        mkdir($this->directory . '/shared');
        file_put_contents($this->directory . '/qmx.dist.yaml', "fail_on: error\n");
        file_put_contents($this->directory . '/shared/qmx.yaml', "fail_on: warning\n");
        symlink($this->directory . '/shared/qmx.yaml', $this->directory . '/qmx.yaml');
        try {
            $layer = (new ConfigFileStage(new YamlConfigLoader()))
                ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));
            self::assertNotNull($layer);
            $entry = $this->directory . '/qmx.dist.yaml';
            self::assertSame(
                ['Configuration file "qmx.dist.yaml" is not used: "qmx.yaml" takes precedence and does not import it. Add "' . $entry . '" to its "imports" to build on it.'],
                array_map(static fn($diagnostic): string => $diagnostic->message, $layer->diagnostics),
            );

            file_put_contents($this->directory . '/shared/qmx.yaml', "imports: ['" . $entry . "']\n");
            $followed = (new ConfigFileStage(new YamlConfigLoader()))
                ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));
            self::assertNotNull($followed);
            self::assertSame([], $followed->diagnostics);
        } finally {
            @unlink($this->directory . '/shared/qmx.yaml');
            @rmdir($this->directory . '/shared');
        }
    }

    #[Test]
    public function itLeavesTheDistFileToAnExplicitLocalConfigPath(): void
    {
        file_put_contents($this->directory . '/qmx.dist.yaml', "fail_on: error\n");
        file_put_contents($this->directory . '/qmx.yaml', "fail_on: warning\n");

        $layer = (new ConfigFileStage(new YamlConfigLoader()))->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory), $this->directory . '/qmx.yaml'),
        );

        self::assertNotNull($layer);
        self::assertSame([], $layer->diagnostics);
    }
}
