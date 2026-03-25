<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Git;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\PathsConfiguration;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;
use Qualimetrix\Infrastructure\Git\GitFileDiscovery;
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
        $projectRoot = \dirname(__DIR__, 4); // repo root

        $resolved = new ResolvedConfiguration(
            paths: new PathsConfiguration(['src']),
            analysis: new AnalysisConfiguration(projectRoot: $projectRoot),
            ruleOptions: [],
        );

        $definition = new InputDefinition([
            new InputOption('analyze', null, InputOption::VALUE_REQUIRED),
            new InputOption('report', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput(['--analyze' => 'git:staged'], $definition);

        $resolver = new GitScopeResolver();
        $result = $resolver->resolve($input, $resolved);

        self::assertNotNull($result->gitClient);

        // Verify GitClient was constructed with projectRoot, not getcwd()
        $repoRootProperty = new ReflectionProperty($result->gitClient, 'repoRoot');
        self::assertSame($projectRoot, $repoRootProperty->getValue($result->gitClient));
    }

    #[Test]
    public function itDoesNotCreateGitClientWithoutGitOptions(): void
    {
        $resolved = new ResolvedConfiguration(
            paths: new PathsConfiguration(['src']),
            analysis: new AnalysisConfiguration(projectRoot: '/some/project'),
            ruleOptions: [],
        );

        $definition = new InputDefinition([
            new InputOption('analyze', null, InputOption::VALUE_REQUIRED),
            new InputOption('report', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([], $definition);

        $resolver = new GitScopeResolver();
        $result = $resolver->resolve($input, $resolved);

        self::assertNull($result->gitClient);
    }

    #[Test]
    public function itPassesExcludesToGitFileDiscovery(): void
    {
        $projectRoot = \dirname(__DIR__, 4); // repo root

        $resolved = new ResolvedConfiguration(
            paths: new PathsConfiguration(['src'], ['vendor', 'tests']),
            analysis: new AnalysisConfiguration(projectRoot: $projectRoot),
            ruleOptions: [],
        );

        $definition = new InputDefinition([
            new InputOption('analyze', null, InputOption::VALUE_REQUIRED),
            new InputOption('report', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput(['--analyze' => 'git:staged'], $definition);

        $resolver = new GitScopeResolver();
        $result = $resolver->resolve($input, $resolved);

        self::assertInstanceOf(GitFileDiscovery::class, $result->fileDiscovery);

        $excludedDirsProperty = new ReflectionProperty($result->fileDiscovery, 'excludedDirs');
        self::assertSame(['vendor', 'tests'], $excludedDirsProperty->getValue($result->fileDiscovery));
    }
}
