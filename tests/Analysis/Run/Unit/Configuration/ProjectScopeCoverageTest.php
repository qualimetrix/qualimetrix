<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

/**
 * The verdict a scope-conditioned channel reads before it speaks.
 *
 * The message the console builds from the same answer is proved by
 * {@see \Qualimetrix\Tests\Unit\Infrastructure\Console\ScopeWarningCheckerTest}.
 */
#[CoversClass(ProjectScopeCoverage::class)]
final class ProjectScopeCoverageTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_project_scope_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/src', 0o755, true);
        mkdir($this->tempDir . '/lib', 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (['/src', '/lib'] as $directory) {
            @rmdir($this->tempDir . $directory);
        }
        @unlink($this->tempDir . '/composer.json');
        @rmdir($this->tempDir);
    }

    #[Test]
    public function itCoversTheProjectWhenEveryProductionAutoloadRootIsAnalysed(): void
    {
        $this->writeComposerJson(['src/', 'lib/']);

        self::assertTrue($this->covers($this->configuration(['src', 'lib'])));
    }

    /**
     * The case the precondition exists for: the caller checked one directory
     * of a larger project, so a configured value that binds outside it binds
     * nowhere in this run — through no fault of the configuration.
     */
    #[Test]
    public function itDoesNotCoverTheProjectWhenAnAutoloadRootIsOutsideTheRun(): void
    {
        $this->writeComposerJson(['src/', 'lib/']);

        $configuration = $this->configuration(['src']);

        self::assertFalse($this->covers($configuration));
        self::assertSame(
            ['lib'],
            $this->coverage()->uncoveredAutoloadRoots($configuration->projectRoot, $configuration->paths),
        );
    }

    /**
     * A project without a composer manifest has no denominator. Answering
     * "narrowed" there would silence every scope-conditioned channel for the
     * lifetime of such a project, which is the silence the channels exist to
     * remove.
     */
    #[Test]
    public function itCoversTheProjectWhenThereIsNoComposerManifest(): void
    {
        self::assertTrue($this->covers($this->configuration(['src'])));
    }

    /**
     * A manifest this product cannot read is the third answer, and it is not
     * "covers". A project autoloading its production code through `classmap`,
     * `psr-0` or `files` gives the measurement no denominator at all — and
     * answering "covers" there let every channel of the row accuse the author
     * on a run nobody could judge. No uncovered root is named either: there is
     * none to name, which is why the warning list and the verdict are taken
     * from one measurement rather than from each other.
     */
    #[Test]
    public function itDoesNotCoverTheProjectWhenTheManifestDeclaresNoReadableProductionAutoload(): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode(['autoload' => ['classmap' => ['src/', 'lib/']]], \JSON_THROW_ON_ERROR),
        );

        $configuration = $this->configuration(['src', 'lib']);

        self::assertFalse($this->covers($configuration));
        self::assertSame(
            [],
            $this->coverage()->uncoveredAutoloadRoots($configuration->projectRoot, $configuration->paths),
        );
    }

    /** @param list<string> $autoloadRoots */
    private function writeComposerJson(array $autoloadRoots): void
    {
        $map = [];
        foreach ($autoloadRoots as $index => $root) {
            $map['Fixture\\N' . $index . '\\'] = $root;
        }

        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode(['autoload' => ['psr-4' => $map]], \JSON_THROW_ON_ERROR),
        );
    }

    private function covers(RunConfiguration $configuration): bool
    {
        return $this->coverage()->pathsCoverProjectScope($configuration->projectRoot, $configuration->paths);
    }

    private function coverage(): ProjectScopeCoverage
    {
        return new ProjectScopeCoverage(new ComposerReader());
    }

    /** @param list<string> $paths */
    private function configuration(array $paths): RunConfiguration
    {
        $root = AbsolutePath::fromString($this->tempDir);

        return new RunConfiguration(
            paths: array_map(
                static fn(string $path): AbsolutePath => $root->joinRelative(RelativePath::fromString($path)),
                $paths,
            ),
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            coversProjectScope: true,
            authoredPathExcludes: [],
        );
    }
}
