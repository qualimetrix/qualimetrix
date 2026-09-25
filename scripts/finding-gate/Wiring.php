<?php

declare(strict_types=1);

namespace QmxFindingGate;

use Closure;

/**
 * What each declaration form adds to the gate, one file per form, read from a
 * fixed list.
 *
 * The forms are written in parallel, and every one of them has to reach the
 * same few places: the class loader, the controls table, the self-test, the
 * check witnesses, the pending rows of the witness registry, the case checks,
 * the stages of a surface's comparison, the checks of a whole run and the
 * derivations. A shared list at each of those places is a file every form
 * edits; a file per form is one only that form edits. So each place reads the
 * union of these files, and a form registers itself in its own.
 *
 * The list of files is fixed, not discovered: a `wiring-*.php` that is not
 * named here is refused rather than read, and one that is named must exist.
 * A pending row may only name its own file's package, so a form cannot excuse
 * another form's class.
 *
 * @phpstan-type Pending array<string, array{0: string, 1: string}>
 */
final class Wiring
{
    /** Form file => the package that owns it. */
    public const array FILES = [
        'records' => 'S01b/P2',
        'tuple' => 'S01b/P3',
        'capture' => 'S01b/P4',
        'outcomes' => 'S01b/P5',
        'maps' => 'S01b/P6',
        'corpus' => 'S01b/P7',
    ];

    /**
     * What a form file may register. Each is a list of class names, or of
     * `Class::method` entries, except `pending`, which maps a failure class to
     * its `[marker, reason]` row.
     *
     * - `classes`: gate classes, `scripts/finding-gate/<Class>.php`
     * - `controlClasses`: controls-harness classes, `scripts/finding-gate-controls/<Class>.php`
     * - `controls`: `Class::method` of the harness, returning a `Control`
     * - `selfTest`: `Class::method` of a {@see SelfTestGroup}
     * - `witnesses`: `Class::method` returning a list of {@see CheckWitnesses::witness()}
     * - `caseChecks`, `surfaceStages`, `runChecks`, `derivations`: gate classes
     *   implementing {@see CaseCheck}, {@see SurfaceStage}, {@see RunCheck}, {@see Derivation}
     */
    public const array KEYS = [
        'classes',
        'controlClasses',
        'controls',
        'selfTest',
        'witnesses',
        'pending',
        'caseChecks',
        'surfaceStages',
        'runChecks',
        'derivations',
    ];

    private const string PREFIX = 'wiring-';

    /** @var array<string, self> */
    private static array $loaded = [];

    /**
     * @param array<string, list<string>> $lists key => entries, every form's in {@see FILES} order
     * @param Pending $pending
     */
    private function __construct(
        public readonly string $directory,
        private readonly array $lists,
        public readonly array $pending,
    ) {}

    /** The gate's own wiring. */
    public static function gate(): self
    {
        return self::of(__DIR__);
    }

    public static function of(string $directory): self
    {
        return self::$loaded[$directory] ??= self::read($directory);
    }

    /**
     * Requires every gate class the forms register, after the loader's own
     * list: a form's classes may use the gate's, never the other way round.
     */
    public function load(): void
    {
        foreach ($this->list('classes') as $class) {
            require_once $this->directory . '/' . $class . '.php';
        }
    }

    /** Requires every controls-harness class the forms register. */
    public function loadControls(string $controlsDirectory): void
    {
        foreach ($this->list('controlClasses') as $class) {
            require_once $controlsDirectory . '/' . $class . '.php';
        }
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        if (!\in_array($key, self::KEYS, true) || $key === 'pending') {
            throw new GateError(\sprintf('Wiring has no list "%s".', $key));
        }

        return $this->lists[$key] ?? [];
    }

    /**
     * `Class::method` entries as the fully qualified class and the method.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function methods(string $key, string $namespace): array
    {
        $methods = [];

        foreach ($this->list($key) as $entry) {
            [$class, $method] = explode('::', $entry, 2);
            $methods[] = [$namespace . '\\' . $class, $method];
        }

        return $methods;
    }

    /**
     * `Class::method` entries naming static factories, ready to call.
     *
     * @return list<Closure>
     */
    public function factories(string $key, string $namespace): array
    {
        $factories = [];

        foreach ($this->methods($key, $namespace) as [$class, $method]) {
            $factory = [$class, $method];

            if (!\is_callable($factory)) {
                throw new GateError(\sprintf('The wiring registers %s::%s() under "%s", which cannot be called.', $class, $method, $key));
            }

            $factories[] = Closure::fromCallable($factory);
        }

        return $factories;
    }

    public static function fileOf(string $directory, string $form): string
    {
        return $directory . '/' . self::PREFIX . $form . '.php';
    }

    private static function read(string $directory): self
    {
        $onDisk = glob($directory . '/' . self::PREFIX . '*.php');

        foreach ($onDisk === false ? [] : $onDisk as $path) {
            $form = substr(basename($path, '.php'), \strlen(self::PREFIX));

            if (!isset(self::FILES[$form])) {
                throw new GateError(\sprintf(
                    '%s is a wiring file no form owns. The forms are a fixed list (Wiring::FILES), so a file outside'
                    . ' it would be read by nothing that is accountable for it.',
                    $path,
                ));
            }
        }

        $lists = [];
        $pending = [];

        foreach (self::FILES as $form => $package) {
            $path = self::fileOf($directory, $form);

            if (!is_file($path)) {
                throw new GateError(\sprintf('The wiring file of %s (%s) does not exist.', $package, $path));
            }

            $wiring = require $path;

            if (!\is_array($wiring)) {
                throw new GateError(\sprintf('%s must return an array.', $path));
            }

            $unknown = array_diff(array_keys($wiring), self::KEYS);

            if ($unknown !== []) {
                throw new GateError(\sprintf('%s registers under unknown key(s): %s.', $path, implode(', ', $unknown)));
            }

            foreach ($wiring as $key => $entries) {
                if ($key === 'pending') {
                    $pending = [...$pending, ...self::pendingOf($path, $package, $entries, $pending)];

                    continue;
                }

                $lists[$key] = [...$lists[$key] ?? [], ...self::entriesOf($path, $key, $entries)];
            }

            foreach (['caseChecks', 'surfaceStages', 'runChecks', 'derivations'] as $key) {
                foreach (self::entriesOf($path, $key, $wiring[$key] ?? []) as $class) {
                    if (!\in_array($class, self::entriesOf($path, 'classes', $wiring['classes'] ?? []), true)) {
                        throw new GateError(\sprintf(
                            '%s registers %s under "%s" without listing it under "classes", so nothing would load it.',
                            $path,
                            $class,
                            $key,
                        ));
                    }
                }
            }
        }

        foreach ($lists as $key => $entries) {
            if (\count(array_unique($entries)) !== \count($entries)) {
                throw new GateError(\sprintf('The wiring files register one entry under "%s" twice.', $key));
            }
        }

        return new self($directory, $lists, $pending);
    }

    /** @return list<string> */
    private static function entriesOf(string $path, string $key, mixed $entries): array
    {
        if (!\is_array($entries) || !array_is_list($entries)) {
            throw new GateError(\sprintf('%s: "%s" must be a list of strings.', $path, $key));
        }

        $pattern = \in_array($key, ['controls', 'selfTest', 'witnesses'], true)
            ? '~^[A-Z]\w*::[a-z]\w*$~'
            : '~^[A-Z]\w*$~';
        $valid = [];

        foreach ($entries as $entry) {
            if (!\is_string($entry) || preg_match($pattern, $entry) !== 1) {
                throw new GateError(\sprintf(
                    '%s: "%s" carries %s, which is not a %s.',
                    $path,
                    $key,
                    json_encode($entry),
                    $pattern === '~^[A-Z]\w*$~' ? 'class name' : '"Class::method" entry',
                ));
            }

            $valid[] = $entry;
        }

        return $valid;
    }

    /**
     * @param Pending $already
     *
     * @return Pending
     */
    private static function pendingOf(string $path, string $package, mixed $entries, array $already): array
    {
        if (!\is_array($entries)) {
            throw new GateError(\sprintf('%s: "pending" must map a failure class to [marker, reason].', $path));
        }

        $pending = [];

        foreach ($entries as $class => $row) {
            if (!\is_string($class) || !\is_array($row) || !\is_string($row[0] ?? null) || !\is_string($row[1] ?? null)
                || \count($row) !== 2
            ) {
                throw new GateError(\sprintf('%s: "pending" must map a failure class to [marker, reason].', $path));
            }

            if ($row[0] !== 'pending: ' . $package) {
                throw new GateError(\sprintf(
                    '%s: the pending row of %s is marked "%s". A form file excuses only its own package\'s classes,'
                    . ' as "pending: %s".',
                    $path,
                    $class,
                    $row[0],
                    $package,
                ));
            }

            if (isset($already[$class])) {
                throw new GateError(\sprintf('%s: %s is already pending in another form file.', $path, $class));
            }

            $pending[$class] = [$row[0], $row[1]];
        }

        return $pending;
    }
}
