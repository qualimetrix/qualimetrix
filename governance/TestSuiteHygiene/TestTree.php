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

    /** @var array<string, array{namespaces: list<string>, classes: list<string>, executableClasses: list<string>}> */
    private static array $declarations = [];

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
     * @return array{namespaces: list<string>, classes: list<string>, executableClasses: list<string>}
     */
    public static function declarationsIn(string $relativePath): array
    {
        return self::$declarations[$relativePath] ??= self::parseDeclarations(
            $relativePath,
            self::read($relativePath),
        );
    }

    /**
     * The namespaces a file opens, the class-likes it declares, and which of
     * those PHPUnit would run.
     *
     * `executableClasses` asks what makes a method run rather than what a class
     * inherits from: a concrete class declaring a public, non-abstract method
     * that either carries `#[Test]` or is named `test…`. Both forms were measured
     * running in this tree. Inheritance is the wrong question here because the
     * base class may be anywhere, and a guard that resolved it would be loading
     * the very classes that cannot be autoloaded.
     *
     * Which class PHPUnit keeps when a file declares several is not modelled
     * anywhere in this group — only whether a given class was listed.
     *
     * @return array{namespaces: list<string>, classes: list<string>, executableClasses: list<string>}
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

        // Attribute names resolve as the traversal descends, so a class read on
        // the way in cannot be judged until the whole file has been walked.
        $classes = [];
        $executable = [];
        foreach ($collector->classes as ['name' => $name, 'node' => $node]) {
            $classes[] = $name;
            if (self::isExecutableClass($node)) {
                $executable[] = $name;
            }
        }

        return ['namespaces' => $collector->namespaces, 'classes' => $classes, 'executableClasses' => $executable];
    }

    /** Whether PHPUnit would discover at least one case in this declaration. */
    public static function isExecutableClass(ClassLike $node): bool
    {
        if (!$node instanceof Class_ || $node->isAbstract()) {
            return false;
        }

        foreach ($node->getMethods() as $method) {
            if (self::isExecutableMethod($method)) {
                return true;
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

    public static function carriesTestAttribute(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $resolved = $attribute->name->getAttribute('resolvedName');
                $name = $resolved instanceof Node\Name ? $resolved->toString() : $attribute->name->toString();
                if ($name === self::TEST_ATTRIBUTE) {
                    return true;
                }
            }
        }

        return false;
    }
}
