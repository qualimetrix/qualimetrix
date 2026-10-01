<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Configuration\PathsNormalizer;
use Qualimetrix\Core\Path\AbsolutePath;

final class PathsNormalizerTest extends TestCase
{
    private string $base;
    private string $root;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/qmx-path-normalizer-' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/project';
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->base . '/outside');
    }

    protected function tearDown(): void
    {
        foreach ([$this->root . '/directory-link', $this->root . '/src/Named.php', $this->root . '/root-link', $this->base . '/inside-alias', $this->base . '/outside/Named.php'] as $link) {
            if (is_link($link)) {
                unlink($link);
            }
        }
        if (is_file($this->base . '/outside/Target.php')) {
            unlink($this->base . '/outside/Target.php');
        }
        if (is_file($this->root . '/src/Item.php')) {
            unlink($this->root . '/src/Item.php');
        }
        if (is_link($this->base . '/root-alias')) {
            unlink($this->base . '/root-alias');
        }
        rmdir($this->root . '/src');
        rmdir($this->root);
        rmdir($this->base . '/outside');
        rmdir($this->base);
    }

    #[Test]
    public function itAcceptsRootSpellingsAndAnEquivalentDirectoryAlias(): void
    {
        symlink($this->root, $this->root . '/root-link');
        symlink($this->root, $this->base . '/root-alias');
        $root = AbsolutePath::fromString($this->root);

        self::assertSame([$this->root, $this->root, $this->root], array_map(
            static fn(AbsolutePath $path): string => $path->value(),
            PathsNormalizer::normalize($root, ['.', './', $this->root]),
        ));
        self::assertSame([$this->root . '/root-link'], array_map(
            static fn(AbsolutePath $path): string => $path->value(),
            PathsNormalizer::normalize($root, ['root-link']),
        ));
        self::assertSame([$this->base . '/root-alias'], array_map(
            static fn(AbsolutePath $path): string => $path->value(),
            PathsNormalizer::normalize($root, [$this->base . '/root-alias']),
        ));
    }

    #[Test]
    public function itPreservesAFileLinksNameAndLiteralBackslash(): void
    {
        file_put_contents($this->base . '/outside/Target.php', '<?php');
        symlink($this->base . '/outside/Target.php', $this->root . '/src/Named.php');
        $root = AbsolutePath::fromString($this->root);

        self::assertSame(
            [$this->root . '/src/Named.php', $this->root . '/src/Name\\Part.php'],
            array_map(static fn(AbsolutePath $path): string => $path->value(), PathsNormalizer::normalize($root, ['src/Named.php', 'src/Name\\Part.php'])),
        );
    }

    #[Test]
    public function itRefusesADirectoryAliasThatResolvesOutside(): void
    {
        symlink($this->base . '/outside', $this->root . '/directory-link');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--working-dir');
        PathsNormalizer::normalize(AbsolutePath::fromString($this->root), ['directory-link']);
    }

    #[Test]
    public function itRefusesAnExistingOutsideLexicalPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($this->base . '/outside');
        PathsNormalizer::normalize(AbsolutePath::fromString($this->root), [$this->base . '/outside']);
    }

    #[Test]
    public function itDefersAnUnresolvedInternalPath(): void
    {
        $root = AbsolutePath::fromString($this->root);

        self::assertSame([$this->root . '/missing/deep'], array_map(
            static fn(AbsolutePath $path): string => $path->value(),
            PathsNormalizer::normalize($root, ['missing/deep']),
        ));
    }

    #[Test]
    public function itAcceptsAnExternalDirectoryAliasIntoTheProjectAndAFileBeneathIt(): void
    {
        symlink($this->root . '/src', $this->base . '/inside-alias');
        file_put_contents($this->root . '/src/Item.php', '<?php');

        self::assertSame(
            [$this->base . '/inside-alias', $this->base . '/inside-alias/Item.php'],
            array_map(
                static fn(AbsolutePath $path): string => $path->value(),
                PathsNormalizer::normalize(AbsolutePath::fromString($this->root), [
                    $this->base . '/inside-alias',
                    $this->base . '/inside-alias/Item.php',
                ]),
            ),
        );
    }

    #[Test]
    public function itRefusesAnExternalFileLinkWhoseFinalTargetIsInside(): void
    {
        file_put_contents($this->root . '/src/Item.php', '<?php');
        symlink($this->root . '/src/Item.php', $this->base . '/outside/Named.php');

        $this->expectException(InvalidArgumentException::class);
        PathsNormalizer::normalize(AbsolutePath::fromString($this->root), [$this->base . '/outside/Named.php']);
    }

    #[Test]
    public function itRefusesAFileBeneathAnInternalDirectoryAliasToOutside(): void
    {
        symlink($this->base . '/outside', $this->root . '/directory-link');

        $this->expectException(InvalidArgumentException::class);
        PathsNormalizer::normalize(AbsolutePath::fromString($this->root), ['directory-link/Thing.php']);
    }
}
