<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use JsonException;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The corpus every hygiene guard judges, read once.
 *
 * Three guards ask the same two questions — which directories hold test
 * classes, and what does a given file actually declare — and a guard that
 * answers them for itself is a second copy of a map that can drift from the
 * first. That is the defect class this whole group exists to make loud, so the
 * answers live here and the guards share them.
 *
 * **Scope is the PSR-4 dev roots**, taken from `composer.json`: a root
 * registered for autoloading is judged from the moment it is registered, so a
 * new one cannot be added without also being covered. A declared root that is
 * not on disk is refused rather than skipped — skipping it would drop every
 * file under it from all three guards while the count of the remaining roots
 * kept the floors satisfied. The `classmap` entry is deliberately not a root —
 * its files are analyser input that does not comply with PSR-4 on purpose.
 *
 * **Which class PHPUnit keeps when a file declares several is not modelled
 * here, or anywhere in this group.** Only whether a given class appeared in a
 * listing, which is measured.
 *
 * **Declarations are read from the syntax tree, not from reflection.** The tree
 * still carries test files whose namespace does not follow their path — they
 * are the ones {@see NamespacePathAllowList} tracks, and `composer
 * dump-autoload -o` skips them outright — so loading a class by the name its
 * path implies would miss exactly the files most likely to be wrong. Parsing
 * reads every file the same way and needs no autoloader.
 */
final class TestTree
{
    /**
     * The attribute that makes a method a case.
     */
    public const TEST_ATTRIBUTE = 'PHPUnit\Framework\Attributes\Test';

    /**
     * Base classes that make a descendant a test class although the corpus
     * cannot see them. Measured, not assumed: these are the only two
     * out-of-corpus parents in this tree that are test bases at all — every
     * other one is an exception, a visitor, a logger or a rule. A base class
     * from some third namespace would leave its descendants unjudged, which is
     * why the list is here rather than implied.
     *
     * @var list<string>
     */
    private const FOREIGN_TEST_BASE_PREFIXES = ['PHPUnit\\', 'PHPStan\\Testing\\'];

    /** @var array<string, array{namespaces: list<string>, classes: list<string>, declarations: array<string, array{concrete: bool, declaresCase: bool, parents: list<string>}>}> */
    private static array $declarations = [];

    /** @var array<string, array{concrete: bool, declaresCase: bool, parents: list<string>}>|null */
    private static ?array $corpusIndex = null;

    public static function projectRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    public static function absolute(string $relativePath): string
    {
        return self::projectRoot() . '/' . $relativePath;
    }

    /**
     * The `autoload-dev` PSR-4 map, every entry of it.
     *
     * @return array<string, string> namespace prefix without its trailing
     *                               separator => project-relative directory
     *                               without its trailing slash
     */
    public static function autoloadDevRoots(): array
    {
        $contents = file_get_contents(self::absolute('composer.json'));
        if ($contents === false) {
            throw new LogicException('composer.json is not readable');
        }

        try {
            $composer = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException('composer.json is not readable JSON', 0, $exception);
        }

        if (!\is_array($composer) || !isset($composer['autoload-dev'])) {
            throw new LogicException('composer.json declares no autoload-dev section');
        }

        $autoloadDev = $composer['autoload-dev'];
        if (!\is_array($autoloadDev) || !isset($autoloadDev['psr-4']) || !\is_array($autoloadDev['psr-4'])) {
            throw new LogicException('composer.json declares no autoload-dev PSR-4 map');
        }

        $roots = [];
        foreach ($autoloadDev['psr-4'] as $prefix => $directory) {
            if (!\is_string($prefix) || !\is_string($directory)) {
                throw new LogicException('composer.json declares a non-string PSR-4 entry');
            }

            $root = rtrim($directory, '/');
            if (!is_dir(self::absolute($root))) {
                throw new LogicException(\sprintf(
                    'composer.json maps %s to %s, which is not a directory. Every file under a dev root that is '
                    . 'declared but absent is judged by no guard here, and the floors do not notice because the '
                    . 'other roots still satisfy them. Create the directory or drop the entry.',
                    $prefix,
                    $root,
                ));
            }

            $roots[rtrim($prefix, '\\')] = $root;
        }

        if ($roots === []) {
            throw new LogicException('composer.json declares no PSR-4 dev root');
        }

        ksort($roots);

        return $roots;
    }

    /** @return list<string> project-relative directories, without a trailing slash */
    public static function roots(): array
    {
        $roots = array_values(array_unique(array_values(self::autoloadDevRoots())));
        sort($roots);

        return $roots;
    }

    /**
     * Every `*Test.php` under the dev roots, named once.
     *
     * A root nested inside another is a shape {@see NamespacePathAllowList}
     * already resolves, so it reaches here too, and a file under both would
     * otherwise be counted twice — inflating the floors that are the only thing
     * standing between a scan that read nothing and a green guard.
     *
     * @return list<string> project-relative paths
     */
    public static function testFiles(): array
    {
        $files = [];

        foreach (self::roots() as $root) {
            foreach (self::testFilesIn($root) as $file) {
                $files[$file] = true;
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /** @return list<string> project-relative paths of every *Test.php under one directory */
    public static function testFilesIn(string $relativeDirectory): array
    {
        return self::filesIn($relativeDirectory, static fn(string $name): bool => str_ends_with($name, 'Test.php'));
    }

    /**
     * @param callable(string): bool $accepts judged on the file name alone
     *
     * @return list<string> project-relative paths, sorted
     */
    public static function filesIn(string $relativeDirectory, callable $accepts): array
    {
        $files = [];
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            self::absolute($relativeDirectory),
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        /** @var SplFileInfo $entry */
        foreach ($walk as $entry) {
            if ($entry->isFile() && $accepts($entry->getFilename())) {
                $files[] = self::relativePath($entry->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    public static function relativePath(string $path): string
    {
        $prefix = self::projectRoot() . '/';

        return str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : $path;
    }

    public static function read(string $relativePath): string
    {
        $contents = file_get_contents(self::absolute($relativePath));
        if ($contents === false) {
            throw new LogicException('Unable to read ' . $relativePath);
        }

        return $contents;
    }

    /**
     * What a file declares, cached per path because two guards ask for it.
     *
     * @return array{namespaces: list<string>, classes: list<string>, declarations: array<string, array{concrete: bool, declaresCase: bool, parents: list<string>}>}
     */
    public static function declarationsIn(string $relativePath): array
    {
        return self::$declarations[$relativePath] ??= self::parseDeclarations(
            $relativePath,
            self::read($relativePath),
        );
    }

    /**
     * What a file opens, declares, and inherits from.
     *
     * `declarations` carries, per class-like, the three facts a corpus-wide
     * answer is assembled from: whether it is a concrete class, whether it
     * declares a method that would run as a case, and which classes and traits
     * it takes members from. None of them answers on its own — a class whose
     * only cases come from an abstract base declares none itself — so the
     * answer is computed across the whole corpus by {@see looksExecutable()}.
     *
     * Trait `insteadof` and `as` adaptations are not read: they rename and
     * resolve members, and this only asks whether a trait brings a case at all.
     *
     * @return array{namespaces: list<string>, classes: list<string>, declarations: array<string, array{concrete: bool, declaresCase: bool, parents: list<string>}>}
     */
    public static function parseDeclarations(string $displayPath, string $code): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        if ($statements === null) {
            throw new LogicException('Unable to parse ' . $displayPath);
        }

        $collector = new class extends NodeVisitorAbstract {
            /** @var list<string> */
            public array $namespaces = [];

            /** @var list<array{name: string, node: ClassLike}> */
            public array $classes = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->namespaces[] = $node->name?->toString() ?? '';
                }

                if ($node instanceof ClassLike && $node->name !== null) {
                    $this->classes[] = [
                        'name' => $node->namespacedName?->toString() ?? $node->name->toString(),
                        'node' => $node,
                    ];
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($collector);
        $traverser->traverse($statements);

        // Names resolve as the traversal descends, so a class read on the way
        // in cannot be judged until the whole file has been walked.
        $classes = [];
        $declarations = [];
        foreach ($collector->classes as ['name' => $name, 'node' => $node]) {
            $classes[] = $name;
            $declarations[$name] = [
                'concrete' => $node instanceof Class_ && !$node->isAbstract(),
                'declaresCase' => self::declaresCase($node),
                'parents' => self::parentsOf($node),
            ];
        }

        return ['namespaces' => $collector->namespaces, 'classes' => $classes, 'declarations' => $declarations];
    }

    /**
     * The classes and traits a declaration takes members from.
     *
     * @return list<string>
     */
    private static function parentsOf(ClassLike $node): array
    {
        $parents = [];

        if ($node instanceof Class_ && $node->extends !== null) {
            $parents[] = $node->extends->toString();
        }

        if ($node instanceof Class_ || $node instanceof Trait_) {
            foreach ($node->getTraitUses() as $use) {
                foreach ($use->traits as $trait) {
                    $parents[] = $trait->toString();
                }
            }
        }

        return $parents;
    }

    private static function declaresCase(ClassLike $node): bool
    {
        foreach ($node->getMethods() as $method) {
            if (self::isExecutableMethod($method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every class in the corpus, by name, with what it declares and inherits.
     *
     * Built once from {@see testFiles()} alone: a handed-in source must never
     * reach it, or a probe class would join the map the real scan is judged
     * against.
     *
     * @return array<string, array{concrete: bool, declaresCase: bool, parents: list<string>}>
     */
    public static function corpusIndex(): array
    {
        if (self::$corpusIndex !== null) {
            return self::$corpusIndex;
        }

        $index = [];
        foreach (self::testFiles() as $path) {
            foreach (self::declarationsIn($path)['declarations'] as $name => $declaration) {
                $index[$name] = $declaration;
            }
        }

        return self::$corpusIndex = $index;
    }

    /**
     * Whether PHPUnit would find a case in this class, answered over an index
     * rather than over one file.
     *
     * This is a *triage* predicate and nothing else: it decides whether a class
     * the listing does not name deserves to be accused, so that a helper, a spy
     * or a fixture rule is not. A class the listing does name is answered for by
     * PHPUnit, and no answer here may overrule that.
     *
     * @param array<string, array{concrete: bool, declaresCase: bool, parents: list<string>}> $index
     */
    public static function looksExecutable(string $class, array $index): bool
    {
        $declaration = $index[$class] ?? null;
        if ($declaration === null || !$declaration['concrete']) {
            return false;
        }

        $ancestry = self::ancestryOf($class, $index);
        if (!self::reachesATestBase($ancestry, $index)) {
            return false;
        }

        foreach ($ancestry as $name) {
            if ($index[$name]['declaresCase'] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this class is one PHPUnit would treat as a test class at all.
     *
     * @param array<string, array{concrete: bool, declaresCase: bool, parents: list<string>}> $index
     */
    public static function reachesATestBaseClass(string $class, array $index): bool
    {
        return self::reachesATestBase(self::ancestryOf($class, $index), $index);
    }

    /**
     * @param array<string, array{concrete: bool, declaresCase: bool, parents: list<string>}> $index
     * @param array<string, bool> $seen
     *
     * @return list<string> the class itself and everything it takes members from
     */
    private static function ancestryOf(string $class, array $index, array $seen = []): array
    {
        if (isset($seen[$class])) {
            return [];
        }

        $seen[$class] = true;
        $ancestry = [$class];
        foreach ($index[$class]['parents'] ?? [] as $parent) {
            foreach (self::ancestryOf($parent, $index, $seen) as $name) {
                $ancestry[] = $name;
            }
        }

        return $ancestry;
    }

    /**
     * @param list<string> $ancestry
     * @param array<string, array{concrete: bool, declaresCase: bool, parents: list<string>}> $index
     */
    private static function reachesATestBase(array $ancestry, array $index): bool
    {
        foreach ($ancestry as $name) {
            if (isset($index[$name])) {
                continue;
            }

            foreach (self::FOREIGN_TEST_BASE_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether PHPUnit would run this method as a case. */
    public static function isExecutableMethod(ClassMethod $method): bool
    {
        if (!$method->isPublic() || $method->isAbstract()) {
            return false;
        }

        return self::carriesTestAttribute($method) || self::carriesLegacyTestPrefix($method);
    }

    /**
     * A public `test…` method runs without the attribute — measured in this
     * tree. A guard that knew only the attribute would call such a method
     * unreachable while PHPUnit was running it.
     */
    public static function carriesLegacyTestPrefix(ClassMethod $method): bool
    {
        return str_starts_with($method->name->toString(), 'test');
    }

    /**
     * `NameResolver` replaces each name node with a fully qualified one, so the
     * name read here is already resolved and an aliased import compares equal.
     * Reading a `resolvedName` attribute alongside it would be a second path
     * that never runs.
     */
    public static function carriesTestAttribute(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() === self::TEST_ATTRIBUTE) {
                    return true;
                }
            }
        }

        return false;
    }
}
