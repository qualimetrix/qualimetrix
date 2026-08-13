<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Git;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Run\Discovery\FileDiscoveryFactory;
use Qualimetrix\Analysis\Run\Discovery\FinderFileDiscovery;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Git\GitScopeResolver;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(GitScopeResolver::class)]
final class GitScopeResolverTest extends TestCase
{
    #[Test]
    public function itUsesProjectRootForGitClient(): void
    {
        $projectRoot = AbsolutePath::fromString(\dirname(__DIR__, 4)); // repo root

        $resolved = new TransitionalResolvedConfiguration(
            paths: ['src'],
            pathExcludes: ['vendor', 'node_modules', '.git'],
            runtime: new TransitionalRuntimeConfiguration(projectRoot: $projectRoot),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $definition = new InputDefinition([
            new InputOption('report', null, InputOption::VALUE_REQUIRED),
        ]);

        // HEAD is a branch-independent scope: this wiring test must also pass
        // in detached CI checkouts where no local main branch exists.
        $input = new ArrayInput(['--report' => 'git:HEAD'], $definition);

        $resolver = new GitScopeResolver(new FileDiscoveryFactory());
        $result = $resolver->resolve($input, $resolved);

        self::assertNotNull($result->gitClient);

        // The explicit projectRoot on the resolution carries the same value
        // we configured (Phase 5 collapsed the GitClient::getProjectRoot
        // accessor — the resolution VO is the canonical source).
        self::assertTrue($result->projectRoot->equals($projectRoot));
    }

    #[Test]
    public function itDoesNotCreateGitClientWithoutGitOptions(): void
    {
        $resolved = new TransitionalResolvedConfiguration(
            paths: ['src'],
            pathExcludes: ['vendor', 'node_modules', '.git'],
            runtime: new TransitionalRuntimeConfiguration(projectRoot: AbsolutePath::fromString('/some/project')),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $definition = new InputDefinition([
            new InputOption('report', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([], $definition);

        $resolver = new GitScopeResolver(new FileDiscoveryFactory());
        $result = $resolver->resolve($input, $resolved);

        self::assertNull($result->gitClient);
    }

    #[Test]
    public function itAlwaysUsesFinderFileDiscoveryWithExcludes(): void
    {
        $projectRoot = AbsolutePath::fromString(\dirname(__DIR__, 4)); // repo root

        $resolved = new TransitionalResolvedConfiguration(
            paths: ['src'],
            pathExcludes: ['vendor', 'tests'],
            runtime: new TransitionalRuntimeConfiguration(projectRoot: $projectRoot),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $definition = new InputDefinition([
            new InputOption('report', null, InputOption::VALUE_REQUIRED),
        ]);

        // Keep this wiring assertion independent from local branch names.
        // Missing-reference rejection is covered by direct GitClient/CLI tests.
        $input = new ArrayInput(['--report' => 'git:HEAD'], $definition);

        $resolver = new GitScopeResolver(new FileDiscoveryFactory());
        $result = $resolver->resolve($input, $resolved);

        // Always uses FinderFileDiscovery for full project collection
        self::assertInstanceOf(FinderFileDiscovery::class, $result->fileDiscovery);

        $excludedDirsProperty = new ReflectionProperty($result->fileDiscovery, 'excludedDirs');
        self::assertSame(['vendor', 'tests'], $excludedDirsProperty->getValue($result->fileDiscovery));
    }

    #[Test]
    public function itReturnsFindDiscoveryForFullAnalysis(): void
    {
        $resolved = new TransitionalResolvedConfiguration(
            paths: ['src'],
            pathExcludes: ['vendor', 'node_modules', '.git'],
            runtime: new TransitionalRuntimeConfiguration(projectRoot: AbsolutePath::fromString('/some/project')),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $definition = new InputDefinition([
            new InputOption('report', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([], $definition);

        $resolver = new GitScopeResolver(new FileDiscoveryFactory());
        $result = $resolver->resolve($input, $resolved);

        self::assertInstanceOf(FinderFileDiscovery::class, $result->fileDiscovery);
    }
}
