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
