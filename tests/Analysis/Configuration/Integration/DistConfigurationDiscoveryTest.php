<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{'directory'|'dangling link'|'unreadable'|'invalid YAML'|'missing import', string}> */
    public static function provideDistFilesARunWithoutTheLocalFileRefuses(): iterable
    {
        yield 'directory' => ['directory', 'Configuration file "qmx.dist.yaml" is not a regular file.'];
        yield 'dangling link' => ['dangling link', 'Configuration file "qmx.dist.yaml" was not found or is not accessible.'];
        yield 'unreadable' => ['unreadable', 'Configuration file "qmx.dist.yaml" is not readable.'];
        yield 'invalid YAML' => ['invalid YAML', 'Failed to parse configuration file "qmx.dist.yaml"'];
        yield 'missing import' => ['missing import', 'Configuration file "absent.yaml" (imported by configuration file "qmx.dist.yaml") was not found or is not accessible.'];
    }

    /**
     * The verdict is the one a run without the local file reaches reading the dist file.
     *
     * @param 'directory'|'dangling link'|'unreadable'|'invalid YAML'|'missing import' $form
     */
    #[Test]
    #[DataProvider('provideDistFilesARunWithoutTheLocalFileRefuses')]
    public function itReportsADistFileThatARunWithoutTheLocalFileRefuses(string $form, string $refusal): void
    {
        file_put_contents($this->directory . '/qmx.yaml', "fail_on: warning\n");
        $dist = $this->directory . '/qmx.dist.yaml';
        match ($form) {
            'directory' => mkdir($dist),
            'dangling link' => symlink($this->directory . '/absent.yaml', $dist),
            'unreadable' => file_put_contents($dist, "fail_on: error\n") !== false && chmod($dist, 0o000),
            'invalid YAML' => file_put_contents($dist, "fail_on: [\n"),
            'missing import' => file_put_contents($dist, "imports: [absent.yaml]\n"),
        };
        try {
            if ($form === 'unreadable' && is_readable($dist)) {
                self::markTestSkipped('The file stays readable for this user.');
            }

            $layer = (new ConfigFileStage(new YamlConfigLoader()))
                ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

            self::assertNotNull($layer);
            self::assertCount(1, $layer->diagnostics);
            self::assertStringStartsWith('A run without "qmx.yaml" refuses configuration file "qmx.dist.yaml": ' . $refusal, $layer->diagnostics[0]->message);
        } finally {
            $form === 'directory' ? rmdir($dist) : @chmod($dist, 0o600);
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

    /** A root linked to a file in the same directory still imports the dist file by its name. */
    #[Test]
    public function itAdvisesTheBareNameFromARootLinkedWithinItsDirectory(): void
    {
        file_put_contents($this->directory . '/qmx.dist.yaml', "fail_on: error\n");
        file_put_contents($this->directory . '/mine.yaml', "fail_on: warning\n");
        symlink($this->directory . '/mine.yaml', $this->directory . '/qmx.yaml');

        $layer = (new ConfigFileStage(new YamlConfigLoader()))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertSame(
            ['Configuration file "qmx.dist.yaml" is not used: "qmx.yaml" takes precedence and does not import it. Add "qmx.dist.yaml" to its "imports" to build on it.'],
            array_map(static fn($diagnostic): string => $diagnostic->message, $layer->diagnostics),
        );
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
