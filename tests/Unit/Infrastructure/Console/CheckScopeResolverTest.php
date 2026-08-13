<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Discovery\ComposerAutoloadPathReaderInterface;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\CheckScopeResolver;
use Qualimetrix\Infrastructure\Console\ResolvedCheckScope;
use Qualimetrix\Infrastructure\Console\ScopeWarningChecker;
use Qualimetrix\Infrastructure\Git\GitScopeResolver;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

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
        $reader->expects(self::once())->method('extractAutoloadPaths')->willReturnCallback(
            function () use (&$events): array {
                $events[] = 'warnings';

                return [];
            },
        );

        $this->resolver($factory, $reader)->resolve($this->input(), $this->configuration());

        self::assertSame(['scope', 'warnings'], $events);
    }

    #[Test]
    public function itReturnsTheUnchangedGitScopeWithWarnings(): void
    {
        $projectRoot = sys_get_temp_dir() . '/qmx_check_scope_' . uniqid();
        mkdir($projectRoot . '/src', 0o755, true);
        mkdir($projectRoot . '/lib', 0o755, true);
        file_put_contents($projectRoot . '/composer.json', '{}');
        $factory = $this->createMock(FileDiscoveryFactoryInterface::class);
        $discovery = self::createStub(FileDiscoveryInterface::class);
        $factory->expects(self::once())->method('create')->with(['vendor'])->willReturn($discovery);
        $reader = $this->createMock(ComposerAutoloadPathReaderInterface::class);
        $reader->expects(self::once())->method('extractAutoloadPaths')->with(
            self::callback(static fn(string $path): bool => str_ends_with($path, '/composer.json')),
            false,
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
    public function itDoesNotComputeWarningsWhenGitScopeResolutionFails(): void
    {
        $factory = $this->createMock(FileDiscoveryFactoryInterface::class);
        $factory->expects(self::never())->method('create');
        $reader = $this->createMock(ComposerAutoloadPathReaderInterface::class);
        $reader->expects(self::never())->method('extractAutoloadPaths');

        $this->expectException(InvalidArgumentException::class);

        $this->resolver($factory, $reader)->resolve($this->input('invalid'), $this->configuration());
    }

    private function resolver(
        FileDiscoveryFactoryInterface $factory,
        ComposerAutoloadPathReaderInterface $reader,
    ): CheckScopeResolver {
        return new CheckScopeResolver(
            new GitScopeResolver($factory),
            new ScopeWarningChecker($reader),
        );
    }

    private function input(?string $report = null): ArrayInput
    {
        $definition = new InputDefinition([new InputOption('report', null, InputOption::VALUE_REQUIRED)]);

        return new ArrayInput($report === null ? [] : ['--report' => $report], $definition);
    }

    /** @param list<string> $paths */
    private function configuration(?AbsolutePath $projectRoot = null, array $paths = ['src']): TransitionalResolvedConfiguration
    {
        return new TransitionalResolvedConfiguration(
            paths: $paths,
            pathExcludes: ['vendor'],
            runtime: new TransitionalRuntimeConfiguration(
                projectRoot: $projectRoot ?? AbsolutePath::fromString((string) getcwd()),
            ),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );
    }
}
