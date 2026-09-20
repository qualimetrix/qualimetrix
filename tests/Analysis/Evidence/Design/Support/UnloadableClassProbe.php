<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Support;

use Closure;
use RuntimeException;
use Throwable;

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
 * {@see failedOnTheMissingParent()} is what keeps a test using this probe from
 * passing vacuously, and it is deliberately a statement about the failure
 * rather than about the call. Depth 0 is what an unresolvable parent scores for
 * any reason at all, so the metric cannot tell the target case from a parent
 * nobody looked for; and "the autoloader was asked" cannot tell it from a load
 * that failed for some unrelated reason, such as a file this probe never
 * managed to write. Only the identity of the class PHP could not find can.
 */
final class UnloadableClassProbe
{
    private int $queryCount = 0;

    private ?Throwable $loadFailure = null;

    private ?Closure $autoloader = null;

    private function __construct(
        private readonly string $childFqcn,
        private readonly string $absentParentFqcn,
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
        $written = file_put_contents($file, \sprintf(
            "<?php\n\nnamespace %s;\n\nclass ReachableChild extends AbsentParent\n{\n}\n",
            $namespace,
        ));

        // An unwritten file still makes `require` throw, which would satisfy a
        // test that only checked that loading failed.
        if ($written === false) {
            throw new RuntimeException('Cannot write probe class to ' . $file);
        }

        $probe = new self(
            $namespace . '\\ReachableChild',
            $namespace . '\\AbsentParent',
            $directory,
            $file,
        );

        $probe->autoloader = static function (string $class) use ($probe, $file): void {
            if ($class !== $probe->childFqcn) {
                return;
            }

            ++$probe->queryCount;

            try {
                require $file;
            } catch (Throwable $failure) {
                $probe->loadFailure = $failure;

                // Rethrown on purpose: this escape is the condition under test.
                throw $failure;
            }
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
     * Whether loading was attempted and failed on the absent parent itself.
     */
    public function failedOnTheMissingParent(): bool
    {
        return $this->loadFailure !== null
            && str_contains($this->loadFailure->getMessage(), $this->absentParentFqcn);
    }

    /**
     * How many times this probe's autoloader was asked for that FQCN.
     */
    public function queryCount(): int
    {
        return $this->queryCount;
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
