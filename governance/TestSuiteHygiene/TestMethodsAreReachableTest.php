<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use JsonException;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A test method is reachable only when its name and its attribute agree.
 *
 * PHPUnit discovers a case by the `#[Test]` attribute alone, while a reader
 * recognises one by the `itXxx` name. Either half on its own is silent: a
 * method named `itXxx` without the attribute is never called and nothing says
 * so, and a method carrying the attribute under another name runs but reads as
 * a helper. Both halves are refused here, because both were found in the tree
 * — the first as a CLI-alias contract that had never executed since the day it
 * was written.
 *
 * **The attribute is read from the syntax tree, not from reflection.** The tree
 * still carries test files whose namespace does not follow their path, so
 * loading a class by its expected name would skip exactly the files most likely
 * to be wrong. Parsing reads every file the same way and needs no autoloader.
 *
 * **Scope is the PSR-4 dev roots**, taken from `composer.json`. That is what
 * makes a `*Test.php` a test class rather than a fixture: the corpus files the
 * finding gate analyses, and the tooling helpers under `scripts/`, live outside
 * those roots and are deliberately not judged by this rule. A root registered
 * for autoloading is in scope from the moment it is registered, so a new one
 * cannot be added without also being covered.
 */
final class TestMethodsAreReachableTest extends TestCase
{
    private const TEST_ATTRIBUTE = 'PHPUnit\Framework\Attributes\Test';

    #[Test]
    public function itFindsNoTestMethodWhoseNameAndAttributeDisagree(): void
    {
        $violations = [];

        foreach (self::testFiles() as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents, $path);

            foreach (self::violationsIn(self::relativePath($path), $contents) as $violation) {
                $violations[] = $violation;
            }
        }

        self::assertSame([], $violations, \sprintf(
            "%d test method(s) are named or attributed so that PHPUnit and the reader disagree.\n"
            . "Give the method the #[Test] attribute, or rename it out of the itXxx form:\n%s",
            \count($violations),
            implode("\n", $violations),
        ));
    }

    /**
     * Proves the rule refuses at all, in both directions, without waiting for
     * the tree to break: a scan that reads nothing reports nothing either, and
     * would stay green forever.
     */
    #[Test]
    public function itRefusesEachDirectionOnSourceItIsGiven(): void
    {
        $source = <<<'PHP'
            <?php

            namespace Acme\Probe;

            use PHPUnit\Framework\Attributes\Test as RenamedAttribute;

            final class ProbeTest
            {
                #[RenamedAttribute]
                public function itRuns(): void
                {
                }

                public function itIsNeverCalled(): void
                {
                }

                #[RenamedAttribute]
                public function runsUnderTheWrongName(): void
                {
                }

                public function provideCases(): array
                {
                    return [];
                }

                private function itIsAPrivateHelper(): void
                {
                }
            }
            PHP;

        $violations = self::violationsIn('probe/ProbeTest.php', $source);

        self::assertSame(
            [
                'probe/ProbeTest.php:14 Acme\Probe\ProbeTest::itIsNeverCalled()'
                . ' is named itXxx but carries no #[Test] attribute, so PHPUnit never calls it',
                'probe/ProbeTest.php:18 Acme\Probe\ProbeTest::runsUnderTheWrongName()'
                . ' carries #[Test] but is not named itXxx',
            ],
            $violations,
        );
    }

    /**
     * Proves the scan reaches every root it claims, and names the two that
     * exist today as a floor rather than a ceiling: a root added to
     * `autoload-dev` joins the scan on its own, but neither of these may
     * silently drop out of it.
     */
    #[Test]
    public function itReadsEveryTestRootItJudges(): void
    {
        $perRoot = [];

        foreach (self::testRoots() as $root) {
            $perRoot[$root] = \count(self::testFilesIn(self::projectRoot() . '/' . $root));
        }

        self::assertArrayHasKey('tests', $perRoot);
        self::assertArrayHasKey('governance', $perRoot);
        self::assertGreaterThan(500, $perRoot['tests']);
        self::assertGreaterThan(0, $perRoot['governance']);
        self::assertGreaterThan(500, \count(self::testFiles()));
    }

    /**
     * @return list<string> one line per offending method, each naming the file,
     *                      the line, the class and the method
     */
    private static function violationsIn(string $displayPath, string $code): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        if ($statements === null) {
            throw new LogicException('Unable to parse ' . $displayPath);
        }

        $collector = new class extends NodeVisitorAbstract {
            /** @var list<array{class: string, method: ClassMethod}> */
            public array $methods = [];

            public function enterNode(Node $node): null
            {
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }

                $name = $node->namespacedName?->toString() ?? $node->name->toString();
                foreach ($node->getMethods() as $method) {
                    if ($method->isPublic()) {
                        $this->methods[] = ['class' => $name, 'method' => $method];
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($collector);
        $traverser->traverse($statements);

        $violations = [];
        foreach ($collector->methods as ['class' => $class, 'method' => $method]) {
            $name = $method->name->toString();
            $named = preg_match('/^it[A-Z]/', $name) === 1;
            $attributed = self::carriesTestAttribute($method);
            if ($named === $attributed) {
                continue;
            }

            $violations[] = \sprintf(
                '%s:%d %s::%s() %s',
                $displayPath,
                $method->getStartLine(),
                $class,
                $name,
                $named
                    ? 'is named itXxx but carries no #[Test] attribute, so PHPUnit never calls it'
                    : 'carries #[Test] but is not named itXxx',
            );
        }

        return $violations;
    }

    private static function carriesTestAttribute(ClassMethod $method): bool
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

    /** @return list<string> */
    private static function testFiles(): array
    {
        $files = [];

        foreach (self::testRoots() as $root) {
            foreach (self::testFilesIn(self::projectRoot() . '/' . $root) as $file) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private static function testFilesIn(string $directory): array
    {
        $files = [];
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $directory,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        /** @var SplFileInfo $entry */
        foreach ($walk as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), 'Test.php')) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> PSR-4 dev roots, relative to the project root, without a trailing slash */
    private static function testRoots(): array
    {
        $contents = file_get_contents(self::projectRoot() . '/composer.json');
        self::assertIsString($contents);

        try {
            /** @var array{'autoload-dev': array{'psr-4': array<string, string>}} $composer */
            $composer = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException('composer.json is not readable JSON', 0, $exception);
        }

        $roots = [];
        foreach ($composer['autoload-dev']['psr-4'] as $directory) {
            $root = rtrim($directory, '/');
            if (is_dir(self::projectRoot() . '/' . $root)) {
                $roots[] = $root;
            }
        }
        sort($roots);

        return array_values(array_unique($roots));
    }

    private static function relativePath(string $path): string
    {
        return str_starts_with($path, self::projectRoot() . '/')
            ? substr($path, \strlen(self::projectRoot()) + 1)
            : $path;
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
