<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Support;

use BackedEnum;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use ReflectionClass;

/**
 * One `fromArray()` body, read.
 *
 * It shares a file with the reader that is its only producer: nothing else may
 * construct one, so it is never autoloaded by name, and PSR-4 never has to
 * resolve it.
 */
final class FromArrayKeyReading
{
    /** @var array<string, bool> canonical key => reached without any enclosing branch */
    public array $keys = [];

    /** @var array<string, int> blind-spot kind => how many sites of it */
    public array $unresolved = [
        'dynamic-key' => 0,
        'opaque-sink' => 0,
        'spread' => 0,
        'iteration' => 0,
        'nested-delegation' => 0,
    ];

    /** @var list<string> */
    public array $unresolvedDetail = [];

    public function record(string $canonical, bool $guarded): void
    {
        $this->keys[$canonical] = ($this->keys[$canonical] ?? false) || !$guarded;
    }

    public function blind(string $kind, string $detail): void
    {
        $this->unresolved[$kind] = ($this->unresolved[$kind] ?? 0) + 1;
        $this->unresolvedDetail[] = $kind . '@' . $detail;
    }
}

/**
 * Reads the literal configuration keys one Options class consumes.
 *
 * Resolution covers: `$config['key']`, `$config[Some::CONST]`,
 * `$config[Enum::Case->value]`, `$config[$localVariable]` for a
 * single-assignment local, `array_key_exists(k, $config)`, the key arguments of
 * `ThresholdParser::parse()` (positional and named, `legacyKeys` included), and
 * same-class helper methods handed the config array.
 */
final class FromArrayKeyReader
{
    private const string CONFIG_METHOD = 'fromArray';

    /** @var array<string, ClassMethod> */
    private array $methods = [];

    /** @var array<int, true> object id of every node that only runs when a branch was taken */
    private array $guarded = [];

    /** @var array<string, string> local variable name => resolved literal */
    private array $locals = [];

    private FromArrayKeyReading $reading;

    /** @var list<string> */
    private array $visited = [];

    public function __construct(private readonly NodeFinder $finder = new NodeFinder()) {}

    /**
     * @param class-string $optionsClass
     */
    public function read(string $optionsClass): FromArrayKeyReading
    {
        $this->reading = new FromArrayKeyReading();
        $this->methods = [];
        $this->visited = [];
        $this->guarded = [];
        $this->locals = [];

        $classNode = $this->classNode($optionsClass);
        if ($classNode === null) {
            $this->reading->blind('opaque-sink', 'class-source-unavailable');

            return $this->reading;
        }

        foreach ($classNode->getMethods() as $method) {
            $this->methods[$method->name->toString()] = $method;
        }

        $this->markGuarded($classNode);

        $entry = $this->methods[self::CONFIG_METHOD] ?? null;
        if ($entry === null) {
            $this->reading->blind('opaque-sink', 'no-fromArray');

            return $this->reading;
        }

        $configVariable = ($entry->params[0] ?? null)?->var;
        if (!$configVariable instanceof Variable || !\is_string($configVariable->name)) {
            $this->reading->blind('opaque-sink', 'fromArray-parameter-not-a-plain-variable');

            return $this->reading;
        }

        $this->walkMethod($entry, $configVariable->name, false);

        return $this->reading;
    }

    /**
     * How many class declarations sit in the file this class lives in,
     * counted in the very AST {@see self::classNode()} then picks from.
     *
     * The reader takes the first one and ignores the class name it was asked
     * for, so anything past the first is read as if it were the requested
     * class. Anonymous classes count: their branches and their reads would be
     * attributed to the enclosing declaration just the same. A caller that
     * wants the reader's word to mean what it says asserts this is 1.
     *
     * @param class-string $class
     */
    public function classDeclarationsInFileOf(string $class): int
    {
        return \count($this->finder->findInstanceOf($this->fileAst($class), Class_::class));
    }

    /**
     * @param class-string $optionsClass
     */
    private function classNode(string $optionsClass): ?Class_
    {
        $node = $this->finder->findFirstInstanceOf($this->fileAst($optionsClass), Class_::class);

        return $node instanceof Class_ ? $node : null;
    }

    /**
     * @param class-string $class
     *
     * @return array<Node>
     */
    private function fileAst(string $class): array
    {
        $file = (new ReflectionClass($class))->getFileName();
        if ($file === false) {
            return [];
        }

        $code = file_get_contents($file);
        if ($code === false) {
            return [];
        }

        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        if ($ast === null) {
            return [];
        }

        return (new NodeTraverser(new NameResolver()))->traverse($ast);
    }

    /**
     * Marks every node that only runs when some branch was taken.
     *
     * An `if` *condition* is always evaluated, so a key probed there is read
     * unconditionally; only the bodies are guarded. That distinction is the
     * whole difference between a key that warns and works and a key that warns
     * and does nothing.
     */
    private function markGuarded(Class_ $classNode): void
    {
        foreach ($this->finder->findInstanceOf([$classNode], If_::class) as $branch) {
            \assert($branch instanceof If_);

            $conditional = [...$branch->stmts, ...$branch->elseifs];
            if ($branch->else !== null) {
                $conditional[] = $branch->else;
            }

            foreach ($this->finder->find($conditional, static fn(Node $node): bool => true) as $inner) {
                $this->guarded[spl_object_id($inner)] = true;
            }
        }
    }

    private function walkMethod(ClassMethod $method, string $configVariable, bool $callSiteGuarded): void
    {
        $signature = $method->name->toString() . '/' . $configVariable;
        if (\in_array($signature, $this->visited, true)) {
            return;
        }
        $this->visited[] = $signature;

        $this->collectLocals($method);

        $body = $method->stmts ?? [];

        foreach ($this->finder->find($body, static fn(Node $node): bool => true) as $node) {
            $guarded = $callSiteGuarded || isset($this->guarded[spl_object_id($node)]);
            $this->inspect($node, $configVariable, $guarded);
        }
    }

    /**
     * Remembers locals assigned exactly once from something reducible to a
     * literal — enough for `$key = SymbolLevel::Class_->value;`, and nothing
     * beyond it.
     */
    private function collectLocals(ClassMethod $method): void
    {
        $seen = [];

        foreach ($this->finder->findInstanceOf($method->stmts ?? [], Assign::class) as $assign) {
            \assert($assign instanceof Assign);

            if (!$assign->var instanceof Variable || !\is_string($assign->var->name)) {
                continue;
            }

            $name = $assign->var->name;
            $seen[$name] = ($seen[$name] ?? 0) + 1;

            $literal = $this->literal($assign->expr);
            if ($literal !== null) {
                $this->locals[$name] = $literal;
            }
        }

        foreach ($seen as $name => $count) {
            if ($count > 1) {
                unset($this->locals[$name]);
            }
        }
    }

    private function inspect(Node $node, string $configVariable, bool $guarded): void
    {
        if ($node instanceof ArrayDimFetch && $this->isConfig($node->var, $configVariable)) {
            $key = $node->dim === null ? null : $this->literal($node->dim);
            if ($key === null) {
                $this->reading->blind('dynamic-key', 'line ' . $node->getStartLine());
            } else {
                $this->reading->record(ConfigKeySpelling::normalize($key), $guarded);
            }

            return;
        }

        if ($node instanceof Foreach_ && $this->isConfig($node->expr, $configVariable)) {
            $this->reading->blind('iteration', 'line ' . $node->getStartLine());

            return;
        }

        if ($node instanceof FuncCall) {
            $this->inspectFuncCall($node, $configVariable, $guarded);

            return;
        }

        if ($node instanceof StaticCall) {
            $this->inspectStaticCall($node, $configVariable, $guarded);
        }
    }

    private function inspectFuncCall(FuncCall $node, string $configVariable, bool $guarded): void
    {
        $name = $node->name instanceof Name ? $node->name->toLowerString() : null;

        if ($name === 'array_key_exists' && \count($node->args) === 2) {
            $first = $node->args[0];
            $second = $node->args[1];

            if ($first instanceof Arg && $second instanceof Arg && $this->isConfig($second->value, $configVariable)) {
                $key = $this->literal($first->value);
                if ($key === null) {
                    $this->reading->blind('dynamic-key', 'line ' . $node->getStartLine());
                } else {
                    $this->reading->record(ConfigKeySpelling::normalize($key), $guarded);
                }

                return;
            }
        }

        $this->noteConfigHandoff($node->args, $configVariable, $name ?? 'closure', $node->getStartLine());
    }

    private function inspectStaticCall(StaticCall $node, string $configVariable, bool $guarded): void
    {
        $class = $node->class instanceof Name ? $node->class->toString() : '?';
        $method = $node->name instanceof Identifier ? $node->name->toString() : '?';

        if ($class === ThresholdParser::class && $method === 'parse') {
            $this->inspectThresholdParse($node, $configVariable, $guarded);

            return;
        }

        // A slice of the config handed to another class's own fromArray(): the
        // keys read there belong to that class, and Table A is where they are
        // enumerated. Counted, so the boundary of Table B is a number rather
        // than an assumption.
        if ($method === 'fromArray' && $class !== 'self' && $class !== 'static') {
            $this->reading->blind('nested-delegation', $class . ' line ' . $node->getStartLine());

            return;
        }

        $position = $this->configArgumentPosition($node->args, $configVariable);
        if ($position === null) {
            $this->noteConfigHandoff($node->args, $configVariable, $class . '::' . $method, $node->getStartLine());

            return;
        }

        $helper = ($class === 'self' || $class === 'static') ? ($this->methods[$method] ?? null) : null;
        if ($helper === null) {
            $this->reading->blind('opaque-sink', $class . '::' . $method . ' line ' . $node->getStartLine());

            return;
        }

        $parameter = ($helper->params[$position] ?? null)?->var;
        if (!$parameter instanceof Variable || !\is_string($parameter->name)) {
            $this->reading->blind('opaque-sink', 'self::' . $method . ' line ' . $node->getStartLine());

            return;
        }

        $this->walkMethod($helper, $parameter->name, $guarded);
    }

    /**
     * `parse()` names its key arguments; every call site in the tree passes
     * `legacyKeys:` by name and skips `thresholdKey`, so position alone lies.
     */
    private function inspectThresholdParse(StaticCall $node, string $configVariable, bool $guarded): void
    {
        if ($this->configArgumentPosition($node->args, $configVariable) === null) {
            return;
        }

        $order = ['config', 'warningKey', 'errorKey', 'defaultWarning', 'defaultError', 'thresholdKey', 'legacyKeys'];
        $bound = [];

        $position = 0;
        foreach ($node->args as $argument) {
            if (!$argument instanceof Arg) {
                $this->reading->blind('spread', 'ThresholdParser::parse line ' . $node->getStartLine());

                continue;
            }

            if ($argument->name instanceof Identifier) {
                $bound[$argument->name->toString()] = $argument->value;

                continue;
            }

            $bound[$order[$position] ?? 'extra'] = $argument->value;
            ++$position;
        }

        foreach (['warningKey', 'errorKey'] as $slot) {
            $expression = $bound[$slot] ?? null;
            $key = $expression === null ? null : $this->literal($expression);
            if ($key === null) {
                $this->reading->blind('dynamic-key', 'ThresholdParser::parse line ' . $node->getStartLine());

                continue;
            }
            $this->reading->record(ConfigKeySpelling::normalize($key), $guarded);
        }

        $thresholdKey = isset($bound['thresholdKey']) ? $this->literal($bound['thresholdKey']) : RuleOptionKey::THRESHOLD;
        if ($thresholdKey === null) {
            $this->reading->blind('dynamic-key', 'ThresholdParser::parse line ' . $node->getStartLine());
        } else {
            $this->reading->record(ConfigKeySpelling::normalize($thresholdKey), $guarded);
        }

        $legacy = $bound['legacyKeys'] ?? null;
        if ($legacy === null) {
            return;
        }

        // Only the VALUES are config keys. The map's own keys are the three
        // threshold slots ('warning'/'error'/'threshold'), and collecting them
        // would invent a `warning` key for every rule whose primary key is
        // named something else.
        if (!$legacy instanceof Array_) {
            $this->reading->blind('dynamic-key', 'legacyKeys line ' . $node->getStartLine());

            return;
        }

        foreach ($legacy->items as $slot) {
            foreach ($this->finder->findInstanceOf([$slot->value], String_::class) as $string) {
                \assert($string instanceof String_);
                $this->reading->record(ConfigKeySpelling::normalize($string->value), $guarded);
            }
        }
    }

    /**
     * @param array<int, Arg|Node\VariadicPlaceholder> $args
     */
    private function noteConfigHandoff(array $args, string $configVariable, string $callee, int $line): void
    {
        foreach ($args as $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }

            if (!$this->isConfig($argument->value, $configVariable)) {
                continue;
            }

            if ($argument->unpack) {
                $this->reading->blind('spread', $callee . ' line ' . $line);

                continue;
            }

            $this->reading->blind('opaque-sink', $callee . ' line ' . $line);
        }
    }

    /**
     * @param array<int, Arg|Node\VariadicPlaceholder> $args
     */
    private function configArgumentPosition(array $args, string $configVariable): ?int
    {
        $position = 0;

        foreach ($args as $argument) {
            if (!$argument instanceof Arg || $argument->name instanceof Identifier) {
                continue;
            }

            if ($this->isConfig($argument->value, $configVariable) && !$argument->unpack) {
                return $position;
            }

            ++$position;
        }

        return null;
    }

    private function isConfig(Node $node, string $configVariable): bool
    {
        return $node instanceof Variable && $node->name === $configVariable;
    }

    private function literal(Node $node): ?string
    {
        if ($node instanceof String_) {
            return $node->value;
        }

        if ($node instanceof Variable && \is_string($node->name)) {
            return $this->locals[$node->name] ?? null;
        }

        if ($node instanceof PropertyFetch) {
            if (!$node->name instanceof Identifier || $node->name->toString() !== 'value') {
                return null;
            }

            $case = $this->classConstant($node->var);

            return $case instanceof BackedEnum && \is_string($case->value) ? $case->value : null;
        }

        $value = $this->classConstant($node);

        return \is_string($value) ? $value : null;
    }

    private function classConstant(Node $node): mixed
    {
        if (!$node instanceof ClassConstFetch || !$node->class instanceof Name || !$node->name instanceof Identifier) {
            return null;
        }

        $reference = $node->class->toString() . '::' . $node->name->toString();

        return \defined($reference) ? \constant($reference) : null;
    }
}
