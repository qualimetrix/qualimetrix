<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use RuntimeException;
use SplFileInfo;

/**
 * Closes the seam {@see ChannelCoverageTest} and
 * {@see \Qualimetrix\Tests\Integration\Infrastructure\Rule\ChannelDeclarationFixtureDriftTest}
 * cannot: both compare the **declared** set against itself (the fixture) or
 * against a 12-case hand-written corpus — neither reads what a rule actually
 * *emits*. A rule whose `code:` argument drifts from its
 * `channelDeclarations()` entry (e.g. `self::NAME . '.method'` in one place,
 * `self::NAME . '.methods'` in the other, both hand-typed) passes both of
 * those guards, because the fixture was transcribed from the declaration,
 * not from the emission site.
 *
 * This guard parses every rule class's own source with `nikic/php-parser`
 * (already a project dependency; this project is itself a static analyser),
 * finds every `new Finding(...)` construction, and resolves its
 * `ruleName:`/`code:` argument expressions where they are built
 * from constants:
 *
 * - a string literal;
 * - `self::CONST` (bound to the class whose source declares the
 *   `new Finding(...)` — the abstract base, for the ~12 rules whose
 *   emission is inherited) and `static::CONST` (bound to the concrete rule
 *   class under test, matching PHP's own late-static-binding rule — verified
 *   empirically: `ReflectionMethod::invoke(null)` on a static method
 *   resolves `static::` against the class the `ReflectionClass` was
 *   constructed with, not the method's declaring class, which is exactly
 *   how {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader} already
 *   behaves for `channelDeclarations()`);
 * - `$this->methodName()` with no arguments, resolved by following the
 *   method to its *actual* declaring class for the concrete rule under test
 *   (dynamic dispatch, via reflection) and recursing into that method's
 *   body when it is exactly one `return <resolvable expression>;` statement
 *   — the shape every `getName()` in this codebase has;
 * - string concatenation of resolvable sub-expressions;
 * - a ternary, resolved to the union of both branches (the condition itself
 *   is never evaluated — both outcomes are real emissions on different
 *   inputs, e.g. `coupling.cbo`'s class/namespace split);
 * - a `match` expression, resolved to the union of every non-throwing arm;
 * - `FindingChannel::leveled($name, $level)->code`, the one place
 *   a level is spelled into a channel code, resolved by resolving both
 *   halves — the level from an enum case, or, when it arrives as a
 *   parameter, from every value passed at every call of that method in the
 *   same class (`coupling.cbo`'s class/namespace split reaches its emission
 *   point that way);
 * - a local variable, resolved by finding its assignment(s) in the same
 *   method — including a `[$code, $hint] = match (...) { ... }`
 *   destructuring assignment, resolved per-arm at the destructured index
 *   (`coupling.cbo`'s class/namespace split).
 *
 * **What this deliberately does not do**: trace values across function
 * calls (beyond the one `$this->method()` hop described above), or reason
 * about which branch is reachable. Over-approximating a branch that turns
 * out unreachable only asks for one more declared channel than strictly
 * necessary; under-approximating would silently narrow the guard back to
 * the failure mode it exists to close.
 *
 * @see self::skipList() for the sites this resolver cannot evaluate at all — each
 *      must be named and justified there, or this test fails; an entry that
 *      no longer matches an actual unresolvable site fails just as loudly
 *      (see the second assertion below), so the list cannot silently rot in
 *      either direction.
 */
final class ChannelEmissionStaticGuardTest extends TestCase
{
    /**
     * Every entry is `"<declaringClass>::<method>()#<argName>"` — the
     * emission site whose argument expression this resolver gives up on —
     * mapped to why.
     *
     * @return array<string, string>
     */
    private static function skipList(): array
    {
        return [];
    }

    /** @var array<string, array<\PhpParser\Node\Stmt>> */
    private static array $astCache = [];

    /**
     * Level parameters currently being resolved, keyed by class, method and
     * argument position — the base case a cyclic call chain would otherwise
     * lack.
     *
     * @var array<string, true>
     */
    private static array $levelParametersInProgress = [];

    #[Test]
    public function everyStaticallyResolvableEmittedChannelIsDeclaredOrExcluded(): void
    {
        $registry = self::registry();
        $excludedKeys = self::readExcludedFixtureKeys();
        $skipList = self::skipList();

        $usedSkipEntries = [];
        $failures = [];

        foreach (self::ruleClasses() as $ruleClass) {
            foreach (self::findEmissionSites($ruleClass) as $site) {
                $ruleNames = self::resolveArgOrRecordFailure($site, 'ruleName', $ruleClass, $skipList, $usedSkipEntries, $failures);
                $codes = self::resolveArgOrRecordFailure($site, 'code', $ruleClass, $skipList, $usedSkipEntries, $failures);

                if ($ruleNames === null || $codes === null) {
                    continue;
                }

                foreach ($ruleNames as $ruleName) {
                    foreach ($codes as $code) {
                        $channel = new FindingChannel($ruleName, $code);
                        $key = $channel->toKey();

                        if ($registry->declarationFor($channel) === null && !\in_array($key, $excludedKeys, true)) {
                            $failures[] = \sprintf(
                                'Channel "%s" is statically emitted by %s (via %s::%s()) but is neither'
                                . ' declared nor recorded in excluded.txt.',
                                $key,
                                $ruleClass,
                                $site['declaringClass'],
                                $site['method'],
                            );
                        }
                    }
                }
            }
        }

        self::assertSame([], $failures, "\n" . implode("\n", $failures));

        $staleSkipEntries = array_values(array_diff(array_keys($skipList), array_keys($usedSkipEntries)));
        self::assertSame(
            [],
            $staleSkipEntries,
            \sprintf(
                'Skip-list entries no longer correspond to any actual unresolvable emission site — remove them'
                . ' (the resolver now handles them, or the code changed): %s',
                implode(', ', $staleSkipEntries),
            ),
        );
    }

    /**
     * The floor the guard above lacks.
     *
     * `everyStaticallyResolvableEmittedChannelIsDeclaredOrExcluded()` reports
     * only on sites it found: with a detector that matches nothing it asserts
     * twice on empty arrays and reports PASS. Measured, that is not a
     * hypothetical — `src/` holds dozens of constructions, and a detector
     * naming the pre-rename class name would have inspected none of them.
     *
     * So the number of constructions the parser sees is checked against the
     * same number counted by a second, deliberately dumber route: PHP's own
     * tokenizer, walking `T_NEW` followed by a name. Neither route can be the
     * other's oracle — one is `nikic/php-parser` plus the guard's own
     * predicate, the other is `token_get_all()` — and both are compared per
     * file, so a detector that stops matching is a red test rather than a
     * silent zero.
     *
     * The second half of the accounting is where the construction lives. A
     * construction reached by no rule class is outside the guard's promise
     * entirely, and that is a fact worth naming rather than one worth
     * discovering later: every such file is listed in
     * {@see self::delegatedEmitters()} with a reason, a listed file that
     * constructs nothing fails as loudly as an unlisted one that does, and a
     * file the traversal *does* reach must have every one of its constructions
     * inspected — not merely one.
     */
    #[Test]
    public function everyFindingConstructionInSourceIsInspectedOrDeclaredDelegated(): void
    {
        $byTokens = self::constructionCountsByToken();
        $byParser = self::constructionCountsByParser();

        self::assertSame(
            $byParser,
            $byTokens,
            'The guard\'s php-parser detector and an independent token scan disagree about where'
            . ' a finding is constructed. Either the detector no longer matches the construction'
            . ' idiom, or the token scan has to be taught the new one — a disagreement is never'
            . ' the safe direction, because the detector matching nothing is what makes this'
            . ' guard pass on an empty set.',
        );

        self::assertNotSame(
            [],
            $byTokens,
            'No finding construction was found anywhere in src/. Findings are still published, so the'
            . ' construction idiom moved (a factory, a named constructor) and this guard now inspects'
            . ' nothing. Teach both routes the new idiom.',
        );

        $inspectedSites = [];

        foreach (self::ruleClasses() as $ruleClass) {
            foreach (self::findEmissionSites($ruleClass) as $site) {
                $inspectedSites[$site['file']][$site['line']] = true;
            }
        }

        $delegated = self::delegatedEmitters();
        $failures = [];

        foreach ($byTokens as $file => $constructions) {
            if (isset($delegated[$file])) {
                continue;
            }

            $inspected = \count($inspectedSites[$file] ?? []);

            if ($inspected === $constructions) {
                continue;
            }

            $failures[] = \sprintf(
                '%s constructs %d finding(s) but the guard inspected %d of them — reachable from no rule'
                . ' class, or reached only past a construction it stopped at. Name it in delegatedEmitters()'
                . ' with the reason, or make it reachable.',
                $file,
                $constructions,
                $inspected,
            );
        }

        foreach ($delegated as $file => $reason) {
            if (!isset($byTokens[$file])) {
                $failures[] = \sprintf(
                    '%s is named in delegatedEmitters() ("%s") but constructs no finding — the file moved,'
                    . ' was renamed, or stopped emitting. Remove the entry.',
                    $file,
                    $reason,
                );
            }
        }

        self::assertSame([], $failures, "\n" . implode("\n", $failures));
    }

    /**
     * The two argument names the resolver reads are the constructor's own.
     *
     * A stale argument-name literal fails loudly today — a missing argument is
     * recorded as a failure — but only as long as the argument is *named* at
     * the emission site. Asserting the names against the constructor keeps the
     * two spellings from drifting apart in the first place, and says which
     * literal is wrong when they do.
     */
    #[Test]
    public function theResolvedArgumentNamesAreTheFindingConstructorsOwn(): void
    {
        $constructor = (new ReflectionClass(Finding::class))->getConstructor();
        self::assertNotNull($constructor);

        $parameters = array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );

        foreach (['ruleName', 'code'] as $argName) {
            self::assertContains(
                $argName,
                $parameters,
                \sprintf(
                    'The guard resolves the "%s" argument of a finding construction, but the constructor has no'
                    . ' such parameter — the resolver reads an argument name that can never be present.',
                    $argName,
                ),
            );
        }
    }

    /**
     * Files that construct a finding outside any rule class's own inheritance
     * chain, and are therefore outside this guard's promise, mapped to why.
     *
     * An entry is a statement that these channels are covered elsewhere or not
     * at all — not that they need no cover.
     *
     * @return array<string, string>
     */
    private static function delegatedEmitters(): array
    {
        return [
            'src/Analysis/Evidence/CodeSmell/CodeSmellFinding.php' =>
                'AbstractCodeSmellRule hands a collected entry to this value object, which builds the finding;'
                . ' the resolver follows constructions declared on a rule chain, not one method call further.',
            'src/Analysis/Evidence/Security/SecurityPatternFinding.php' =>
                'The same shape for AbstractSecurityPatternRule.',
            'src/Analysis/Evidence/ComputedMetrics/Finding/ComputedMetricFindingBuilder.php' =>
                'ComputedMetricRule delegates construction to this builder, and a computed channel is named by'
                . ' user configuration rather than by a constant this resolver could read.',
            'src/Analysis/Policy/Architecture/LayerViolation/LayerViolationFinding.php' =>
                'LayerViolationRule delegates to this value object.',
            'src/Analysis/Policy/Architecture/LayerViolation/UnassignedClassSummary.php' =>
                'UnassignedClassRule delegates to this summary.',
            'src/Analysis/Policy/Architecture/LayerViolation/DeclaredLayerReachability.php' =>
                'Reached from LayerDeclarationValidator, a configuration validator rather than a rule, so no'
                . ' rule class chain leads here at all.',
            'src/Analysis/Policy/Inline/Directive/InlineDirectiveValidator.php' =>
                'A configuration validator, like the one above.',
            'src/Analysis/Policy/Inline/Directive/InlineDirectivePolicy.php' =>
                'Policy state consulted by UnusedDirectiveRule; the construction sits on the policy, not on the'
                . ' rule chain.',
        ];
    }

    /**
     * Every `new <Finding>` in `src/`, counted with PHP's tokenizer.
     *
     * Deliberately independent of `nikic/php-parser` and of the guard's own
     * predicate: an oracle that shares the machinery it checks proves that the
     * machinery agrees with itself.
     *
     * @return array<string, int> relative path => constructions, files without one omitted
     */
    private static function constructionCountsByToken(): array
    {
        $shortName = self::shortNameOf(Finding::class);
        $counts = [];

        foreach (self::sourceFiles() as $relative => $absolute) {
            $tokens = token_get_all(self::contentsOf($absolute));
            $count = 0;
            $afterNew = false;

            foreach ($tokens as $token) {
                if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }

                if (\is_array($token) && $token[0] === \T_NEW) {
                    $afterNew = true;

                    continue;
                }

                if ($afterNew
                    && \is_array($token)
                    && \in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)
                ) {
                    $segments = explode('\\', $token[1]);

                    if (end($segments) === $shortName) {
                        ++$count;
                    }
                }

                $afterNew = false;
            }

            if ($count > 0) {
                $counts[$relative] = $count;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * The same count, taken with the parser and the very predicate the guard
     * resolves emission sites with.
     *
     * @return array<string, int>
     */
    private static function constructionCountsByParser(): array
    {
        $finder = new NodeFinder();
        $counts = [];

        foreach (self::sourceFiles() as $relative => $absolute) {
            /** @var list<New_> $newNodes */
            $newNodes = $finder->findInstanceOf(self::parsedFile($absolute), New_::class);
            $count = 0;

            foreach ($newNodes as $new) {
                if (self::isFindingConstruction($new)) {
                    ++$count;
                }
            }

            if ($count > 0) {
                $counts[$relative] = $count;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private static function sourceFiles(): array
    {
        $root = self::projectRoot();
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            \assert($file instanceof SplFileInfo);

            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /** @param class-string $class */
    private static function relativeFileOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();

        if ($file === false) {
            throw new RuntimeException(\sprintf('%s has no source file.', $class));
        }

        return substr($file, \strlen(self::projectRoot()) + 1);
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * @param array{declaringClass: class-string, method: string, file: string, line: int, args: array<string, Expr>, methodNode: ClassMethod} $site
     * @param class-string $ruleClass
     * @param array<string, string> $skipList
     * @param array<string, true> $usedSkipEntries
     * @param list<string> $failures
     *
     * @return ?list<string>
     */
    private static function resolveArgOrRecordFailure(
        array $site,
        string $argName,
        string $ruleClass,
        array $skipList,
        array &$usedSkipEntries,
        array &$failures,
    ): ?array {
        $expr = $site['args'][$argName] ?? null;

        if ($expr === null) {
            $failures[] = \sprintf(
                '%s::%s() constructs a Finding with no "%s" argument.',
                $site['declaringClass'],
                $site['method'],
                $argName,
            );

            return null;
        }

        $resolved = self::resolveExpr($expr, $site['declaringClass'], $ruleClass, $site['methodNode']);

        if ($resolved !== null) {
            return $resolved;
        }

        $siteId = \sprintf('%s::%s()#%s', $site['declaringClass'], $site['method'], $argName);

        if (isset($skipList[$siteId])) {
            $usedSkipEntries[$siteId] = true;

            return null;
        }

        $failures[] = \sprintf(
            'Cannot statically resolve %s at %s::%s() (concrete rule %s) — extend the resolver, or add'
            . ' "%s" to skipList() with a reason.',
            $argName,
            $site['declaringClass'],
            $site['method'],
            $ruleClass,
            $siteId,
        );

        return null;
    }

    /**
     * @return list<class-string>
     */
    private static function ruleClasses(): array
    {
        $registry = (new ContainerFactory())->create()->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        return $registry->getClasses();
    }

    private static function registry(): ChannelDeclarationRegistryInterface
    {
        $registry = (new ContainerFactory())->create()->get(ChannelDeclarationRegistryInterface::class);
        \assert($registry instanceof ChannelDeclarationRegistryInterface);

        return $registry;
    }

    /**
     * Walks from the concrete rule class upward through its ancestors until
     * one of them textually contains a `new Finding(...)` construction —
     * the emission may be inherited (AbstractCodeSmellRule,
     * AbstractSecurityPatternRule) rather than declared on the concrete
     * class itself.
     *
     * @param class-string $ruleClass
     *
     * @return list<array{declaringClass: class-string, method: string, file: string, line: int, args: array<string, Expr>, methodNode: ClassMethod}>
     */
    private static function findEmissionSites(string $ruleClass): array
    {
        $current = $ruleClass;

        while ($current !== false && $current !== AbstractRule::class) {
            $sites = self::findFindingSitesDeclaredOn($current);

            if ($sites !== []) {
                return $sites;
            }

            $current = get_parent_class($current);
        }

        return [];
    }

    /**
     * @param class-string $class
     *
     * @return list<array{declaringClass: class-string, method: string, file: string, line: int, args: array<string, Expr>, methodNode: ClassMethod}>
     */
    private static function findFindingSitesDeclaredOn(string $class): array
    {
        $classNode = self::findClassNode($class);

        if ($classNode === null) {
            return [];
        }

        $finder = new NodeFinder();
        $sites = [];

        foreach ($classNode->getMethods() as $method) {
            /** @var list<New_> $newNodes */
            $newNodes = $finder->findInstanceOf($method, New_::class);

            foreach ($newNodes as $new) {
                if (!self::isFindingConstruction($new)) {
                    continue;
                }

                $args = [];
                foreach ($new->args as $arg) {
                    if ($arg instanceof Arg && $arg->name instanceof Identifier) {
                        $args[$arg->name->toString()] = $arg->value;
                    }
                }

                $sites[] = [
                    'declaringClass' => $class,
                    'method' => $method->name->toString(),
                    'file' => self::relativeFileOf($class),
                    'line' => $new->getStartLine(),
                    'args' => $args,
                    'methodNode' => $method,
                ];
            }
        }

        return $sites;
    }

    /**
     * The construction the resolver looks for, named by reflection rather than
     * spelled out.
     *
     * A literal here is the guard's silent failure mode, not a cosmetic detail:
     * with a stale short name nothing matches, every rule yields zero emission
     * sites, and both assertions of the main test compare empty arrays. The
     * short name now comes from the class itself, so a rename cannot leave this
     * detector behind, and
     * {@see self::everyFindingConstructionInSourceIsInspectedOrDeclaredDelegated}
     * measures the detector against an independent count so a detector that
     * matches nothing is loud rather than green.
     */
    private static function isFindingConstruction(New_ $new): bool
    {
        return $new->class instanceof Name && $new->class->getLast() === self::shortNameOf(Finding::class);
    }

    /** @param class-string $class */
    private static function shortNameOf(string $class): string
    {
        return (new ReflectionClass($class))->getShortName();
    }

    /**
     * @param class-string $class
     */
    private static function findClassNode(string $class): ?Class_
    {
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();

        if ($file === false) {
            return null;
        }

        $ast = self::parsedFile($file);
        $finder = new NodeFinder();
        $shortName = $reflection->getShortName();

        /** @var list<Class_> $classNodes */
        $classNodes = $finder->findInstanceOf($ast, Class_::class);

        foreach ($classNodes as $classNode) {
            if ($classNode->name !== null && $classNode->name->toString() === $shortName) {
                return $classNode;
            }
        }

        return null;
    }

    /**
     * @return array<\PhpParser\Node\Stmt>
     */
    private static function parsedFile(string $file): array
    {
        if (!isset(self::$astCache[$file])) {
            $parser = (new ParserFactory())->createForHostVersion();
            self::$astCache[$file] = $parser->parse(self::contentsOf($file)) ?? [];
        }

        return self::$astCache[$file];
    }

    private static function contentsOf(string $file): string
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $file));
        }

        return $contents;
    }

    /**
     * Resolves an expression to every string value it can statically
     * produce, or null when it is not one of the shapes this guard
     * understands. `$selfClass` is the class whose source text declares
     * `$expr` (binds `self::`); `$staticClass` is the concrete rule class
     * under test (binds `static::` and dynamic `$this->` dispatch).
     *
     * @param class-string $selfClass
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveExpr(Expr $expr, string $selfClass, string $staticClass, ClassMethod $scope): ?array
    {
        if ($expr instanceof String_) {
            return [$expr->value];
        }

        if ($expr instanceof ClassConstFetch) {
            return self::resolveClassConstFetch($expr, $selfClass, $staticClass);
        }

        if ($expr instanceof Concat) {
            $left = self::resolveExpr($expr->left, $selfClass, $staticClass, $scope);
            $right = self::resolveExpr($expr->right, $selfClass, $staticClass, $scope);

            if ($left === null || $right === null) {
                return null;
            }

            $result = [];
            foreach ($left as $l) {
                foreach ($right as $r) {
                    $result[] = $l . $r;
                }
            }

            return array_values(array_unique($result));
        }

        if ($expr instanceof Ternary) {
            $ifExpr = $expr->if ?? $expr->cond;
            $ifResolved = self::resolveExpr($ifExpr, $selfClass, $staticClass, $scope);
            $elseResolved = self::resolveExpr($expr->else, $selfClass, $staticClass, $scope);

            if ($ifResolved === null || $elseResolved === null) {
                return null;
            }

            return array_values(array_unique([...$ifResolved, ...$elseResolved]));
        }

        if ($expr instanceof PropertyFetch) {
            return self::resolveLeveledChannelProperty($expr, $selfClass, $staticClass, $scope);
        }

        if ($expr instanceof Match_) {
            return self::resolveMatchArms($expr, $selfClass, $staticClass, $scope, null);
        }

        if ($expr instanceof MethodCall) {
            return self::resolveMethodCall($expr, $staticClass);
        }

        if ($expr instanceof StaticCall) {
            return self::resolveStaticCall($expr, $staticClass);
        }

        if ($expr instanceof Variable && \is_string($expr->name)) {
            return self::resolveVariable($expr->name, $selfClass, $staticClass, $scope);
        }

        return null;
    }

    /**
     * @param class-string $selfClass
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveClassConstFetch(ClassConstFetch $expr, string $selfClass, string $staticClass): ?array
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            return null;
        }

        $targetClass = match ($expr->class->toString()) {
            'self' => $selfClass,
            'static' => $staticClass,
            default => null,
        };

        if ($targetClass === null) {
            return null;
        }

        $constName = $expr->name->toString();
        $reflection = new ReflectionClass($targetClass);

        if (!$reflection->hasConstant($constName)) {
            return null;
        }

        $value = $reflection->getConstant($constName);

        return \is_string($value) ? [$value] : null;
    }

    /**
     * Resolves `$this->methodName()` (no args) by following it to its
     * actual declaring class for the concrete rule under test — dynamic
     * dispatch, exactly as PHP itself would resolve the call — and
     * recursing into that method's body when it is exactly one
     * `return <expr>;` statement.
     *
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveMethodCall(MethodCall $expr, string $staticClass): ?array
    {
        if (!$expr->var instanceof Variable || $expr->var->name !== 'this') {
            return null;
        }

        if (!$expr->name instanceof Identifier || $expr->args !== []) {
            return null;
        }

        return self::resolveNoArgCall($expr->name->toString(), $staticClass);
    }

    /**
     * The same for `static::methodName()` (no args), and **only** for it.
     *
     * A rule family whose shared base builds the finding reaches its own name
     * through a static hook rather than through `$this`, because the base also
     * needs it in `channelDeclarations()`, which is static. `static::` is late
     * static binding, so resolving it against the concrete rule under test is
     * PHP's own rule and the guard follows it exactly.
     *
     * `self::` is deliberately **not** accepted. PHP binds it to the class
     * whose source contains the call and ignores an override, so resolving it
     * the same way would let the guard check the subclass's name while the
     * runtime publishes the base's — a guard reporting a channel that is never
     * emitted, and missing the one that is. Refusing it makes the guard
     * stricter than the code rather than more permissive: an emission written
     * with `self::` fails here as unresolvable, which is a failure, not a
     * shrug. (Measured: with a base `emit()` returning `self::n()` and a child
     * overriding `n()`, PHP prints the base's value while reflection's
     * declaring class is the child.)
     *
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveStaticCall(StaticCall $expr, string $staticClass): ?array
    {
        if (!$expr->class instanceof Name || $expr->class->toString() !== 'static') {
            return null;
        }

        if (!$expr->name instanceof Identifier || $expr->args !== []) {
            return null;
        }

        return self::resolveNoArgCall($expr->name->toString(), $staticClass);
    }

    /**
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveNoArgCall(string $methodName, string $staticClass): ?array
    {
        try {
            $declaringClass = (new ReflectionClass($staticClass))->getMethod($methodName)->getDeclaringClass()->getName();
        } catch (ReflectionException) {
            return null;
        }

        $classNode = self::findClassNode($declaringClass);

        if ($classNode === null) {
            return null;
        }

        $methodNode = null;
        foreach ($classNode->getMethods() as $candidate) {
            if ($candidate->name->toString() === $methodName) {
                $methodNode = $candidate;
                break;
            }
        }

        if ($methodNode === null || $methodNode->stmts === null || \count($methodNode->stmts) !== 1) {
            return null;
        }

        $onlyStmt = $methodNode->stmts[0];

        if (!$onlyStmt instanceof Return_ || $onlyStmt->expr === null) {
            return null;
        }

        return self::resolveExpr($onlyStmt->expr, $declaringClass, $staticClass, $methodNode);
    }

    /**
     * Resolves `FindingChannel::leveled($name, $level)->ruleName` and
     * `->code` — the single assembly point of a level-bearing
     * channel, and therefore the only shape through which a level reaches an
     * emitted code at all.
     *
     * @param class-string $selfClass
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveLeveledChannelProperty(
        PropertyFetch $expr,
        string $selfClass,
        string $staticClass,
        ClassMethod $scope,
    ): ?array {
        $call = $expr->var;

        if (!$expr->name instanceof Identifier || !$call instanceof StaticCall) {
            return null;
        }

        if (!$call->class instanceof Name || $call->class->getLast() !== self::shortNameOf(FindingChannel::class)) {
            return null;
        }

        if (!$call->name instanceof Identifier || $call->name->toString() !== 'leveled') {
            return null;
        }

        $arguments = $call->args;

        if (\count($arguments) !== 2 || !$arguments[0] instanceof Arg || !$arguments[1] instanceof Arg) {
            return null;
        }

        $ruleNames = self::resolveExpr($arguments[0]->value, $selfClass, $staticClass, $scope);

        if ($ruleNames === null) {
            return null;
        }

        if ($expr->name->toString() === 'ruleName') {
            return $ruleNames;
        }

        if ($expr->name->toString() !== 'code') {
            return null;
        }

        $levels = self::resolveLevelValues($arguments[1]->value, $selfClass, $staticClass, $scope);

        if ($levels === null) {
            return null;
        }

        $codes = [];

        foreach ($ruleNames as $ruleName) {
            foreach ($levels as $level) {
                $codes[] = $ruleName . '.' . $level;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * The {@see SymbolLevel} values an expression can carry, as strings.
     *
     * A parameter is resolved from the values its own class passes at every
     * call of the enclosing method — an over-approximation in the same
     * direction as the ternary and match arms: naming one channel too many
     * asks for one more declaration, naming one too few would reopen the
     * hole this guard exists to close.
     *
     * @param class-string $selfClass
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveLevelValues(
        Expr $expr,
        string $selfClass,
        string $staticClass,
        ClassMethod $scope,
    ): ?array {
        if ($expr instanceof ClassConstFetch) {
            return self::resolveLevelConstant($expr, $selfClass, $staticClass);
        }

        if ($expr instanceof Variable && \is_string($expr->name)) {
            return self::resolveLevelParameter($expr->name, $selfClass, $staticClass, $scope);
        }

        return null;
    }

    /**
     * @param class-string $selfClass
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveLevelConstant(ClassConstFetch $expr, string $selfClass, string $staticClass): ?array
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            return null;
        }

        $targetClass = match ($expr->class->toString()) {
            'self' => $selfClass,
            'static' => $staticClass,
            default => $expr->class->getLast() === 'SymbolLevel' ? SymbolLevel::class : null,
        };

        if ($targetClass === null || !(new ReflectionClass($targetClass))->hasConstant($expr->name->toString())) {
            return null;
        }

        $value = (new ReflectionClass($targetClass))->getConstant($expr->name->toString());

        return $value instanceof SymbolLevel ? [$value->value] : null;
    }

    /**
     * @param class-string $selfClass
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveLevelParameter(
        string $name,
        string $selfClass,
        string $staticClass,
        ClassMethod $scope,
    ): ?array {
        $position = null;

        foreach (array_values($scope->params) as $index => $param) {
            if ($param->var instanceof Variable && $param->var->name === $name) {
                $position = $index;
            }
        }

        $classNode = self::findClassNode($selfClass);

        if ($position === null || $classNode === null) {
            return null;
        }

        $method = $scope->name->toString();
        $reentryKey = $selfClass . '::' . $method . '#' . $position;

        // A parameter passed along a cycle of calls would otherwise recurse
        // without a base case and kill the run with a stack overflow instead
        // of the readable refusal this guard promises. Re-entry resolves to
        // nothing, which the caller records as an unresolvable site.
        if (isset(self::$levelParametersInProgress[$reentryKey])) {
            return null;
        }

        self::$levelParametersInProgress[$reentryKey] = true;

        try {
            return self::resolveLevelArguments($classNode, $method, $position, $selfClass, $staticClass);
        } finally {
            unset(self::$levelParametersInProgress[$reentryKey]);
        }
    }

    /**
     * Every value the class passes at the given argument position of the
     * given method.
     *
     * Each argument expression is resolved in the scope of the method that
     * *writes* it, not of the method that receives it. Resolving it in the
     * callee's scope is what an earlier version did, and it is wrong in the
     * silent direction: a variable at the call site whose name happens to
     * match one of the callee's own parameters would resolve to that other
     * parameter's values, narrowing the guard without saying so.
     *
     * @param class-string $selfClass
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveLevelArguments(
        Class_ $classNode,
        string $method,
        int $position,
        string $selfClass,
        string $staticClass,
    ): ?array {
        $finder = new NodeFinder();
        $values = [];
        $foundAny = false;

        foreach ($classNode->getMethods() as $callerNode) {
            /** @var list<MethodCall|StaticCall> $calls */
            $calls = $finder->find(
                $callerNode,
                static fn(object $node): bool => ($node instanceof MethodCall || $node instanceof StaticCall)
                    && $node->name instanceof Identifier
                    && $node->name->toString() === $method,
            );

            foreach ($calls as $call) {
                $argument = $call->args[$position] ?? null;

                if (!$argument instanceof Arg || $argument->name !== null) {
                    return null;
                }

                $foundAny = true;
                $resolved = self::resolveLevelValues($argument->value, $selfClass, $staticClass, $callerNode);

                if ($resolved === null) {
                    return null;
                }

                $values = [...$values, ...$resolved];
            }
        }

        return $foundAny ? array_values(array_unique($values)) : null;
    }

    /**
     * @param class-string $selfClass
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveVariable(string $name, string $selfClass, string $staticClass, ClassMethod $scope): ?array
    {
        $finder = new NodeFinder();
        /** @var list<Assign> $assigns */
        $assigns = $finder->findInstanceOf($scope, Assign::class);

        $values = [];
        $foundAny = false;

        foreach ($assigns as $assign) {
            if ($assign->var instanceof Variable && $assign->var->name === $name) {
                $foundAny = true;
                $resolved = self::resolveExpr($assign->expr, $selfClass, $staticClass, $scope);

                if ($resolved === null) {
                    return null;
                }

                $values = [...$values, ...$resolved];

                continue;
            }

            if ($assign->var instanceof Array_ || $assign->var instanceof List_) {
                $index = self::destructureIndexOf($assign->var, $name);

                if ($index === null) {
                    continue;
                }

                $foundAny = true;

                if (!$assign->expr instanceof Match_) {
                    return null;
                }

                $resolved = self::resolveMatchArms($assign->expr, $selfClass, $staticClass, $scope, $index);

                if ($resolved === null) {
                    return null;
                }

                $values = [...$values, ...$resolved];
            }
        }

        if (!$foundAny) {
            return null;
        }

        return array_values(array_unique($values));
    }

    private static function destructureIndexOf(Array_|List_ $target, string $name): ?int
    {
        foreach ($target->items as $index => $item) {
            if ($item !== null && $item->value instanceof Variable && $item->value->name === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Resolves every non-throwing arm of a `match` expression, optionally
     * reading a fixed tuple position out of each arm's body (for a
     * destructuring assignment like `[$code, $hint] = match (...) {...}`).
     *
     * @param class-string $selfClass
     * @param class-string $staticClass
     *
     * @return ?list<string>
     */
    private static function resolveMatchArms(
        Match_ $match,
        string $selfClass,
        string $staticClass,
        ClassMethod $scope,
        ?int $tupleIndex,
    ): ?array {
        $values = [];

        foreach ($match->arms as $arm) {
            if ($arm->body instanceof Throw_) {
                continue;
            }

            $bodyExpr = $arm->body;

            if ($tupleIndex !== null) {
                if (!$bodyExpr instanceof Array_) {
                    return null;
                }

                $item = $bodyExpr->items[$tupleIndex] ?? null;

                if ($item === null) {
                    return null;
                }

                $bodyExpr = $item->value;
            }

            $resolved = self::resolveExpr($bodyExpr, $selfClass, $staticClass, $scope);

            if ($resolved === null) {
                return null;
            }

            $values = [...$values, ...$resolved];
        }

        return array_values(array_unique($values));
    }

    /**
     * Duplicated from {@see ChannelCoverageTest} deliberately: both tests
     * read the same fixture for the same reason, but sharing the helper
     * would couple two otherwise-independent guards through a third file.
     *
     * @return list<string>
     */
    private static function readExcludedFixtureKeys(): array
    {
        $path = \dirname(__DIR__) . '/Fixtures/Channels/excluded.txt';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read fixture file %s.', $path));
        }

        $keys = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+--\s+/', $line, 2);
            $channelKey = $parts === false ? $line : $parts[0];
            $keys[] = trim($channelKey);
        }

        return $keys;
    }
}
