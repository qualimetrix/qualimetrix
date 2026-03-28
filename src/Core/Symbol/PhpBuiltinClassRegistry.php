<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

/**
 * Registry of PHP built-in classes.
 *
 * Single source of truth for identifying classes provided by PHP and common extensions.
 * Used by dependency analysis, inheritance depth calculation, and other components
 * that need to distinguish user-defined classes from built-in ones.
 *
 * This static list ensures deterministic results across environments.
 * Update when adding support for new PHP versions.
 *
 * @qmx-ignore duplication.code-duplication Repetitive data structure, not logic duplication.
 */
final class PhpBuiltinClassRegistry
{
    /** @var array<string, true> */
    private const BUILTIN_CLASSES = [
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

    public static function isBuiltin(string $className): bool
    {
        return isset(self::BUILTIN_CLASSES[$className]);
    }
}
