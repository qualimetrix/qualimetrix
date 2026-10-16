<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Composer\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraphBuilder;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;
use Qualimetrix\Infrastructure\Composer\DeclaredSupertypeReader;
use Qualimetrix\Infrastructure\Composer\InstalledExternalClassSpelling;
use RuntimeException;

#[CoversClass(InstalledExternalClassSpelling::class)]
final class InstalledExternalClassSpellingTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::removeTree($root);
        }
    }

    #[Test]
    public function itReturnsTheInstalledDeclarationSpellingOnlyForExactComposerPlacement(): void
    {
        $root = $this->project('Vendor\\Thing');
        $spelling = $this->spelling($root);

        self::assertSame('Vendor\\Thing', $spelling->declaredSpelling('Vendor\\Thing'));
        self::assertNull($spelling->declaredSpelling('Vendor\\THING'));
    }

    #[Test]
    public function itReadsTheCurrentInstallAfterTheMapIsReanchored(): void
    {
        $first = $this->project('Vendor\\Thing');
        $second = $this->project('Vendor\\THING');
        $map = new ComposerAutoloadMap(new ComposerManifestReader());
        $map->pointAt($first, [$first . '/src']);
        $spelling = new InstalledExternalClassSpelling(new DeclaredSupertypeReader($map));
        self::assertSame('Vendor\\Thing', $spelling->declaredSpelling('Vendor\\Thing'));

        $map->pointAt($second, [$second . '/src']);

        self::assertSame('Vendor\\THING', $spelling->declaredSpelling('Vendor\\Thing'));
    }

    #[Test]
    public function itCanonicalizesMixedExternalNamesAndKeepsTheOnlyUnplacedSpelling(): void
    {
        $root = $this->project('Vendor\\Thing');
        $builder = new DependencyGraphBuilder($this->spelling($root));
        $mixed = $builder->build(
            [
                self::dependency('App\\First', 'Vendor\\THING'),
                self::dependency('App\\Second', 'Vendor\\Thing'),
            ],
            [self::declaration('App\\First'), self::declaration('App\\Second')],
        );

        self::assertSame(
            ['Vendor\\Thing', 'Vendor\\Thing'],
            array_map(
                static fn(Dependency $dependency): string => $dependency->targetLogical()->toString(),
                $mixed->graph->getAllDependencies(),
            ),
        );
        self::assertSame('Vendor\\Thing', $mixed->mixedSpellings[0]->canonical);

        $unplaced = $builder->build(
            [self::dependency('App\\Only', 'Vendor\\THING')],
            [self::declaration('App\\Only')],
        );

        self::assertSame('Vendor\\THING', $unplaced->graph->getAllDependencies()[0]->targetLogical()->toString());
        self::assertSame([], $unplaced->mixedSpellings);
    }

    private function spelling(string $root): InstalledExternalClassSpelling
    {
        $map = new ComposerAutoloadMap(new ComposerManifestReader());
        $map->pointAt($root, [$root . '/src']);

        return new InstalledExternalClassSpelling(new DeclaredSupertypeReader($map));
    }

    private function project(string $declaration): string
    {
        $root = sys_get_temp_dir() . '/qmx_external_spelling_' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src', 0o777, true) && !is_dir($root . '/src')) {
            throw new RuntimeException('Cannot create the fixture project');
        }
        $this->roots[] = $root;
        file_put_contents($root . '/composer.json', (string) json_encode([
            'name' => 'fixture/project',
            'autoload' => ['psr-4' => ['Vendor\\' => 'src/']],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        file_put_contents(
            $root . '/src/Thing.php',
            "<?php\n\nnamespace Vendor;\n\nclass " . substr($declaration, \strlen('Vendor\\')) . " {}\n",
        );

        return $root;
    }

    private static function declaration(string $class): ClassLikeDeclaration
    {
        $path = self::declarationPath($class);

        return ClassLikeDeclaration::of($path, ClassType::Class_, false, false);
    }

    private static function dependency(string $source, string $target): Dependency
    {
        $path = self::declarationPath($source);

        return Dependency::ofKind(
            $path,
            new LogicalClassPath(SymbolPath::fromClassFqn($target)),
            DependencyType::New_,
            new Location($path->file, 1),
        );
    }

    private static function declarationPath(string $class): DeclarationPath
    {
        return DeclarationPath::of(
            SymbolPath::fromClassFqn($class),
            RelativePath::fromString('src/Fixture.php'),
            DeclarationOrdinal::fromRank(0),
        );
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entryPath = $path . '/' . $entry;
            is_dir($entryPath) ? self::removeTree($entryPath) : unlink($entryPath);
        }
        rmdir($path);
    }
}
