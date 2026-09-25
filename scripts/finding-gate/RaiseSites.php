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
 * another scanned class whose direct call leads into the site's class and on to
 * it. Names compare without case, as PHP resolves them.
 *
 * Denied by default. The name of every method that leads to a raise may occur
 * in the scanned files in three ways only: its declaration; a direct call with
 * arguments whose class the scan resolves (`->m(`, `$this->m(`, `self::`,
 * `static::`, `parent::` of a scanned `extends`, `X::m(` for a scanned `X`
 * however it is qualified); and a line declared in {@see self::DECLARED_NAMES}
 * with a reason. An occurrence is an identifier token, or a string literal
 * whose value is the name or ends in `::name`. Anything else — a callable in
 * any spelling, a first-class callable, a static call on a class the scan does
 * not know — is refused without a list of forms to outgrow. A declaration that
 * matches no occurrence, or more than one, is refused too.
 *
 * On the raising side the name `fail` is reserved: a `fail` that is not
 * `->fail(FailureClass::X, ...)`, `'fail'` as a string, a method called
 * through a variable name, a second `fail()` definition and a `GateReport`
 * that could be extended are refused. What stays unseen is a name assembled at
 * run time from parts — concatenation, `sprintf`, a value read from data — and
 * a caller inside the site's own class or above the nearest caller; a raise
 * that such a path reaches in a run is refused by {@see CheckWitnesses}, which
 * holds every observed raise to this enumeration.
 *
 * @phpstan-type Site array{class: string, file: string, line: int, site: string, caller: string|null}
 * @phpstan-type Call array{name: string, qualifier: string|null, this: bool}
 * @phpstan-type Method array{static: bool, calls: list<Call>}
 * @phpstan-type Read array{
 *     class: string,
 *     file: string,
 *     relative: string,
 *     parent: string|null,
 *     methods: array<string, Method>,
 *     raises: list<array{method: string, class: string, line: int}>,
 *     problems: list<string>,
 *     tokens: list<PhpToken>,
 *     lines: list<string>,
 * }
 */
final class RaiseSites
{
    /**
     * Occurrences of a leading method's name that are data, not a way to call
     * it: file under the scanned directory, the trimmed source line, and why.
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

        $sites = [];
        $leading = [];

        foreach ($reads as $read) {
            $raises = [];

            foreach ($read['raises'] as $raise) {
                $raises[$raise['method']][] = $raise;
            }

            foreach ($raises as $method => $calls) {
                foreach (self::reaching($reads, $read['class'], $method) as $name) {
                    $leading[strtolower($name)][] = $read['class'] . '::' . $name;
                }

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
        $leading = array_map(static fn(array $owners): array => array_values(array_unique($owners)), $leading);

        return new self(
            $sites,
            [...$problems, ...self::occurrences($reads, $leading, $declared)],
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
     * The class a direct call from `$from` reaches, or null when the call does
     * not name one: an instance call on another receiver answers with every
     * class, which is how a caller is over-approximated.
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
     * Every occurrence of a leading method's name that is neither its
     * declaration, nor a direct call the caller search reads, nor declared.
     *
     * @param array<string, Read> $reads
     * @param array<string, list<string>> $leading lower-case name => the `Class::method`s it names
     * @param list<array{0: string, 1: string, 2: string}> $declared
     *
     * @return list<string>
     */
    private static function occurrences(array $reads, array $leading, array $declared): array
    {
        $problems = [];
        $matched = array_fill(0, \count($declared), 0);

        foreach ($reads as $read) {
            foreach ($read['tokens'] as $index => $token) {
                $name = self::nameIn($token);

                if ($name === null || !isset($leading[$name])) {
                    continue;
                }

                if (self::isDeclaration($read['tokens'], $index) || self::isDirectCall($reads, $read, $index)) {
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
                    continue;
                }

                $problems[] = \sprintf(
                    'witness registry: %s:%d names %s, which leads to a raise, other than by declaring it or calling it'
                    . ' directly with arguments on a class the scan resolves, so the scan cannot follow it to where it is'
                    . ' called and no witness could be held to what it reaches. Call the method directly, or declare the'
                    . ' line in RaiseSites::DECLARED_NAMES with the reason it is data.',
                    $read['file'],
                    $token->line,
                    implode(' or ', $leading[$name]),
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

        return $problems;
    }

    /**
     * The lower-case name a token spells, if any: an identifier, the last
     * segment of a qualified name, or a string literal's value — the name itself
     * or the method after `::`.
     */
    private static function nameIn(PhpToken $token): ?string
    {
        if ($token->is(\T_STRING)) {
            return strtolower($token->text);
        }

        if ($token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE])) {
            return strtolower(substr((string) strrchr('\\' . $token->text, '\\'), 1));
        }

        if (!$token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE])) {
            return null;
        }

        $value = strtolower(trim(match (true) {
            str_starts_with($token->text, '"') => stripcslashes(substr($token->text, 1, -1)),
            str_starts_with($token->text, "'") => str_replace(['\\\\', "\\'"], ['\\', "'"], substr($token->text, 1, -1)),
            default => $token->text,
        }));
        $separator = strrpos($value, '::');

        return $separator === false ? $value : substr($value, $separator + 2);
    }

    /** @param list<PhpToken> $tokens */
    private static function isDeclaration(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? null;

        return $tokens[$index]->is(\T_STRING) && $previous !== null && $previous->is(\T_FUNCTION);
    }

    /**
     * A call with arguments the caller search reads and ties to a class.
     *
     * @param array<string, Read> $reads
     * @param Read $read
     */
    private static function isDirectCall(array $reads, array $read, int $index): bool
    {
        $tokens = $read['tokens'];
        $token = $tokens[$index];
        $previous = $tokens[$index - 1] ?? null;

        if (!$token->is(\T_STRING) || $previous === null || ($tokens[$index + 1] ?? null)?->text !== '('
            || ($tokens[$index + 2] ?? null)?->is(\T_ELLIPSIS) === true
        ) {
            return false;
        }

        if ($previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR])) {
            return true;
        }

        $receiver = $tokens[$index - 2] ?? null;

        if (!$previous->is(\T_DOUBLE_COLON) || $receiver === null) {
            return false;
        }

        $qualifier = self::qualifier($receiver);

        return match (true) {
            $qualifier === null => false,
            \in_array($qualifier, ['self', 'static'], true) => true,
            $qualifier === 'parent' => $read['parent'] !== null && isset($reads[strtolower($read['parent'])]),
            default => isset($reads[$qualifier]),
        };
    }

    /** The lower-case short class name a static call's receiver token names, or null for a variable or an expression. */
    private static function qualifier(PhpToken $receiver): ?string
    {
        if ($receiver->is([\T_STRING, \T_STATIC])) {
            return strtolower($receiver->text);
        }

        if ($receiver->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE])) {
            return strtolower(substr((string) strrchr('\\' . $receiver->text, '\\'), 1));
        }

        return null;
    }

    /** @return Read */
    private static function read(string $file, string $relative): array
    {
        $source = Fs::read($file);
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn(PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $type = basename($file, '.php');
        $parent = null;
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

            if ($token->is(\T_EXTENDS) && $parent === null && $next !== null) {
                $parent = substr((string) strrchr('\\' . $next->text, '\\'), 1);
            }

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

            if (!$token->is(\T_STRING) || $previous === null || $previous->is(\T_FUNCTION)) {
                continue;
            }

            $called = $next !== null && $next->text === '(';
            $object = $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]);
            $receiver = $tokens[$index - 2] ?? null;

            if ($called && ($object || $previous->is(\T_DOUBLE_COLON))) {
                $calls[$method][] = [
                    'name' => strtolower($token->text),
                    'qualifier' => $object || $receiver === null ? null : self::qualifier($receiver) ?? '$',
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

        return [
            'class' => $type,
            'file' => $file,
            'relative' => $relative,
            'parent' => $parent,
            'methods' => $methods,
            'raises' => $raises,
            'problems' => $problems,
            'tokens' => $tokens,
            'lines' => explode("\n", $source),
        ];
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
