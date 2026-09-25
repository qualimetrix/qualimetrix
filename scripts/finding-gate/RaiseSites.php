<?php

declare(strict_types=1);

namespace QmxFindingGate;

use FilesystemIterator;
use ParseError;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Every place the gate's source raises a failure class, and every way into it.
 *
 * A site is a `->fail(FailureClass::X, ...)` call, named `Class::method`, with
 * `#n` in source order when a method raises more than once. A check reached
 * through a shared wrapper is only as witnessed as the caller a run went
 * through, so a site reachable from outside its class is enumerated once per
 * caller: `Site <- Caller::method`, where the caller is the nearest method of
 * another scanned class whose direct call leads into the site's class and on to
 * it. Names of methods and classes compare without case, as PHP resolves them;
 * an imported class is resolved through its `use`.
 *
 * Denied by default. The name of every method that leads to a raise may occur
 * in the scanned files in three ways only:
 *
 * - its declaration;
 * - a direct call with arguments (`(...$spread)` included, `(...)` not):
 *   `$this->m(`, `self::`/`static::m(`, `parent::m(` of a scanned `extends`,
 *   and `X::m(` for a scanned `X` however it is qualified or imported, each
 *   resolved to the class declaring `m`; and `$object->m(` on any other
 *   receiver, which is matched to every scanned class declaring `m` — wide on
 *   purpose, since the receiver's type is not read;
 * - a line of {@see self::DECLARED_NAMES} with its reason: data that only
 *   happens to spell the name, or an `$object->m(` call whose receiver is
 *   declared not to be a scanned class, which then leaves the call graph.
 *
 * An occurrence is an identifier, the last segment of a qualified name, a
 * string whose value is the name or ends in `::name` — a whole literal
 * (`b'...'`, heredoc and nowdoc included) as PHP computes its value, a part of
 * an interpolated string read raw, trimmed and, when it holds a `\`, decoded
 * too — and every use of a constant whose literal value is such a name
 * (`X::C`, `self::C`, `static::C`, `parent::C`, a global `const` or
 * `define()`, a string naming it). A property access `->m` and a named
 * argument `m:` are not. A
 * declared line covers that line only, never the uses of a constant it
 * defines; one that matches no occurrence or more than one is refused. A file
 * that does not parse, and an import the scan cannot read, are refused too.
 *
 * On the raising side the name `fail` is reserved the same way: a `fail` that
 * is not `->fail(FailureClass::X, ...)`, a string whose value is `fail`, a
 * method called through a variable name, a second `fail()` definition and a
 * `GateReport` that could be extended are refused.
 *
 * What stays unseen:
 *
 * - a name assembled at run time from parts — concatenation, `sprintf`,
 *   interpolation, a value read from data;
 * - a call through `$this->m()` that PHP dispatches to a subclass overriding
 *   `m`, which is attributed to the declaring class only;
 * - two paths into a site that share the same nearest caller;
 * - the decisions a signal arriving in the tail of a derive run takes.
 *
 * A raise that such a path reaches in a run is refused by
 * {@see CheckWitnesses}, which holds every observed raise to this enumeration.
 *
 * @phpstan-type Site array{class: string, file: string, line: int, site: string, caller: string|null}
 * @phpstan-type Call array{name: string, qualifier: string|null, this: bool, index: int}
 * @phpstan-type Method array{static: bool, calls: list<Call>}
 * @phpstan-type Constant array{name: string, owner: string|null, names: list<string>}
 * @phpstan-type Read array{
 *     class: string,
 *     file: string,
 *     relative: string,
 *     parent: string|null,
 *     methods: array<string, Method>,
 *     raises: list<array{method: string, class: string, line: int}>,
 *     constants: list<Constant>,
 *     aliases: array<string, string>,
 *     problems: list<string>,
 *     tokens: list<PhpToken>,
 *     names: array<int, list<string>|false>,
 *     lines: list<string>,
 * }
 */
final class RaiseSites
{
    /**
     * Occurrences of a leading method's name that are data or a call on a
     * receiver that is not a scanned class: file under the scanned directory,
     * the trimmed source line, and why.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const array DECLARED_NAMES = [
        [
            'Options.php',
            "public const MODE_COMPARE = 'compare';",
            'the name of the command-line mode, which only happens to equal Gate::compare()',
        ],
        [
            'Options.php',
            '$mode = self::MODE_COMPARE;',
            'the mode a command line without a mode flag runs in, compared as a string and never called',
        ],
        [
            'Options.php',
            'if (\in_array($mode, [self::MODE_COMPARE, self::MODE_DERIVE_DECLARED_DELTA], true) && $reference === null) {',
            'the modes that need a reference, compared as strings and never called',
        ],
        [
            'GateModes.php',
            'Options::MODE_COMPARE => self::compare($options, $report),',
            'a match arm keyed by the mode string; the call beside it is direct',
        ],
        [
            'TreeRun.php',
            "['check', ...\$case->paths, ...self::CHECK_ARGUMENTS, '-c', \$config, '-f', \$format, ...\$arguments],",
            'the product command `bin/qmx check`, an argument of a process, not a method of the gate',
        ],
        [
            'TreeRun.php',
            "['check', ...\$case->paths, ...self::CHECK_ARGUMENTS, '-c', \$config, '-f', 'text', '--show-suppressed', ...\$arguments],",
            'the product command `bin/qmx check`, an argument of a process, not a method of the gate',
        ],
    ];

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

    /** @param list<array{0: string, 1: string, 2: string}> $declared see {@see self::DECLARED_NAMES} */
    public static function of(string $directory, array $declared): self
    {
        $reads = [];
        $problems = [];

        foreach (self::files($directory) as $file) {
            $read = self::read($file, substr($file, \strlen($directory) + 1));

            if (\is_string($read)) {
                $problems[] = $read;

                continue;
            }

            if (isset($reads[strtolower($read['class'])])) {
                $problems[] = \sprintf(
                    'witness registry: %s declares a second class named %s, and callers are told apart by short name'
                    . ' only.',
                    $file,
                    $read['class'],
                );

                continue;
            }

            $reads[strtolower($read['class'])] = $read;
            $problems = [...$problems, ...$read['problems']];
        }

        $leading = [];

        foreach ($reads as $read) {
            foreach (array_unique(array_column($read['raises'], 'method')) as $method) {
                foreach (self::reaching($reads, $read['class'], $method) as $name) {
                    $leading[strtolower($name)][] = $read['class'] . '::' . $name;
                }
            }
        }

        $leading = array_map(static fn(array $owners): array => array_values(array_unique($owners)), $leading);
        [$occurrences, $phantoms] = self::occurrences($reads, $leading, self::carriers($reads, $leading), $declared);

        foreach ($phantoms as $class => $indexes) {
            foreach ($reads[$class]['methods'] as $name => $body) {
                $reads[$class]['methods'][$name]['calls'] = array_values(array_filter(
                    $body['calls'],
                    static fn(array $call): bool => !isset($indexes[$call['index']]),
                ));
            }
        }

        return new self(
            self::sites($reads),
            [...$problems, ...$occurrences],
            array_values(array_map(static fn(array $read): string => $read['class'], $reads)),
        );
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

    /**
     * @param array<string, Read> $reads
     *
     * @return array<string, Site>
     */
    private static function sites(array $reads): array
    {
        $sites = [];

        foreach ($reads as $read) {
            $raises = [];

            foreach ($read['raises'] as $raise) {
                $raises[$raise['method']][] = $raise;
            }

            foreach ($raises as $method => $calls) {
                $callers = self::callers($reads, $read['class'], $method);

                foreach ($calls as $index => $call) {
                    $site = $read['class'] . '::' . $method . (\count($calls) === 1 ? '' : '#' . ($index + 1));
                    $entry = ['class' => $call['class'], 'file' => $read['file'], 'line' => $call['line'], 'site' => $site];

                    if ($callers === []) {
                        $sites[$site] = [...$entry, 'caller' => null];

                        continue;
                    }

                    foreach ($callers as $caller) {
                        $sites[$site . ' <- ' . $caller] = [...$entry, 'caller' => $caller];
                    }
                }
            }
        }

        ksort($sites);

        return $sites;
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
     * The class that declares `$method` for a call on `$type`, walking the
     * scanned `extends`, or null.
     *
     * @param array<string, Read> $reads
     */
    private static function declaring(array $reads, ?string $type, string $method): ?string
    {
        $seen = [];

        while ($type !== null && isset($reads[strtolower($type)]) && !isset($seen[strtolower($type)])) {
            $read = $reads[strtolower($type)];
            $seen[strtolower($type)] = true;

            foreach (array_keys($read['methods']) as $declared) {
                if (strcasecmp($declared, $method) === 0) {
                    return $read['class'];
                }
            }

            $type = $read['parent'];
        }

        return null;
    }

    /**
     * The classes a direct call from `$from` may reach: for `$object->m(` on
     * any receiver but `$this`, every scanned class declaring `m`; for every
     * other call, the one class declaring it along the scanned `extends`. Empty
     * when no scanned class declares it.
     *
     * @param array<string, Read> $reads
     * @param Call $call
     *
     * @return list<string>
     */
    private static function reached(array $reads, string $from, array $call): array
    {
        $qualifier = $call['qualifier'];

        if ($qualifier === null && !$call['this']) {
            $all = [];

            foreach ($reads as $read) {
                $declaring = self::declaring($reads, $read['class'], $call['name']);

                if ($declaring !== null) {
                    $all[$declaring] = true;
                }
            }

            return array_keys($all);
        }

        $start = match (true) {
            $call['this'], \in_array($qualifier, ['self', 'static'], true) => $from,
            $qualifier === 'parent' => $reads[strtolower($from)]['parent'] ?? null,
            default => $qualifier,
        };
        $declaring = self::declaring($reads, $start, $call['name']);

        return $declaring === null ? [] : [$declaring];
    }

    /**
     * The methods of a class that lead to one of its methods through its own
     * direct calls.
     *
     * @param array<string, Read> $reads
     *
     * @return list<string>
     */
    private static function reaching(array $reads, string $type, string $method): array
    {
        $reaching = [strtolower($method) => $method];
        $grew = true;

        while ($grew) {
            $grew = false;

            foreach ($reads[strtolower($type)]['methods'] as $name => $body) {
                if (isset($reaching[strtolower($name)])) {
                    continue;
                }

                foreach ($body['calls'] as $call) {
                    $internal = $call['this'] || \in_array($call['qualifier'], ['self', 'static', strtolower($type)], true);

                    if ($internal && isset($reaching[$call['name']])) {
                        $reaching[strtolower($name)] = $name;
                        $grew = true;

                        break;
                    }
                }
            }
        }

        return array_values($reaching);
    }

    /**
     * The methods of other classes whose direct calls reach one of the
     * methods of the site's class that lead to it.
     *
     * @param array<string, Read> $reads
     *
     * @return list<string>
     */
    private static function callers(array $reads, string $type, string $method): array
    {
        $reaching = array_flip(array_map(strtolower(...), self::reaching($reads, $type, $method)));
        $callers = [];

        foreach ($reads as $read) {
            if ($read['class'] === $type) {
                continue;
            }

            foreach ($read['methods'] as $name => $body) {
                foreach ($body['calls'] as $call) {
                    if (isset($reaching[$call['name']]) && \in_array($type, self::reached($reads, $read['class'], $call), true)) {
                        $callers[$read['class'] . '::' . $name] = true;
                    }
                }
            }
        }

        $callers = array_keys($callers);
        sort($callers);

        return $callers;
    }

    /**
     * The constants whose literal value names a leading method: lower-case
     * constant name => the constants, each with its owning class (null for a
     * global one) and the `Class::method`s it names.
     *
     * @param array<string, Read> $reads
     * @param array<string, list<string>> $leading
     *
     * @return array<string, list<array{owner: string|null, names: list<string>}>>
     */
    private static function carriers(array $reads, array $leading): array
    {
        $carriers = [];

        foreach ($reads as $read) {
            foreach ($read['constants'] as $constant) {
                $named = self::leadingIn($constant['names'], $leading);

                if ($named !== []) {
                    $carriers[strtolower($constant['name'])][] = ['owner' => $constant['owner'], 'names' => $named];
                }
            }
        }

        return $carriers;
    }

    /**
     * The `Class::method`s any of these names is.
     *
     * @param list<string> $names
     * @param array<string, list<string>> $leading
     *
     * @return list<string>
     */
    private static function leadingIn(array $names, array $leading): array
    {
        $named = [];

        foreach ($names as $name) {
            $named = [...$named, ...$leading[$name] ?? []];
        }

        return array_values(array_unique($named));
    }

    /**
     * Every occurrence of a leading method's name — or of a constant carrying
     * one — that is not its declaration, a direct call, or a declared line;
     * and the calls a declared line takes out of the call graph.
     *
     * @param array<string, Read> $reads
     * @param array<string, list<string>> $leading lower-case name => the `Class::method`s it names
     * @param array<string, list<array{owner: string|null, names: list<string>}>> $carriers
     * @param list<array{0: string, 1: string, 2: string}> $declared
     *
     * @return array{0: list<string>, 1: array<string, array<int, true>>}
     */
    private static function occurrences(array $reads, array $leading, array $carriers, array $declared): array
    {
        $problems = [];
        $phantoms = [];
        $matched = array_fill(0, \count($declared), 0);

        foreach ($reads as $key => $read) {
            $tokens = $read['tokens'];

            foreach ($tokens as $index => $token) {
                [$named, $phantomCall] = self::judge($reads, $read, $index, $leading, $carriers);

                if ($named === null) {
                    continue;
                }

                if ($named === false) {
                    $problems[] = \sprintf(
                        'witness registry: %s:%d has a string literal whose value the scan cannot compute, so it cannot'
                        . ' tell whether it names a method that leads to a raise.',
                        $read['file'],
                        $token->line,
                    );

                    continue;
                }

                $line = trim($read['lines'][$token->line - 1] ?? '');
                $covering = 0;

                foreach ($declared as $row => [$file, $text]) {
                    if ($file === $read['relative'] && $text === $line) {
                        ++$covering;
                        ++$matched[$row];
                    }
                }

                if ($covering > 1) {
                    $problems[] = \sprintf(
                        'witness registry: %s:%d is declared %d times in RaiseSites::DECLARED_NAMES; declare it once.',
                        $read['file'],
                        $token->line,
                        $covering,
                    );
                }

                if ($covering > 0) {
                    if ($phantomCall) {
                        $phantoms[$key][$index] = true;
                    }

                    continue;
                }

                if ($phantomCall) {
                    continue;
                }

                $problems[] = \sprintf(
                    'witness registry: %s:%d names %s, which leads to a raise, other than by declaring it or calling it'
                    . ' directly with arguments on a class the scan resolves, so the scan cannot follow it to where it is'
                    . ' called and no witness could be held to what it reaches. Call the method directly, or declare the'
                    . ' line in RaiseSites::DECLARED_NAMES with the reason it is data.',
                    $read['file'],
                    $token->line,
                    implode(' or ', $named),
                );
            }
        }

        foreach ($declared as $row => [$file, $text, $reason]) {
            if (trim($reason) === '') {
                $problems[] = \sprintf('witness registry: the declared name at %s "%s" gives no reason.', $file, $text);
            }

            if ($matched[$row] !== 1) {
                $problems[] = \sprintf(
                    'witness registry: the declared name at %s "%s" matches %d occurrence(s) of a leading method\'s name,'
                    . ' and a declaration covers exactly one. Correct or remove it.',
                    $file,
                    $text,
                    $matched[$row],
                );
            }
        }

        return [$problems, $phantoms];
    }

    /**
     * What one token names: null for nothing to judge, false for a string the
     * scan cannot compute, or the `Class::method`s it names; and whether it is
     * a direct call on a receiver whose type is not read, which a declared
     * line may take out of the call graph instead of refusing.
     *
     * @param array<string, Read> $reads
     * @param Read $read
     * @param array<string, list<string>> $leading
     * @param array<string, list<array{owner: string|null, names: list<string>}>> $carriers
     *
     * @return array{0: list<string>|false|null, 1: bool}
     */
    private static function judge(array $reads, array $read, int $index, array $leading, array $carriers): array
    {
        $tokens = $read['tokens'];
        $token = $tokens[$index];
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        if (\array_key_exists($index, $read['names'])) {
            $names = $read['names'][$index];

            if ($names === false) {
                return [false, false];
            }

            $named = self::leadingIn($names, $leading);

            foreach ($names as $name) {
                $named = [...$named, ...isset($carriers[$name]) ? self::carried($carriers[$name]) : []];
            }

            return [$named === [] ? null : array_values(array_unique($named)), false];
        }

        if ($token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE])) {
            if ($next !== null && $next->is(\T_DOUBLE_COLON)) {
                return [null, false];
            }

            $name = self::lastSegment($token->text);

            return [$leading[$name] ?? (isset($carriers[$name]) ? self::carriedGlobal($carriers[$name]) : null), false];
        }

        if (!$token->is(\T_STRING) || $previous === null) {
            return [null, false];
        }

        $name = strtolower($token->text);
        $object = $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]);
        $called = $next !== null && $next->text === '(';

        if ($previous->is([\T_FUNCTION, \T_CONST]) || ($object && !$called)
            || ($next !== null && $next->text === ':' && \in_array($previous->text, ['(', ','], true))
            || ($next !== null && $next->text === '=' && self::definesConstant($tokens, $index))
        ) {
            return [null, false];
        }

        if (isset($leading[$name])) {
            if (!$called || self::isFirstClassCallable($tokens, $index)) {
                return [$leading[$name], false];
            }

            if ($object) {
                $receiver = $tokens[$index - 2] ?? null;
                $onThis = $receiver !== null && $receiver->is(\T_VARIABLE) && $receiver->text === '$this';

                return $onThis ? [null, false] : [$leading[$name], true];
            }

            if (!$previous->is(\T_DOUBLE_COLON)) {
                return [$leading[$name], false];
            }

            return [self::resolvesStatic($reads, $read, $index) ? null : $leading[$name], false];
        }

        if (!isset($carriers[$name]) || $called) {
            return [null, false];
        }

        if (!$previous->is(\T_DOUBLE_COLON)) {
            return [self::carriedGlobal($carriers[$name]), false];
        }

        $receiver = $tokens[$index - 2] ?? null;
        $qualifier = $receiver === null ? null : self::qualifier($receiver, $read['aliases']);
        $class = match (true) {
            $qualifier === null => null,
            \in_array($qualifier, ['self', 'static'], true) => strtolower($read['class']),
            $qualifier === 'parent' => $read['parent'] === null ? null : strtolower($read['parent']),
            default => $qualifier,
        };
        $owned = array_values(array_filter(
            $carriers[$name],
            static fn(array $carrier): bool => $carrier['owner'] !== null
                && ($class === null || !isset($reads[$class]) || self::extendsOrIs($reads, $class, $carrier['owner'])),
        ));

        return [$owned === [] ? null : self::carried($owned), false];
    }

    /**
     * Whether `$type`, walking the scanned `extends`, is or inherits from `$owner`.
     *
     * @param array<string, Read> $reads
     */
    private static function extendsOrIs(array $reads, string $type, string $owner): bool
    {
        $seen = [];

        while ($type !== '' && !isset($seen[$type])) {
            if ($type === $owner) {
                return true;
            }

            $seen[$type] = true;
            $type = strtolower($reads[$type]['parent'] ?? '');
        }

        return false;
    }

    /**
     * @param list<array{owner: string|null, names: list<string>}> $carriers
     *
     * @return list<string>
     */
    private static function carried(array $carriers): array
    {
        return array_values(array_unique(array_merge(...array_column($carriers, 'names'))));
    }

    /**
     * @param list<array{owner: string|null, names: list<string>}> $carriers
     *
     * @return list<string>|null
     */
    private static function carriedGlobal(array $carriers): ?array
    {
        $global = array_values(array_filter($carriers, static fn(array $carrier): bool => $carrier['owner'] === null));

        return $global === [] ? null : self::carried($global);
    }

    /** @param list<PhpToken> $tokens */
    private static function isFirstClassCallable(array $tokens, int $index): bool
    {
        return ($tokens[$index + 2] ?? null)?->is(\T_ELLIPSIS) === true && ($tokens[$index + 3] ?? null)?->text === ')';
    }

    /** @param list<PhpToken> $tokens */
    private static function definesConstant(array $tokens, int $index): bool
    {
        for ($at = $index - 1; $at >= 0; --$at) {
            $text = $tokens[$at]->text;

            if ($tokens[$at]->is([\T_CONST, \T_CASE])) {
                return true;
            }

            if (\in_array($text, [';', '{', '}'], true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Whether a static call `Q::m(` names a class the scan resolves.
     *
     * @param array<string, Read> $reads
     * @param Read $read
     */
    private static function resolvesStatic(array $reads, array $read, int $index): bool
    {
        $receiver = $read['tokens'][$index - 2] ?? null;
        $qualifier = $receiver === null ? null : self::qualifier($receiver, $read['aliases']);

        return match (true) {
            $qualifier === null => false,
            \in_array($qualifier, ['self', 'static'], true) => true,
            $qualifier === 'parent' => $read['parent'] !== null && isset($reads[strtolower($read['parent'])]),
            default => isset($reads[$qualifier]),
        };
    }

    /**
     * The lower-case short class name a static receiver names, through the
     * file's imports, or null for a variable or an expression.
     *
     * @param array<string, string> $aliases
     */
    private static function qualifier(PhpToken $receiver, array $aliases): ?string
    {
        if ($receiver->is(\T_STATIC)) {
            return 'static';
        }

        if ($receiver->is(\T_STRING)) {
            return $aliases[strtolower($receiver->text)] ?? strtolower($receiver->text);
        }

        if ($receiver->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE])) {
            return self::lastSegment($receiver->text);
        }

        return null;
    }

    private static function lastSegment(string $name): string
    {
        return strtolower(substr((string) strrchr('\\' . $name, '\\'), 1));
    }

    /**
     * The names every string token of a file spells, by token index: the
     * lower-case value itself, or the part after its last `::`. `false` where
     * a value cannot be computed.
     *
     * A whole literal — a `T_CONSTANT_ENCAPSED_STRING`, `b'...'` included, or
     * a heredoc or nowdoc without interpolation — is evaluated as the literal
     * it is, so its value is PHP's own; that is safe, since such a literal
     * carries no interpolation. A part of an interpolated string is a fragment
     * of a value assembled at run time: it is read trimmed, raw and, when it
     * holds a `\`, also decoded as a double-quoted string and with every `\`
     * dropped, and any of those naming a method is enough.
     *
     * @param list<PhpToken> $tokens
     *
     * @return array<int, list<string>|false>
     */
    private static function stringNames(array $tokens): array
    {
        $names = [];
        $quoting = null;

        foreach ($tokens as $index => $token) {
            if ($token->text === '"' && !$token->is(\T_CONSTANT_ENCAPSED_STRING)) {
                $quoting = $quoting === 'double' ? null : 'double';

                continue;
            }

            if ($token->is(\T_START_HEREDOC)) {
                $quoting = 'heredoc';
                $body = $tokens[$index + 1] ?? null;
                $end = $body !== null && $body->is(\T_END_HEREDOC) ? $body : ($tokens[$index + 2] ?? null);

                if ($end !== null && $end->is(\T_END_HEREDOC)) {
                    $whole = $body === $end ? '' : (string) $body?->text;
                    $value = self::evaluate($token->text . $whole . $end->text);
                    $names[$body === $end ? $index : $index + 1] = $value === false ? false : [self::named($value)];
                    $quoting = 'whole';
                }

                continue;
            }

            if ($token->is(\T_END_HEREDOC)) {
                $quoting = null;

                continue;
            }

            if ($token->is(\T_CONSTANT_ENCAPSED_STRING)) {
                $value = self::evaluate($token->text);
                $names[$index] = $value === false ? false : [self::named($value)];
            } elseif ($token->is(\T_ENCAPSED_AND_WHITESPACE) && $quoting !== 'whole') {
                $readings = [$token->text];

                if (str_contains($token->text, '\\')) {
                    $readings[] = str_replace('\\', '', $token->text);
                    $decoded = self::evaluate('"' . ($quoting === 'double' ? $token->text : addcslashes($token->text, '"')) . '"');

                    if ($decoded !== false) {
                        $readings[] = $decoded;
                    }
                }

                $names[$index] = array_values(array_unique(array_map(
                    static fn(string $reading): string => self::named(trim($reading)),
                    $readings,
                )));
            }
        }

        return $names;
    }

    /** The lower-case name a value spells: all of it, or the part after its last `::`. */
    private static function named(string $value): string
    {
        $value = strtolower($value);
        $separator = strrpos($value, '::');

        return $separator === false ? $value : substr($value, $separator + 2);
    }

    private static function evaluate(string $literal): string|false
    {
        try {
            $value = eval('return ' . $literal . ';');
        } catch (Throwable) {
            return false;
        }

        return \is_string($value) ? $value : false;
    }

    /** @return Read|string the file read, or why it cannot be */
    private static function read(string $file, string $relative): array|string
    {
        $source = Fs::read($file);

        try {
            $all = PhpToken::tokenize($source, \TOKEN_PARSE);
        } catch (ParseError $error) {
            return \sprintf(
                'witness registry: %s does not parse (%s), so the scan of raise sites cannot read it.',
                $file,
                $error->getMessage(),
            );
        }

        $tokens = array_values(array_filter($all, static fn(PhpToken $token): bool => !$token->isIgnorable()));
        $names = self::stringNames($tokens);
        $type = basename($file, '.php');
        $parent = null;
        $method = '(file)';
        $pending = null;
        $depth = 0;
        $stack = [];
        $classSeen = false;
        $static = false;
        $statics = [$method => false];
        $calls = [$method => []];
        $raises = [];
        $constants = [];
        $defining = [];
        $aliases = [];
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

            if ($token->text === '{' || $token->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                if ($pending !== null) {
                    $stack[] = [$method, $depth];
                    $method = $pending;
                    $pending = null;
                }

                ++$depth;

                continue;
            }

            if ($token->text === '}') {
                --$depth;

                if ($stack !== [] && $stack[\count($stack) - 1][1] === $depth) {
                    [$method] = array_pop($stack);
                }

                continue;
            }

            if ($token->text === ';') {
                $pending = null;
            }

            if ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM]) && ($previous === null || !$previous->is(\T_DOUBLE_COLON))) {
                $classSeen = true;
            }

            if ($token->is(\T_USE) && !$classSeen && $method === '(file)') {
                $import = self::import($tokens, $index);

                if ($import === null) {
                    $unreadable($token->line, 'imports through a group `use`, which the scan does not resolve');
                } else {
                    $aliases = [...$aliases, ...$import];
                }

                continue;
            }

            if ($token->is(\T_EXTENDS) && $parent === null && $next !== null) {
                $parent = substr((string) strrchr('\\' . $next->text, '\\'), 1);
            }

            if ($token->is([\T_CONST, \T_CASE])) {
                $constants = [...$constants, ...self::constantsAt($tokens, $index, $names, $classSeen ? $type : null)];
            }

            if ($token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED]) && strtolower(ltrim($token->text, '\\')) === 'define'
                && $next !== null && $next->text === '('
                && \is_array($names[$index + 2] ?? null) && ($tokens[$index + 3] ?? null)?->text === ','
                && \is_array($names[$index + 4] ?? null) && ($tokens[$index + 5] ?? null)?->text === ')'
            ) {
                $defining[$index + 2] = true;

                foreach ($names[$index + 2] as $constant) {
                    $constants[] = ['name' => $constant, 'owner' => null, 'names' => $names[$index + 4]];
                }
            }

            if ($token->is(\T_STATIC) && $next !== null && $next->is(\T_FUNCTION)) {
                $static = true;
            }

            if ($token->is(\T_FUNCTION) && $next !== null && $next->is(\T_STRING)) {
                $pending = $next->text;
                $statics[$pending] = $static || ($previous !== null && $previous->is(\T_STATIC));
                $calls[$pending] = [];
                $static = false;

                if (strtolower($pending) === 'fail' && $type !== self::REPORT) {
                    $unreadable($token->line, 'declares a method fail()');
                }

                continue;
            }

            if ($token->is(\T_FUNCTION)) {
                $static = false;
            }

            if (\is_array($names[$index] ?? null) && \in_array('fail', $names[$index], true)) {
                $unreadable($token->line, 'names "fail" as a string');

                continue;
            }

            if ($token->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON]) && self::callsByVariable($tokens, $index + 1)) {
                $unreadable($token->line, 'calls a method whose name is a variable');

                continue;
            }

            if (!$token->is(\T_STRING) || $previous === null || $previous->is(\T_FUNCTION)) {
                continue;
            }

            $called = $next !== null && $next->text === '(';
            $object = $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]);
            $receiver = $tokens[$index - 2] ?? null;

            if ($called && ($object || $previous->is(\T_DOUBLE_COLON))) {
                $calls[$method][] = [
                    'name' => strtolower($token->text),
                    'qualifier' => $object || $receiver === null ? null : self::qualifier($receiver, $aliases) ?? '$',
                    'this' => $object && $receiver !== null && $receiver->is(\T_VARIABLE) && $receiver->text === '$this',
                    'index' => $index,
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

        return [
            'class' => $type,
            'file' => $file,
            'relative' => $relative,
            'parent' => $parent,
            'methods' => $methods,
            'raises' => $raises,
            'constants' => $constants,
            'aliases' => $aliases,
            'problems' => $problems,
            'tokens' => $tokens,
            'names' => array_diff_key($names, $defining),
            'lines' => explode("\n", $source),
        ];
    }

    /**
     * The aliases one `use` statement declares — lower-case alias => lower-case
     * short name of the class it imports — or null for a group `use`, which
     * this scan does not resolve.
     *
     * @param list<PhpToken> $tokens
     *
     * @return array<string, string>|null
     */
    private static function import(array $tokens, int $at): ?array
    {
        $aliases = [];
        $target = null;

        for ($index = $at + 1, $count = \count($tokens); $index < $count && $tokens[$index]->text !== ';'; ++$index) {
            $token = $tokens[$index];

            if ($token->text === '{') {
                return null;
            }

            if ($token->is([\T_FUNCTION, \T_CONST]) && $index === $at + 1) {
                return [];
            }

            $afterAs = ($tokens[$index - 1] ?? null)?->is(\T_AS) === true;

            if ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED]) && !$afterAs) {
                $target = self::lastSegment($token->text);
                $aliases[$target] = $target;
            }

            if ($token->is(\T_STRING) && $afterAs && $target !== null) {
                unset($aliases[$target]);
                $aliases[strtolower($token->text)] = $target;
            }
        }

        return $aliases;
    }

    /**
     * The constants one `const` or `case` statement defines with a literal value.
     *
     * @param list<PhpToken> $tokens
     * @param array<int, list<string>|false> $names
     *
     * @return list<Constant>
     */
    private static function constantsAt(array $tokens, int $at, array $names, ?string $owner): array
    {
        $constants = [];

        for ($index = $at + 1, $count = \count($tokens); $index < $count && !\in_array($tokens[$index]->text, [';', ':', '{'], true); ++$index) {
            if ($tokens[$index]->is(\T_STRING) && ($tokens[$index + 1] ?? null)?->text === '='
                && \is_array($names[$index + 2] ?? null)
                && \in_array(($tokens[$index + 3] ?? null)?->text, [';', ','], true)
            ) {
                $constants[] = ['name' => $tokens[$index]->text, 'owner' => $owner === null ? null : strtolower($owner), 'names' => $names[$index + 2]];
            }
        }

        return $constants;
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
