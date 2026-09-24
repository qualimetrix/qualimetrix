<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Discovery\DirectoryPruner;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

#[CoversClass(DirectoryPruner::class)]
final class DirectoryPrunerTest extends TestCase
{
    private const string ROOT = '/project';

    #[Test]
    public function itMatchesProjectRelativeDirectoriesAndReturnsTheFirstAttribution(): void
    {
        $pruner = $this->pruner([
            $this->pattern(SelectorKind::Regex, 'src/.+'),
            $this->pattern(SelectorKind::Subtree, 'src/Legacy'),
        ]);

        $match = $pruner->match(AbsolutePath::fromString(self::ROOT . '/src/Legacy'));

        self::assertNotNull($match);
        self::assertSame('regex:src/.+', $match->definition->display());
        self::assertNull($pruner->match(AbsolutePath::fromString('/other/src/Legacy')));
    }

    #[Test]
    public function itKeepsSiblingPrefixesSeparate(): void
    {
        $pruner = $this->pruner([$this->pattern(SelectorKind::Subtree, 'src/Legacy')]);

        self::assertNotNull($pruner->match(AbsolutePath::fromString(self::ROOT . '/src/Legacy')));
        self::assertNotNull($pruner->match(AbsolutePath::fromString(self::ROOT . '/src/Legacy/Deep')));
        self::assertNull($pruner->match(AbsolutePath::fromString(self::ROOT . '/src/LegacyExtra')));
    }

    #[Test]
    public function itProvidesBuiltInsThatMatchReservedDirectoriesAtAnyDepth(): void
    {
        $pruner = $this->pruner(DirectoryPruner::builtInPatterns());

        foreach (['vendor', 'packages/acme/vendor', 'node_modules', 'web/node_modules', '.git', 'nested/.git'] as $path) {
            self::assertNotNull($pruner->match(AbsolutePath::fromString(self::ROOT . '/' . $path)), $path);
        }

        self::assertNull($pruner->match(AbsolutePath::fromString(self::ROOT . '/vendorized')));
        self::assertNull($pruner->match(AbsolutePath::fromString(self::ROOT . '/git')));
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

        try {
            $pruner = new DirectoryPruner(AbsolutePath::fromString($root), DirectoryPruner::builtInPatterns());
            $ancestor = static fn(string $path): ?string => $pruner->prunedAncestor(AbsolutePath::fromString($root . '/' . $path));

            self::assertSame('vendor', $ancestor('vendor/acme/helpers.php'));
            self::assertSame('vendor', $ancestor('vendor/acme/legacy'));
            self::assertSame('lib/vendor', $ancestor('lib/vendor'));
            self::assertSame('packages/vendor', $ancestor('packages/vendor/deep/vendor'));
            // Absent, so not a directory: only its ancestors are asked, as a walk would.
            self::assertSame('vendor', $ancestor('vendor/gone'));

            self::assertNull($ancestor('vendors'));
            self::assertNull($ancestor('src/Vendor'));
            // A file is never asked about itself, whatever its name.
            self::assertNull($ancestor('vendors/vendor'));
            self::assertNull($pruner->prunedAncestor(AbsolutePath::fromString($root)));
            self::assertNull($pruner->prunedAncestor(AbsolutePath::fromString(\dirname($root) . '/vendor')));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /** @param list<PathPattern> $patterns */
    private function pruner(array $patterns): DirectoryPruner
    {
        return new DirectoryPruner(AbsolutePath::fromString(self::ROOT), $patterns);
    }

    private function pattern(SelectorKind $kind, string $value): PathPattern
    {
        return new PathPattern(new SelectorDefinition($kind, $value));
    }
}
