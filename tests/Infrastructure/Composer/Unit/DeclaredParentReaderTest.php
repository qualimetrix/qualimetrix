<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Composer\Unit;

use LogicException;
use PhpParser\ErrorHandler;
use PhpParser\Parser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap;
use Qualimetrix\Infrastructure\Composer\DeclaredParentReader;
use Qualimetrix\Tests\Analysis\Evidence\Design\Support\UnloadableClassProbe;
use RuntimeException;

#[CoversClass(DeclaredParentReader::class)]
final class DeclaredParentReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx_parent_reader_' . bin2hex(random_bytes(6));

        if (!mkdir($this->root . '/src', 0o777, true) && !is_dir($this->root . '/src')) {
            throw new RuntimeException('Cannot create the fixture project');
        }

        $this->write('composer.json', (string) json_encode([
            'name' => 'fixture/project',
            'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    #[Test]
    public function itReadsTheParentAClassDeclares(): void
    {
        $this->write('src/Child.php', "<?php\n\nnamespace Fixture;\n\nclass Child extends \\Fixture\\Base {}\n");

        $lookup = $this->reader()->parentOf('Fixture\\Child');

        self::assertTrue($lookup->placed);
        self::assertSame('Fixture\\Base', $lookup->parent);
    }

    /**
     * Most parents are written relatively, so this is the common path rather
     * than an edge. Reading `extends Base` as the bare `Base` places nothing
     * and reads as a broken chain — which is how the prototype behind this
     * design first mis-measured the corpus, twice over.
     */
    #[Test]
    public function itResolvesAParentWrittenWithoutItsNamespace(): void
    {
        $this->write('src/Child.php', "<?php\n\nnamespace Fixture;\n\nclass Child extends Base {}\n");

        self::assertSame('Fixture\\Base', $this->reader()->parentOf('Fixture\\Child')->parent);
    }

    #[Test]
    public function itResolvesAParentImportedUnderAnAlias(): void
    {
        $this->write('src/Child.php', "<?php\n\nnamespace Fixture;\n\nuse Other\\Thing as Aliased;\n\nclass Child extends Aliased {}\n");

        self::assertSame('Other\\Thing', $this->reader()->parentOf('Fixture\\Child')->parent);
    }

    #[Test]
    public function itCallsAClassWithNoParentARoot(): void
    {
        $this->write('src/Base.php', "<?php\n\nnamespace Fixture;\n\nclass Base {}\n");

        $lookup = $this->reader()->parentOf('Fixture\\Base');

        self::assertTrue($lookup->placed);
        self::assertNull($lookup->parent);
    }

    #[Test]
    public function itReadsAnInterfacesFirstParent(): void
    {
        $this->write('src/Contract.php', "<?php\n\nnamespace Fixture;\n\ninterface Contract extends \\Fixture\\Root {}\n");

        self::assertSame('Fixture\\Root', $this->reader()->parentOf('Fixture\\Contract')->parent);
    }

    #[Test]
    public function itRefusesToPlaceANameNoFileDeclares(): void
    {
        self::assertFalse($this->reader()->parentOf('Fixture\\Missing')->placed);
    }

    /**
     * A file the map places but which declares something else is a disagreement
     * between the install and the sources, and that is not a root.
     */
    #[Test]
    public function itRefusesAFileThatDeclaresAnotherName(): void
    {
        $this->write('src/Child.php', "<?php\n\nnamespace Fixture;\n\nclass SomethingElse {}\n");

        self::assertFalse($this->reader()->parentOf('Fixture\\Child')->placed);
    }

    #[Test]
    public function itRefusesAFileThisPhpCannotParse(): void
    {
        $this->write('src/Child.php', "<?php\n\nnamespace Fixture;\n\nclass Child extends { !!!\n");

        self::assertFalse($this->reader()->parentOf('Fixture\\Child')->placed);
    }

    #[Test]
    public function itRefusesAFileWhoseImportedAliasesCannotBeResolved(): void
    {
        $this->write('src/Child.php', <<<'PHP'
            <?php

            namespace Fixture;

            use First\Package\Base as ParentClass;
            use Second\Package\Base as ParentClass;

            class Child extends ParentClass {}
            PHP);

        self::assertFalse($this->reader()->parentOf('Fixture\\Child')->placed);
    }

    #[Test]
    public function itDoesNotSuppressUnexpectedParserFailures(): void
    {
        $this->write('src/Child.php', "<?php\n\nnamespace Fixture;\n\nclass Child {}\n");

        $parser = new class implements Parser {
            public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
            {
                throw new LogicException('Injected parser failure');
            }

            public function getTokens(): array
            {
                return [];
            }
        };
        $map = new ComposerAutoloadMap();
        $map->pointAt($this->root, [$this->root . '/src']);
        $reader = new DeclaredParentReader($map, $parser);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Injected parser failure');

        $reader->parentOf('Fixture\\Child');
    }

    /**
     * The claim the campaign is for. The probe counts autoloader queries, which
     * is the only thing that separates "was not asked" from "was asked and
     * threw": both leave the same answer behind.
     */
    #[Test]
    public function itExecutesNothingWhileReading(): void
    {
        $probe = UnloadableClassProbe::start();

        try {
            $this->write('src/Child.php', \sprintf("<?php\n\nnamespace Fixture;\n\nclass Child extends \\%s {}\n", $probe->childFqcn()));

            $this->reader()->parentOf('Fixture\\Child');

            self::assertSame(0, $probe->queryCount(), 'The reader consulted an autoloader');
            self::assertFalse($probe->failedOnTheMissingParent(), 'A load was attempted, so foreign code ran');
            self::assertFalse(class_exists($probe->childFqcn(), false), 'The reader declared a class it was only meant to read');
        } finally {
            $probe->stop();
        }
    }

    /**
     * Aiming the reader at a second tree must not answer from the first.
     *
     * Found by review, not by this suite: the reader is a container singleton,
     * so a second run in one process -- a test suite, or a command that
     * analyses twice -- would otherwise read the tree it is no longer pointed
     * at.
     */
    #[Test]
    public function itForgetsTheTreeItWasPointedAtBefore(): void
    {
        $this->write('src/Child.php', "<?php\n\nnamespace Fixture;\n\nclass Child extends \\Fixture\\First {}\n");

        $map = new ComposerAutoloadMap();
        $reader = new DeclaredParentReader($map);
        $reader->pointAt($this->root, [$this->root . '/src']);

        self::assertSame('Fixture\\First', $reader->parentOf('Fixture\\Child')->parent);

        // The same name, a different tree, a different answer.
        $second = sys_get_temp_dir() . '/qmx_parent_reader_second_' . bin2hex(random_bytes(6));
        mkdir($second . '/src', 0o777, true);
        file_put_contents($second . '/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['Fixture\\' => 'src/']]]));
        file_put_contents($second . '/src/Child.php', "<?php\n\nnamespace Fixture;\n\nclass Child extends \\Fixture\\Second {}\n");

        try {
            $reader->pointAt($second, [$second . '/src']);

            self::assertSame('Fixture\\Second', $reader->parentOf('Fixture\\Child')->parent);
        } finally {
            self::removeTree($second);
        }
    }

    private function reader(): DeclaredParentReader
    {
        $map = new ComposerAutoloadMap();
        $map->pointAt($this->root, [$this->root . '/src']);

        return new DeclaredParentReader($map);
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
