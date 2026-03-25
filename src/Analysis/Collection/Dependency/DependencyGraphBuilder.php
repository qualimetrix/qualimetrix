<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Util\StringSet;

/**
 * Builds a DependencyGraph from a collection of dependencies.
 *
 * Constructs all indexes and precomputes namespace-level Ce/Ca metrics
 * for efficient coupling queries.
 *
 * Dependencies targeting PHP built-in classes are excluded from the graph
 * because coupling to stable standard library types does not contribute to
 * architectural risk measured by CBO. Only `extends` edges are preserved
 * (needed by DitGlobalCollector and NocCollector for inheritance metrics).
 */
final class DependencyGraphBuilder
{
    /**
     * Builds a dependency graph from a collection of dependencies.
     *
     * @param array<Dependency> $dependencies
     */
    public function build(array $dependencies): DependencyGraph
    {
        // Filter dependencies targeting PHP built-in classes, keeping only
        // extends edges (needed by DitGlobalCollector for DIT, NocCollector for NOC).
        // All other types (implements, type hints, catch, instanceof, new, etc.)
        // are filtered — coupling to stable built-in types is not architectural risk.
        $dependencies = array_values(array_filter(
            $dependencies,
            fn(Dependency $dep): bool => $dep->type === DependencyType::Extends
                || !$this->isPhpBuiltinClass($dep->target),
        ));
        $bySource = [];
        $byTarget = [];
        /** @var array<string, SymbolPath> $classMap */
        $classMap = [];
        /** @var array<string, SymbolPath> $namespaceMap */
        $namespaceMap = [];

        // Index dependencies and collect unique classes/namespaces
        foreach ($dependencies as $dep) {
            $sourceKey = $dep->source->toCanonical();
            $targetKey = $dep->target->toCanonical();

            // Index by source
            if (!isset($bySource[$sourceKey])) {
                $bySource[$sourceKey] = [];
            }
            $bySource[$sourceKey][] = $dep;

            // Index by target
            if (!isset($byTarget[$targetKey])) {
                $byTarget[$targetKey] = [];
            }
            $byTarget[$targetKey][] = $dep;

            // Collect unique classes
            $classMap[$sourceKey] = $dep->source;
            $classMap[$targetKey] = $dep->target;

            // Collect unique namespaces (deduplicate via array key)
            $sourceNs = $dep->source->namespace;
            $targetNs = $dep->target->namespace;

            if ($sourceNs !== null && !isset($namespaceMap[$sourceNs])) {
                $namespaceMap[$sourceNs] = SymbolPath::forNamespace($sourceNs);
            }
            if ($targetNs !== null && !isset($namespaceMap[$targetNs])) {
                $namespaceMap[$targetNs] = SymbolPath::forNamespace($targetNs);
            }
        }

        // Re-key namespaceMap by canonical path for downstream consumers
        $canonicalNamespaceMap = [];
        foreach ($namespaceMap as $nsPath) {
            $canonicalNamespaceMap[$nsPath->toCanonical()] = $nsPath;
        }

        // Precompute namespace Ce/Ca
        $namespaceCe = $this->computeNamespaceCe($dependencies, $canonicalNamespaceMap);
        $namespaceCa = $this->computeNamespaceCa($dependencies, $canonicalNamespaceMap);

        // Precompute class-level Ce/Ca (unique targets/sources per class)
        $classCe = $this->computeClassCe($bySource);
        $classCa = $this->computeClassCa($byTarget);

        return new DependencyGraph(
            $dependencies,
            $bySource,
            $byTarget,
            array_values($classMap),
            array_values($canonicalNamespaceMap),
            $namespaceCe,
            $namespaceCa,
            $classCe,
            $classCa,
        );
    }

    /**
     * Computes Efferent Coupling (Ce) for each namespace.
     *
     * Ce = unique external classes that classes in this namespace depend on.
     *
     * @param array<Dependency> $dependencies
     * @param array<string, SymbolPath> $namespaceMap
     *
     * @return array<string, StringSet>
     */
    private function computeNamespaceCe(array $dependencies, array $namespaceMap): array
    {
        /** @var array<string, StringSet> $result */
        $result = [];

        // Initialize all namespaces with empty sets
        foreach ($namespaceMap as $canonicalKey => $nsPath) {
            $result[$canonicalKey] = new StringSet();
        }

        // Cache for SymbolPath::forNamespace()->toCanonical() calls
        /** @var array<string, string> $nsCanonicalCache */
        $nsCanonicalCache = [];

        // For each dependency, if source is in namespace and target is outside,
        // add target to namespace's Ce
        foreach ($dependencies as $dep) {
            $sourceNs = $dep->source->namespace;
            $targetNs = $dep->target->namespace;

            // Skip file-level symbols (namespace is null only for file-level SymbolPaths)
            if ($sourceNs === null) {
                continue;
            }

            // Skip if target is in same namespace (internal dependency)
            if ($sourceNs === $targetNs) {
                continue;
            }

            // Add target class to namespace's Ce
            $nsKey = $nsCanonicalCache[$sourceNs] ??= SymbolPath::forNamespace($sourceNs)->toCanonical();
            $result[$nsKey] = $result[$nsKey]->add($dep->target->toCanonical());
        }

        return $result;
    }

    /**
     * Computes Afferent Coupling (Ca) for each namespace.
     *
     * Ca = unique external classes that depend on classes in this namespace.
     *
     * @param array<Dependency> $dependencies
     * @param array<string, SymbolPath> $namespaceMap
     *
     * @return array<string, StringSet>
     */
    private function computeNamespaceCa(array $dependencies, array $namespaceMap): array
    {
        /** @var array<string, StringSet> $result */
        $result = [];

        // Initialize all namespaces with empty sets
        foreach ($namespaceMap as $canonicalKey => $nsPath) {
            $result[$canonicalKey] = new StringSet();
        }

        // Cache for SymbolPath::forNamespace()->toCanonical() calls
        /** @var array<string, string> $nsCanonicalCache */
        $nsCanonicalCache = [];

        // For each dependency, if target is in namespace and source is outside,
        // add source to namespace's Ca
        foreach ($dependencies as $dep) {
            $sourceNs = $dep->source->namespace;
            $targetNs = $dep->target->namespace;

            // Skip file-level symbols (namespace is null only for file-level SymbolPaths)
            if ($targetNs === null) {
                continue;
            }

            // Skip if source is in same namespace (internal dependency)
            if ($sourceNs === $targetNs) {
                continue;
            }

            // Add source class to namespace's Ca
            $nsKey = $nsCanonicalCache[$targetNs] ??= SymbolPath::forNamespace($targetNs)->toCanonical();
            $result[$nsKey] = $result[$nsKey]->add($dep->source->toCanonical());
        }

        return $result;
    }

    /**
     * Precomputes Efferent Coupling (Ce) for each class.
     *
     * Ce = count of unique classes this class depends on.
     *
     * @param array<string, array<Dependency>> $bySource Dependencies indexed by source canonical key
     *
     * @return array<string, int>
     */
    private function computeClassCe(array $bySource): array
    {
        $result = [];

        foreach ($bySource as $sourceKey => $deps) {
            $targets = [];
            foreach ($deps as $dep) {
                $targets[$dep->target->toCanonical()] = true;
            }
            $result[$sourceKey] = \count($targets);
        }

        return $result;
    }

    /**
     * Precomputes Afferent Coupling (Ca) for each class.
     *
     * Ca = count of unique classes that depend on this class.
     *
     * @param array<string, array<Dependency>> $byTarget Dependencies indexed by target canonical key
     *
     * @return array<string, int>
     */
    private function computeClassCa(array $byTarget): array
    {
        $result = [];

        foreach ($byTarget as $targetKey => $deps) {
            $sources = [];
            foreach ($deps as $dep) {
                $sources[$dep->source->toCanonical()] = true;
            }
            $result[$targetKey] = \count($sources);
        }

        return $result;
    }

    /**
     * PHP built-in classes, interfaces, and enums from core, SPL, standard,
     * and commonly bundled extensions (date, json, pcre, reflection, random,
     * dom, filter, pdo, curl, gd, xml, intl, mysqli, zip).
     *
     * This static list ensures deterministic results across environments.
     * Update when adding support for new PHP versions.
     *
     * @var array<string, bool>
     */
    private const array PHP_BUILTIN_CLASSES = [
        // Core: Exception hierarchy
        'Exception' => true, 'ErrorException' => true,
        'LogicException' => true, 'BadFunctionCallException' => true, 'BadMethodCallException' => true,
        'DomainException' => true, 'InvalidArgumentException' => true, 'LengthException' => true,
        'OutOfRangeException' => true, 'RuntimeException' => true, 'OutOfBoundsException' => true,
        'OverflowException' => true, 'RangeException' => true, 'UnderflowException' => true,
        'UnexpectedValueException' => true,
        // Core: Error hierarchy
        'Error' => true, 'ArithmeticError' => true, 'DivisionByZeroError' => true,
        'AssertionError' => true, 'CompileError' => true, 'ParseError' => true,
        'TypeError' => true, 'ArgumentCountError' => true, 'ValueError' => true,
        'UnhandledMatchError' => true, 'FiberError' => true,
        // Core: fundamental types
        'stdClass' => true, 'Closure' => true, 'Generator' => true, 'Fiber' => true,
        'WeakReference' => true, 'WeakMap' => true, 'ClosedGeneratorException' => true,
        // Core: interfaces
        'Throwable' => true, 'Stringable' => true, 'ArrayAccess' => true,
        'Countable' => true, 'Serializable' => true, 'JsonSerializable' => true,
        'Iterator' => true, 'IteratorAggregate' => true, 'Traversable' => true,
        'OuterIterator' => true, 'SeekableIterator' => true, 'RecursiveIterator' => true,
        'BackedEnum' => true, 'UnitEnum' => true,
        // Core: attributes
        'Attribute' => true, 'AllowDynamicProperties' => true, 'Override' => true,
        'SensitiveParameter' => true, 'ReturnTypeWillChange' => true,
        'Deprecated' => true, 'NoDiscard' => true,
        // Core: misc
        'SensitiveParameterValue' => true, 'Directory' => true, 'StreamBucket' => true,
        'php_user_filter' => true, '__PHP_Incomplete_Class' => true,
        'PropertyHookType' => true, 'RoundingMode' => true, 'DelayedTargetValidation' => true,
        'RequestParseBodyException' => true,
        // SPL: iterators
        'AppendIterator' => true, 'ArrayIterator' => true, 'CachingIterator' => true,
        'CallbackFilterIterator' => true, 'DirectoryIterator' => true, 'EmptyIterator' => true,
        'FilesystemIterator' => true, 'FilterIterator' => true, 'GlobIterator' => true,
        'InfiniteIterator' => true, 'InternalIterator' => true, 'IteratorIterator' => true,
        'LimitIterator' => true, 'MultipleIterator' => true, 'NoRewindIterator' => true,
        'ParentIterator' => true, 'RecursiveArrayIterator' => true, 'RecursiveCachingIterator' => true,
        'RecursiveCallbackFilterIterator' => true, 'RecursiveDirectoryIterator' => true,
        'RecursiveFilterIterator' => true, 'RecursiveIteratorIterator' => true,
        'RecursiveRegexIterator' => true, 'RecursiveTreeIterator' => true, 'RegexIterator' => true,
        // SPL: data structures
        'ArrayObject' => true, 'SplDoublyLinkedList' => true, 'SplFixedArray' => true,
        'SplHeap' => true, 'SplMaxHeap' => true, 'SplMinHeap' => true,
        'SplObjectStorage' => true, 'SplPriorityQueue' => true, 'SplQueue' => true, 'SplStack' => true,
        // SPL: file handling
        'SplFileInfo' => true, 'SplFileObject' => true, 'SplTempFileObject' => true,
        // SPL: observer
        'SplObserver' => true, 'SplSubject' => true,
        // Date/Time
        'DateTime' => true, 'DateTimeImmutable' => true, 'DateTimeInterface' => true,
        'DateTimeZone' => true, 'DateInterval' => true, 'DatePeriod' => true,
        'DateError' => true, 'DateException' => true, 'DateObjectError' => true, 'DateRangeError' => true,
        'DateInvalidOperationException' => true, 'DateInvalidTimeZoneException' => true,
        'DateMalformedIntervalStringException' => true, 'DateMalformedPeriodStringException' => true,
        'DateMalformedStringException' => true,
        // Reflection
        'Reflection' => true, 'ReflectionClass' => true, 'ReflectionClassConstant' => true,
        'ReflectionConstant' => true, 'ReflectionEnum' => true, 'ReflectionEnumBackedCase' => true,
        'ReflectionEnumUnitCase' => true, 'ReflectionException' => true, 'ReflectionExtension' => true,
        'ReflectionFiber' => true, 'ReflectionFunction' => true, 'ReflectionFunctionAbstract' => true,
        'ReflectionGenerator' => true, 'ReflectionIntersectionType' => true, 'ReflectionMethod' => true,
        'ReflectionNamedType' => true, 'ReflectionObject' => true, 'ReflectionParameter' => true,
        'ReflectionProperty' => true, 'ReflectionReference' => true, 'ReflectionType' => true,
        'ReflectionUnionType' => true, 'ReflectionZendExtension' => true, 'Reflector' => true,
        'ReflectionAttribute' => true,
        // JSON
        'JsonException' => true,
        // Random (PHP 8.2+)
        'Random\\Randomizer' => true, 'Random\\Engine' => true, 'Random\\CryptoSafeEngine' => true,
        'Random\\IntervalBoundary' => true, 'Random\\RandomError' => true, 'Random\\RandomException' => true,
        'Random\\BrokenRandomEngineError' => true,
        'Random\\Engine\\Mt19937' => true, 'Random\\Engine\\PcgOneseq128XslRr64' => true,
        'Random\\Engine\\Secure' => true, 'Random\\Engine\\Xoshiro256StarStar' => true,
        // Dom (PHP 8.4+)
        'Dom\\Document' => true, 'Dom\\HTMLDocument' => true, 'Dom\\XMLDocument' => true,
        'Dom\\Element' => true, 'Dom\\HTMLElement' => true, 'Dom\\Attr' => true,
        'Dom\\Node' => true, 'Dom\\NodeList' => true, 'Dom\\NamedNodeMap' => true,
        'Dom\\Text' => true, 'Dom\\Comment' => true, 'Dom\\CDATASection' => true,
        'Dom\\CharacterData' => true, 'Dom\\DocumentFragment' => true, 'Dom\\DocumentType' => true,
        'Dom\\Entity' => true, 'Dom\\EntityReference' => true, 'Dom\\Notation' => true,
        'Dom\\ProcessingInstruction' => true, 'Dom\\XPath' => true, 'Dom\\Implementation' => true,
        'Dom\\DtdNamedNodeMap' => true, 'Dom\\HTMLCollection' => true, 'Dom\\TokenList' => true,
        'Dom\\NamespaceInfo' => true, 'Dom\\AdjacentPosition' => true,
        'Dom\\ChildNode' => true, 'Dom\\ParentNode' => true,
        // Filter (PHP 8.5+)
        'Filter\\FilterException' => true, 'Filter\\FilterFailedException' => true,
        // PDO
        'PDO' => true, 'PDOStatement' => true, 'PDOException' => true, 'PDORow' => true,
        'Pdo\\Mysql' => true, 'Pdo\\Pgsql' => true, 'Pdo\\Sqlite' => true,
        'Pdo\\Dblib' => true, 'Pdo\\Firebird' => true, 'Pdo\\Odbc' => true,
        // Legacy DOM
        'DOMDocument' => true, 'DOMElement' => true, 'DOMNode' => true, 'DOMNodeList' => true,
        'DOMAttr' => true, 'DOMText' => true, 'DOMComment' => true, 'DOMException' => true,
        'DOMXPath' => true, 'DOMNamedNodeMap' => true, 'DOMImplementation' => true,
        'DOMCharacterData' => true, 'DOMCdataSection' => true, 'DOMDocumentFragment' => true,
        'DOMDocumentType' => true, 'DOMEntity' => true, 'DOMEntityReference' => true,
        'DOMNotation' => true, 'DOMProcessingInstruction' => true, 'DOMNameSpaceNode' => true,
        'DOMChildNode' => true, 'DOMParentNode' => true,
        // XML
        'XMLReader' => true, 'XMLWriter' => true, 'XMLParser' => true,
        'SimpleXMLElement' => true, 'SimpleXMLIterator' => true,
        // Curl
        'CurlHandle' => true, 'CurlMultiHandle' => true, 'CurlShareHandle' => true,
        'CURLFile' => true, 'CURLStringFile' => true,
        // GD
        'GdImage' => true, 'GdFont' => true,
        // Intl
        'IntlException' => true, 'IntlDateFormatter' => true, 'NumberFormatter' => true,
        'Collator' => true, 'MessageFormatter' => true, 'Normalizer' => true, 'Locale' => true,
        'IntlCalendar' => true, 'IntlGregorianCalendar' => true, 'IntlTimeZone' => true,
        'IntlBreakIterator' => true, 'IntlCodePointBreakIterator' => true, 'IntlRuleBasedBreakIterator' => true,
        'IntlIterator' => true, 'IntlPartsIterator' => true, 'IntlChar' => true,
        'IntlDatePatternGenerator' => true, 'IntlListFormatter' => true,
        'Transliterator' => true, 'Spoofchecker' => true, 'UConverter' => true, 'ResourceBundle' => true,
        // mysqli
        'mysqli' => true, 'mysqli_stmt' => true, 'mysqli_result' => true,
        'mysqli_driver' => true, 'mysqli_warning' => true, 'mysqli_sql_exception' => true,
        // Zip
        'ZipArchive' => true,
        // Other extensions (opaque handles)
        'BcMath\\Number' => true,
        'Dba\\Connection' => true,
        'FTP\\Connection' => true,
        'LDAP\\Connection' => true, 'LDAP\\Result' => true, 'LDAP\\ResultEntry' => true,
        'Odbc\\Connection' => true, 'Odbc\\Result' => true,
        'PgSql\\Connection' => true, 'PgSql\\Result' => true, 'PgSql\\Lob' => true,
        'Pcntl\\QueuedSignalInfo' => true,
        'Soap\\Url' => true, 'Soap\\Sdl' => true,
    ];

    /**
     * Checks whether a SymbolPath points to a PHP built-in class or interface.
     *
     * Uses a static whitelist for deterministic results across environments.
     */
    private function isPhpBuiltinClass(SymbolPath $target): bool
    {
        $className = $target->type;

        if ($className === null || $className === '') {
            return false;
        }

        $namespace = $target->namespace;

        // Build FQN for lookup: 'Exception' or 'Random\Randomizer'
        $fqn = ($namespace !== null && $namespace !== '')
            ? $namespace . '\\' . $className
            : $className;

        return isset(self::PHP_BUILTIN_CLASSES[$fqn]);
    }
}
