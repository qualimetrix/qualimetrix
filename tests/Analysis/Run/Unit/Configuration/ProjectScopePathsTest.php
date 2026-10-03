<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopePaths;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(ProjectScopePaths::class)]
final class ProjectScopePathsTest extends TestCase
{
    #[Test]
    public function itProvidesBuiltInsThatMatchReservedDirectoriesAtAnyDepth(): void
    {
        $root = sys_get_temp_dir() . '/qmx-built-in-floor-' . bin2hex(random_bytes(8));
        foreach (['vendor', 'packages/acme/vendor', 'node_modules', 'web/node_modules', '.git', 'nested/.git', 'vendorized', 'git'] as $directory) {
            mkdir($root . '/' . $directory, 0o777, true);
        }
        try {
            $projectRoot = AbsolutePath::fromString($root);
            foreach (['vendor', 'packages/acme/vendor', 'node_modules', 'web/node_modules', '.git', 'nested/.git'] as $path) {
                self::assertSame($path, ProjectScopePaths::partition($projectRoot, [$path])[1][0]['directory'] ?? null, $path);
            }
            self::assertSame([], ProjectScopePaths::partition($projectRoot, ['vendorized'])[1]);
            self::assertSame([], ProjectScopePaths::partition($projectRoot, ['git'])[1]);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * The question a declared target is asked so that it answers the way a
     * walk from the project root would: the outermost directory at or above
     * it that the walk refuses to enter.
     */
    #[Test]
    public function itNamesTheOutermostPrunedDirectoryAWalkWouldStopAt(): void
    {
        $root = sys_get_temp_dir() . '/qmx-pruned-ancestor-' . bin2hex(random_bytes(8));
        foreach (['vendor/acme/legacy', 'lib/vendor', 'vendors', 'src/Vendor', 'packages/vendor/deep/vendor'] as $directory) {
            mkdir($root . '/' . $directory, 0o777, true);
        }
        file_put_contents($root . '/vendor/acme/helpers.php', "<?php\n");
        file_put_contents($root . '/vendors/vendor', "not a directory\n");
        mkdir($root . '/src/sub', 0o777, true);

        try {
            $projectRoot = AbsolutePath::fromString($root);
            $ancestor = static fn(string $path): ?string => ProjectScopePaths::partition($projectRoot, [$path])[1][0]['directory'] ?? null;

            self::assertSame('vendor', $ancestor('vendor/acme/helpers.php'));
            self::assertSame('vendor', $ancestor('vendor/acme/legacy'));
            self::assertSame('lib/vendor', $ancestor('lib/vendor'));
            self::assertSame('packages/vendor', $ancestor('packages/vendor/deep/vendor'));
            // Absent, so not a directory: only its ancestors are asked, as a walk would.
            self::assertSame('vendor', $ancestor('vendor/gone'));
            self::assertNull($ancestor('src/sub/vendor'));

            self::assertNull($ancestor('vendors'));
            self::assertNull($ancestor('src/Vendor'));
            // A file is never asked about itself, whatever its name.
            self::assertNull($ancestor('vendors/vendor'));
            self::assertNull($ancestor(''));
            self::assertNull(ProjectScopePaths::partition($projectRoot, [\dirname($root) . '/vendor'])[1][0]['directory'] ?? null);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

}
