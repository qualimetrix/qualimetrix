<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * That {@see InstalledDependencyGraph} refuses the disagreements it claims to.
 *
 * A guard whose refusal path nothing executes is a guard that can rot green.
 * The two controls it protects exercise only its agreeing case — on a healthy
 * checkout it returns silently, and every assertion after it is about
 * something else — so a defect in the refusal itself would ship under a green
 * `composer check`. That is not hypothetical: while this guard was being
 * written, a leftover variable made it compose every description from an
 * undefined value, and both protected controls stayed green.
 *
 * Each case is a real repository with a real commit, because the guard reads
 * HEAD through git and reads `installed.json` from disk; a fixture that stubs
 * either would be measuring a substitute. The manifest and lock are copied
 * from this repository rather than invented: they are a pair Composer already
 * agrees on, which is what leaves each case free to plant exactly one
 * disagreement.
 */
final class InstalledDependencyGraphRefusesDisagreementTest extends TestCase
{
    /**
     * The accepting case, stated so that it cannot pass by never arriving.
     *
     * "It did not throw" is true of a guard that was never reached and of one
     * that refuses nothing, so the same fixture is then moved one package to
     * the other side and has to refuse. The pair says the fixture sits on the
     * boundary the guard draws, which is what makes every refusing case below
     * evidence about the guard rather than about the fixture.
     */
    #[Test]
    public function itAcceptsAnInstalledGraphThatIsHeadsAndRefusesTheSameOneMovedOffIt(): void
    {
        $root = self::fixture();

        try {
            $faithful = self::splitFromLock($root);
            self::writeInstalled($root, $faithful);

            try {
                InstalledDependencyGraph::assertMatchesHead($root);
            } catch (RuntimeException $refusal) {
                self::fail('The guard refused a graph that is HEAD\'s:' . \PHP_EOL . $refusal->getMessage());
            }

            $faithful['dev-package-names'][] = 'psr/log';
            self::writeInstalled($root, $faithful);

            self::assertStringContainsString(
                'psr/log',
                self::refusalFrom($root),
                'One package moved sides did not change the answer, so the accepting half proves nothing.',
            );
        } finally {
            self::removeDirectory($root);
        }
    }

    #[Test]
    public function itRefusesAPackageInstalledOnTheOtherSideOfTheSplit(): void
    {
        $refusal = self::refusalFor(static function (array $split): array {
            $split['dev-package-names'][] = 'psr/log';

            return $split;
        });

        self::assertStringContainsString('psr/log: production', $refusal);
        self::assertStringContainsString('development', $refusal);
        self::assertStringContainsString('run composer install', $refusal);
    }

    /**
     * The side alone is not enough, and this is the case that says so: a
     * production package pinned to one revision at HEAD and installed at
     * another resolves a different set of classes, which is exactly what the
     * protected controls ask about.
     */
    #[Test]
    public function itRefusesAPackageInstalledAtAnotherVersion(): void
    {
        $refusal = self::refusalFor(static function (array $split): array {
            foreach ($split['packages'] as $index => $package) {
                if ($package['name'] === 'psr/log') {
                    $split['packages'][$index]['version'] = '0.0.1-planted';
                }
            }

            return $split;
        });

        self::assertStringContainsString('psr/log', $refusal);
        self::assertStringContainsString('0.0.1-planted', $refusal);
    }

    #[Test]
    public function itRefusesAPackageHeadLocksAndVendorDoesNotHold(): void
    {
        $refusal = self::refusalFor(static function (array $split): array {
            $split['packages'] = array_values(array_filter(
                $split['packages'],
                static fn(array $package): bool => $package['name'] !== 'psr/log',
            ));

            return $split;
        });

        self::assertStringContainsString('psr/log', $refusal);
        self::assertStringContainsString('absent in vendor/', $refusal);
    }

    #[Test]
    public function itRefusesAnInstalledGraphItCannotRead(): void
    {
        $root = self::fixture();

        try {
            $refusal = self::refusalFrom($root);

            self::assertStringContainsString('installed.json', $refusal);
            self::assertStringContainsString('composer install', $refusal);
        } finally {
            self::removeDirectory($root);
        }
    }

    /**
     * The cure is the finding here, not the refusal.
     *
     * `composer install` installs from the working tree's lock. When that lock
     * is the one `vendor/` already holds and is merely uncommitted, the
     * suggested cure is a no-op and the developer runs it into the same
     * refusal. The guard promises to name the cure, so it has to name this one.
     */
    #[Test]
    public function itNamesCommittingTheLockWhenTheLockIsTheThingThatMoved(): void
    {
        $root = self::fixture();

        try {
            $split = self::splitFromLock($root);
            $split['dev-package-names'][] = 'psr/log';
            self::writeInstalled($root, $split);

            // The working tree's lock now differs from HEAD's, which is the
            // shape `composer require` leaves behind mid-task.
            file_put_contents($root . '/composer.lock', self::read($root . '/composer.lock') . "\n");

            $refusal = self::refusalFrom($root);

            self::assertStringContainsString('commit the lock', $refusal);
            self::assertStringNotContainsString('run composer install', $refusal);
        } finally {
            self::removeDirectory($root);
        }
    }

    /**
     * Link one: that HEAD's lock is the lock HEAD's manifest resolves to.
     *
     * Without it the guard is green on a HEAD that moved a package out of
     * `require` and never re-resolved — the defect class the git scope control
     * exists for, reached from the one side the installed graph cannot show.
     */
    #[Test]
    public function itRefusesAHeadWhoseLockNoLongerAnswersItsManifest(): void
    {
        $root = self::fixture();

        try {
            self::writeInstalled($root, self::splitFromLock($root));

            $manifest = json_decode(self::read($root . '/composer.json'), true);
            self::assertIsArray($manifest);

            $manifest['require']['psr/log'] = '^99.0';

            file_put_contents($root . '/composer.json', (string) json_encode($manifest, \JSON_PRETTY_PRINT));
            self::commit($root, 'Re-declare a package without re-resolving');

            $refusal = self::refusalFrom($root);

            self::assertStringContainsString('no consumer resolves', $refusal);
            self::assertStringContainsString('not up to date', $refusal, 'Composer\'s own words are what name the cure.');
        } finally {
            self::removeDirectory($root);
        }
    }

    /**
     * Plants one disagreement in the installed graph and returns the refusal.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $plant
     */
    private static function refusalFor(callable $plant): string
    {
        $root = self::fixture();

        try {
            self::writeInstalled($root, $plant(self::splitFromLock($root)));

            return self::refusalFrom($root);
        } finally {
            self::removeDirectory($root);
        }
    }

    private static function refusalFrom(string $root): string
    {
        try {
            InstalledDependencyGraph::assertMatchesHead($root);
        } catch (RuntimeException $refusal) {
            return $refusal->getMessage();
        }

        self::fail('The guard accepted a graph that is not HEAD\'s, so it would not refuse the real one either.');
    }

    /**
     * A repository whose HEAD carries a manifest and lock Composer agrees on.
     *
     * @return string its root
     */
    private static function fixture(): string
    {
        $root = sys_get_temp_dir() . '/qmx-graph-guard-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($root, 0777, true));

        $resolved = realpath($root);
        self::assertIsString($resolved);

        self::git(['git', 'init', '--quiet', $resolved]);

        foreach (['composer.json', 'composer.lock'] as $name) {
            copy(\dirname(__DIR__, 2) . '/' . $name, $resolved . '/' . $name);
        }

        self::commit($resolved, 'The manifest and lock this case starts from');

        return $resolved;
    }

    private static function commit(string $root, string $message): void
    {
        self::git(['git', '-C', $root, 'add', '-A']);
        self::git([
            'git', '-C', $root,
            '-c', 'user.email=governance@qualimetrix.invalid',
            '-c', 'user.name=Governance Fixture',
            // A developer who signs every commit globally would otherwise be
            // prompted by this fixture, mid-suite, and read it as a defect.
            '-c', 'commit.gpgsign=false',
            'commit', '--quiet', '--no-verify', '-m', $message,
        ]);
    }

    /**
     * The installed graph a faithful `composer install` would have written.
     *
     * @return array<string, mixed>
     */
    private static function splitFromLock(string $root): array
    {
        $lock = json_decode(self::read($root . '/composer.lock'), true);

        self::assertIsArray($lock);
        self::assertIsArray($lock['packages']);
        self::assertIsArray($lock['packages-dev']);

        return [
            'packages' => [...$lock['packages'], ...$lock['packages-dev']],
            'dev' => true,
            'dev-package-names' => array_map(
                static fn(array $package): string => (string) $package['name'],
                $lock['packages-dev'],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $installed
     */
    private static function writeInstalled(string $root, array $installed): void
    {
        $directory = $root . '/vendor/composer';

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }

        file_put_contents($directory . '/installed.json', (string) json_encode($installed));
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents, $path . ' could not be read, so nothing here was checked.');

        return $contents;
    }

    /**
     * @param list<string> $command
     */
    private static function git(array $command): void
    {
        $result = ChildProcess::run($command);

        self::assertSame(
            0,
            $result['exitCode'],
            implode(' ', $command) . ' failed, so nothing here was checked:' . \PHP_EOL . $result['stderr'],
        );
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;

            is_dir($child) && !is_link($child) ? self::removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }
}
