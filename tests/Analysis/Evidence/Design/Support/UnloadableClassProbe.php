<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Support;

use Closure;
use RuntimeException;

/**
 * A class the autoloader can reach but PHP cannot finish loading.
 *
 * DIT resolution asks this tool's own autoloader to load a class belonging to
 * the analysed project. When the file it finds names a parent that is absent,
 * `class_exists()` throws an `Error` instead of returning false -- the shape a
 * standalone install of the tool hits on its own incomplete vendored copies.
 *
 * The child is generated at run time rather than committed as a fixture: a
 * tracked file extending a missing class would fail static analysis, which
 * reads the source without ever running it.
 *
 * {@see queryCount()} is what keeps a test using this probe from passing
 * vacuously. "Threw and was caught" and "was never asked" both leave the
 * subject at depth 0, so the resulting metric cannot tell them apart; only the
 * count can say the resolution reached the load step at all.
 */
final class UnloadableClassProbe
{
    private int $queryCount = 0;

    private ?Closure $autoloader = null;

    private function __construct(
        private readonly string $childFqcn,
        private readonly string $directory,
        private readonly string $file,
    ) {}

    public static function start(): self
    {
        // Each probe gets its own namespace so two of them in one process
        // cannot be handed the same already-failed class name.
        $suffix = bin2hex(random_bytes(6));
        $namespace = 'QmxUnloadableProbe\\N' . $suffix;
        $directory = sys_get_temp_dir() . '/qmx-unloadable-probe-' . $suffix;

        if (!mkdir($directory) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create probe directory ' . $directory);
        }

        $file = $directory . '/ReachableChild.php';
        // AbsentParent resolves inside the probe namespace and is never written.
        file_put_contents($file, \sprintf(
            "<?php\n\nnamespace %s;\n\nclass ReachableChild extends AbsentParent\n{\n}\n",
            $namespace,
        ));

        $probe = new self($namespace . '\\ReachableChild', $directory, $file);

        $probe->autoloader = static function (string $class) use ($probe, $file): void {
            if ($class !== $probe->childFqcn) {
                return;
            }

            ++$probe->queryCount;

            // Deliberately unguarded: the missing parent must escape as an
            // Error, because that escape is the condition under test.
            require $file;
        };
        spl_autoload_register($probe->autoloader);

        return $probe;
    }

    /**
     * The FQCN whose file loads and then fails on its missing parent.
     */
    public function childFqcn(): string
    {
        return $this->childFqcn;
    }

    /**
     * How many times this probe's autoloader was asked for that FQCN.
     */
    public function queryCount(): int
    {
        return $this->queryCount;
    }

    /**
     * Whether the load left the class undeclared, i.e. it did not complete.
     */
    public function stillUndeclared(): bool
    {
        return !class_exists($this->childFqcn, false);
    }

    public function stop(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
            $this->autoloader = null;
        }

        if (is_file($this->file)) {
            unlink($this->file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }
}
