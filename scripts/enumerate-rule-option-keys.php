<?php

declare(strict_types=1);

/**
 * The two option-key populations behind the rule-option recognition subject, as TSV.
 *
 * Table A — per rule Options class: which level slots it accepts and which keys
 * are allowed *inside* each slot. That inner set is what
 * `RuleOptionsFactory::warnAboutUnknownKeys()` never compares anything against.
 *
 * Table B — per rule Options class: the key set DECLARED to the product
 * (constructor parameters plus `ShorthandOptionKeysInterface` plus
 * `AdditionalOptionKeysInterface`), the key set actually READ by `fromArray()`,
 * and the two differences between them.
 *
 * Table C — what this script could not reduce to a literal key, per class. It is
 * printed rather than reasoned about afterwards: a blind spot counted by hand is
 * a blind spot.
 *
 * The declared side comes from the real container's rule registry plus
 * reflection, never a hand-typed list. The read side cannot come from
 * reflection at all — it lives inside a method body — so it comes from the AST.
 *
 * Usage: php scripts/enumerate-rule-option-keys.php [--out-dir=DIR]
 */

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
use Qualimetrix\Analysis\Finding\Contract\Rule\AdditionalOptionKeysInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;

require __DIR__ . '/../vendor/autoload.php';

/**
 * One `fromArray()` body, read.
 */
final class OptionKeyReading
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
final class FromArrayReader
{
    private const string CONFIG_METHOD = 'fromArray';

    /** @var array<string, ClassMethod> */
    private array $methods = [];

    /** @var array<int, true> object id of every node that only runs when a branch was taken */
    private array $guarded = [];

    /** @var array<string, string> local variable name => resolved literal */
    private array $locals = [];

    private OptionKeyReading $reading;

    /** @var list<string> */
    private array $visited = [];

    public function __construct(private readonly NodeFinder $finder = new NodeFinder()) {}

    /**
     * @param class-string $optionsClass
     */
    public function read(string $optionsClass): OptionKeyReading
    {
        $this->reading = new OptionKeyReading();
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
        if (!$configVariable instanceof Variable || !is_string($configVariable->name)) {
            $this->reading->blind('opaque-sink', 'fromArray-parameter-not-a-plain-variable');

            return $this->reading;
        }

        $this->walkMethod($entry, $configVariable->name, false);

        return $this->reading;
    }

    /**
     * @param class-string $optionsClass
     */
    private function classNode(string $optionsClass): ?Class_
    {
        $file = (new \ReflectionClass($optionsClass))->getFileName();
        if ($file === false) {
            return null;
        }

        $code = file_get_contents($file);
        if ($code === false) {
            return null;
        }

        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        if ($ast === null) {
            return null;
        }

        $traverser = new NodeTraverser(new NameResolver());
        $ast = $traverser->traverse($ast);

        $node = $this->finder->findFirstInstanceOf($ast, Class_::class);

        return $node instanceof Class_ ? $node : null;
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
            assert($branch instanceof If_);

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
        if (in_array($signature, $this->visited, true)) {
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
            assert($assign instanceof Assign);

            if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
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

        if ($name === 'array_key_exists' && count($node->args) === 2) {
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
        if (!$parameter instanceof Variable || !is_string($parameter->name)) {
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

        $thresholdKey = isset($bound['thresholdKey']) ? $this->literal($bound['thresholdKey']) : \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey::THRESHOLD;
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
                assert($string instanceof String_);
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

        if ($node instanceof Variable && is_string($node->name)) {
            return $this->locals[$node->name] ?? null;
        }

        if ($node instanceof PropertyFetch) {
            if (!$node->name instanceof Identifier || $node->name->toString() !== 'value') {
                return null;
            }

            $case = $this->classConstant($node->var);

            return $case instanceof \BackedEnum && is_string($case->value) ? $case->value : null;
        }

        $value = $this->classConstant($node);

        return is_string($value) ? $value : null;
    }

    private function classConstant(Node $node): mixed
    {
        if (!$node instanceof ClassConstFetch || !$node->class instanceof Name || !$node->name instanceof Identifier) {
            return null;
        }

        $reference = $node->class->toString() . '::' . $node->name->toString();

        return defined($reference) ? constant($reference) : null;
    }
}

/**
 * Prints Table A, Table B and the blind-spot table.
 */
final class RuleOptionKeyEnumeration
{
    /** Keys the factory strips before `fromArray()` ever sees them. */
    private const array FRAMEWORK_KEYS = ['suppressNamespaces', 'suppressNamespaceChannels', 'suppressPaths'];

    /**
     * @param list<string> $arguments
     */
    public static function main(array $arguments): int
    {
        $outDir = null;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--out-dir=')) {
                $outDir = substr($argument, strlen('--out-dir='));
            }
        }

        $enumeration = new self();
        $optionsClasses = $enumeration->optionsClassesFromContainer();
        $independent = $enumeration->optionsClassesFromSource();

        $reader = new FromArrayReader();

        $tableA = [implode("\t", [
            'options_class', 'rules', 'hierarchical', 'level_slot_keys',
            'keys_allowed_inside_each_slot', 'slot_key_set_defined_at', 'slot_names_defined_at',
        ])];
        $tableB = [implode("\t", [
            'options_class', 'rules', 'declared', 'declared_from',
            'read_unguarded', 'read_branch_guarded', 'read_not_declared', 'declared_not_read',
        ])];
        $tableC = [implode("\t", ['options_class', 'blind_spot', 'sites', 'where'])];
        $tableD = [implode("\t", [
            'level_class', 'owning_options_class', 'slot', 'declared', 'declared_from',
            'read_unguarded', 'read_branch_guarded', 'read_not_declared', 'declared_not_read',
            'blind_spots',
        ])];
        $classesPerBlindSpot = [];
        $classesWithGuardedOnlyKeys = 0;
        $filesWithSeveralClasses = 0;

        foreach ($optionsClasses as $optionsClass => $rules) {
            if (!is_a($optionsClass, RuleOptionsInterface::class, true)) {
                continue;
            }

            $reading = $reader->read($optionsClass);
            $declared = $enumeration->declaredKeys($optionsClass);

            $read = array_keys($reading->keys);
            sort($read);
            $unguarded = array_keys(array_filter($reading->keys));
            sort($unguarded);
            $guardedOnly = array_values(array_diff($read, $unguarded));
            if ($guardedOnly !== []) {
                ++$classesWithGuardedOnlyKeys;
            }
            if ($enumeration->classesInFileOf($optionsClass) > 1) {
                ++$filesWithSeveralClasses;
            }

            $declaredNames = array_keys($declared);
            sort($declaredNames);

            $tableB[] = implode("\t", [
                $optionsClass,
                implode(',', $rules),
                self::set($declaredNames),
                self::set(array_map(static fn(string $key): string => $key . ':' . $declared[$key], $declaredNames)),
                self::set($unguarded),
                self::set($guardedOnly),
                self::set(array_values(array_diff($read, $declaredNames))),
                self::set(array_values(array_diff($declaredNames, $read))),
            ]);

            $tableA[] = $enumeration->rowA($optionsClass, $rules, $reader);
            foreach ($enumeration->rowsD($optionsClass, $reader) as $levelRow) {
                $tableD[] = $levelRow;
            }

            foreach ($reading->unresolved as $kind => $count) {
                if ($count === 0) {
                    continue;
                }
                $where = array_values(array_filter(
                    $reading->unresolvedDetail,
                    static fn(string $detail): bool => str_starts_with($detail, $kind . '@'),
                ));
                $classesPerBlindSpot[$kind] = ($classesPerBlindSpot[$kind] ?? 0) + 1;
                $tableC[] = implode("\t", [$optionsClass, $kind, (string) $count, implode('; ', $where)]);
            }
        }

        $sections = [
            'table-a.tsv' => $tableA,
            'table-b.tsv' => $tableB,
            'blind-spots.tsv' => $tableC,
            'level-declared-vs-read.tsv' => $tableD,
        ];

        foreach ($sections as $name => $rows) {
            if ($outDir !== null) {
                file_put_contents(rtrim($outDir, '/') . '/' . $name, implode("\n", $rows) . "\n");
            }
            echo '## ', $name, "\n", implode("\n", $rows), "\n\n";
        }

        $fromContainer = array_keys($optionsClasses);
        sort($fromContainer);
        sort($independent);

        echo '## counts', "\n";
        echo 'options_classes_via_container', "\t", count($fromContainer), "\n";
        echo 'options_classes_via_source_scan', "\t", count($independent), "\n";
        echo 'in_source_scan_only', "\t", self::set(array_values(array_diff($independent, $fromContainer))), "\n";
        echo 'in_container_only', "\t", self::set(array_values(array_diff($fromContainer, $independent))), "\n";
        echo "\n## blind-spot reach (classes affected, out of ", count($fromContainer), ")\n";
        foreach (['dynamic-key', 'opaque-sink', 'spread', 'iteration', 'nested-delegation'] as $kind) {
            echo $kind, "\t", $classesPerBlindSpot[$kind] ?? 0, "\n";
        }
        echo 'branch-guarded-only-keys', "\t", $classesWithGuardedOnlyKeys, "\n";
        echo 'files-holding-more-than-one-class', "\t", $filesWithSeveralClasses, "\n";

        return 0;
    }

    /**
     * The same declared-versus-read question as table B, asked of the level
     * classes table B never reaches: a hierarchical wrapper hands its slot's
     * sub-array to a level class, and that class — not the wrapper — decides
     * which keys are legal inside the slot.
     *
     * @param class-string<RuleOptionsInterface> $optionsClass
     *
     * @return list<string>
     */
    private function rowsD(string $optionsClass, FromArrayReader $reader): array
    {
        $options = $optionsClass::fromArray([]);
        if (!$options instanceof HierarchicalRuleOptionsInterface) {
            return [];
        }

        $rows = [];
        foreach ($options->getSupportedLevels() as $level) {
            $levelClass = $options->forLevel($level)::class;
            $reading = $reader->read($levelClass);
            $declared = $this->declaredKeys($levelClass);

            $read = array_keys($reading->keys);
            sort($read);
            $unguarded = array_keys(array_filter($reading->keys));
            sort($unguarded);
            $guardedOnly = array_values(array_diff($read, $unguarded));
            $declaredNames = array_keys($declared);
            sort($declaredNames);

            $blind = [];
            foreach ($reading->unresolved as $kind => $count) {
                if ($count > 0) {
                    $blind[] = $kind . ':' . $count;
                }
            }

            $rows[] = implode("\t", [
                $levelClass,
                $optionsClass,
                $level->value,
                self::set($declaredNames),
                self::set(array_map(static fn(string $key): string => $key . ':' . $declared[$key], $declaredNames)),
                self::set($unguarded),
                self::set($guardedOnly),
                self::set(array_values(array_diff($read, $declaredNames))),
                self::set(array_values(array_diff($declaredNames, $read))),
                self::set($blind),
            ]);
        }

        return $rows;
    }

    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     * @param list<string> $rules
     */
    private function rowA(string $optionsClass, array $rules, FromArrayReader $reader): string
    {
        $options = $optionsClass::fromArray([]);

        if (!$options instanceof HierarchicalRuleOptionsInterface) {
            return implode("\t", [$optionsClass, implode(',', $rules), 'no', '-', '-', '-', '-']);
        }

        $slots = [];
        $sets = [];
        $sources = [];

        foreach ($options->getSupportedLevels() as $level) {
            $levelOptions = $options->forLevel($level);
            $levelClass = $levelOptions::class;
            $reading = $reader->read($levelClass);

            $keys = array_values(array_diff(array_keys($reading->keys), self::FRAMEWORK_KEYS));
            sort($keys);

            $slots[] = $level->value;
            $sets[] = $level->value . '={' . implode(',', $keys) . '}';
            $sources[] = $level->value . '=' . self::sourceOf($levelClass);
        }

        return implode("\t", [
            $optionsClass,
            implode(',', $rules),
            'yes',
            implode(',', $slots),
            implode('|', $sets),
            implode('|', $sources),
            self::sourceOf($optionsClass),
        ]);
    }

    /**
     * Declared = what the factory compares a user key against.
     *
     * @param class-string $optionsClass
     *
     * @return array<string, string> canonical key => which contract declares it
     */
    private function declaredKeys(string $optionsClass): array
    {
        $declared = [];

        $constructor = (new \ReflectionClass($optionsClass))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $declared[ConfigKeySpelling::normalize($parameter->getName())] = 'constructor';
        }

        if (is_a($optionsClass, ShorthandOptionKeysInterface::class, true)) {
            foreach ($optionsClass::getShorthandOptionKeys() as $key) {
                $declared[ConfigKeySpelling::normalize($key)] = 'shorthand';
            }
        }

        if (is_a($optionsClass, AdditionalOptionKeysInterface::class, true)) {
            foreach ($optionsClass::getAdditionalOptionKeys() as $key) {
                $declared[ConfigKeySpelling::normalize($key)] = 'additional';
            }
        }

        foreach (self::FRAMEWORK_KEYS as $frameworkKey) {
            unset($declared[$frameworkKey]);
        }

        return $declared;
    }

    /**
     * @return array<class-string<RuleOptionsInterface>, list<string>>
     */
    private function optionsClassesFromContainer(): array
    {
        $container = (new ContainerFactory())->create();
        $registry = $container->get(RuleRegistryInterface::class);
        assert($registry instanceof RuleRegistryInterface);

        $classes = [];
        foreach ($registry->getClasses() as $ruleClass) {
            $classes[$ruleClass::getOptionsClass()][] = RuleNameReader::read($ruleClass);
        }

        ksort($classes);

        return $classes;
    }

    /**
     * The independent count: every concrete class under `src/` that implements
     * the interface, found by walking files rather than by asking the container.
     *
     * @return list<string>
     */
    private function optionsClassesFromSource(): array
    {
        $root = dirname(__DIR__) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        $found = [];
        foreach ($files as $file) {
            assert($file instanceof \SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            if (
                preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) !== 1
                || preg_match('/^(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?class\s+(\w+)/m', $contents, $class) !== 1
            ) {
                continue;
            }

            $fqcn = trim($namespace[1]) . '\\' . $class[1];
            if (!class_exists($fqcn)) {
                continue;
            }

            $reflection = new \ReflectionClass($fqcn);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(RuleOptionsInterface::class)) {
                continue;
            }

            $found[] = $fqcn;
        }

        return array_values(array_unique($found));
    }

    /**
     * How many class declarations sit in the file this class lives in.
     *
     * The reader takes the first one; anything past it is unread.
     *
     * @param class-string $class
     */
    public function classesInFileOf(string $class): int
    {
        $file = (new \ReflectionClass($class))->getFileName();
        if ($file === false) {
            return 1;
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return 1;
        }

        $declarations = preg_match_all('/^(?:final\s+|readonly\s+|abstract\s+)*class\s+\w+/m', $contents);

        return $declarations === false ? 1 : $declarations;
    }

    /**
     * @param class-string $class
     */
    private static function sourceOf(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $file = $reflection->getFileName();
        $method = $reflection->hasMethod('fromArray') ? $reflection->getMethod('fromArray') : null;

        if ($file === false || $method === null) {
            return '-';
        }

        $relative = str_replace(dirname(__DIR__) . '/', '', $file);

        return $relative . ':' . $method->getStartLine();
    }

    /**
     * @param list<string> $values
     */
    private static function set(array $values): string
    {
        sort($values);

        return $values === [] ? '-' : implode(',', $values);
    }
}

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];

exit(RuleOptionKeyEnumeration::main($argv));
