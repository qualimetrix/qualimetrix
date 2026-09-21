<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SymbolVocabulary;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;
use ReflectionClass;
use ReflectionExtension;

/**
 * The registry is hand-written on purpose, and until this control nothing
 * compared it to a PHP.
 *
 * {@see PhpBuiltinClassRegistry} answers one question for the whole product --
 * is this name PHP's, or the analysed project's -- and four call sites act on
 * the answer. `ExternalAncestry` and the two inheritance-depth participants
 * stop a chain at a builtin; `DependencyGraphBuilder` keeps an `Extends` edge
 * to one and drops every other edge that reaches it. So a missing name is read
 * as the project's own class twice over: a chain that should have ended keeps
 * walking into nothing, and an edge that is not the project's coupling stays
 * in the graph.
 *
 * The list stays hand-written because the metric must not become a function of
 * the analysing machine -- a runner without `intl` would otherwise report a
 * different DIT for the same source. This control therefore compares and
 * refuses; it never writes the list.
 *
 * Both directions have caught a real defect on this tree: 59 names were
 * missing, `SessionHandler` among them, and `Pcntl\QueuedSignalInfo` was
 * listed while existing in no branch of php-src.
 *
 * ## Why the attribution lives here and not in the registry
 *
 * `isBuiltin()` must not know which extension ships a name or which PHP added
 * it. A class that is builtin in 8.5 is still builtin when 8.5 source is
 * analysed on an 8.4 runtime, so a version gate inside the product would make
 * it answer wrongly. The same fact is legitimate here, because here it means
 * something narrower: what this environment is allowed to demand. An int cell
 * is therefore **checked-from, not added-in** -- each one names a version where
 * a witness loaded the extension and the name was absent. A cell written too
 * high silently checks less, which is why {@see self::REQUIRED_EXTENSIONS} and
 * the per-extension set equality below do not depend on the cells.
 *
 * ## Scope
 *
 * php-src's bundled extensions on Unix builds. `com_dotnet` is out: it is
 * Windows-only and registers its classes in C rather than in a stub, so no
 * witness reachable from here can enumerate it. PECL extensions are out and
 * are excused by name in {@see self::UNLISTED_EXTENSIONS} -- the bundled/PECL
 * line is a fact about php-src's `ext/` directory, not about the runtime, and
 * no runtime predicate decides it: measured on PHP 8.5.9, comparing
 * `ReflectionExtension::getVersion()` with `PHP_VERSION` calls `zip` and `dom`
 * PECL and would drop `ZipArchive` from the scope.
 */
final class PhpBuiltinClassRegistryCensusTest extends TestCase
{
    /**
     * Extensions without which this control is not evidence.
     *
     * Every one declares classes on a stock PHP build, so a run that cannot
     * see them has not found no gap -- it has looked at almost nothing, and a
     * sweep that returned zero would read as agreement. Derived as: the
     * extensions declaring at least one class on a stock `php:8.4-cli`, plus
     * `filter`, which `composer.json` requires and which declares classes from
     * 8.5 on.
     *
     * @var list<string>
     */
    private const array REQUIRED_EXTENSIONS = [
        'Core', 'curl', 'date', 'dom', 'fileinfo', 'filter', 'hash', 'json', 'libxml', 'openssl', 'PDO',
        'pdo_sqlite', 'Phar', 'random', 'Reflection', 'session', 'SimpleXML', 'sodium', 'SPL', 'sqlite3',
        'standard', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zlib',
    ];

    /**
     * php-src's `ext/` directories on the supported branches, lowercased, plus
     * `core` for the engine's own classes, which has no `ext/` directory.
     *
     * This is the scope oracle. A loaded extension in neither this list nor
     * {@see self::UNLISTED_EXTENSIONS} is refused rather than assumed PECL, so
     * an extension bundled by a future PHP forces the table to grow instead of
     * passing unseen. `uri` arrived exactly that way in 8.5.
     *
     * `skeleton`, `dl_test` and `zend_test` are omitted: php-src carries them
     * but ships none of them.
     *
     * @var list<string>
     */
    private const array BUNDLED_EXTENSIONS = [
        'bcmath', 'bz2', 'calendar', 'com_dotnet', 'core', 'ctype', 'curl', 'date', 'dba', 'dom', 'enchant',
        'exif', 'ffi', 'fileinfo', 'filter', 'ftp', 'gd', 'gettext', 'gmp', 'hash', 'iconv', 'intl', 'json',
        'ldap', 'lexbor', 'libxml', 'mbstring', 'mysqli', 'mysqlnd', 'odbc', 'opcache', 'openssl', 'pcntl',
        'pcre', 'pdo', 'pdo_dblib', 'pdo_firebird', 'pdo_mysql', 'pdo_odbc', 'pdo_pgsql', 'pdo_sqlite',
        'pgsql', 'phar', 'posix', 'random', 'readline', 'reflection', 'session', 'shmop', 'simplexml',
        'snmp', 'soap', 'sockets', 'sodium', 'spl', 'sqlite3', 'standard', 'sysvmsg', 'sysvsem', 'sysvshm',
        'tidy', 'tokenizer', 'uri', 'xml', 'xmlreader', 'xmlwriter', 'xsl', 'zip', 'zlib',
    ];

    /**
     * Loaded extensions whose classes the registry deliberately omits, each
     * with the reason it is out of scope.
     *
     * Seeded only with extensions a witness actually loaded. A contributor
     * running something else -- `ext-parallel`, which this repository's own
     * `suggest` block names, or redis, imagick, mongodb -- gets one red and
     * cures it with one row. That is the intended cost: the alternative is
     * assuming every unknown extension is PECL, which is the assumption that
     * would have hidden `uri`.
     *
     * @var array<string, string>
     */
    private const array UNLISTED_EXTENSIONS = [
        'apcu' => 'PECL; not shipped with PHP',
        'amqp' => 'PECL; preinstalled on the GitHub-hosted Ubuntu runner',
        'ast' => 'PECL; preinstalled on the GitHub-hosted Ubuntu runner',
        'ds' => 'PECL; preinstalled on the GitHub-hosted Ubuntu runner',
        'igbinary' => 'PECL; not shipped with PHP',
        'imagick' => 'PECL; preinstalled on the GitHub-hosted Ubuntu runner',
        'imap' => 'Bundled through 8.3, moved to PECL in 8.4; out of scope on every supported version',
        'memcache' => 'PECL; preinstalled on the GitHub-hosted Ubuntu runner',
        'memcached' => 'PECL; preinstalled on the GitHub-hosted Ubuntu runner',
        'mongodb' => 'PECL; preinstalled on the GitHub-hosted Ubuntu runner',
        'msgpack' => 'PECL; preinstalled on the GitHub-hosted Ubuntu runner',
        'redis' => 'PECL; preinstalled on the GitHub-hosted Ubuntu runner',
        'xdebug' => 'PECL; not shipped with PHP',
        'zmq' => 'PECL; preinstalled on the GitHub-hosted Ubuntu runner',
    ];

    /**
     * Spellings the registry carries so that source written against them
     * resolves, and that no extension declares as a canonical name. PHP
     * registers this one in its alias table, lowercased, as `dom\\domexception`.
     *
     * @var array<string, true>
     */
    private const array ALIAS_SPELLINGS = ['Dom\\DOMException' => true];

    /**
     * The fewest names a supported environment compares, so that a run which
     * quietly compared less is refused rather than read as agreement.
     *
     * Measured on a stock `php:8.4-cli` (34 extensions), the leanest
     * environment this repository supports: 240 of the registered names.
     * Deriving again only ever raises it; lowering it is a hand edit that says
     * the registry now covers less.
     */
    private const int COMPARED_NAMES_FLOOR = 240;

    /**
     * Extension => name => `true`, or the PHP_VERSION_ID from which this
     * environment may demand the name. See the class docblock: checked-from,
     * not added-in.
     *
     * @var array<string, array<string, true|int>>
     */
    private const array ATTRIBUTION = [
        'bcmath' => [
            'BcMath\\Number' => true,
        ],
        'Core' => [
            'AllowDynamicProperties' => true, 'ArgumentCountError' => true, 'ArithmeticError' => true,
            'ArrayAccess' => true, 'Attribute' => true, 'BackedEnum' => true, 'ClosedGeneratorException' => true,
            'Closure' => true, 'CompileError' => true, 'Countable' => true, 'DelayedTargetValidation' => 80500,
            'Deprecated' => true, 'DivisionByZeroError' => true, 'Error' => true, 'ErrorException' => true,
            'Exception' => true, 'Fiber' => true, 'FiberError' => true, 'Generator' => true,
            'InternalIterator' => true, 'Iterator' => true, 'IteratorAggregate' => true, 'NoDiscard' => 80500,
            'Override' => true, 'ParseError' => true, 'RequestParseBodyException' => true,
            'ReturnTypeWillChange' => true, 'SensitiveParameter' => true, 'SensitiveParameterValue' => true,
            'Serializable' => true, 'stdClass' => true, 'Stringable' => true, 'Throwable' => true,
            'Traversable' => true, 'TypeError' => true, 'UnhandledMatchError' => true, 'UnitEnum' => true,
            'ValueError' => true, 'WeakMap' => true, 'WeakReference' => true,
        ],
        'curl' => [
            'CURLFile' => true, 'CurlHandle' => true, 'CurlMultiHandle' => true, 'CurlShareHandle' => true,
            'CurlSharePersistentHandle' => 80500, 'CURLStringFile' => true,
        ],
        'date' => [
            'DateError' => true, 'DateException' => true, 'DateInterval' => true,
            'DateInvalidOperationException' => true, 'DateInvalidTimeZoneException' => true,
            'DateMalformedIntervalStringException' => true, 'DateMalformedPeriodStringException' => true,
            'DateMalformedStringException' => true, 'DateObjectError' => true, 'DatePeriod' => true,
            'DateRangeError' => true, 'DateTime' => true, 'DateTimeImmutable' => true, 'DateTimeInterface' => true,
            'DateTimeZone' => true,
        ],
        'dba' => [
            'Dba\\Connection' => true,
        ],
        'dom' => [
            'DOMAttr' => true, 'DOMCdataSection' => true, 'DOMCharacterData' => true, 'DOMChildNode' => true,
            'DOMComment' => true, 'DOMDocument' => true, 'DOMDocumentFragment' => true, 'DOMDocumentType' => true,
            'DOMElement' => true, 'DOMEntity' => true, 'DOMEntityReference' => true, 'DOMException' => true,
            'DOMImplementation' => true, 'DOMNamedNodeMap' => true, 'DOMNameSpaceNode' => true, 'DOMNode' => true,
            'DOMNodeList' => true, 'DOMNotation' => true, 'DOMParentNode' => true,
            'DOMProcessingInstruction' => true, 'DOMText' => true, 'DOMXPath' => true,
            'Dom\\AdjacentPosition' => true, 'Dom\\Attr' => true, 'Dom\\CDATASection' => true,
            'Dom\\CharacterData' => true, 'Dom\\ChildNode' => true, 'Dom\\Comment' => true, 'Dom\\Document' => true,
            'Dom\\DocumentFragment' => true, 'Dom\\DocumentType' => true, 'Dom\\DOMException' => true,
            'Dom\\DtdNamedNodeMap' => true, 'Dom\\Element' => true, 'Dom\\Entity' => true,
            'Dom\\EntityReference' => true, 'Dom\\HTMLCollection' => true, 'Dom\\HTMLDocument' => true,
            'Dom\\HTMLElement' => true, 'Dom\\Implementation' => true, 'Dom\\NamedNodeMap' => true,
            'Dom\\NamespaceInfo' => true, 'Dom\\Node' => true, 'Dom\\NodeList' => true, 'Dom\\Notation' => true,
            'Dom\\ParentNode' => true, 'Dom\\ProcessingInstruction' => true, 'Dom\\Text' => true,
            'Dom\\TokenList' => true, 'Dom\\XMLDocument' => true, 'Dom\\XPath' => true,
        ],
        'enchant' => [
            'EnchantBroker' => true, 'EnchantDictionary' => true,
        ],
        'FFI' => [
            'FFI' => true, 'FFI\\CData' => true, 'FFI\\CType' => true, 'FFI\\Exception' => true,
            'FFI\\ParserException' => true,
        ],
        'fileinfo' => [
            'finfo' => true,
        ],
        'filter' => [
            'Filter\\FilterException' => 80500, 'Filter\\FilterFailedException' => 80500,
        ],
        'ftp' => [
            'FTP\\Connection' => true,
        ],
        'gd' => [
            'GdFont' => true, 'GdImage' => true,
        ],
        'gmp' => [
            'GMP' => true,
        ],
        'hash' => [
            'HashContext' => true,
        ],
        'intl' => [
            'Collator' => true, 'IntlBreakIterator' => true, 'IntlCalendar' => true, 'IntlChar' => true,
            'IntlCodePointBreakIterator' => true, 'IntlDateFormatter' => true, 'IntlDatePatternGenerator' => true,
            'IntlException' => true, 'IntlGregorianCalendar' => true, 'IntlIterator' => true,
            'IntlListFormatter' => 80500, 'IntlPartsIterator' => true, 'IntlRuleBasedBreakIterator' => true,
            'IntlTimeZone' => true, 'Locale' => true, 'MessageFormatter' => true, 'Normalizer' => true,
            'NumberFormatter' => true, 'ResourceBundle' => true, 'Spoofchecker' => true, 'Transliterator' => true,
            'UConverter' => true,
        ],
        'json' => [
            'JsonException' => true, 'JsonSerializable' => true,
        ],
        'ldap' => [
            'LDAP\\Connection' => true, 'LDAP\\Result' => true, 'LDAP\\ResultEntry' => true,
        ],
        'libxml' => [
            'LibXMLError' => true,
        ],
        'mysqli' => [
            'mysqli' => true, 'mysqli_driver' => true, 'mysqli_result' => true, 'mysqli_sql_exception' => true,
            'mysqli_stmt' => true, 'mysqli_warning' => true,
        ],
        'odbc' => [
            'Odbc\\Connection' => true, 'Odbc\\Result' => true,
        ],
        'openssl' => [
            'OpenSSLAsymmetricKey' => true, 'OpenSSLCertificate' => true,
            'OpenSSLCertificateSigningRequest' => true,
        ],
        'pcntl' => [
            'Pcntl\\QosClass' => true,
        ],
        'PDO' => [
            'PDO' => true, 'PDOException' => true, 'PDORow' => true, 'PDOStatement' => true,
        ],
        'pdo_dblib' => [
            'Pdo\\Dblib' => true,
        ],
        'pdo_firebird' => [
            'Pdo\\Firebird' => true,
        ],
        'pdo_mysql' => [
            'Pdo\\Mysql' => true,
        ],
        'PDO_ODBC' => [
            'Pdo\\Odbc' => true,
        ],
        'pdo_pgsql' => [
            'Pdo\\Pgsql' => true,
        ],
        'pdo_sqlite' => [
            'Pdo\\Sqlite' => true,
        ],
        'pgsql' => [
            'PgSql\\Connection' => true, 'PgSql\\Lob' => true, 'PgSql\\Result' => true,
        ],
        'Phar' => [
            'Phar' => true, 'PharData' => true, 'PharException' => true, 'PharFileInfo' => true,
        ],
        'random' => [
            'Random\\BrokenRandomEngineError' => true, 'Random\\CryptoSafeEngine' => true, 'Random\\Engine' => true,
            'Random\\Engine\\Mt19937' => true, 'Random\\Engine\\PcgOneseq128XslRr64' => true,
            'Random\\Engine\\Secure' => true, 'Random\\Engine\\Xoshiro256StarStar' => true,
            'Random\\IntervalBoundary' => true, 'Random\\RandomError' => true, 'Random\\RandomException' => true,
            'Random\\Randomizer' => true,
        ],
        'Reflection' => [
            'PropertyHookType' => true, 'Reflection' => true, 'ReflectionAttribute' => true,
            'ReflectionClass' => true, 'ReflectionClassConstant' => true, 'ReflectionConstant' => true,
            'ReflectionEnum' => true, 'ReflectionEnumBackedCase' => true, 'ReflectionEnumUnitCase' => true,
            'ReflectionException' => true, 'ReflectionExtension' => true, 'ReflectionFiber' => true,
            'ReflectionFunction' => true, 'ReflectionFunctionAbstract' => true, 'ReflectionGenerator' => true,
            'ReflectionIntersectionType' => true, 'ReflectionMethod' => true, 'ReflectionNamedType' => true,
            'ReflectionObject' => true, 'ReflectionParameter' => true, 'ReflectionProperty' => true,
            'ReflectionReference' => true, 'ReflectionType' => true, 'ReflectionUnionType' => true,
            'ReflectionZendExtension' => true, 'Reflector' => true,
        ],
        'session' => [
            'SessionHandler' => true, 'SessionHandlerInterface' => true, 'SessionIdInterface' => true,
            'SessionUpdateTimestampHandlerInterface' => true,
        ],
        'shmop' => [
            'Shmop' => true,
        ],
        'SimpleXML' => [
            'SimpleXMLElement' => true, 'SimpleXMLIterator' => true,
        ],
        'snmp' => [
            'SNMP' => true, 'SNMPException' => true,
        ],
        'soap' => [
            'SoapClient' => true, 'SoapFault' => true, 'SoapHeader' => true, 'SoapParam' => true,
            'SoapServer' => true, 'SoapVar' => true, 'Soap\\Sdl' => true, 'Soap\\Url' => true,
        ],
        'sockets' => [
            'AddressInfo' => true, 'Socket' => true,
        ],
        'sodium' => [
            'SodiumException' => true,
        ],
        'SPL' => [
            'AppendIterator' => true, 'ArrayIterator' => true, 'ArrayObject' => true,
            'BadFunctionCallException' => true, 'BadMethodCallException' => true, 'CachingIterator' => true,
            'CallbackFilterIterator' => true, 'DirectoryIterator' => true, 'DomainException' => true,
            'EmptyIterator' => true, 'FilesystemIterator' => true, 'FilterIterator' => true, 'GlobIterator' => true,
            'InfiniteIterator' => true, 'InvalidArgumentException' => true, 'IteratorIterator' => true,
            'LengthException' => true, 'LimitIterator' => true, 'LogicException' => true,
            'MultipleIterator' => true, 'NoRewindIterator' => true, 'OuterIterator' => true,
            'OutOfBoundsException' => true, 'OutOfRangeException' => true, 'OverflowException' => true,
            'ParentIterator' => true, 'RangeException' => true, 'RecursiveArrayIterator' => true,
            'RecursiveCachingIterator' => true, 'RecursiveCallbackFilterIterator' => true,
            'RecursiveDirectoryIterator' => true, 'RecursiveFilterIterator' => true, 'RecursiveIterator' => true,
            'RecursiveIteratorIterator' => true, 'RecursiveRegexIterator' => true, 'RecursiveTreeIterator' => true,
            'RegexIterator' => true, 'RuntimeException' => true, 'SeekableIterator' => true,
            'SplDoublyLinkedList' => true, 'SplFileInfo' => true, 'SplFileObject' => true, 'SplFixedArray' => true,
            'SplHeap' => true, 'SplMaxHeap' => true, 'SplMinHeap' => true, 'SplObjectStorage' => true,
            'SplObserver' => true, 'SplPriorityQueue' => true, 'SplQueue' => true, 'SplStack' => true,
            'SplSubject' => true, 'SplTempFileObject' => true, 'UnderflowException' => true,
            'UnexpectedValueException' => true,
        ],
        'sqlite3' => [
            'SQLite3' => true, 'SQLite3Exception' => true, 'SQLite3Result' => true, 'SQLite3Stmt' => true,
        ],
        'standard' => [
            'AssertionError' => true, 'Directory' => true, 'php_user_filter' => true, 'RoundingMode' => true,
            'StreamBucket' => true, '__PHP_Incomplete_Class' => true,
        ],
        'sysvmsg' => [
            'SysvMessageQueue' => true,
        ],
        'sysvsem' => [
            'SysvSemaphore' => true,
        ],
        'sysvshm' => [
            'SysvSharedMemory' => true,
        ],
        'tidy' => [
            'tidy' => true, 'tidyNode' => true,
        ],
        'tokenizer' => [
            'PhpToken' => true,
        ],
        'uri' => [
            'Uri\\InvalidUriException' => true, 'Uri\\Rfc3986\\Uri' => true, 'Uri\\UriComparisonMode' => true,
            'Uri\\UriError' => true, 'Uri\\UriException' => true, 'Uri\\WhatWg\\InvalidUrlException' => true,
            'Uri\\WhatWg\\Url' => true, 'Uri\\WhatWg\\UrlValidationError' => true,
            'Uri\\WhatWg\\UrlValidationErrorType' => true,
        ],
        'xml' => [
            'XMLParser' => true,
        ],
        'xmlreader' => [
            'XMLReader' => true,
        ],
        'xmlwriter' => [
            'XMLWriter' => true,
        ],
        'xsl' => [
            'XSLTProcessor' => true,
        ],
        'zip' => [
            'ZipArchive' => true,
        ],
        'zlib' => [
            'DeflateContext' => true, 'InflateContext' => true,
        ],
    ];

    #[Test]
    public function itRefusesAnEnvironmentTooBareToJudgeTheRegistry(): void
    {
        $absent = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn(string $extension): bool => !\extension_loaded($extension),
        ));

        self::assertSame([], $absent, \sprintf(
            'This PHP cannot judge the registry: %s not loaded. Every other assertion here would '
                . 'pass by having nothing to compare.',
            implode(', ', $absent),
        ));
    }

    #[Test]
    public function itAttributesEveryRegisteredNameToExactlyOneExtension(): void
    {
        $attributed = [];
        $twice = [];
        foreach (self::ATTRIBUTION as $extension => $names) {
            foreach (array_keys($names) as $name) {
                if (isset($attributed[$name])) {
                    $twice[] = \sprintf('%s (%s and %s)', $name, $attributed[$name], $extension);
                }
                $attributed[$name] = $extension;
            }
        }

        self::assertSame([], $twice, 'A name attributed twice makes its extension cell unreadable.');

        // Both directions in one assertion: asserted one after the other, the
        // first failure aborts and the second gap never reaches the report --
        // which is how a single stale entry would mask fifty-nine missing ones.
        $registered = self::registeredNames();
        $divergence = [];
        foreach (array_diff($registered, array_keys($attributed)) as $name) {
            $divergence[] = 'registered, unattributed: ' . $name;
        }
        foreach (array_diff(array_keys($attributed), $registered) as $name) {
            $divergence[] = 'attributed, unregistered: ' . $name;
        }
        sort($divergence);

        self::assertSame([], $divergence, \sprintf(
            "The registry and this control's attribution disagree, so neither constrains the other:\n  %s",
            implode("\n  ", $divergence),
        ));
    }

    /**
     * The load-bearing one: per extension, exact set equality.
     *
     * Comparing each extension's declarations against `ATTRIBUTION[$extension]`
     * rather than against the flattened registry is what makes the attribution
     * itself measured. Against the flat list, a name filed under the wrong
     * extension still passes -- and that wrong row silently exempts the name
     * from the existence check below, because its supposed owner is not loaded.
     */
    #[Test]
    public function itAgreesWithEveryLoadedExtensionOnWhatItDeclares(): void
    {
        $compared = 0;
        $divergent = [];

        foreach (self::ATTRIBUTION as $extension => $names) {
            if (!\extension_loaded($extension)) {
                continue;
            }

            $declared = self::canonicalClassNames($extension);
            $claimed = array_keys(array_filter(
                $names,
                static fn(bool|int $from): bool => $from === true || \PHP_VERSION_ID >= $from,
            ));

            // Alias spellings are registered so that source written against them
            // resolves, but no extension declares them as canonical names, so
            // they are not demanded of the extension -- only of PHP, below.
            $claimedCanonical = array_values(array_filter(
                $claimed,
                static fn(string $name): bool => !isset(self::ALIAS_SPELLINGS[$name]),
            ));

            $missing = array_values(array_diff($declared, $claimedCanonical));
            $unknown = array_values(array_diff($claimedCanonical, $declared));

            if ($missing !== [] || $unknown !== []) {
                $divergent[$extension] = \sprintf(
                    'declares but the registry omits: [%s]; registry claims but it does not declare: [%s]',
                    implode(', ', $missing),
                    implode(', ', $unknown),
                );
            }

            $compared += \count($claimedCanonical);
        }

        self::assertSame([], $divergent, "The registry and this PHP disagree:\n" . print_r($divergent, true));

        // Guards the one direction the cells can weaken silently: a `from`
        // written too high, or an extension key misspelt so that
        // `extension_loaded()` is false forever, both show up as a smaller
        // comparison rather than as a failure.
        self::assertGreaterThanOrEqual(self::COMPARED_NAMES_FLOOR, $compared, \sprintf(
            'Only %d names were compared, below the %d this environment must reach. A run that compares '
                . 'less is not a run that found agreement.',
            $compared,
            self::COMPARED_NAMES_FLOOR,
        ));
    }

    #[Test]
    public function itRefusesAnExtensionNoDispositionCovers(): void
    {
        $disposed = array_change_key_case(self::ATTRIBUTION) + array_change_key_case(self::UNLISTED_EXTENSIONS);

        $undisposed = [];
        foreach (get_loaded_extensions() as $extension) {
            if (self::canonicalClassNames($extension) === []) {
                continue;
            }
            if (isset($disposed[strtolower($extension)])) {
                continue;
            }
            $undisposed[] = $extension;
        }

        self::assertSame([], $undisposed, \sprintf(
            'Loaded, declares classes, and neither attributed nor excused: %s. Assuming an unknown '
                . 'extension is PECL is the assumption that would have let `uri` through in 8.5.',
            implode(', ', $undisposed),
        ));
    }

    #[Test]
    public function itKeepsEveryDispositionInsideTheDeclaredScope(): void
    {
        $bundled = array_flip(self::BUNDLED_EXTENSIONS);
        $notBundled = [];
        foreach (array_keys(self::ATTRIBUTION) as $extension) {
            $key = $extension === 'Zend OPcache' ? 'opcache' : strtolower($extension);
            if (!isset($bundled[$key])) {
                $notBundled[] = $extension;
            }
        }

        self::assertSame([], $notBundled, \sprintf(
            'Attributed to an extension php-src does not bundle: %s. The registry covers bundled '
                . 'extensions only, so either the roster is stale or the scope moved without saying so.',
            implode(', ', $notBundled),
        ));

        $contradictory = array_values(array_intersect(
            array_keys(self::UNLISTED_EXTENSIONS),
            array_keys(self::ATTRIBUTION),
        ));

        self::assertSame([], $contradictory, \sprintf(
            'Both excused and attributed: %s.',
            implode(', ', $contradictory),
        ));
    }

    /**
     * The registry's answer, not the table's: every name it claims must be a
     * name this PHP knows, whenever this PHP is in a position to say.
     *
     * This is the direction that catches a name that was never real.
     */
    #[Test]
    public function itRegistersNoNameThisPhpDenies(): void
    {
        $absent = [];
        foreach (self::ATTRIBUTION as $extension => $names) {
            if (!\extension_loaded($extension)) {
                continue;
            }
            foreach ($names as $name => $from) {
                if ($from !== true && \PHP_VERSION_ID < $from) {
                    continue;
                }
                if (class_exists($name, false) || interface_exists($name, false) || enum_exists($name, false)) {
                    continue;
                }
                $absent[] = \sprintf('%s (%s)', $name, $extension);
            }
        }

        self::assertSame([], $absent, \sprintf(
            "Registered, its extension is loaded, and PHP does not know it:\n  %s",
            implode("\n  ", $absent),
        ));
    }

    /**
     * @return list<string>
     */
    private static function registeredNames(): array
    {
        $classes = (new ReflectionClass(PhpBuiltinClassRegistry::class))->getConstant('BUILTIN_CLASSES');
        self::assertIsArray($classes);

        /** @var list<string> $names */
        $names = array_keys($classes);
        sort($names);

        return $names;
    }

    /**
     * Canonical declarations only: `getClassNames()` also returns alias-table
     * keys, lowercased, and comparing those to the registry's exact spellings
     * would report a gap that is not one -- `dom\domexception` against the
     * registered `DOMException`.
     *
     * @return list<string>
     */
    private static function canonicalClassNames(string $extension): array
    {
        if (!\extension_loaded($extension)) {
            return [];
        }

        $canonical = [];
        foreach ((new ReflectionExtension($extension))->getClassNames() as $spelling) {
            $canonical[(new ReflectionClass($spelling))->getName()] = true;
        }

        $names = array_keys($canonical);
        sort($names);

        return $names;
    }
}
