<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RuleOptionKeys;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Tests\Analysis\Finding\Support\FromArrayKeyReader;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Every unguarded literal read must belong to its owning declaration.
 *
 * Root Options may also read framework keys; level Options have only their
 * own surface. Declarations and AST reads remain independent populations.
 * Branch-only reads and unresolved dynamic keys are outside this subset;
 * declared-but-unread keys are not refused because dynamic reads can be honest.
 * Foreign opaque handoffs, spread and iteration are refused. Level delegation
 * is covered by walking each declared level class separately.
 */
#[CoversClass(RuleOptionKeySet::class)]
final class DeclaredOptionKeysCoverReadKeysTest extends TestCase
{
    /**
     * @param class-string<LevelOptionsInterface|RuleOptionsInterface> $optionsClass
     */
    #[Test]
    #[DataProvider('provideDeclaringClasses')]
    public function itDeclaresEveryKeyItReadsOutsideABranch(string $optionsClass): void
    {
        $reader = new FromArrayKeyReader();
        $reading = $reader->read($optionsClass);

        $undeclared = [];
        foreach (array_keys(array_filter($reading->keys)) as $key) {
            if (!\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface::declaredFor($optionsClass)->knows(ConfigKeySpelling::normalize($key))
                && !(is_a($optionsClass, RuleOptionsInterface::class, true) && \Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys::declared()->knows($key))) {
                $undeclared[] = $key;
            }
        }

        self::assertSame([], $undeclared, self::wording($optionsClass, $undeclared, $reading->unresolvedDetail));

        $escaped = [];
        foreach (['opaque-sink', 'spread', 'iteration'] as $kind) {
            if (($reading->unresolved[$kind] ?? 0) > 0) {
                $escaped[] = $kind;
            }
        }

        self::assertSame(
            [],
            $escaped,
            $optionsClass . ' hands its config somewhere the reader cannot follow (' . implode(', ', $escaped)
            . '), so reads past that point are outside the invariant entirely — a key read there and never'
            . " declared is exactly the defect this guard exists to catch, and it would stay green.
"
            . 'Only two blind-spot kinds are tolerated, because each is covered by something else:'
            . " nested-delegation (the level class is walked on its own) and dynamic-key (declared ⊇ read, not
"
            . 'equality). Read the config keys in this class, or extend FromArrayKeyReader to follow the handoff.'
            . "
Sites: " . implode('; ', $reading->unresolvedDetail),
        );
    }

    /**
     * The reader resolves a class to a file and then takes the *first* class
     * node in it, the requested name playing no part. That is silent only
     * while every class of this population is alone in its file.
     */
    #[Test]
    public function itReadsOnlyFilesHoldingASingleClass(): void
    {
        $reader = new FromArrayKeyReader();

        $shared = [];
        foreach (self::declaringClasses() as $optionsClass) {
            $declarations = $reader->classDeclarationsInFileOf($optionsClass);
            if ($declarations > 1) {
                $shared[] = $optionsClass . ' (' . $declarations . ' class declarations in its file)';
            }
        }

        self::assertSame(
            0,
            \count($shared),
            "The key reader takes the first class in a file and ignores the name it was asked for, so a\n"
            . "second declaration in one of these files is read as if it were the first — with no blind spot\n"
            . "raised and the wrong declaration held to the wrong reads. Split the file, or teach\n"
            . "FromArrayKeyReader::classNode() to select by name:\n  " . implode("\n  ", $shared),
        );
    }

    /**
     * The two interfaces the key set replaced must stay dead: a reintroduction
     * splits the declaration back into places the guard does not read.
     */
    #[Test]
    public function itKeepsTheReplacedDeclarationInterfacesDead(): void
    {
        $retired = [
            'Qualimetrix\Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface',
            'Qualimetrix\Analysis\Finding\Contract\Rule\AdditionalOptionKeysInterface',
        ];

        foreach ($retired as $interface) {
            self::assertFalse(
                interface_exists($interface),
                $interface . ' is back. Option keys are declared in one place, acceptedOptionKeys(); a second'
                . ' declaration surface is a second thing for the guard to miss.',
            );
        }
    }

    /**
     * The population enumerated for the guard must be the population the
     * product actually configures, and it must not be able to shrink silently:
     * the provider walks `src/`, the registry answers from the container, and
     * this asserts the second is inside the first.
     */
    #[Test]
    public function itCoversEveryOptionsClassTheRuleRegistryUses(): void
    {
        $walked = self::declaringClasses();
        self::assertNotEmpty($walked, 'The source walk found no options classes at all; the guard would pass vacuously.');

        $registry = (new ContainerFactory())->create()->get(RuleRegistryInterface::class);
        self::assertInstanceOf(RuleRegistryInterface::class, $registry);

        $registered = [];
        foreach ($registry->getClasses() as $ruleClass) {
            $registered[] = $ruleClass::getOptionsClass();
        }

        self::assertNotEmpty($registered);
        self::assertSame(
            [],
            array_values(array_diff(array_unique($registered), $walked)),
            'The registry configures an options class the source walk did not enumerate, so the guard is not'
            . ' holding it to anything.',
        );
    }

    /**
     * A control over the reader, not over the tree: two classes read by one
     * reader instance, the first binding a local variable to a literal key and
     * the second reading `$config[$key]` for a local of *the same name* it
     * never bound. The second must come back as a blind spot, not carrying the
     * first's literal — the name has to match, or the reader's per-name state
     * is never asked the question.
     *
     * Reading one class twice cannot fail this — a repeated read starts from
     * the same locals — so the two halves are asserted in that order.
     */
    #[Test]
    public function itDoesNotCarryOneClassesLocalsIntoTheNext(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $directory = sys_get_temp_dir() . '/qmx-from-array-key-reader-' . $suffix;
        mkdir($directory);

        try {
            $binder = self::writeFixture($directory, 'LocalBindingFixture' . $suffix, <<<'PHP'
                    public static function fromArray(array $config): void
                    {
                        $key = 'carried';
                        $value = $config[$key];
                    }
                PHP);
            $borrower = self::writeFixture($directory, 'UnboundLocalFixture' . $suffix, <<<'PHP'
                    public static function fromArray(array $config): void
                    {
                        $value = $config[$key];
                    }
                PHP);

            $reader = new FromArrayKeyReader();

            $bound = $reader->read($binder);
            self::assertSame(['carried' => true], $bound->keys, 'The local-variable resolution the control depends on is gone.');

            $borrowed = $reader->read($borrower);
            self::assertSame([], $borrowed->keys, 'A literal from the previously read class leaked into this one.');
            self::assertSame(1, $borrowed->unresolved['dynamic-key']);
        } finally {
            $written = glob($directory . '/*.php');
            foreach ($written === false ? [] : $written as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /**
     * @return iterable<string, array{class-string<LevelOptionsInterface|RuleOptionsInterface>}>
     */
    public static function provideDeclaringClasses(): iterable
    {
        foreach (self::declaringClasses() as $optionsClass) {
            yield $optionsClass => [$optionsClass];
        }
    }

    /**
     * Every options class under `src/`, plus the level classes a hierarchical
     * one delegates its slots to. The level classes are walked separately for
     * a measured reason: a wrapper handing `$config` to a level class is a
     * `nested-delegation` blind spot for the reader, so the only way those
     * keys are covered at all is by the child's own declaration being held to
     * the child's own reads.
     *
     * @return list<class-string<LevelOptionsInterface|RuleOptionsInterface>>
     */
    private static function declaringClasses(): array
    {
        $classes = [];

        foreach (self::optionsClassesUnderSource() as $optionsClass) {
            $classes[] = $optionsClass;

            if (is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true)) {
                foreach ($optionsClass::levelOptionsClasses() as $levelClass) {
                    $classes[] = $levelClass;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @return list<class-string<RuleOptionsInterface>>
     */
    private static function optionsClassesUnderSource(): array
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        $found = [];
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
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

            $reflection = new ReflectionClass($fqcn);
            if ($reflection->isAbstract() || !is_a($fqcn, RuleOptionsInterface::class, true)) {
                continue;
            }

            $found[] = $fqcn;
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * @param list<string> $undeclared
     * @param list<string> $blindSpots
     */
    private static function wording(string $optionsClass, array $undeclared, array $blindSpots): string
    {
        return \sprintf(
            "%s::fromResolved() reads %s outside any branch body, and its owning declarations do not answer for"
            . " %s. A key read but not declared is refused from the user's config and then applied anyway — the very"
            . " defect this declaration exists to close. Either declare the key or stop reading it.\n"
            . "What this guard does NOT see, so do not read its green as more than it is:\n"
            . "  - a key read only inside the body of an if/else;\n"
            . "  - a key the reader could not reduce to a literal — for this class: %s;\n"
            . "  - a declared key nothing reads: not asserted, because an honest dynamic read looks exactly like it.",
            $optionsClass,
            implode(', ', $undeclared),
            \count($undeclared) === 1 ? 'it' : 'them',
            $blindSpots === [] ? 'none' : implode('; ', $blindSpots),
        );
    }

    /**
     * @return class-string
     */
    private static function writeFixture(string $directory, string $name, string $body): string
    {
        $file = $directory . '/' . $name . '.php';
        file_put_contents($file, "<?php\n\nfinal class " . $name . "\n{\n" . $body . "\n}\n");
        require $file;

        /** @var class-string $name */
        return $name;
    }
}
