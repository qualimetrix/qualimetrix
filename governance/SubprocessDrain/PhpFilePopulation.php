<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SubprocessDrain;

use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * Every PHP file this repository ships or runs, as the controls in this group
 * see it.
 *
 * The set is `git ls-files --cached --others --exclude-standard`:
 * untracked-but-not-ignored files are in it, so a brand-new file cannot pass a
 * control by not being in the index yet. A file counts as PHP by extension or
 * by a `php` shebang, because `bin/qmx` carries no extension and a `'*.php'`
 * filter would not see it.
 *
 * It is one class rather than a copy per control on purpose: two controls in
 * this group that computed their own population would agree today and drift
 * apart on the next change to either, and a control looking at a narrower tree
 * than it claims is green for the wrong reason.
 */
final class PhpFilePopulation
{
    /** @var list<string>|null */
    private static ?array $paths = null;

    public static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Paths relative to {@see root()}, in the order git reports them.
     *
     * @throws RuntimeException the repository's files cannot be listed, so the
     *                          population is unknown — which must not read as
     *                          an empty one
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        if (self::$paths !== null) {
            return self::$paths;
        }

        $root = self::root();
        $result = ChildProcess::run(
            ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'],
            $root,
        );

        if ($result['exitCode'] !== 0) {
            throw new RuntimeException(
                'Cannot list the repository\'s files, so the population is unknown: ' . $result['stderr'],
            );
        }

        $paths = [];

        foreach (explode("\0", $result['stdout']) as $path) {
            if ($path === '') {
                continue;
            }

            $absolute = $root . '/' . $path;

            if (!is_file($absolute)) {
                continue;
            }

            if (self::isPhpFile($absolute, $path)) {
                $paths[] = $path;
            }
        }

        return self::$paths = $paths;
    }

    public static function forget(): void
    {
        self::$paths = null;
    }

    private static function isPhpFile(string $absolute, string $path): bool
    {
        if (str_ends_with($path, '.php')) {
            return true;
        }

        $handle = @fopen($absolute, 'rb');

        if ($handle === false) {
            return false;
        }

        $first = (string) fgets($handle, 256);
        fclose($handle);

        return str_starts_with($first, '#!') && str_contains($first, 'php');
    }
}
