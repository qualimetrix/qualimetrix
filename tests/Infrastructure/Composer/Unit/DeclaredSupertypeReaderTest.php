<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Composer\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;
use Qualimetrix\Infrastructure\Composer\DeclaredClassLikeFactExtractor;
use Qualimetrix\Infrastructure\Composer\DeclaredSupertypeReader;
use RuntimeException;

#[CoversClass(DeclaredSupertypeReader::class)]
#[CoversClass(DeclaredClassLikeFactExtractor::class)]
final class DeclaredSupertypeReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx_supertype_reader_' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/src', 0o777, true) && !is_dir($this->root . '/src')) {
            throw new RuntimeException('Cannot create the fixture project');
        }
        $this->writeManifest();
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    #[Test]
    public function itReadsResolvedClassFactsWithoutLoadingTheDeclaration(): void
    {
        $this->write('src/Child.php', <<<'PHP'
            <?php

            namespace Fixture;

            use Vendor\Base as ParentClass;
            use Vendor\Contract as ContractAlias;
            use Vendor\Reusable as TraitAlias;

            class Child extends ParentClass implements ContractAlias
            {
                use TraitAlias {
                    render as __toString;
                }

                public function __toString(): string
                {
                    return '';
                }
            }
            PHP);

        $facts = $this->reader()->supertypesOf('Fixture\Child');

        self::assertTrue($facts->placed);
        self::assertSame('Fixture\Child', $facts->declaredSpelling);
        self::assertSame(ClassType::Class_, $facts->classType);
        self::assertSame('Vendor\Base', $facts->parent);
        self::assertSame(['Vendor\Contract'], $facts->interfaces);
        self::assertSame(['Vendor\Reusable'], $facts->traits);
        self::assertTrue($facts->declaresToString);
        self::assertTrue($facts->aliasesTraitMethodAsToString);
        self::assertNull($facts->unreadable);
        self::assertFalse(class_exists('Fixture\Child', false));
    }

    #[Test]
    public function itKeepsInterfaceKindForImplicitStringableSemantics(): void
    {
        $this->write('src/StringContract.php', <<<'PHP'
            <?php

            namespace Fixture;

            interface StringContract
            {
                public function __toString(): string;
            }
            PHP);

        $facts = $this->reader()->supertypesOf('Fixture\StringContract');

        self::assertSame(ClassType::Interface_, $facts->classType);
        self::assertTrue($facts->declaresToString);
    }

    #[Test]
    public function itDistinguishesNotPlacedUnreadableAndConditionalDeclarations(): void
    {
        $this->write('src/Broken.php', "<?php\n\nnamespace Fixture;\n\nclass Broken extends {\n");
        $this->write('src/Conditional.php', "<?php\n\nnamespace Fixture;\n\nif (true) { class Conditional {} }\n");
        $reader = $this->reader();
        self::assertFalse($reader->supertypesOf('Fixture\Missing')->placed);

        $broken = $reader->supertypesOf('Fixture\Broken');
        self::assertTrue($broken->placed);
        self::assertNull($broken->classType);
        self::assertNotNull($broken->unreadable);

        $conditional = $reader->supertypesOf('Fixture\Conditional');
        self::assertTrue($conditional->placed);
        self::assertNull($conditional->classType);
        self::assertNotNull($conditional->unreadable);
    }

    #[Test]
    public function itReadsTheCurrentTreeAfterTheMapIsReanchored(): void
    {
        $this->write('src/Child.php', "<?php\n\nnamespace Fixture;\n\nclass Child extends First {}\n");
        $map = new ComposerAutoloadMap(new ComposerManifestReader());
        $map->pointAt($this->root, [$this->root . '/src']);
        $reader = new DeclaredSupertypeReader($map);
        self::assertSame('Fixture\First', $reader->supertypesOf('Fixture\Child')->parent);

        $second = sys_get_temp_dir() . '/qmx_supertype_reader_second_' . bin2hex(random_bytes(6));
        mkdir($second . '/src', 0o777, true);
        file_put_contents($second . '/composer.json', (string) json_encode([
            'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
        ]));
        file_put_contents($second . '/src/Child.php', "<?php\n\nnamespace Fixture;\n\nclass Child extends Second {}\n");

        try {
            $map->pointAt($second, [$second . '/src']);
            self::assertSame('Fixture\Second', $reader->supertypesOf('Fixture\Child')->parent);
        } finally {
            self::removeTree($second);
        }
    }

    private function reader(): DeclaredSupertypeReader
    {
        $map = new ComposerAutoloadMap(new ComposerManifestReader());
        $map->pointAt($this->root, [$this->root . '/src']);

        return new DeclaredSupertypeReader($map);
    }

    private function writeManifest(): void
    {
        file_put_contents($this->root . '/composer.json', (string) json_encode([
            'name' => 'fixture/project',
            'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }
        file_put_contents($path, $contents);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $entryPath = $path . '/' . $entry;
                is_dir($entryPath) ? self::removeTree($entryPath) : unlink($entryPath);
            }
        }
        rmdir($path);
    }
}
