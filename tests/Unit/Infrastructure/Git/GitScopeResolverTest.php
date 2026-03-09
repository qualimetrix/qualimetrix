<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Git;

use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\PathsConfiguration;
use AiMessDetector\Configuration\Pipeline\ResolvedConfiguration;
use AiMessDetector\Infrastructure\Git\GitScopeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}
