<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

/**
 * What is above each class and interface {@see PhpBuiltinClassRegistry} lists:
 * its parent class, the interfaces it implements, and the attributes written
 * on its own declaration.
 *
 * A static fact for the reason the registry is one. Read from the running PHP,
 * the answer for `class X extends \Uri\InvalidUriException` depended on
 * whether the analysing PHP loaded `uri`, and layer membership, violations and
 * the exit code with it. This table answers every listed name the same way on
 * every machine. `PhpBuiltinClassHierarchyCensusTest` compares each name with
 * the running PHP wherever that PHP declares it; it never writes the table.
 *
 * Which names it answers for is the registry's list, not a copy of it. Each
 * map below holds only the names with something to say, so a registered name
 * missing from one of them has no parent, no interfaces or no attributes.
 *
 * Interfaces are transitive, as reflection reports them; for an interface they
 * are every interface it extends. Attributes are the class-level ones only:
 * which members of PHP's classes carry `#[\Deprecated]` changes between PHP
 * versions, so no one table could state it.
 */
final class PhpBuiltinClassHierarchy
{
    /**
     * The registered names that are interfaces. For them `extends` reaches
     * interfaces, not a parent class.
     *
     * @var array<string, true>
     */
    private const array INTERFACE_NAMES = [
        'ArrayAccess' => true,
        'BackedEnum' => true,
        'Countable' => true,
        'DateTimeInterface' => true,
        'Dom\\ChildNode' => true,
        'Dom\\ParentNode' => true,
        'DOMChildNode' => true,
        'DOMParentNode' => true,
        'Iterator' => true,
        'IteratorAggregate' => true,
        'JsonSerializable' => true,
        'OuterIterator' => true,
        'Random\\CryptoSafeEngine' => true,
        'Random\\Engine' => true,
        'RecursiveIterator' => true,
        'Reflector' => true,
        'SeekableIterator' => true,
        'Serializable' => true,
        'SessionHandlerInterface' => true,
        'SessionIdInterface' => true,
        'SessionUpdateTimestampHandlerInterface' => true,
        'SplObserver' => true,
        'SplSubject' => true,
        'Stringable' => true,
        'Throwable' => true,
        'Traversable' => true,
        'UnitEnum' => true,
    ];

    /**
     * Registered class or enum => its parent class.
     *
     * @var array<string, string>
     */
    private const array PARENTS = [
        'AppendIterator' => 'IteratorIterator',
        'ArgumentCountError' => 'TypeError',
        'ArithmeticError' => 'Error',
        'AssertionError' => 'Error',
        'BadFunctionCallException' => 'LogicException',
        'BadMethodCallException' => 'BadFunctionCallException',
        'CachingIterator' => 'IteratorIterator',
        'CallbackFilterIterator' => 'FilterIterator',
        'ClosedGeneratorException' => 'Exception',
        'CompileError' => 'Error',
        'DateError' => 'Error',
        'DateException' => 'Exception',
        'DateInvalidOperationException' => 'DateException',
        'DateInvalidTimeZoneException' => 'DateException',
        'DateMalformedIntervalStringException' => 'DateException',
        'DateMalformedPeriodStringException' => 'DateException',
        'DateMalformedStringException' => 'DateException',
        'DateObjectError' => 'DateError',
        'DateRangeError' => 'DateError',
        'DirectoryIterator' => 'SplFileInfo',
        'DivisionByZeroError' => 'ArithmeticError',
        'Dom\\Attr' => 'Dom\\Node',
        'Dom\\CDATASection' => 'Dom\\Text',
        'Dom\\CharacterData' => 'Dom\\Node',
        'Dom\\Comment' => 'Dom\\CharacterData',
        'Dom\\Document' => 'Dom\\Node',
        'Dom\\DocumentFragment' => 'Dom\\Node',
        'Dom\\DocumentType' => 'Dom\\Node',
        'Dom\\DOMException' => 'Exception',
        'Dom\\Element' => 'Dom\\Node',
        'Dom\\Entity' => 'Dom\\Node',
        'Dom\\EntityReference' => 'Dom\\Node',
        'Dom\\HTMLDocument' => 'Dom\\Document',
        'Dom\\HTMLElement' => 'Dom\\Element',
        'Dom\\Notation' => 'Dom\\Node',
        'Dom\\ProcessingInstruction' => 'Dom\\CharacterData',
        'Dom\\Text' => 'Dom\\CharacterData',
        'Dom\\XMLDocument' => 'Dom\\Document',
        'DomainException' => 'LogicException',
        'DOMAttr' => 'DOMNode',
        'DOMCdataSection' => 'DOMText',
        'DOMCharacterData' => 'DOMNode',
        'DOMComment' => 'DOMCharacterData',
        'DOMDocument' => 'DOMNode',
        'DOMDocumentFragment' => 'DOMNode',
        'DOMDocumentType' => 'DOMNode',
        'DOMElement' => 'DOMNode',
        'DOMEntity' => 'DOMNode',
        'DOMEntityReference' => 'DOMNode',
        'DOMException' => 'Exception',
        'DOMNotation' => 'DOMNode',
        'DOMProcessingInstruction' => 'DOMNode',
        'DOMText' => 'DOMCharacterData',
        'ErrorException' => 'Exception',
        'FFI\\Exception' => 'Error',
        'FFI\\ParserException' => 'FFI\\Exception',
        'FiberError' => 'Error',
        'FilesystemIterator' => 'DirectoryIterator',
        'Filter\\FilterException' => 'Exception',
        'Filter\\FilterFailedException' => 'Filter\\FilterException',
        'FilterIterator' => 'IteratorIterator',
        'GlobIterator' => 'FilesystemIterator',
        'InfiniteIterator' => 'IteratorIterator',
        'IntlCodePointBreakIterator' => 'IntlBreakIterator',
        'IntlException' => 'Exception',
        'IntlGregorianCalendar' => 'IntlCalendar',
        'IntlPartsIterator' => 'IntlIterator',
        'IntlRuleBasedBreakIterator' => 'IntlBreakIterator',
        'InvalidArgumentException' => 'LogicException',
        'JsonException' => 'Exception',
        'LengthException' => 'LogicException',
        'LimitIterator' => 'IteratorIterator',
        'LogicException' => 'Exception',
        'mysqli_sql_exception' => 'RuntimeException',
        'NoRewindIterator' => 'IteratorIterator',
        'OutOfBoundsException' => 'RuntimeException',
        'OutOfRangeException' => 'LogicException',
        'OverflowException' => 'RuntimeException',
        'ParentIterator' => 'RecursiveFilterIterator',
        'ParseError' => 'CompileError',
        'Pdo\\Dblib' => 'PDO',
        'Pdo\\Firebird' => 'PDO',
        'Pdo\\Mysql' => 'PDO',
        'Pdo\\Odbc' => 'PDO',
        'Pdo\\Pgsql' => 'PDO',
        'Pdo\\Sqlite' => 'PDO',
        'PDOException' => 'RuntimeException',
        'Phar' => 'RecursiveDirectoryIterator',
        'PharData' => 'RecursiveDirectoryIterator',
        'PharException' => 'Exception',
        'PharFileInfo' => 'SplFileInfo',
        'Random\\BrokenRandomEngineError' => 'Random\\RandomError',
        'Random\\RandomError' => 'Error',
        'Random\\RandomException' => 'Exception',
        'RangeException' => 'RuntimeException',
        'RecursiveArrayIterator' => 'ArrayIterator',
        'RecursiveCachingIterator' => 'CachingIterator',
        'RecursiveCallbackFilterIterator' => 'CallbackFilterIterator',
        'RecursiveDirectoryIterator' => 'FilesystemIterator',
        'RecursiveFilterIterator' => 'FilterIterator',
        'RecursiveRegexIterator' => 'RegexIterator',
        'RecursiveTreeIterator' => 'RecursiveIteratorIterator',
        'ReflectionEnum' => 'ReflectionClass',
        'ReflectionEnumBackedCase' => 'ReflectionEnumUnitCase',
        'ReflectionEnumUnitCase' => 'ReflectionClassConstant',
        'ReflectionException' => 'Exception',
        'ReflectionFunction' => 'ReflectionFunctionAbstract',
        'ReflectionIntersectionType' => 'ReflectionType',
        'ReflectionMethod' => 'ReflectionFunctionAbstract',
        'ReflectionNamedType' => 'ReflectionType',
        'ReflectionObject' => 'ReflectionClass',
        'ReflectionUnionType' => 'ReflectionType',
        'RegexIterator' => 'FilterIterator',
        'RequestParseBodyException' => 'Exception',
        'RuntimeException' => 'Exception',
        'SimpleXMLIterator' => 'SimpleXMLElement',
        'SNMPException' => 'RuntimeException',
        'SoapFault' => 'Exception',
        'SodiumException' => 'Exception',
        'SplFileObject' => 'SplFileInfo',
        'SplMaxHeap' => 'SplHeap',
        'SplMinHeap' => 'SplHeap',
        'SplQueue' => 'SplDoublyLinkedList',
        'SplStack' => 'SplDoublyLinkedList',
        'SplTempFileObject' => 'SplFileObject',
        'SQLite3Exception' => 'Exception',
        'TypeError' => 'Error',
        'UnderflowException' => 'RuntimeException',
        'UnexpectedValueException' => 'RuntimeException',
        'UnhandledMatchError' => 'Error',
        'Uri\\InvalidUriException' => 'Uri\\UriException',
        'Uri\\UriError' => 'Error',
        'Uri\\UriException' => 'Exception',
        'Uri\\WhatWg\\InvalidUrlException' => 'Uri\\InvalidUriException',
        'ValueError' => 'Error',
    ];

    /**
     * Registered name => every interface it implements or extends, sorted.
     *
     * @var array<string, list<string>>
     */
    private const array INTERFACES = [
        'AppendIterator' => ['Iterator', 'OuterIterator', 'Traversable'],
        'ArgumentCountError' => ['Stringable', 'Throwable'],
        'ArithmeticError' => ['Stringable', 'Throwable'],
        'ArrayIterator' => ['ArrayAccess', 'Countable', 'Iterator', 'SeekableIterator', 'Serializable', 'Traversable'],
        'ArrayObject' => ['ArrayAccess', 'Countable', 'IteratorAggregate', 'Serializable', 'Traversable'],
        'AssertionError' => ['Stringable', 'Throwable'],
        'BackedEnum' => ['UnitEnum'],
        'BadFunctionCallException' => ['Stringable', 'Throwable'],
        'BadMethodCallException' => ['Stringable', 'Throwable'],
        'BcMath\\Number' => ['Stringable'],
        'CachingIterator' => ['ArrayAccess', 'Countable', 'Iterator', 'OuterIterator', 'Stringable', 'Traversable'],
        'CallbackFilterIterator' => ['Iterator', 'OuterIterator', 'Traversable'],
        'ClosedGeneratorException' => ['Stringable', 'Throwable'],
        'CompileError' => ['Stringable', 'Throwable'],
        'DateError' => ['Stringable', 'Throwable'],
        'DateException' => ['Stringable', 'Throwable'],
        'DateInvalidOperationException' => ['Stringable', 'Throwable'],
        'DateInvalidTimeZoneException' => ['Stringable', 'Throwable'],
        'DateMalformedIntervalStringException' => ['Stringable', 'Throwable'],
        'DateMalformedPeriodStringException' => ['Stringable', 'Throwable'],
        'DateMalformedStringException' => ['Stringable', 'Throwable'],
        'DateObjectError' => ['Stringable', 'Throwable'],
        'DatePeriod' => ['IteratorAggregate', 'Traversable'],
        'DateRangeError' => ['Stringable', 'Throwable'],
        'DateTime' => ['DateTimeInterface'],
        'DateTimeImmutable' => ['DateTimeInterface'],
        'DirectoryIterator' => ['Iterator', 'SeekableIterator', 'Stringable', 'Traversable'],
        'DivisionByZeroError' => ['Stringable', 'Throwable'],
        'Dom\\AdjacentPosition' => ['BackedEnum', 'UnitEnum'],
        'Dom\\CDATASection' => ['Dom\\ChildNode'],
        'Dom\\CharacterData' => ['Dom\\ChildNode'],
        'Dom\\Comment' => ['Dom\\ChildNode'],
        'Dom\\Document' => ['Dom\\ParentNode'],
        'Dom\\DocumentFragment' => ['Dom\\ParentNode'],
        'Dom\\DocumentType' => ['Dom\\ChildNode'],
        'Dom\\DOMException' => ['Stringable', 'Throwable'],
        'Dom\\DtdNamedNodeMap' => ['Countable', 'IteratorAggregate', 'Traversable'],
        'Dom\\Element' => ['Dom\\ChildNode', 'Dom\\ParentNode'],
        'Dom\\HTMLCollection' => ['Countable', 'IteratorAggregate', 'Traversable'],
        'Dom\\HTMLDocument' => ['Dom\\ParentNode'],
        'Dom\\HTMLElement' => ['Dom\\ChildNode', 'Dom\\ParentNode'],
        'Dom\\NamedNodeMap' => ['Countable', 'IteratorAggregate', 'Traversable'],
        'Dom\\NodeList' => ['Countable', 'IteratorAggregate', 'Traversable'],
        'Dom\\ProcessingInstruction' => ['Dom\\ChildNode'],
        'Dom\\Text' => ['Dom\\ChildNode'],
        'Dom\\TokenList' => ['Countable', 'IteratorAggregate', 'Traversable'],
        'Dom\\XMLDocument' => ['Dom\\ParentNode'],
        'DomainException' => ['Stringable', 'Throwable'],
        'DOMCdataSection' => ['DOMChildNode'],
        'DOMCharacterData' => ['DOMChildNode'],
        'DOMComment' => ['DOMChildNode'],
        'DOMDocument' => ['DOMParentNode'],
        'DOMDocumentFragment' => ['DOMParentNode'],
        'DOMElement' => ['DOMChildNode', 'DOMParentNode'],
        'DOMException' => ['Stringable', 'Throwable'],
        'DOMNamedNodeMap' => ['Countable', 'IteratorAggregate', 'Traversable'],
        'DOMNodeList' => ['Countable', 'IteratorAggregate', 'Traversable'],
        'DOMText' => ['DOMChildNode'],
        'EmptyIterator' => ['Iterator', 'Traversable'],
        'Error' => ['Stringable', 'Throwable'],
        'ErrorException' => ['Stringable', 'Throwable'],
        'Exception' => ['Stringable', 'Throwable'],
        'FFI\\Exception' => ['Stringable', 'Throwable'],
        'FFI\\ParserException' => ['Stringable', 'Throwable'],
        'FiberError' => ['Stringable', 'Throwable'],
        'FilesystemIterator' => ['Iterator', 'SeekableIterator', 'Stringable', 'Traversable'],
        'Filter\\FilterException' => ['Stringable', 'Throwable'],
        'Filter\\FilterFailedException' => ['Stringable', 'Throwable'],
        'FilterIterator' => ['Iterator', 'OuterIterator', 'Traversable'],
        'Generator' => ['Iterator', 'Traversable'],
        'GlobIterator' => ['Countable', 'Iterator', 'SeekableIterator', 'Stringable', 'Traversable'],
        'InfiniteIterator' => ['Iterator', 'OuterIterator', 'Traversable'],
        'InternalIterator' => ['Iterator', 'Traversable'],
        'IntlBreakIterator' => ['IteratorAggregate', 'Traversable'],
        'IntlCodePointBreakIterator' => ['IteratorAggregate', 'Traversable'],
        'IntlException' => ['Stringable', 'Throwable'],
        'IntlIterator' => ['Iterator', 'Traversable'],
        'IntlPartsIterator' => ['Iterator', 'Traversable'],
        'IntlRuleBasedBreakIterator' => ['IteratorAggregate', 'Traversable'],
        'InvalidArgumentException' => ['Stringable', 'Throwable'],
        'Iterator' => ['Traversable'],
        'IteratorAggregate' => ['Traversable'],
        'IteratorIterator' => ['Iterator', 'OuterIterator', 'Traversable'],
        'JsonException' => ['Stringable', 'Throwable'],
        'LengthException' => ['Stringable', 'Throwable'],
        'LimitIterator' => ['Iterator', 'OuterIterator', 'Traversable'],
        'LogicException' => ['Stringable', 'Throwable'],
        'MultipleIterator' => ['Iterator', 'Traversable'],
        'mysqli_result' => ['IteratorAggregate', 'Traversable'],
        'mysqli_sql_exception' => ['Stringable', 'Throwable'],
        'NoRewindIterator' => ['Iterator', 'OuterIterator', 'Traversable'],
        'OuterIterator' => ['Iterator', 'Traversable'],
        'OutOfBoundsException' => ['Stringable', 'Throwable'],
        'OutOfRangeException' => ['Stringable', 'Throwable'],
        'OverflowException' => ['Stringable', 'Throwable'],
        'ParentIterator' => ['Iterator', 'OuterIterator', 'RecursiveIterator', 'Traversable'],
        'ParseError' => ['Stringable', 'Throwable'],
        'Pcntl\\QosClass' => ['UnitEnum'],
        'PDOException' => ['Stringable', 'Throwable'],
        'PDOStatement' => ['IteratorAggregate', 'Traversable'],
        'Phar' => ['ArrayAccess', 'Countable', 'Iterator', 'RecursiveIterator', 'SeekableIterator', 'Stringable', 'Traversable'],
        'PharData' => ['ArrayAccess', 'Countable', 'Iterator', 'RecursiveIterator', 'SeekableIterator', 'Stringable', 'Traversable'],
        'PharException' => ['Stringable', 'Throwable'],
        'PharFileInfo' => ['Stringable'],
        'PhpToken' => ['Stringable'],
        'PropertyHookType' => ['BackedEnum', 'UnitEnum'],
        'Random\\BrokenRandomEngineError' => ['Stringable', 'Throwable'],
        'Random\\CryptoSafeEngine' => ['Random\\Engine'],
        'Random\\Engine\\Mt19937' => ['Random\\Engine'],
        'Random\\Engine\\PcgOneseq128XslRr64' => ['Random\\Engine'],
        'Random\\Engine\\Secure' => ['Random\\CryptoSafeEngine', 'Random\\Engine'],
        'Random\\Engine\\Xoshiro256StarStar' => ['Random\\Engine'],
        'Random\\IntervalBoundary' => ['UnitEnum'],
        'Random\\RandomError' => ['Stringable', 'Throwable'],
        'Random\\RandomException' => ['Stringable', 'Throwable'],
        'RangeException' => ['Stringable', 'Throwable'],
        'RecursiveArrayIterator' => ['ArrayAccess', 'Countable', 'Iterator', 'RecursiveIterator', 'SeekableIterator', 'Serializable', 'Traversable'],
        'RecursiveCachingIterator' => ['ArrayAccess', 'Countable', 'Iterator', 'OuterIterator', 'RecursiveIterator', 'Stringable', 'Traversable'],
        'RecursiveCallbackFilterIterator' => ['Iterator', 'OuterIterator', 'RecursiveIterator', 'Traversable'],
        'RecursiveDirectoryIterator' => ['Iterator', 'RecursiveIterator', 'SeekableIterator', 'Stringable', 'Traversable'],
        'RecursiveFilterIterator' => ['Iterator', 'OuterIterator', 'RecursiveIterator', 'Traversable'],
        'RecursiveIterator' => ['Iterator', 'Traversable'],
        'RecursiveIteratorIterator' => ['Iterator', 'OuterIterator', 'Traversable'],
        'RecursiveRegexIterator' => ['Iterator', 'OuterIterator', 'RecursiveIterator', 'Traversable'],
        'RecursiveTreeIterator' => ['Iterator', 'OuterIterator', 'Traversable'],
        'ReflectionAttribute' => ['Reflector', 'Stringable'],
        'ReflectionClass' => ['Reflector', 'Stringable'],
        'ReflectionClassConstant' => ['Reflector', 'Stringable'],
        'ReflectionConstant' => ['Reflector', 'Stringable'],
        'ReflectionEnum' => ['Reflector', 'Stringable'],
        'ReflectionEnumBackedCase' => ['Reflector', 'Stringable'],
        'ReflectionEnumUnitCase' => ['Reflector', 'Stringable'],
        'ReflectionException' => ['Stringable', 'Throwable'],
        'ReflectionExtension' => ['Reflector', 'Stringable'],
        'ReflectionFunction' => ['Reflector', 'Stringable'],
        'ReflectionFunctionAbstract' => ['Reflector', 'Stringable'],
        'ReflectionIntersectionType' => ['Stringable'],
        'ReflectionMethod' => ['Reflector', 'Stringable'],
        'ReflectionNamedType' => ['Stringable'],
        'ReflectionObject' => ['Reflector', 'Stringable'],
        'ReflectionParameter' => ['Reflector', 'Stringable'],
        'ReflectionProperty' => ['Reflector', 'Stringable'],
        'ReflectionType' => ['Stringable'],
        'ReflectionUnionType' => ['Stringable'],
        'ReflectionZendExtension' => ['Reflector', 'Stringable'],
        'Reflector' => ['Stringable'],
        'RegexIterator' => ['Iterator', 'OuterIterator', 'Traversable'],
        'RequestParseBodyException' => ['Stringable', 'Throwable'],
        'ResourceBundle' => ['Countable', 'IteratorAggregate', 'Traversable'],
        'RoundingMode' => ['UnitEnum'],
        'RuntimeException' => ['Stringable', 'Throwable'],
        'SeekableIterator' => ['Iterator', 'Traversable'],
        'SessionHandler' => ['SessionHandlerInterface', 'SessionIdInterface'],
        'SimpleXMLElement' => ['Countable', 'Iterator', 'RecursiveIterator', 'Stringable', 'Traversable'],
        'SimpleXMLIterator' => ['Countable', 'Iterator', 'RecursiveIterator', 'Stringable', 'Traversable'],
        'SNMPException' => ['Stringable', 'Throwable'],
        'SoapFault' => ['Stringable', 'Throwable'],
        'SodiumException' => ['Stringable', 'Throwable'],
        'SplDoublyLinkedList' => ['ArrayAccess', 'Countable', 'Iterator', 'Serializable', 'Traversable'],
        'SplFileInfo' => ['Stringable'],
        'SplFileObject' => ['Iterator', 'RecursiveIterator', 'SeekableIterator', 'Stringable', 'Traversable'],
        'SplFixedArray' => ['ArrayAccess', 'Countable', 'IteratorAggregate', 'JsonSerializable', 'Traversable'],
        'SplHeap' => ['Countable', 'Iterator', 'Traversable'],
        'SplMaxHeap' => ['Countable', 'Iterator', 'Traversable'],
        'SplMinHeap' => ['Countable', 'Iterator', 'Traversable'],
        'SplObjectStorage' => ['ArrayAccess', 'Countable', 'Iterator', 'SeekableIterator', 'Serializable', 'Traversable'],
        'SplPriorityQueue' => ['Countable', 'Iterator', 'Traversable'],
        'SplQueue' => ['ArrayAccess', 'Countable', 'Iterator', 'Serializable', 'Traversable'],
        'SplStack' => ['ArrayAccess', 'Countable', 'Iterator', 'Serializable', 'Traversable'],
        'SplTempFileObject' => ['Iterator', 'RecursiveIterator', 'SeekableIterator', 'Stringable', 'Traversable'],
        'SQLite3Exception' => ['Stringable', 'Throwable'],
        'Throwable' => ['Stringable'],
        'TypeError' => ['Stringable', 'Throwable'],
        'UnderflowException' => ['Stringable', 'Throwable'],
        'UnexpectedValueException' => ['Stringable', 'Throwable'],
        'UnhandledMatchError' => ['Stringable', 'Throwable'],
        'Uri\\InvalidUriException' => ['Stringable', 'Throwable'],
        'Uri\\UriComparisonMode' => ['UnitEnum'],
        'Uri\\UriError' => ['Stringable', 'Throwable'],
        'Uri\\UriException' => ['Stringable', 'Throwable'],
        'Uri\\WhatWg\\InvalidUrlException' => ['Stringable', 'Throwable'],
        'Uri\\WhatWg\\UrlValidationErrorType' => ['UnitEnum'],
        'ValueError' => ['Stringable', 'Throwable'],
        'WeakMap' => ['ArrayAccess', 'Countable', 'IteratorAggregate', 'Traversable'],
        'ZipArchive' => ['Countable'],
    ];

    /**
     * Registered name => the attributes on its own declaration, sorted.
     *
     * @var array<string, list<string>>
     */
    private const array ATTRIBUTES = [
        '__PHP_Incomplete_Class' => ['AllowDynamicProperties'],
        'AllowDynamicProperties' => ['Attribute'],
        'Attribute' => ['Attribute'],
        'DelayedTargetValidation' => ['Attribute'],
        'Deprecated' => ['Attribute'],
        'NoDiscard' => ['Attribute'],
        'Override' => ['Attribute'],
        'ReturnTypeWillChange' => ['Attribute'],
        'SensitiveParameter' => ['Attribute'],
        'stdClass' => ['AllowDynamicProperties'],
    ];

    /**
     * One step up the chain `extends` follows: the parent class of a class or
     * enum, or every interface an interface extends. Null when PHP does not
     * declare the name.
     *
     * @return list<string>|null
     */
    public static function extendsOf(string $fqn): ?array
    {
        if (!PhpBuiltinClassRegistry::isBuiltin($fqn)) {
            return null;
        }

        if (isset(self::INTERFACE_NAMES[$fqn])) {
            return self::INTERFACES[$fqn] ?? [];
        }

        return isset(self::PARENTS[$fqn]) ? [self::PARENTS[$fqn]] : [];
    }

    /**
     * Every interface the name implements or extends, transitively. Null when
     * PHP does not declare the name.
     *
     * @return list<string>|null
     */
    public static function interfacesOf(string $fqn): ?array
    {
        return PhpBuiltinClassRegistry::isBuiltin($fqn) ? self::INTERFACES[$fqn] ?? [] : null;
    }

    /**
     * The attributes on PHP's own declaration of the name. Null when PHP does
     * not declare the name.
     *
     * @return list<string>|null
     */
    public static function attributesOf(string $fqn): ?array
    {
        return PhpBuiltinClassRegistry::isBuiltin($fqn) ? self::ATTRIBUTES[$fqn] ?? [] : null;
    }
}
