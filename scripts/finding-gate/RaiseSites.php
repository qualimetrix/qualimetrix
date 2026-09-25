<?php

declare(strict_types=1);

namespace QmxFindingGate;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every place the gate's source raises a failure class, and every way into it.
 *
 * A site is a `->fail(FailureClass::X, ...)` call, named `Class::method`, with
 * `#n` in source order when a method raises more than once. A check reached
 * through a shared wrapper is only as witnessed as the caller a run went
 * through, so a site reachable from outside its class is enumerated once per
 * caller: `Site <- Caller::method`, where the caller is the nearest method of
 * another scanned class whose call leads into the site's class and on to it.
 * Callers are found by name — `->m(` for an instance method, `Class::m(` for a
 * static one — which can over-approximate a caller but not miss one that is
 * spelled.
 *
 * What the scan cannot read it refuses rather than skips: a `fail` it does not
 * recognise as that call, `'fail'` as a string (a callable), a method called
 * through a variable, a second `fail()` definition, and a `GateReport` that
 * could be extended. What it still cannot see — a caller inside the site's own
 * class, a path above the nearest caller, a callable built from parts — is the
 * reason {@see CheckWitnesses} also refuses a raise it observed that this
 * enumeration does not name.
 *
 * @phpstan-type Site array{class: string, file: string, line: int, site: string, caller: string|null}
 * @phpstan-type Call array{name: string, qualifier: string|null, this: bool}
 * @phpstan-type Method array{static: bool, calls: list<Call>}
 */
final class RaiseSites
{
    /**
     * Files that raise failures to test the gate rather than to judge a run:
     * the verdict cases fail a report of their own.
     */
    private const array NOT_CHECKS = ['CheckWitnesses', 'SyntheticTree', 'WitnessRegistry', 'RaiseSites'];

    private const string NOT_CHECKS_PREFIX = 'SelfTest';

    /** PHPUnit's, which test the gate and are not part of it. */
    private const string TESTS = 'tests';

    private const string REPORT = 'GateReport';

    /**
     * @param array<string, Site> $sites identity => site
     * @param list<string> $problems
     * @param list<string> $classes the scanned classes, by short name
     */
    private function __construct(
        public readonly array $sites,
        public readonly array $problems,
        public readonly array $classes,
    ) {}

    public static function of(string $directory): self
    {
        $raises = [];
        $methods = [];
        $problems = [];

        foreach (self::files($directory) as $file) {
            $read = self::read($file);
            $methods[$read['class']] = $read['methods'];
            $problems = [...$problems, ...$read['problems']];

            foreach ($read['raises'] as $raise) {
                $raises[$read['class'] . '::' . $raise['method']][] = [
                    'class' => $raise['class'],
                    'file' => $file,
                    'line' => $raise['line'],
                    'type' => $read['class'],
                    'method' => $raise['method'],
                ];
            }
        }

        $sites = [];

        foreach ($raises as $owner => $calls) {
            foreach ($calls as $index => $call) {
                $site = \count($calls) === 1 ? $owner : $owner . '#' . ($index + 1);
                $callers = self::callers($methods, $call['type'], $call['method']);
                $entry = ['class' => $call['class'], 'file' => $call['file'], 'line' => $call['line'], 'site' => $site];

                if ($callers === []) {
                    $sites[$site] = [...$entry, 'caller' => null];

                    continue;
                }

                foreach ($callers as $caller) {
                    $sites[$site . ' <- ' . $caller] = [...$entry, 'caller' => $caller];
                }
            }
        }

        ksort($sites);

        return new self($sites, $problems, array_keys($methods));
    }

    /**
     * The identity a raise observed at run time has: its site, and the nearest
     * frame of another scanned class on the way to it.
     *
     * @param list<string> $chain `Class::method` frames from the raising method outwards
     */
    public function identityOf(string $site, array $chain): string
    {
        $raising = substr($site, 0, (int) strpos($site, '::'));

        foreach ($chain as $frame) {
            $class = substr($frame, 0, (int) strpos($frame, '::'));

            if ($class === $raising) {
                continue;
            }

            return \in_array($class, $this->classes, true) ? $site . ' <- ' . $frame : $site;
        }

        return $site;
    }

    /** @return list<string> */
    private static function files(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($directory) + 1);
            $name = $file->getBasename('.php');

            if (str_starts_with($relative, self::TESTS . '/') || \in_array($name, self::NOT_CHECKS, true)
                || str_starts_with($name, self::NOT_CHECKS_PREFIX)
            ) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * The methods of the site's class that lead to it, and the methods of
     * other classes that call one of them.
     *
     * @param array<string, array<string, Method>> $methods
     *
     * @return list<string>
     */
    private static function callers(array $methods, string $type, string $method): array
    {
        $reaching = [$method => true];
        $grew = true;

        while ($grew) {
            $grew = false;

            foreach ($methods[$type] ?? [] as $name => $body) {
                if (isset($reaching[$name])) {
                    continue;
                }

                foreach ($body['calls'] as $call) {
                    $internal = $call['this'] || \in_array($call['qualifier'], ['self', 'static', $type], true);

                    if ($internal && isset($reaching[$call['name']])) {
                        $reaching[$name] = true;
                        $grew = true;

                        break;
                    }
                }
            }
        }

        $callers = [];

        foreach ($methods as $other => $bodies) {
            if ($other === $type) {
                continue;
            }

            foreach ($bodies as $name => $body) {
                foreach ($body['calls'] as $call) {
                    $target = $methods[$type][$call['name']] ?? null;

                    if ($target === null || !isset($reaching[$call['name']])) {
                        continue;
                    }

                    $reachesIt = $target['static'] ? $call['qualifier'] === $type : $call['qualifier'] === null && !$call['this'];

                    if ($reachesIt) {
                        $callers[$other . '::' . $name] = true;
                    }
                }
            }
        }

        $callers = array_keys($callers);
        sort($callers);

        return $callers;
    }

    /**
     * @return array{
     *     class: string,
     *     methods: array<string, Method>,
     *     raises: list<array{method: string, class: string, line: int}>,
     *     problems: list<string>,
     * }
     */
    private static function read(string $file): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize(Fs::read($file)),
            static fn(PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $type = basename($file, '.php');
        $method = '(file)';
        $static = false;
        $statics = [$method => false];
        $calls = [$method => []];
        $raises = [];
        $problems = [];
        $unreadable = static function (int $line, string $what) use ($file, &$problems): void {
            $problems[] = \sprintf(
                'witness registry: %s:%d %s, which the scan of raise sites cannot read, so no witness could be held to'
                . ' it. Raise failures as $report->fail(FailureClass::X, ...).',
                $file,
                $line,
                $what,
            );
        };

        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;

            if ($token->is(\T_STATIC) && $next !== null && $next->is(\T_FUNCTION)) {
                $static = true;
            }

            if ($token->is(\T_FUNCTION) && $next !== null && $next->is(\T_STRING)) {
                $method = $next->text;
                $statics[$method] = $static || ($previous !== null && $previous->is(\T_STATIC));
                $calls[$method] = [];
                $static = false;

                if (strtolower($method) === 'fail' && $type !== self::REPORT) {
                    $unreadable($token->line, 'declares a method fail()');
                }

                continue;
            }

            if ($token->is(\T_FUNCTION)) {
                $static = false;
            }

            if ($token->is(\T_CONSTANT_ENCAPSED_STRING) && strtolower(trim($token->text, '\'"')) === 'fail') {
                $unreadable($token->line, 'names "fail" as a string');

                continue;
            }

            if ($token->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON]) && self::callsByVariable($tokens, $index + 1)) {
                $unreadable($token->line, 'calls a method whose name is a variable');

                continue;
            }

            if (!$token->is(\T_STRING) || $previous === null) {
                continue;
            }

            if ($previous->is(\T_FUNCTION)) {
                continue;
            }

            $called = $next !== null && $next->text === '(';
            $object = $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]);

            if ($called && ($object || $previous->is(\T_DOUBLE_COLON))) {
                $receiver = $tokens[$index - 2] ?? null;
                $calls[$method][] = [
                    'name' => $token->text,
                    'qualifier' => $object ? null : ($receiver === null ? '' : $receiver->text),
                    'this' => $object && $receiver !== null && $receiver->is(\T_VARIABLE) && $receiver->text === '$this',
                ];
            }

            if (strtolower($token->text) !== 'fail') {
                continue;
            }

            $argument = \array_slice($tokens, $index + 2, 3);
            $constant = $called && $object && \count($argument) === 3 && $argument[0]->text === 'FailureClass'
                && $argument[1]->is(\T_DOUBLE_COLON)
                ? FailureClass::class . '::' . $argument[2]->text
                : null;
            $class = $constant !== null && \defined($constant) ? \constant($constant) : null;

            if (!\is_string($class)) {
                $unreadable($token->line, 'raises a failure whose class is not a FailureClass constant, or reaches fail() another way');

                continue;
            }

            $raises[] = ['method' => $method, 'class' => $class, 'line' => $token->line];
        }

        if ($type === self::REPORT && !self::isFinal($tokens)) {
            $unreadable(1, 'declares a GateReport that can be extended, so fail() could be overridden');
        }

        $methods = [];

        foreach ($statics as $name => $isStatic) {
            $methods[$name] = ['static' => $isStatic, 'calls' => $calls[$name]];
        }

        return ['class' => $type, 'methods' => $methods, 'raises' => $raises, 'problems' => $problems];
    }

    /**
     * Whether the member name starting at `$at` is a variable or an expression
     * that is then called — `->$name(` or `->{$expression}(` — rather than read.
     *
     * @param list<PhpToken> $tokens
     */
    private static function callsByVariable(array $tokens, int $at): bool
    {
        $name = $tokens[$at] ?? null;

        if ($name === null) {
            return false;
        }

        if ($name->is(\T_VARIABLE)) {
            return ($tokens[$at + 1] ?? null)?->text === '(';
        }

        if ($name->text !== '{') {
            return false;
        }

        $depth = 0;

        for ($index = $at, $count = \count($tokens); $index < $count; ++$index) {
            $text = $tokens[$index]->text;
            $depth += match ($text) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return ($tokens[$index + 1] ?? null)?->text === '(';
            }
        }

        return false;
    }

    /** @param list<PhpToken> $tokens */
    private static function isFinal(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            $before = $tokens[$index - 1] ?? null;

            if (!$token->is(\T_CLASS) || ($before !== null && $before->is(\T_DOUBLE_COLON))) {
                continue;
            }

            foreach ([$before, $tokens[$index - 2] ?? null] as $modifier) {
                if ($modifier !== null && $modifier->is(\T_FINAL)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
