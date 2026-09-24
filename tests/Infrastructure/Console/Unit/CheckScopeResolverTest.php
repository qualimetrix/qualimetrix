<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Discovery\ComposerAutoloadPathReaderInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
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
    #[Test]
    public function itResolvesGitScopeBeforeComputingWarnings(): void
    {
        $events = [];
        $factory = $this->createMock(FileDiscoveryFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturnCallback(
            function () use (&$events): FileDiscoveryInterface {
                $events[] = 'scope';

                return self::createStub(FileDiscoveryInterface::class);
            },
        );
        $reader = $this->createMock(ComposerAutoloadPathReaderInterface::class);
        $reader->expects(self::once())->method('productionAutoloadTargets')->willReturnCallback(
            function () use (&$events): array {
                $events[] = 'warnings';

                return ['src'];
            },
        );

        $this->resolver($factory, $reader)->resolve($this->input(), $this->configuration());

        self::assertSame(['scope', 'warnings'], $events);
    }

    #[Test]
    public function itReturnsTheUnchangedGitScopeWithWarnings(): void
    {
        $projectRoot = sys_get_temp_dir() . '/qmx_check_scope_' . bin2hex(random_bytes(6));
        mkdir($projectRoot . '/src', 0o755, true);
        mkdir($projectRoot . '/lib', 0o755, true);
        file_put_contents($projectRoot . '/composer.json', '{}');
        $factory = $this->createMock(FileDiscoveryFactoryInterface::class);
        $discovery = self::createStub(FileDiscoveryInterface::class);
        $factory->expects(self::once())->method('create')->with(
            self::callback(static fn(AbsolutePath $root): bool => $root->value() === $projectRoot),
            self::callback(static fn(array $patterns): bool => array_map(
                static fn(PathPattern $pattern): string => $pattern->definition->display(),
                $patterns,
            ) === ['subtree:vendor']),
        )->willReturn($discovery);
        $reader = $this->createMock(ComposerAutoloadPathReaderInterface::class);
        $reader->expects(self::once())->method('productionAutoloadTargets')->with(
            self::callback(static fn(string $path): bool => str_ends_with($path, '/composer.json')),
        )->willReturn(['src', 'lib']);

        try {
            $result = $this->resolver($factory, $reader)->resolve(
                $this->input(),
                $this->configuration(AbsolutePath::fromString($projectRoot), [$projectRoot . '/src']),
            );

            self::assertSame($discovery, $result->scope->fileDiscovery);
            self::assertTrue($result->scope->projectRoot->equals(AbsolutePath::fromString($projectRoot)));
            self::assertCount(1, $result->scope->paths);
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
        $factory = self::createStub(FileDiscoveryFactoryInterface::class);
        $factory->method('create')->willReturn(self::createStub(FileDiscoveryInterface::class));
        $reader = self::createStub(ComposerAutoloadPathReaderInterface::class);
        $reader->method('productionAutoloadTargets')->willReturn(['src', 'lib/vendor']);

        try {
            $result = $this->resolver($factory, $reader)->resolve(
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

    #[Test]
    public function itDoesNotComputeWarningsWhenGitScopeResolutionFails(): void
    {
        $factory = $this->createMock(FileDiscoveryFactoryInterface::class);
        $factory->expects(self::never())->method('create');
        $reader = $this->createMock(ComposerAutoloadPathReaderInterface::class);
        $reader->expects(self::never())->method('productionAutoloadTargets');

        $this->expectException(InvalidArgumentException::class);

        $this->resolver($factory, $reader)->resolve($this->input('invalid'), $this->configuration());
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
        $factory = $this->createMock(FileDiscoveryFactoryInterface::class);
        $factory->expects(self::never())->method('create');
        $reader = $this->createMock(ComposerAutoloadPathReaderInterface::class);
        $reader->expects(self::never())->method('productionAutoloadTargets');

        $this->expectException($refusal);
        $this->expectExceptionMessage($message);

        $this->resolver($factory, $reader)->resolve($this->input($report), $this->configuration());
    }

    private function resolver(
        FileDiscoveryFactoryInterface $factory,
        ComposerAutoloadPathReaderInterface $reader,
    ): CheckScopeResolver {
        return new CheckScopeResolver(
            new GitScopeResolver($factory),
            new ScopeWarningChecker(),
            new ProjectScopeCoverage($reader),
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

        return new RunConfiguration(
            paths: array_map(
                static fn(string $path): AbsolutePath => AbsolutePath::fromString(
                    str_starts_with($path, '/') ? $path : $root->value() . '/' . $path,
                ),
                $paths,
            ),
            pathExcludes: [new PathPattern(new SelectorDefinition(SelectorKind::Subtree, 'vendor'))],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            coversProjectScope: true,
            authoredPathExcludes: [],
        );
    }
}
