<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Violation;

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
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Rules\AbstractRule;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

/**
 * Closes the seam {@see ChannelCoverageTest} and
 * {@see \Qualimetrix\Tests\Integration\Infrastructure\Rule\ChannelDeclarationFixtureDriftTest}
 * cannot: both compare the **declared** set against itself (the fixture) or
 * against a 12-case hand-written corpus — neither reads what a rule actually
 * *emits*. A rule whose `violationCode:` argument drifts from its
 * `channelDeclarations()` entry (e.g. `self::NAME . '.method'` in one place,
 * `self::NAME . '.methods'` in the other, both hand-typed) passes both of
 * those guards, because the fixture was transcribed from the declaration,
 * not from the emission site.
 *
 * This guard parses every rule class's own source with `nikic/php-parser`
 * (already a project dependency; this project is itself a static analyser),
 * finds every `new Violation(...)` construction, and resolves its
 * `ruleName:`/`violationCode:` argument expressions where they are built
 * from constants:
 *
 * - a string literal;
 * - `self::CONST` (bound to the class whose source declares the
 *   `new Violation(...)` — the abstract base, for the ~12 rules whose
 *   emission is inherited) and `static::CONST` (bound to the concrete rule
 *   class under test, matching PHP's own late-static-binding rule — verified
 *   empirically: `ReflectionMethod::invoke(null)` on a static method
 *   resolves `static::` against the class the `ReflectionClass` was
 *   constructed with, not the method's declaring class, which is exactly
 *   how {@see \Qualimetrix\Core\Rule\ChannelDeclarationReader} already
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
 * - a local variable, resolved by finding its assignment(s) in the same
 *   method — including a `[$code, $hint] = match (...) { ... }`
 *   destructuring assignment, resolved per-arm at the destructured index
 *   (`design.type-coverage`'s `.param`/`.return`/`.property` split).
 *
 * **What this deliberately does not do**: trace values across function
 * calls (beyond the one `$this->method()` hop described above), or reason
 * about which branch is reachable. Over-approximating a branch that turns
 * out unreachable only asks for one more declared channel than strictly
 * necessary; under-approximating would silently narrow the guard back to
 * the failure mode it exists to close.
 *
 * @see SKIP_LIST for the sites this resolver cannot evaluate at all — each
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
     * mapped to why. `ComputedMetricRule` is the only member: its
     * `violationCode` is `$definition->name`, the configured computed
     * metric's name, which is an open per-installation vocabulary with no
     * literal to resolve — the run-time `computed.*`/`health.*` family
     * ADR 0017 describes, guarded separately by
     * `ChannelDeclarationRegistryTest`'s run-time resolution cases rather
     * than by this static guard.
     *
     * @var array<string, string>
     */
    private const array SKIP_LIST = [
        'Qualimetrix\Rules\ComputedMetric\ComputedMetricRule::checkLevel()#violationCode' =>
            'violationCode is $definition->name — the configured computed metric\'s name, an '
            . 'open per-installation vocabulary with no fixed literal to resolve. This is the '
            . 'run-time computed.*/health.* family (ADR 0017); guarded by '
            . 'ChannelDeclarationRegistryTest\'s run-time resolution cases instead.',
    ];

    /** @var array<string, array<\PhpParser\Node\Stmt>> */
    private static array $astCache = [];

    #[Test]
    public function everyStaticallyResolvableEmittedChannelIsDeclaredOrExcluded(): void
    {
        $registry = self::registry();
        $excludedKeys = self::readExcludedFixtureKeys();

        $usedSkipEntries = [];
        $failures = [];

        foreach (self::ruleClasses() as $ruleClass) {
            foreach (self::findEmissionSites($ruleClass) as $site) {
                $ruleNames = self::resolveArgOrRecordFailure($site, 'ruleName', $ruleClass, $usedSkipEntries, $failures);
                $violationCodes = self::resolveArgOrRecordFailure($site, 'violationCode', $ruleClass, $usedSkipEntries, $failures);

                if ($ruleNames === null || $violationCodes === null) {
                    continue;
                }

                foreach ($ruleNames as $ruleName) {
                    foreach ($violationCodes as $violationCode) {
                        $channel = new ViolationChannel($ruleName, $violationCode);
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

        $staleSkipEntries = array_values(array_diff(array_keys(self::SKIP_LIST), array_keys($usedSkipEntries)));
        self::assertSame(
            [],
            $staleSkipEntries,
            \sprintf(
                'SKIP_LIST entries no longer correspond to any actual unresolvable emission site — remove them'
                . ' (the resolver now handles them, or the code changed): %s',
                implode(', ', $staleSkipEntries),
            ),
        );
    }

    /**
     * @param array{declaringClass: class-string, method: string, args: array<string, Expr>, methodNode: ClassMethod} $site
     * @param class-string $ruleClass
     * @param array<string, true> $usedSkipEntries
     * @param list<string> $failures
     *
     * @return ?list<string>
     */
    private static function resolveArgOrRecordFailure(
        array $site,
        string $argName,
        string $ruleClass,
        array &$usedSkipEntries,
        array &$failures,
    ): ?array {
        $expr = $site['args'][$argName] ?? null;

        if ($expr === null) {
            $failures[] = \sprintf(
                '%s::%s() constructs a Violation with no "%s" argument.',
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

        if (isset(self::SKIP_LIST[$siteId])) {
            $usedSkipEntries[$siteId] = true;

            return null;
        }

        $failures[] = \sprintf(
            'Cannot statically resolve %s at %s::%s() (concrete rule %s) — extend the resolver, or add'
            . ' "%s" to SKIP_LIST with a reason.',
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
     * one of them textually contains a `new Violation(...)` construction —
     * the emission may be inherited (AbstractCodeSmellRule,
     * AbstractSecurityPatternRule) rather than declared on the concrete
     * class itself.
     *
     * @param class-string $ruleClass
     *
     * @return list<array{declaringClass: class-string, method: string, args: array<string, Expr>, methodNode: ClassMethod}>
     */
    private static function findEmissionSites(string $ruleClass): array
    {
        $current = $ruleClass;

        while ($current !== false && $current !== AbstractRule::class) {
            $sites = self::findViolationSitesDeclaredOn($current);

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
     * @return list<array{declaringClass: class-string, method: string, args: array<string, Expr>, methodNode: ClassMethod}>
     */
    private static function findViolationSitesDeclaredOn(string $class): array
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
                if (!self::isViolationConstruction($new)) {
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
                    'args' => $args,
                    'methodNode' => $method,
                ];
            }
        }

        return $sites;
    }

    private static function isViolationConstruction(New_ $new): bool
    {
        return $new->class instanceof Name && $new->class->getLast() === 'Violation';
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
            $contents = file_get_contents($file);

            if ($contents === false) {
                throw new RuntimeException(\sprintf('Could not read %s.', $file));
            }

            $parser = (new ParserFactory())->createForHostVersion();
            self::$astCache[$file] = $parser->parse($contents) ?? [];
        }

        return self::$astCache[$file];
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

        if ($expr instanceof Match_) {
            return self::resolveMatchArms($expr, $selfClass, $staticClass, $scope, null);
        }

        if ($expr instanceof MethodCall) {
            return self::resolveMethodCall($expr, $staticClass);
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

        $methodName = $expr->name->toString();

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
        $path = \dirname(__DIR__, 3) . '/tests/Fixtures/Channels/excluded.txt';
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
