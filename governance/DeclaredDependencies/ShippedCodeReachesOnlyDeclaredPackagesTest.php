<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DeclaredDependencies;

use PhpParser\Node;
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
 * Whether the PHP a consumer installs can name a class the install does not
 * carry.
 *
 * `composer install --no-dev` is what a consumer runs, and it keeps only the
 * packages `require` names plus their transitive closure. Nothing read
 * `composer.json` and the shipped tree together, so a `use` of a package that
 * arrives only through `require-dev` was invisible: measured on
 * `src/Infrastructure/Git/GitClient.php`, which named
 * `Symfony\Component\Process\Process` while `symfony/process` reached this
 * tree from `friendsofphp/php-cs-fixer`. A `--no-dev` install of that tree
 * answered `bin/qmx check src/ --report=git:HEAD~1..HEAD` with
 * `Internal error: Class "Symfony\Component\Process\Process" not found` and
 * exit 1 — the first git-scoped run a consumer attempted, with no earlier
 * warning anywhere.
 *
 * The same read also refuses a package that is merely transitive on the
 * production side: `Amp\Cancellation` and `Amp\Sync\Channel` reached this tree
 * through `amphp/parallel` rather than through a declaration of their own.
 * Those do not break a `--no-dev` install today, and that is exactly why they
 * are worth a declaration: what keeps them installed is another package's
 * requirement, which its next major may drop without this repository
 * noticing.
 *
 * The judged tree is what the dist package ships and the consumer executes:
 * every `.php` file under `src/`, plus each entry of `composer.json`'s `bin`
 * (extensionless, so a directory walk alone would miss `bin/qmx`).
 *
 * Attribution is read out of `vendor/composer/installed.json` — each
 * package's own `psr-4`/`psr-0` prefixes, longest prefix winning — rather than
 * out of a table here, so a package that renames or adds a namespace is
 * followed rather than re-declared. A namespace that no prefix claims is a
 * REFUSAL, not a skip: the one name in this tree that no installed package
 * owns, `Composer\InstalledVersions`, is served by Composer's own runtime and
 * has to be declared as `composer-runtime-api`, and nothing about a namespace
 * being unattributable makes it safe. {@see self::RUNTIME_PROVIDERS} is the
 * single row that encodes it.
 *
 * Its known false refusal is a core-extension namespace used as a type — a
 * `Random\Randomizer` parameter, say, whose classes belong to no Composer
 * package at all. That would fail here and needs a row of its own, which is
 * the fail-closed direction on purpose: a control that guessed such a name
 * was fine would have had to guess `Symfony\Component\Process\` was fine too.
 *
 * What this read does not see: a class named by a string — `class_exists()`,
 * a service id, a DI configurator argument, a callable array — or reached by
 * reflection. Those are namespaces nothing here can distinguish from prose,
 * and the shipped tree spells plenty of them (the built-in class census under
 * `Analysis\Evidence`, for one), so scanning string literals would refuse
 * dozens of names that are not imports at all.
 */
final class ShippedCodeReachesOnlyDeclaredPackagesTest extends TestCase
{
    /**
     * Namespaces the Composer runtime itself serves. No entry of
     * `installed.json` owns them, because they are not shipped by a package:
     * `vendor/composer/InstalledVersions.php` is written by Composer during
     * install, and the declaration that promises it is the virtual
     * `composer-runtime-api`.
     *
     * @var array<string, string> psr-4 prefix => the package to declare
     */
    private const array RUNTIME_PROVIDERS = ['Composer\\' => 'composer-runtime-api'];

    #[Test]
    public function itReachesOnlyPackagesTheInstallDeclares(): void
    {
        $root = self::repositoryRoot();
        $verdict = self::judge(
            self::shippedFiles($root),
            $root,
            self::prefixMap($root . '/vendor/composer/installed.json'),
            self::declaredPackages($root . '/composer.json'),
            self::ownRoots($root . '/composer.json'),
        );

        self::assertSame([], $verdict['refusals'], \sprintf(
            "%d vendor namespace(s) in the shipped tree resolve to a package composer.json does not require:\n%s",
            \count($verdict['refusals']),
            implode("\n", $verdict['refusals']),
        ));
    }

    /**
     * Proves the read above judged a populated tree. A scan that resolved
     * nothing — a broken walk, a parser that returned no node, a prefix map
     * read out of a file that moved — reports no refusal either, and would
     * pass for as long as it stayed broken.
     *
     * The anchors are named rather than counted: a count drifts with every
     * file added to `src/`, while a tree that stopped seeing `nikic/php-parser`
     * or `symfony/console` has stopped seeing anything at all.
     */
    #[Test]
    public function itReadsTheShippedTreeItJudges(): void
    {
        $root = self::repositoryRoot();
        $files = self::shippedFiles($root);

        self::assertGreaterThan(500, \count($files));
        self::assertContains($root . '/bin/qmx', $files, 'The console entry point is outside src/ and ships.');

        $verdict = self::judge(
            $files,
            $root,
            self::prefixMap($root . '/vendor/composer/installed.json'),
            self::declaredPackages($root . '/composer.json'),
            self::ownRoots($root . '/composer.json'),
        );

        foreach (['nikic/php-parser', 'symfony/console', 'psr/log', 'amphp/parallel'] as $anchor) {
            self::assertContains($anchor, $verdict['packages'], \sprintf(
                'Resolved no namespace to %s, so the scan is reading less than the tree contains.',
                $anchor,
            ));
        }
    }

    /**
     * The defect this control exists for, planted: a file naming a package
     * that is installed but undeclared must be refused, and the refusal must
     * carry the three things a reader needs — which file, which namespace,
     * which package to declare.
     */
    #[Test]
    public function itRefusesAnImportOfAnUndeclaredPackage(): void
    {
        $root = self::repositoryRoot();
        $tree = self::plantedTree(<<<'PLANTED'
            <?php

            namespace Planted;

            use Symfony\Component\Process\Process;

            final class RunsGit
            {
                public function run(): Process
                {
                    return new Process(['git', 'status']);
                }
            }
            PLANTED);

        $refusals = self::judge(
            [$tree . '/Planted.php'],
            $tree,
            self::prefixMap($root . '/vendor/composer/installed.json'),
            ['php', 'symfony/console'],
            ['Planted\\'],
        )['refusals'];

        self::assertCount(1, $refusals, implode("\n", $refusals));
        self::assertStringContainsString('Planted.php', $refusals[0]);
        self::assertStringContainsString('Symfony\Component\Process\Process', $refusals[0]);
        self::assertStringContainsString('symfony/process', $refusals[0]);
    }

    /**
     * Fail-closed, planted: a namespace no installed package claims is a
     * refusal naming the unattributable namespace, never a name the scan
     * quietly walks past because it could not place it.
     */
    #[Test]
    public function itRefusesANamespaceNoInstalledPackageProvides(): void
    {
        $root = self::repositoryRoot();
        $tree = self::plantedTree(<<<'PLANTED'
            <?php

            namespace Planted;

            use Nowhere\Land\Contraption;

            final class UsesNothingInstalled
            {
                public function make(): Contraption
                {
                    return new Contraption();
                }
            }
            PLANTED);

        $refusals = self::judge(
            [$tree . '/Planted.php'],
            $tree,
            self::prefixMap($root . '/vendor/composer/installed.json'),
            ['php'],
            ['Planted\\'],
        )['refusals'];

        self::assertCount(1, $refusals, implode("\n", $refusals));
        self::assertStringContainsString('Nowhere\Land\Contraption', $refusals[0]);
        self::assertStringContainsString('no installed package', $refusals[0]);
    }

    /**
     * The other half of the pair: a planted tree whose every import is
     * declared must produce no refusal, so the two cases above are evidence
     * of what the control rejects rather than of a control that rejects
     * everything.
     *
     * The import is deliberately never used, because that is the one shape a
     * `use`-line grep sees and a name-resolution pass alone does not; this
     * case also fails if the collector stops reading `use` statements and
     * starts reporting a resolved-name count of zero for the file.
     */
    #[Test]
    public function itAcceptsATreeWhoseImportsAreAllDeclared(): void
    {
        $root = self::repositoryRoot();
        $tree = self::plantedTree(<<<'PLANTED'
            <?php

            namespace Planted;

            use Symfony\Component\Console\Command\Command;

            final class NamesOnlyWhatIsDeclared
            {
            }
            PLANTED);

        $verdict = self::judge(
            [$tree . '/Planted.php'],
            $tree,
            self::prefixMap($root . '/vendor/composer/installed.json'),
            ['php', 'symfony/console'],
            ['Planted\\'],
        );

        self::assertSame([], $verdict['refusals'], implode("\n", $verdict['refusals']));
        self::assertSame(['symfony/console'], $verdict['packages']);
    }

    /**
     * Judges one file list against one prefix map and one `require` section.
     *
     * @param list<string> $files absolute paths
     * @param array<string, string> $prefixes namespace prefix => package name
     * @param list<string> $declared package names from `require`
     * @param list<string> $ownRoots namespace prefixes this repository declares as its own
     *
     * @return array{refusals: list<string>, packages: list<string>}
     */
    private static function judge(
        array $files,
        string $treeRoot,
        array $prefixes,
        array $declared,
        array $ownRoots,
    ): array {
        $prefixes += self::RUNTIME_PROVIDERS;
        $ordered = array_keys($prefixes);
        usort($ordered, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        $refusals = [];
        $packages = [];

        foreach (self::namespacesReached($files) as $file => $names) {
            $relative = str_starts_with($file, $treeRoot . '/')
                ? substr($file, \strlen($treeRoot) + 1)
                : $file;

            foreach ($names as $name) {
                foreach ($ownRoots as $own) {
                    if (str_starts_with($name, $own)) {
                        continue 2;
                    }
                }

                $package = null;
                foreach ($ordered as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $package = $prefixes[$prefix];

                        break;
                    }
                }

                if ($package === null) {
                    $refusals[] = \sprintf(
                        '%s reaches %s, which no installed package provides — attribute it, or add its provider to RUNTIME_PROVIDERS.',
                        $relative,
                        $name,
                    );

                    continue;
                }

                if (!\in_array($package, $declared, true)) {
                    $refusals[] = \sprintf(
                        '%s reaches %s — declare %s in composer.json require.',
                        $relative,
                        $name,
                        $package,
                    );

                    continue;
                }

                $packages[$package] = true;
            }
        }

        sort($refusals);
        $resolved = array_keys($packages);
        sort($resolved);

        return ['refusals' => $refusals, 'packages' => $resolved];
    }

    /**
     * Every namespaced name each file reaches, whether it is written out in
     * full, imported and used, or imported and left unused. Name resolution
     * covers the first two; the `use` statements are read separately because
     * an import nothing references resolves to no name at all, and that is
     * the shape a plain grep over `use` lines does see.
     *
     * @param list<string> $files
     *
     * @return array<string, list<string>> file => every namespaced name it reaches
     */
    private static function namespacesReached(array $files): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $reached = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source, $file);

            $statements = $parser->parse($source);
            self::assertIsArray($statements, $file);

            $collector = new class extends NodeVisitorAbstract {
                /** @var array<string, true> */
                public array $names = [];

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Node\Name\FullyQualified) {
                        $this->names[$node->toString()] = true;
                    }

                    if ($node instanceof Node\Stmt\Use_) {
                        foreach ($node->uses as $use) {
                            $this->names[$use->name->toString()] = true;
                        }
                    }

                    if ($node instanceof Node\Stmt\GroupUse) {
                        foreach ($node->uses as $use) {
                            $this->names[$node->prefix->toString() . '\\' . $use->name->toString()] = true;
                        }
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($collector);
            $traverser->traverse($statements);

            $vendor = [];
            foreach (array_keys($collector->names) as $name) {
                if (str_contains($name, '\\')) {
                    $vendor[] = $name;
                }
            }

            sort($vendor);
            $reached[$file] = $vendor;
        }

        return $reached;
    }

    /**
     * @return array<string, string> namespace prefix => package name
     */
    private static function prefixMap(string $installedJson): array
    {
        $contents = file_get_contents($installedJson);
        self::assertIsString($contents, $installedJson);

        $installed = json_decode($contents, true);
        self::assertIsArray($installed);
        self::assertArrayHasKey('packages', $installed);
        self::assertIsArray($installed['packages']);

        $map = [];
        foreach ($installed['packages'] as $package) {
            self::assertIsArray($package);
            self::assertIsString($package['name'] ?? null);

            foreach (['psr-4', 'psr-0'] as $standard) {
                $declarations = $package['autoload'][$standard] ?? [];
                if (!\is_array($declarations)) {
                    continue;
                }

                foreach (array_keys($declarations) as $prefix) {
                    // The empty prefix claims every namespace; a package that
                    // declares one (a classmap-shaped autoload written as
                    // psr-4) would swallow the whole judgement.
                    if (!\is_string($prefix) || $prefix === '') {
                        continue;
                    }

                    $map[$prefix] ??= $package['name'];
                }
            }
        }

        self::assertNotSame([], $map, 'Read no autoload prefix out of ' . $installedJson);

        return $map;
    }

    /**
     * @return list<string>
     */
    private static function declaredPackages(string $composerJson): array
    {
        $contents = file_get_contents($composerJson);
        self::assertIsString($contents, $composerJson);

        $manifest = json_decode($contents, true);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['require'] ?? null);

        return array_map(strval(...), array_keys($manifest['require']));
    }

    /**
     * The namespace prefixes this repository autoloads out of its own tree.
     * Read rather than written down, so renaming the PSR-4 root cannot leave
     * this control judging its own classes as an undeclared dependency.
     *
     * @return list<string>
     */
    private static function ownRoots(string $composerJson): array
    {
        $contents = file_get_contents($composerJson);
        self::assertIsString($contents, $composerJson);

        $manifest = json_decode($contents, true);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['autoload']['psr-4'] ?? null);

        $roots = array_map(strval(...), array_keys($manifest['autoload']['psr-4']));
        self::assertNotSame([], $roots, 'Read no PSR-4 root out of ' . $composerJson);

        return $roots;
    }

    /**
     * Every PHP file the dist package ships and the consumer runs: the PSR-4
     * root, plus the console entry points, which carry no `.php` suffix and
     * would therefore be invisible to the walk.
     *
     * @return list<string>
     */
    private static function shippedFiles(string $root): array
    {
        $files = [];
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/src',
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        /** @var SplFileInfo $entry */
        foreach ($walk as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        $contents = file_get_contents($root . '/composer.json');
        self::assertIsString($contents);
        $manifest = json_decode($contents, true);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['bin'] ?? null);

        foreach ($manifest['bin'] as $entryPoint) {
            self::assertIsString($entryPoint);
            $files[] = $root . '/' . $entryPoint;
        }

        sort($files);

        return $files;
    }

    private static function plantedTree(string $source): string
    {
        $directory = sys_get_temp_dir() . '/qmx-declared-dependencies-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o777, true), $directory);
        self::assertIsInt(file_put_contents($directory . '/Planted.php', $source));

        register_shutdown_function(static function () use ($directory): void {
            @unlink($directory . '/Planted.php');
            @rmdir($directory);
        });

        return $directory;
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
