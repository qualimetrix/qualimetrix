<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

/**
 * The verdict a scope-conditioned channel reads before it speaks.
 *
 * The message the console builds from the same answer is proved by
 * {@see \Qualimetrix\Tests\Infrastructure\Console\Unit\ScopeWarningCheckerTest}.
 *
 * **Every target these fixtures declare exists on disk.** A declared path that
 * does not resolve is skipped by the measurement, which opens the gate — so a
 * fixture with a phantom target would let a silent case pass for a reason that
 * has nothing to do with the answer under test.
 */
#[CoversClass(ProjectScopeCoverage::class)]
final class ProjectScopeCoverageTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_project_scope_' . bin2hex(random_bytes(6));

        foreach (['src', 'lib', 'legacy', 'stubs'] as $directory) {
            mkdir($this->tempDir . '/' . $directory, 0o755, true);
        }

        file_put_contents($this->tempDir . '/src/helpers.php', "<?php\n");
        file_put_contents($this->tempDir . '/stubs/helpers.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
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
        self::assertSame(['lib'], $this->uncovered($configuration));
    }

    /**
     * A manifest declaring production code through
     * `classmap`, `psr-0` or `files` used to be unjudgeable, which silenced
     * every scope-conditioned channel on 51 of the 125 packages in
     * `benchmarks/vendor` — a cure inert on half of real projects, and
     * invisibly so. Those sections are ordinary path targets now, measured
     * exactly like a PSR-4 root.
     *
     * @param array<string, mixed> $manifest
     * @param list<string> $paths
     */
    #[Test]
    #[DataProvider('provideManifestsWhoseTargetsTheRunCovers')]
    public function itMeasuresEveryProductionSectionAgainstTheRun(array $manifest, array $paths): void
    {
        $this->writeManifest($manifest);

        self::assertTrue($this->covers($this->configuration($paths)));
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function provideManifestsWhoseTargetsTheRunCovers(): iterable
    {
        yield 'a classmap-only project is measured, not refused' => [
            ['autoload' => ['classmap' => ['src/', 'lib/']]],
            ['src', 'lib'],
        ];

        yield 'a psr-0 root is a root like any other' => [
            ['autoload' => ['psr-0' => ['Legacy_' => 'legacy/']]],
            ['legacy'],
        ];

        yield 'a files entry inside an analysed directory is covered' => [
            ['autoload' => ['psr-4' => ['Fixture\\' => 'src/'], 'files' => ['src/helpers.php']]],
            ['src'],
        ];

        yield 'a files entry named by the run itself is covered' => [
            ['autoload' => ['psr-4' => ['Fixture\\' => 'src/'], 'files' => ['stubs/helpers.php']]],
            ['src', 'stubs/helpers.php'],
        ];
    }

    /**
     * The other half of the same pair: the sections are measured, so a target
     * of theirs the run never looked at narrows the run exactly as an
     * unanalysed PSR-4 root does — and it is named in the warning, which the
     * previous answer could not do.
     *
     * @param array<string, mixed> $manifest
     * @param list<string> $expectedUncovered
     */
    #[Test]
    #[DataProvider('provideManifestsWithATargetOutsideTheRun')]
    public function itNamesTheProductionTargetTheRunDidNotAnalyse(array $manifest, array $expectedUncovered): void
    {
        $this->writeManifest($manifest);

        $configuration = $this->configuration(['src']);

        self::assertFalse($this->covers($configuration));
        self::assertSame($expectedUncovered, $this->uncovered($configuration));
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function provideManifestsWithATargetOutsideTheRun(): iterable
    {
        yield 'a files entry outside the analysed paths' => [
            ['autoload' => ['psr-4' => ['Fixture\\' => 'src/'], 'files' => ['stubs/helpers.php']]],
            ['stubs/helpers.php'],
        ];

        yield 'a classmap directory beside an analysed psr-4 root' => [
            ['autoload' => ['psr-4' => ['Fixture\\' => 'src/'], 'classmap' => ['legacy/']]],
            ['legacy'],
        ];

        yield 'a psr-0 root beside an analysed psr-4 root' => [
            ['autoload' => ['psr-4' => ['Fixture\\' => 'src/'], 'psr-0' => ['Legacy_' => 'legacy/']]],
            ['legacy'],
        ];

        yield 'a classmap-only project checked by one of its two entries' => [
            ['autoload' => ['classmap' => ['src/', 'lib/']]],
            ['lib'],
        ];
    }

    /**
     * `Unknown`: the manifest declares no production autoload this product
     * can read *at all*. There is no denominator and no target to name, and the
     * project is what the user named, so a whole-project channel judges the
     * paths. It used to close the gate instead, which silenced
     * `architecture.unreachable-layer` on every such project for good.
     *
     * The state, not the verdict, is what keeps it apart from `Covered`: both
     * cover and both name nothing.
     *
     * @param ?string $manifest raw `composer.json` content, or null for no manifest at all
     */
    #[Test]
    #[DataProvider('provideManifestsThatDeclareNoProductionAutoload')]
    public function itTakesTheAnalysedPathsAsTheProjectWhenTheManifestDeclaresNone(?string $manifest): void
    {
        if ($manifest !== null) {
            file_put_contents($this->tempDir . '/composer.json', $manifest);
        }

        $configuration = $this->configuration(['src']);

        self::assertTrue($this->covers($configuration));
        self::assertSame([], $this->uncovered($configuration));
        self::assertSame(ProjectScopeState::Unknown, $this->state($configuration));
    }

    /** Covered and Narrowed are told apart by the uncovered list, Unknown by the manifest. */
    #[Test]
    public function itNamesTheStateOfARunWhoseManifestWasRead(): void
    {
        $this->writeComposerJson(['src/', 'lib/']);

        self::assertSame(ProjectScopeState::Covered, $this->state($this->configuration(['src', 'lib'])));
        self::assertSame(ProjectScopeState::Narrowed, $this->state($this->configuration(['src'])));
        self::assertFalse($this->covers($this->configuration(['src'])));
    }

    /** @return iterable<string, array{?string}> */
    public static function provideManifestsThatDeclareNoProductionAutoload(): iterable
    {
        yield 'no composer.json at all' => [null];
        yield 'a composer.json that does not parse' => ['{ "autoload": { "psr-4": '];
        yield 'a manifest with no autoload section' => ['{"name":"acme/demo"}'];
        yield 'production sections that are all empty' => ['{"autoload":{"classmap":[],"files":[]}}'];
        yield 'only a dev section' => ['{"autoload-dev":{"psr-4":{"Fixture\\\\Tests\\\\":"tests/"}}}'];
    }

    /**
     * The opposite error the fix must not introduce, in the three shapes that
     * would produce it. Closing the gate on any of these silences every
     * scope-conditioned channel on an ordinary project — including this
     * repository, whose own manifest carries an `autoload-dev.classmap`.
     *
     * @param array<string, mixed> $autoload the whole manifest
     */
    #[Test]
    #[DataProvider('provideManifestsWhoseExtraSectionAddsNoTarget')]
    public function itStillCoversTheProjectWhenTheExtraSectionAddsNoProductionTarget(array $autoload): void
    {
        $this->writeManifest($autoload);

        self::assertTrue($this->covers($this->configuration(['src', 'lib'])));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provideManifestsWhoseExtraSectionAddsNoTarget(): iterable
    {
        $psr4 = ['psr-4' => ['Fixture\\N0\\' => 'src/', 'Fixture\\N1\\' => 'lib/']];

        yield 'a dev classmap is test code, outside the denominator by design' => [[
            'autoload' => $psr4,
            'autoload-dev' => ['psr-4' => ['Fixture\\Tests\\' => 'tests/'], 'classmap' => ['tests/Fixtures/']],
        ]];

        yield 'an empty production section declares nothing' => [[
            'autoload' => $psr4 + ['classmap' => [], 'files' => []],
        ]];

        yield 'exclude-from-classmap removes code rather than declaring it' => [[
            'autoload' => $psr4 + ['exclude-from-classmap' => ['src/Generated/']],
        ]];
    }

    /**
     * The policy that adds `autoload-dev` to a run's default paths adds it to
     * the denominator too, so a run that left test code out is named for it.
     */
    #[Test]
    public function itCountsAutoloadDevTargetsOnlyUnderAPolicyThatIncludesThem(): void
    {
        $this->writeManifest([
            'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
            'autoload-dev' => ['classmap' => ['legacy/']],
        ]);

        self::assertTrue($this->covers($this->configuration(['src'])));
        self::assertSame(['legacy'], $this->uncovered($this->configuration(['src'], AutoloadDevPolicy::Include)));
        self::assertTrue($this->covers($this->configuration(['src', 'legacy'], AutoloadDevPolicy::Include)));
    }

    /** A manifest with only `autoload-dev` is judged once the policy counts it. */
    #[Test]
    public function itJudgesADevOnlyManifestUnderAPolicyThatIncludesIt(): void
    {
        $this->writeManifest(['autoload-dev' => ['psr-4' => ['Fixture\\Tests\\' => 'lib/']]]);

        self::assertSame([], $this->uncovered($this->configuration(['src'])), 'Unreadable without the policy: nothing to name');
        self::assertSame(['lib'], $this->uncovered($this->configuration(['src'], AutoloadDevPolicy::Include)));
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(array $manifest): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode($manifest, \JSON_THROW_ON_ERROR),
        );
    }

    /** @param list<string> $autoloadRoots */
    private function writeComposerJson(array $autoloadRoots): void
    {
        $map = [];
        foreach ($autoloadRoots as $index => $root) {
            $map['Fixture\\N' . $index . '\\'] = $root;
        }

        $this->writeManifest(['autoload' => ['psr-4' => $map]]);
    }

    private function covers(RunConfiguration $configuration): bool
    {
        return $this->coverage()->pathsCoverProjectScope($configuration->projectRoot, $configuration->paths, $configuration->autoloadDevPolicy);
    }

    private function state(RunConfiguration $configuration): ProjectScopeState
    {
        return $this->coverage()->measure($configuration->projectRoot, $configuration->paths, $configuration->autoloadDevPolicy)->state();
    }

    /** @return list<string> */
    private function uncovered(RunConfiguration $configuration): array
    {
        return $this->coverage()->uncoveredAutoloadRoots($configuration->projectRoot, $configuration->paths, $configuration->autoloadDevPolicy);
    }

    private function coverage(): ProjectScopeCoverage
    {
        return new ProjectScopeCoverage(new ComposerReader());
    }

    /** @param list<string> $paths */
    private function configuration(array $paths, AutoloadDevPolicy $autoloadDev = AutoloadDevPolicy::Exclude): RunConfiguration
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
            autoloadDevPolicy: $autoloadDev,
        );
    }
}
