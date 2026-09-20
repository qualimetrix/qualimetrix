<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * Whether `vendor/` is the dependency graph HEAD's lock describes.
 *
 * Every control in this group judges an archive of HEAD, but runs it against a
 * `vendor/` copied from this checkout. The two are different trees, and where
 * they disagree the run produces a verdict about neither.
 *
 * The disagreement is not visible in the obvious place. `dump-autoload
 * --no-dev` does not recompute the production/development split from the lock:
 * it reads `dev-package-names` out of `vendor/composer/installed.json`, which
 * the copy brings along. So a package's side of the split is decided by the
 * last real `composer install`, while `composer.json` and `src/` are decided
 * by HEAD. Both directions were measured on the tree that introduced the git
 * scope control:
 *
 * - a correct fix stays red until someone runs an install, because the
 *   installed split still carries the defect;
 * - a broken `composer.json` at HEAD goes green, because the installed split
 *   still carries the cure. This is not hypothetical — it is how the first
 *   attempt to prove that control red produced a false green.
 *
 * In CI the two always agree: the test job installs from the lock at the
 * commit it checked out. This is a local-developer trap, and it is refused
 * here rather than documented, because a documented defect is still a defect.
 *
 * One definition, for the same reason {@see \Qualimetrix\Governance\DeclaredDependencies\ShippedTree}
 * is one: both controls that copy `vendor/` and dump without the dev graph ask
 * this question, and two copies of it would drift — with the half that drifted
 * still passing.
 *
 * ## What is compared, and what is not
 *
 * Package *names*, per side of the split. Versions are not compared: a
 * `vendor/` installed from a lock with the same partition but different
 * versions passes this guard. That residue is deliberate — the subject of
 * these controls is which packages a consumer receives, not which revision of
 * them — and it is the residue every control in this group already carries.
 *
 * Both sides are compared, though only the production side decides the
 * controls' verdicts. The development side is what the git scope control's own
 * witness rests on: it proves the fixture is production-shaped by showing
 * PHPUnit unreachable, which means something only while PHPUnit is installed
 * *and* marked development. A split that disagrees there would redden that
 * witness with the wrong diagnosis.
 */
final class InstalledDependencyGraph
{
    /**
     * Refuses when this checkout's `vendor/` is not HEAD's graph.
     *
     * A refusal rather than a failure on purpose: the run has not judged the
     * subject and found it broken, it has found that it cannot judge the
     * subject at all. PHPUnit reports a thrown exception as an error and an
     * assertion as a failure, and that is the distinction being spent.
     *
     * @throws RuntimeException naming the cure
     */
    public static function assertMatchesHead(string $root): void
    {
        $atHead = self::sides(self::lockAtHead($root));
        $inVendor = self::sides(self::installed($root));

        $differences = [];

        foreach (array_unique([...array_keys($atHead), ...array_keys($inVendor)]) as $name) {
            $head = $atHead[$name] ?? 'absent';
            $vendor = $inVendor[$name] ?? 'absent';

            if ($head !== $vendor) {
                $differences[] = '  ' . $name . ': ' . $head . ' at HEAD, ' . $vendor . ' in vendor/';
            }
        }

        if ($differences === []) {
            return;
        }

        sort($differences);

        throw new RuntimeException(
            'vendor/ disagrees with HEAD, so this control cannot judge either tree — run composer install.' . \PHP_EOL
            . 'The autoload dump takes the production/development split from vendor/composer/installed.json,' . \PHP_EOL
            . 'not from the lock, so the split below is what a run here would actually apply:' . \PHP_EOL
            . implode(\PHP_EOL, $differences),
        );
    }

    /**
     * One side per package name, so a package that merely moved between them
     * is reported as the one move it is rather than as two absences.
     *
     * @param array{production: list<string>, development: list<string>} $split
     *
     * @return array<string, string>
     */
    private static function sides(array $split): array
    {
        $sides = [];

        foreach ($split as $side => $names) {
            foreach ($names as $name) {
                $sides[$name] = $side;
            }
        }

        return $sides;
    }

    /**
     * The split HEAD's lock describes.
     *
     * Read from HEAD rather than from the working tree's `composer.lock`,
     * because HEAD is what the archive these controls judge is taken from. An
     * uncommitted lock edit is a disagreement, not an exemption.
     *
     * @return array{production: list<string>, development: list<string>}
     */
    private static function lockAtHead(string $root): array
    {
        $result = ChildProcess::run(['git', '-C', $root, 'show', 'HEAD:composer.lock']);

        if ($result['exitCode'] !== 0) {
            throw new RuntimeException(
                'Could not read composer.lock at HEAD, so nothing here was checked:' . \PHP_EOL . $result['stderr'],
            );
        }

        $lock = self::decode($result['stdout'], 'composer.lock at HEAD');

        return [
            'production' => self::names($lock, 'packages', 'composer.lock at HEAD'),
            'development' => self::names($lock, 'packages-dev', 'composer.lock at HEAD'),
        ];
    }

    /**
     * The split the copied `vendor/` would impose.
     *
     * @return array{production: list<string>, development: list<string>}
     */
    private static function installed(string $root): array
    {
        $path = $root . '/vendor/composer/installed.json';
        $contents = @file_get_contents($path);

        if (!\is_string($contents)) {
            throw new RuntimeException(
                'No ' . $path . ', so there is no installed graph to compare — run composer install.',
            );
        }

        $installed = self::decode($contents, $path);
        $all = self::names($installed, 'packages', $path);

        if (!isset($installed['dev-package-names']) || !\is_array($installed['dev-package-names'])) {
            throw new RuntimeException(
                $path . ' carries no dev-package-names, which is where the autoload dump reads the split from.',
            );
        }

        /** @var list<string> $development */
        $development = array_values(array_filter($installed['dev-package-names'], '\is_string'));

        return [
            'production' => array_values(array_diff($all, $development)),
            'development' => $development,
        ];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private static function names(array $document, string $key, string $source): array
    {
        if (!isset($document[$key]) || !\is_array($document[$key])) {
            throw new RuntimeException($source . ' carries no ' . $key . ' array, so the split cannot be read from it.');
        }

        $names = [];

        foreach ($document[$key] as $package) {
            if (\is_array($package) && isset($package['name']) && \is_string($package['name'])) {
                $names[] = $package['name'];
            }
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json, string $source): array
    {
        $document = json_decode($json, true);

        if (!\is_array($document)) {
            throw new RuntimeException($source . ' is not a JSON object, so the split cannot be read from it.');
        }

        /** @var array<string, mixed> $document */
        return $document;
    }
}
