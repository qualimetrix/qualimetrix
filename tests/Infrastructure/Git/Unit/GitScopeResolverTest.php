<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Git\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Discovery\FileDiscoveryFactory;
use Qualimetrix\Analysis\Run\Discovery\FinderFileDiscovery;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Infrastructure\Git\GitScopeResolver;
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

        $resolved = new RunConfiguration(
            paths: [AbsolutePath::fromString($projectRoot->value() . '/src')],
            pathExcludes: self::patterns('vendor', 'node_modules', '.git'),
            projectRoot: $projectRoot,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            coversProjectScope: true,
            authoredPathExcludes: [],
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
        $projectRoot = AbsolutePath::fromString('/some/project');
        $resolved = new RunConfiguration(
            paths: [AbsolutePath::fromString('/some/project/src')],
            pathExcludes: self::patterns('vendor', 'node_modules', '.git'),
            projectRoot: $projectRoot,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            coversProjectScope: true,
            authoredPathExcludes: [],
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
        $root = sys_get_temp_dir() . '/qmx-git-scope-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o755, true);
        mkdir($root . '/tests', 0o755, true);
        file_put_contents($root . '/src/App.php', "<?php\n");
        file_put_contents($root . '/tests/AppTest.php', "<?php\n");
        $projectRoot = AbsolutePath::fromString($root);

        $resolved = new RunConfiguration(
            paths: [AbsolutePath::fromString($projectRoot->value() . '/src')],
            pathExcludes: self::patterns('vendor', 'tests'),
            projectRoot: $projectRoot,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            coversProjectScope: true,
            authoredPathExcludes: [],
        );

        try {
            $definition = new InputDefinition([
                new InputOption('report', null, InputOption::VALUE_REQUIRED),
            ]);
            $input = new ArrayInput([], $definition);

            $result = (new GitScopeResolver(new FileDiscoveryFactory()))->resolve($input, $resolved);

            self::assertInstanceOf(FinderFileDiscovery::class, $result->fileDiscovery);
            $files = iterator_to_array($result->fileDiscovery->discover($projectRoot), false);
            self::assertSame(['App.php'], array_map(static fn($file): string => $file->getFilename(), $files));
        } finally {
            unlink($root . '/src/App.php');
            unlink($root . '/tests/AppTest.php');
            rmdir($root . '/src');
            rmdir($root . '/tests');
            rmdir($root);
        }
    }

    #[Test]
    public function itReturnsFindDiscoveryForFullAnalysis(): void
    {
        $projectRoot = AbsolutePath::fromString('/some/project');
        $resolved = new RunConfiguration(
            paths: [AbsolutePath::fromString('/some/project/src')],
            pathExcludes: self::patterns('vendor', 'node_modules', '.git'),
            projectRoot: $projectRoot,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            coversProjectScope: true,
            authoredPathExcludes: [],
        );

        $definition = new InputDefinition([
            new InputOption('report', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([], $definition);

        $resolver = new GitScopeResolver(new FileDiscoveryFactory());
        $result = $resolver->resolve($input, $resolved);

        self::assertInstanceOf(FinderFileDiscovery::class, $result->fileDiscovery);
    }

    /** @return list<PathPattern> */
    private static function patterns(string ...$values): array
    {
        return array_values(array_map(
            static fn(string $value): PathPattern => new PathPattern(new SelectorDefinition(SelectorKind::Subtree, $value)),
            $values,
        ));
    }
}
