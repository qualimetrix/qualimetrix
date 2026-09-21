<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DeclaredDependencies;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
 * package's own `psr-4`/`psr-0` prefixes, longest prefix winning, and among
 * prefixes of equal length the first the file lists — rather than out of a
 * table here, so a package that renames or adds a namespace is followed
 * rather than re-declared. That tie-break settles real entries of this
 * `installed.json`, not a hypothetical one, and
 * {@see self::itAttributesASharedPrefixToThePackageListedFirst} pins which
 * side of it wins. A namespace that no prefix claims is a
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
 * # Two spellings, two stances
 *
 * A class can be named two ways, and the two do not carry the same
 * confidence.
 *
 * A name the language resolved — an import, a written-out reference, a
 * `Thing::class` — can only be a type. An unattributable one is a refusal, as
 * above.
 *
 * A quoted string shaped like a class name is read too, but loosely: it is
 * judged only where an installed package owns the name, and ignored
 * otherwise. That asymmetry is the one place this group departs from
 * "unattributable is a refusal", and the reason is what the shipped tree
 * contains. It spells many such strings that are not reaches at all — a
 * census of PHP's own built-in classes, and tail fragments of this
 * repository's own rule names — and refusing those would redden a clean tree
 * while finding nothing. Judging only the attributable part costs nothing
 * here and still refuses the case this channel was added for: code reaching
 * an undeclared package through `class_exists()`, a service id or a callable
 * array.
 *
 * "Attributable" is narrower than "real", and the gap is the price of the
 * loose stance rather than a claim against it. A quoted name is judged only
 * where the prefix map places it, so a package absent from the development
 * install, and a package that autoloads by classmap and therefore declares
 * no prefix, are both outside the fence even though the first is the purest
 * form of reaching what the consumer will not have. `BOUNDARY` prints that
 * boundary with the verdict, and the two say the same thing on purpose.
 * {@see self::itJudgesAStringOnlyWhereAnInstalledPackageOwnsIt} plants the
 * shapes in one file and pins which are refused.
 *
 * An earlier version left this channel unread and asserted the silence,
 * arguing that judging strings would cost dozens of false refusals. That
 * number is the price of reading strings fail-closed — the stance this
 * paragraph rejects — so it was never the price of the channel. Under the
 * stance actually used, the cost on this tree is zero. What settles it is
 * that a control and the evidence for its own premise are one artifact:
 * while the channel went unread, "no string literal reaches an undeclared
 * package" was a claim nothing could re-check.
 *
 * Still outside the fence: a name built by concatenation or reached by
 * reflection, which nothing here can distinguish from prose.
 *
 * # What this read does not consult
 *
 * `provide` and `replace`. Attribution follows `installed.json` to the
 * package that actually ships a namespace, so a manifest naming a replaced
 * package is REFUSED rather than waved through — substitution can cost a
 * false refusal here, never a silent accept, which is the direction this
 * group chooses everywhere else.
 * {@see self::itAttributesANamespaceToThePackageThatShipsIt} pins it.
 *
 * That is defensible only while nothing travels the channel, and that is a
 * fact about `composer.lock` rather than about this code — a fact that
 * changes on `composer update` without anyone deciding to change it. So it is
 * read rather than asserted here:
 * {@see self::itFindsNoPackageSubstitutionInTheLock}.
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

    /**
     * Printed with the shipped tree's verdict, so a reader of a red build
     * learns what the verdict covers without opening this file.
     *
     * It says "the prefix map could not attribute" rather than "no installed
     * package owns", because those are different facts and only the first one
     * is what the loose channel acts on. Packages that autoload by classmap
     * declare no prefix at all, so one of their classes named in a string is
     * ignored here while being installed and undeclared -- the very shape
     * this group exists for, reached by the one spelling it reads loosely.
     */
    private const string BOUNDARY = 'This read covers imports, written-out names, `Thing::class`, and a quoted '
        . 'class name the prefix map attributes to an installed package. It does not cover a quoted name that '
        . 'map cannot attribute — a package absent from the install, or one that autoloads by classmap and so '
        . 'declares no prefix — nor a name built by concatenation, nor a package substituted through '
        . '`provide`/`replace`.';

    #[Test]
    public function itReachesOnlyPackagesTheInstallDeclares(): void
    {
        $root = self::repositoryRoot();
        $verdict = self::judge(
            ShippedTree::files($root),
            $root,
            self::prefixMap($root . '/vendor/composer/installed.json'),
            self::declaredPackages($root . '/composer.json'),
            self::ownRoots($root . '/composer.json'),
        );

        self::assertSame([], $verdict['refusals'], \sprintf(
            "%d vendor namespace(s) in the shipped tree resolve to a package composer.json does not require:\n%s\n\n%s",
            \count($verdict['refusals']),
            implode("\n", $verdict['refusals']),
            self::BOUNDARY,
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
        $files = ShippedTree::files($root);

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

        // The quoted channel has no anchor in the verdict, because nothing in
        // this tree resolves through it — which is also what a reader that
        // stopped seeing string literals would produce. So its liveness is
        // read where it is visible: the candidates themselves.
        $quoted = ReachedNames::quotedClassNamesIn($files);

        self::assertNotSame([], $quoted, 'The quoted channel reads nothing at all, so its verdict is vacuous.');

        // And the population the loose stance is argued from. If the built-in
        // class census and the rule-name fragments ever leave `src/`, the
        // argument for judging this channel loosely leaves with them, and
        // this is what says so.
        $unattributable = array_filter(
            $quoted,
            static fn(array $record): bool => str_starts_with($record['name'], 'Random\\')
                || str_starts_with($record['name'], 'Dom\\'),
        );

        self::assertNotSame(
            [],
            $unattributable,
            'No unattributable quoted candidate remains, so the reason this channel is read loosely no longer holds.',
        );
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
     * `Thing::class` is inside the fence, planted.
     *
     * This is the idiomatic way a DI configurator or a service map names a
     * class, and it is the premise the loose string channel rests on: the
     * spelling a reader is most likely to reach for is judged strictly, so
     * reading quoted names loosely gives up much less than it sounds.
     *
     * Both names below are written with a leading backslash, so the parser
     * hands them over as `Node\Name\FullyQualified` before any visitor runs.
     */
    #[Test]
    public function itSeesAClassNamedWithTheClassConstant(): void
    {
        $root = self::repositoryRoot();
        $tree = self::plantedTree(<<<'PLANTED'
            <?php

            namespace Planted;

            final class NamesClassesForTheContainer
            {
                public const FINDER = \Symfony\Component\Process\ExecutableFinder::class;

                public function serviceId(): string
                {
                    return \Symfony\Component\Process\PhpProcess::class;
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

        self::assertCount(2, $refusals, implode("\n", $refusals));
        self::assertStringContainsString('ExecutableFinder', $refusals[0]);
        self::assertStringContainsString('PhpProcess', $refusals[1]);

        foreach ($refusals as $refusal) {
            self::assertStringContainsString('symfony/process', $refusal);
        }
    }

    /**
     * The string channel, planted: a class named only by a quoted literal is
     * refused when an installed package owns the name.
     *
     * `symfony/cache` is the anchor because it is the exact shape this
     * control exists for — installed on the production side, reached by
     * nothing that declares it.
     */
    #[Test]
    public function itSeesAClassNamedByAString(): void
    {
        $root = self::repositoryRoot();
        $tree = self::plantedTree(<<<'PLANTED'
            <?php

            namespace Planted;

            final class NamesAClassByString
            {
                public function make(): object
                {
                    $class = 'Symfony\Component\Cache\Adapter\ArrayAdapter';

                    return new $class();
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
        self::assertStringContainsString('Symfony\Component\Cache\Adapter\ArrayAdapter', $refusals[0]);
        self::assertStringContainsString('symfony/cache', $refusals[0]);
        self::assertStringContainsString('string literal', $refusals[0]);
    }

    /**
     * The two stances, planted side by side in one file, because an assertion
     * that something is NOT refused is also satisfied by a control that
     * refuses nothing at all.
     *
     * The file names three things: a `::class` an installed-but-undeclared
     * package owns, a quoted string the same package owns, and a quoted
     * string no installed package owns. Exactly the first two are refused.
     * The third is the shape that makes this channel worth reading loosely —
     * `src/` spells dozens of such names, a built-in class census and tail
     * fragments of this repository's own rule names, and none of them is a
     * reach. Refusing them would redden a clean tree and find nothing.
     *
     * The two positive halves are what keep the negative half honest: a dead
     * judge fails this case rather than passing it.
     */
    #[Test]
    public function itJudgesAStringOnlyWhereAnInstalledPackageOwnsIt(): void
    {
        $root = self::repositoryRoot();
        $tree = self::plantedTree(<<<'PLANTED'
            <?php

            namespace Planted;

            final class SpellsEveryShape
            {
                public const REACHED = \Symfony\Component\Cache\CacheItem::class;

                public const NAMED = 'Symfony\Component\Cache\Adapter\ArrayAdapter';

                public const DECLARED = 'Symfony\Component\Console\Command\Command';

                public const CENSUS = 'Random\Engine\Xoshiro256StarStar';
            }
            PLANTED);

        $refusals = self::judge(
            [$tree . '/Planted.php'],
            $tree,
            self::prefixMap($root . '/vendor/composer/installed.json'),
            ['php', 'symfony/console'],
            ['Planted\\'],
        )['refusals'];

        self::assertCount(2, $refusals, implode("\n", $refusals));

        $joined = implode("\n", $refusals);
        self::assertStringContainsString('Symfony\Component\Cache\CacheItem', $joined);
        self::assertStringContainsString('Symfony\Component\Cache\Adapter\ArrayAdapter', $joined);
        self::assertStringNotContainsString('Random\Engine', $joined);
        self::assertStringNotContainsString('symfony/console', $joined);
    }

    /**
     * Both spellings of one quoted name are one candidate.
     *
     * Asserted on the reader rather than on a verdict, because a verdict
     * cannot see this. Drop the normalization and `'\Vendor\Thing'` keeps its
     * leading backslash, matches no prefix, and is discarded by the same
     * loose stance that makes this channel useful — the refusal count does
     * not move, and the spelling leaves the fence in silence. Counting what
     * the reader hands over is the only place the loss is visible.
     */
    #[Test]
    public function itReadsBothSpellingsOfAQuotedNameAsOne(): void
    {
        $tree = self::plantedTree(<<<'PLANTED'
            <?php

            namespace Planted;

            final class SpellsOneNameTwoWays
            {
                public const BARE = 'Symfony\Component\Cache\Adapter\ArrayAdapter';

                public const LEADING = '\Symfony\Component\Cache\Adapter\ArrayAdapter';
            }
            PLANTED);

        $quoted = ReachedNames::quotedClassNamesIn([$tree . '/Planted.php']);

        self::assertSame(
            ['Symfony\Component\Cache\Adapter\ArrayAdapter'],
            array_column($quoted, 'name'),
        );
    }

    /**
     * Attribution follows the package that ships the namespace, planted
     * through the real `prefixMap()` rather than around it.
     *
     * The synthetic `installed.json` below describes a tree where
     * `vendor/replacement` ships the namespace and the manifest asks for
     * `vendor/replaced`. The name is REFUSED. That is the direction, and it
     * is the reason `provide`/`replace` is left unread: substitution cannot
     * accept silently here, it can only cost a false refusal a human
     * resolves.
     *
     * It goes through `prefixMap()` on purpose. Handing `judge()` a
     * hand-written map would test the map rather than the reading of it:
     * teach `prefixMap()` to honour `replace` and such a case would stay
     * green while the thing it is named for changed.
     */
    #[Test]
    public function itAttributesANamespaceToThePackageThatShipsIt(): void
    {
        $tree = self::plantedTree(<<<'PLANTED'
            <?php

            namespace Planted;

            use Replaced\Library\Client;

            final class UsesTheReplacedPackage
            {
                public function client(): Client
                {
                    return new Client();
                }
            }
            PLANTED);

        $installed = $tree . '/installed.json';
        self::assertIsInt(file_put_contents($installed, json_encode([
            'packages' => [[
                'name' => 'vendor/replacement',
                'replace' => ['vendor/replaced' => '*'],
                'autoload' => ['psr-4' => ['Replaced\\Library\\' => 'src/']],
            ]],
        ], \JSON_THROW_ON_ERROR)));

        $refusals = self::judge(
            [$tree . '/Planted.php'],
            $tree,
            self::prefixMap($installed),
            ['php', 'vendor/replaced'],
            ['Planted\\'],
        )['refusals'];

        self::assertCount(1, $refusals, implode("\n", $refusals));
        self::assertStringContainsString('Replaced\Library\Client', $refusals[0]);
        self::assertStringContainsString('vendor/replacement', $refusals[0]);
    }

    /**
     * The tie-break between two packages claiming one prefix, planted.
     *
     * Longest-prefix-wins says nothing when two prefixes are the same string,
     * and `prefixMap()` resolves that with `??=` — the first entry
     * `installed.json` lists keeps the prefix. Nothing else witnesses that
     * choice: every other case here plants one owner per namespace, so
     * turning the `??=` into a plain assignment would leave them all green
     * while attribution silently changed hands.
     *
     * The planted manifest declares the SECOND package, so the two outcomes
     * are distinguishable: first-wins refuses and names `vendor/first`,
     * last-wins finds a declared package and refuses nothing at all.
     */
    #[Test]
    public function itAttributesASharedPrefixToThePackageListedFirst(): void
    {
        $tree = self::plantedTree(<<<'PLANTED'
            <?php

            namespace Planted;

            use Shared\Library\Thing;

            final class UsesTheSharedPrefix
            {
                public function thing(): Thing
                {
                    return new Thing();
                }
            }
            PLANTED);

        $installed = $tree . '/installed.json';
        self::assertIsInt(file_put_contents($installed, json_encode([
            'packages' => [
                ['name' => 'vendor/first', 'autoload' => ['psr-4' => ['Shared\\Library\\' => 'src/']]],
                ['name' => 'vendor/second', 'autoload' => ['psr-4' => ['Shared\\Library\\' => 'src/']]],
            ],
        ], \JSON_THROW_ON_ERROR)));

        $refusals = self::judge(
            [$tree . '/Planted.php'],
            $tree,
            self::prefixMap($installed),
            ['php', 'vendor/second'],
            ['Planted\\'],
        )['refusals'];

        self::assertCount(1, $refusals, implode("\n", $refusals));
        self::assertStringContainsString('vendor/first', $refusals[0]);
    }

    /**
     * The premise under which `provide`/`replace` is left unread, asserted
     * against the lock instead of written down.
     *
     * Leaving a channel unjudged is defensible only while nothing travels it.
     * That is a fact about `composer.lock`, and a fact about `composer.lock`
     * changes on `composer update` without anyone deciding to change it. So
     * it is read: no production package provides or replaces a package name
     * this manifest requires. A substitution for a name `require` never
     * mentions is not reported and does not need to be — this lock carries
     * several, all of them virtual `*-implementation` names — because it
     * cannot change which package a declared namespace resolves to.
     *
     * `ext-*` names are excluded here because a package providing one is
     * ordinary and expected — the sibling extension control owns that
     * question and names the live instances.
     */
    #[Test]
    public function itFindsNoPackageSubstitutionInTheLock(): void
    {
        $root = self::repositoryRoot();

        $substitutions = self::substitutionsAmong(
            ShippedTree::productionLockPackages($root),
            self::declaredPackages($root . '/composer.json'),
        );

        self::assertSame([], $substitutions, \sprintf(
            "A production package now stands in for something composer.json requires:\n%s\n\n%s",
            implode("\n", $substitutions),
            'This control attributes namespaces through installed.json, so it would refuse the substituting '
                . 'package rather than accept it. Confirm that refusal reads sensibly, then record the decision.',
        ));
    }

    /**
     * The same read, planted, because the lock carries no substitution and a
     * control nobody has seen produce a positive is a control nobody has seen
     * work. Its whole job is to speak up on an input that does not exist yet.
     *
     * Both keys are planted: either satisfies a Composer requirement. An
     * `ext-*` entry is planted alongside to pin that it stays out — that one
     * belongs to the sibling extension control.
     */
    #[Test]
    public function itNamesASubstitutionWhenTheLockCarriesOne(): void
    {
        $substitutions = self::substitutionsAmong(
            [
                ['name' => 'vendor/stand-in', 'replace' => ['vendor/required' => '*']],
                ['name' => 'vendor/provider', 'provide' => ['vendor/other-required' => '1.0']],
                ['name' => 'vendor/polyfill', 'provide' => ['ext-ctype' => '*']],
                ['name' => 'vendor/unrelated', 'replace' => ['vendor/not-required' => '*']],
            ],
            ['php', 'vendor/required', 'vendor/other-required', 'ext-ctype'],
        );

        self::assertSame([
            'vendor/provider provides vendor/other-required',
            'vendor/stand-in replaces vendor/required',
        ], $substitutions);
    }

    /**
     * The section boundary itself, planted.
     *
     * "Production only" is the whole promise of
     * {@see ShippedTree::productionPackagesOf()}, and both readers here rest
     * on it: a dev-only package counted as production would report a
     * substitution a consumer's `--no-dev` install never resolves, and would
     * let the sibling control call a dev package the satisfier of a
     * consumer's `ext-*`. The live lock cannot tell the two readings apart —
     * its `packages-dev` entries provide nothing this manifest requires — so
     * the difference is planted.
     */
    #[Test]
    public function itReadsTheProductionSectionOfTheLockOnly(): void
    {
        $packages = ShippedTree::productionPackagesOf([
            'packages' => [['name' => 'vendor/production']],
            'packages-dev' => [['name' => 'vendor/development', 'replace' => ['vendor/required' => '*']]],
        ]);

        self::assertSame(['vendor/production'], array_column($packages, 'name'));
        self::assertSame([], self::substitutionsAmong($packages, ['php', 'vendor/required']));
    }

    /**
     * Production packages that CLAIM, by name, to stand in for something
     * `require` names.
     *
     * By name only: the version a `provide` or `replace` announces is not
     * compared against the constraint `require` asks for, and a real package
     * carrying the same name alongside the claimant is not looked for. This
     * is a tripwire, not a resolver — Composer decides satisfaction, and
     * re-deciding it here would mean reimplementing its semver. Every entry
     * this reports is meant to be read by a person.
     *
     * `ext-*` is excluded: a package providing an extension is ordinary and
     * expected, and the sibling extension control owns that question.
     *
     * @param list<array<string, mixed>> $packages
     * @param list<string> $declared
     *
     * @return list<string>
     */
    private static function substitutionsAmong(array $packages, array $declared): array
    {
        $substitutions = [];

        foreach ($packages as $package) {
            self::assertIsString($package['name'] ?? null);

            foreach (['provide' => 'provides', 'replace' => 'replaces'] as $key => $verb) {
                $entries = $package[$key] ?? [];

                if (!\is_array($entries)) {
                    continue;
                }

                foreach (array_keys($entries) as $name) {
                    if (!\is_string($name) || str_starts_with($name, 'ext-')) {
                        continue;
                    }

                    if (\in_array($name, $declared, true)) {
                        $substitutions[] = \sprintf('%s %s %s', $package['name'], $verb, $name);
                    }
                }
            }
        }

        sort($substitutions);

        return $substitutions;
    }

    /**
     * Judges one file list against one prefix map and one `require` section.
     *
     * Both channels resolve through the same prefix map and the same
     * `require` list; they differ only in what an unattributable name means.
     * A resolved name nothing owns is a refusal, because nothing but a real
     * type can be spelled that way. A quoted string nothing owns is ignored,
     * because a string that merely resembles a namespace is not a reach.
     *
     * @param list<string> $files absolute paths
     * @param array<string, string> $prefixes namespace prefix => package name
     * @param list<string> $declared package names from `require`
     * @param list<string> $ownRoots namespace prefixes this repository declares as its own
     *
     * @return array{refusals: list<string>, packages: list<string>} packages resolved through the strict channel only
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

        foreach (self::namesReached($files) as $file => $channels) {
            $relative = str_starts_with($file, $treeRoot . '/')
                ? substr($file, \strlen($treeRoot) + 1)
                : $file;

            foreach (['names' => true, 'strings' => false] as $channel => $refuseUnattributable) {
                foreach ($channels[$channel] as $name) {
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
                        if ($refuseUnattributable) {
                            $refusals[] = \sprintf(
                                '%s reaches %s, which no installed package provides — attribute it, or add its provider to RUNTIME_PROVIDERS.',
                                $relative,
                                $name,
                            );
                        }

                        continue;
                    }

                    if (!\in_array($package, $declared, true)) {
                        $refusals[] = \sprintf(
                            $channel === 'strings'
                                ? '%s names %s in a string literal — declare %s in composer.json require.'
                                : '%s reaches %s — declare %s in composer.json require.',
                            $relative,
                            $name,
                            $package,
                        );

                        continue;
                    }

                    // Only the strict channel vouches for the read being
                    // alive. A quoted literal is a candidate by this file's
                    // own stance, and letting one answer for a resolved name
                    // would let the loose channel keep the liveness anchors
                    // green while the strict read saw nothing.
                    if ($channel === 'names') {
                        $packages[$package] = true;
                    }
                }
            }
        }

        sort($refusals);
        $resolved = array_keys($packages);
        sort($resolved);

        return ['refusals' => $refusals, 'packages' => $resolved];
    }

    /**
     * What each file names, split by how it is spelled, because the two
     * spellings carry different confidence and are judged differently.
     *
     * `names` are namespaced names the language itself resolved: an import
     * used or unused, a written-out reference, a `Thing::class`. `strings`
     * are quoted literals shaped like a namespaced class name. See the class
     * docblock for why the two stances differ.
     *
     * The read itself is {@see ReachedNames}, which the sibling extension
     * control shares, for the reason {@see ShippedTree} gives about the
     * population: this group asks two questions of one tree, and a second
     * copy of how names are read out of it would drift exactly the way a
     * second copy of what ships would. A package is named by a namespace and
     * an extension by a global name, so those two differ only in which half
     * of one read they keep; the quoted channel is a third half nobody else
     * asks for, which is why it comes through a door of its own.
     *
     * @param list<string> $files
     *
     * @return array<string, array{names: list<string>, strings: list<string>}>
     */
    private static function namesReached(array $files): array
    {
        $names = array_fill_keys($files, []);
        $strings = array_fill_keys($files, []);

        foreach (ReachedNames::in($files) as $record) {
            if (str_contains($record['name'], '\\')) {
                $names[$record['file']][] = $record['name'];
            }
        }

        foreach (ReachedNames::quotedClassNamesIn($files) as $record) {
            $strings[$record['file']][] = $record['name'];
        }

        $reached = [];

        foreach ($files as $file) {
            $reachedNames = array_values(array_unique($names[$file]));
            $quoted = array_values(array_unique($strings[$file]));
            sort($reachedNames);
            sort($quoted);

            $reached[$file] = ['names' => $reachedNames, 'strings' => $quoted];
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

    private static function plantedTree(string $source): string
    {
        $directory = sys_get_temp_dir() . '/qmx-declared-dependencies-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o777, true), $directory);
        self::assertIsInt(file_put_contents($directory . '/Planted.php', $source));

        // Whatever the case put in the directory, not one named file: two
        // cases write a synthetic installed.json beside the source, and a
        // cleanup that names only the source leaves the directory behind on
        // every run, silently, because rmdir fails on a non-empty one.
        register_shutdown_function(static function () use ($directory): void {
            $planted = glob($directory . '/*');

            if ($planted !== false) {
                foreach ($planted as $file) {
                    unlink($file);
                }
            }

            rmdir($directory);
        });

        return $directory;
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
