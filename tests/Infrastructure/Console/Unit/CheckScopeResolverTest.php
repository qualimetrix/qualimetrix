<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Infrastructure\Console\CheckScopeResolver;
use Qualimetrix\Infrastructure\Console\ResolvedCheckScope;
use Qualimetrix\Infrastructure\Console\ScopeWarningChecker;
use Qualimetrix\Infrastructure\Git\GitScopeResolver;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

#[CoversClass(CheckScopeResolver::class)]
#[CoversClass(ResolvedCheckScope::class)]
final class CheckScopeResolverTest extends TestCase
{
    private ComposerManifestReaderInterface $reader;

    #[Test]
    public function itUsesTheInitialMeasurementWithoutReadingAfterGitResolution(): void
    {
        $events = [];
        $reader = $this->createMock(ComposerManifestReaderInterface::class);
        $reader->expects(self::once())->method('read')->willReturnCallback(
            function (AbsolutePath $root) use (&$events): \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts {
                $events[] = 'warnings';

                return (new \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder())->decode($root, '{"autoload":{"classmap":["src"]}}');
            },
        );

        $resolver = $this->resolver($reader);
        $configuration = $this->configuration();
        $result = $resolver->resolve($this->input(), $configuration);

        self::assertSame($configuration->projectScope, $result->measurement);
        self::assertSame(['warnings'], $events);
    }

    #[Test]
    public function itReturnsTheUnchangedGitScopeWithWarnings(): void
    {
        $projectRoot = sys_get_temp_dir() . '/qmx_check_scope_' . bin2hex(random_bytes(6));
        mkdir($projectRoot . '/src', 0o755, true);
        mkdir($projectRoot . '/lib', 0o755, true);
        file_put_contents($projectRoot . '/composer.json', '{}');
        $reader = $this->createMock(ComposerManifestReaderInterface::class);
        $reader->expects(self::once())->method('read')->with(
            self::callback(static fn(AbsolutePath $path): bool => $path->value() === $projectRoot),
        )->willReturnCallback(static fn(AbsolutePath $root): \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts => (new \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder())->decode($root, json_encode(['autoload' => ['classmap' => ['src', 'lib']]], \JSON_THROW_ON_ERROR)));

        try {
            $result = $this->resolver($reader)->resolve(
                $this->input(),
                $this->configuration(AbsolutePath::fromString($projectRoot), [$projectRoot . '/src']),
            );

            self::assertTrue($result->scope->projectRoot->equals(AbsolutePath::fromString($projectRoot)));
            self::assertCount(1, $result->scope->paths);
            self::assertSame($projectRoot . '/src', $result->scope->paths[0]->value());
            self::assertCount(1, $result->warnings);
            self::assertStringContainsString('lib', $result->warnings[0]);
        } finally {
            unlink($projectRoot . '/composer.json');
            rmdir($projectRoot . '/src');
            rmdir($projectRoot . '/lib');
            rmdir($projectRoot);
        }
    }

    #[Test]
    public function itWarnsAboutAutoloadEntriesUnderAPrunedDirectoryOnAWholeProjectRun(): void
    {
        $projectRoot = sys_get_temp_dir() . '/qmx_check_scope_' . bin2hex(random_bytes(6));
        mkdir($projectRoot . '/src', 0o755, true);
        mkdir($projectRoot . '/lib/vendor', 0o755, true);
        file_put_contents($projectRoot . '/composer.json', '{}');
        $reader = self::createStub(ComposerManifestReaderInterface::class);
        $reader->method('read')->willReturnCallback(static fn(AbsolutePath $root): \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts => (new \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder())->decode($root, json_encode(['autoload' => ['classmap' => ['src', 'lib/vendor']]], \JSON_THROW_ON_ERROR)));

        try {
            $result = $this->resolver($reader)->resolve(
                $this->input(),
                $this->configuration(AbsolutePath::fromString($projectRoot), [$projectRoot]),
            );

            self::assertSame(
                ['Autoload entries that are, or lie inside, a vendor, node_modules or .git directory are not counted as project scope,'
                    . ' and discovery skips them unless a path you name lies inside that directory: lib/vendor.'],
                $result->warnings,
            );
            self::assertTrue($result->coversProjectScope);
        } finally {
            unlink($projectRoot . '/composer.json');
            rmdir($projectRoot . '/lib/vendor');
            rmdir($projectRoot . '/lib');
            rmdir($projectRoot . '/src');
            rmdir($projectRoot);
        }
    }

    /**
     * The three states reach the report from the one measurement the verdict
     * is read from: a narrowed run is not judged and names what it left out
     * and which channels. A judging run — covered or manifest-less — names no
     * channel here: which of its values went unjudged is known only once they
     * are asked, after the analysis, and the report gains them then.
     *
     * @param ?list<string> $targets what the manifest declares, or null for none readable
     * @param list<string> $paths
     * @param array{state: string, uncoveredAutoloadTargets: list<string>, unjudgedChannels: list<string>, unjudgedValues: list<array{option: string, pattern: string}>} $expected
     */
    #[Test]
    #[DataProvider('provideProjectScopes')]
    public function itCarriesTheProjectScopeStateToTheReport(?array $targets, array $paths, bool $covers, array $expected): void
    {
        $projectRoot = sys_get_temp_dir() . '/qmx_check_scope_' . bin2hex(random_bytes(6));
        mkdir($projectRoot . '/src', 0o755, true);
        mkdir($projectRoot . '/lib', 0o755, true);
        $reader = self::createStub(ComposerManifestReaderInterface::class);
        $reader->method('read')->willReturnCallback(static fn(AbsolutePath $root): \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts => (new \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder())->decode($root, json_encode(['autoload' => ['classmap' => $targets ?? []]], \JSON_THROW_ON_ERROR)));

        try {
            $result = $this->resolver($reader)->resolve(
                $this->input(),
                $this->configuration(AbsolutePath::fromString($projectRoot), array_map(
                    static fn(string $path): string => $projectRoot . '/' . $path,
                    $paths,
                )),
            );

            self::assertSame($covers, $result->coversProjectScope);
            self::assertSame($expected, array_diff_key($result->projectScope->toArray(), ['reasons' => true]));
            self::assertSame($result->measurement->universe->reasons, $result->projectScope->reasons);
        } finally {
            rmdir($projectRoot . '/src');
            rmdir($projectRoot . '/lib');
            rmdir($projectRoot);
        }
    }

    /** @return iterable<string, array{?list<string>, list<string>, bool, array<string, mixed>}> */
    public static function provideProjectScopes(): iterable
    {
        yield 'covered' => [['src', 'lib'], ['src', 'lib'], true, [
            'state' => 'covered', 'uncoveredAutoloadTargets' => [], 'unjudgedChannels' => [], 'unjudgedValues' => [],
        ]];
        yield 'narrowed' => [['src', 'lib'], ['src'], false, [
            'state' => 'narrowed',
            'uncoveredAutoloadTargets' => ['lib'],
            'unjudgedChannels' => ProjectScopeCoverage::WHOLE_PROJECT_CHANNELS,
            'unjudgedValues' => [],
        ]];
        yield 'undeclared subset is unmeasured' => [null, ['src'], false, [
            'state' => 'unmeasured',
            'uncoveredAutoloadTargets' => [],
            'unjudgedChannels' => ProjectScopeCoverage::WHOLE_PROJECT_CHANNELS,
            'unjudgedValues' => [],
        ]];
    }

    #[Test]
    public function itDoesNotComputeWarningsWhenGitScopeResolutionFails(): void
    {
        $reader = $this->createMock(ComposerManifestReaderInterface::class);
        $reader->expects(self::once())->method('read')->willReturnCallback(static fn(AbsolutePath $root): \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts => (new \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder())->decode($root, '{}'));

        $this->expectException(InvalidArgumentException::class);

        $this->resolver($reader)->resolve($this->input('invalid'), $this->configuration());
    }

    /**
     * An embedder's array input can hand `--report` any PHP value. Read as
     * "not a string, so not written", a list or a number ran the whole
     * project with no scope and no word about the value it was given.
     *
     * @return iterable<string, array{mixed, class-string<Throwable>, string}>
     */
    public static function provideReportValuesNoCommandLineSpells(): iterable
    {
        yield 'a list' => [['git:HEAD'], ConfigurationRefusal::class, 'Invalid --report value of type array'];
        yield 'a number, read as its digits' => [5, InvalidArgumentException::class, 'Invalid report scope: 5'];
    }

    /** @param class-string<Throwable> $refusal */
    #[Test]
    #[DataProvider('provideReportValuesNoCommandLineSpells')]
    public function itRefusesAReportValueItCannotReadAsWritten(mixed $report, string $refusal, string $message): void
    {
        $reader = $this->createMock(ComposerManifestReaderInterface::class);
        $reader->expects(self::once())->method('read')->willReturnCallback(static fn(AbsolutePath $root): \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts => (new \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder())->decode($root, '{}'));

        $this->expectException($refusal);
        $this->expectExceptionMessage($message);

        $this->resolver($reader)->resolve($this->input($report), $this->configuration());
    }

    private function resolver(ComposerManifestReaderInterface $reader): CheckScopeResolver
    {
        $this->reader = $reader;

        return new CheckScopeResolver(
            new GitScopeResolver(),
            new ScopeWarningChecker(),
        );
    }

    private function input(mixed $report = null): ArrayInput
    {
        $definition = new InputDefinition([new InputOption('report', null, InputOption::VALUE_REQUIRED)]);

        return new ArrayInput($report === null ? [] : ['--report' => $report], $definition);
    }

    /** @param list<string> $paths */
    private function configuration(?AbsolutePath $projectRoot = null, array $paths = ['src']): RunConfiguration
    {
        $root = $projectRoot ?? AbsolutePath::fromString((string) getcwd());

        $absolutePaths = array_map(static fn(string $path): AbsolutePath => AbsolutePath::fromString(str_starts_with($path, '/') ? $path : $root->value() . '/' . $path), $paths);

        return new RunConfiguration(
            pathExcludes: [new PathPattern(new SelectorDefinition(SelectorKind::Subtree, 'vendor'))],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: (new ProjectScopeCoverage($this->reader))->measure($root, $absolutePaths, \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude, \Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship::Authored),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        );
    }
}
