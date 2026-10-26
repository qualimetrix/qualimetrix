<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Discovery\RemovedNamespaceScan;
use Qualimetrix\Analysis\Run\Discovery\RemovedNamespaceText;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(RemovedNamespaceScan::class)]
#[CoversClass(RemovedNamespaceText::class)]
final class RemovedNamespaceScanTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-namespace-scan-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->directory), ['.', '..']) as $name) {
            $file = $this->directory . '/' . $name;
            chmod($file, 0o644);
            unlink($file);
        }
        rmdir($this->directory);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function provideDeclarations(): iterable
    {
        yield 'single namespace' => ["<?php\nnamespace A\\B;\nclass X {}", ['A\\B']];
        yield 'single segment' => ['<?php namespace Top; class X {}', ['Top']];
        yield 'several namespaces' => ["<?php\nnamespace A; class X {}\nnamespace B\\C; class Y {}", ['A', 'B\\C']];
        yield 'braced namespaces' => ['<?php namespace A { class X {} } namespace B\\C { class Y {} }', ['A', 'B\\C']];
        yield 'global block' => ['<?php namespace { class X {} }', ['']];
        yield 'global block beside a named one' => ['<?php namespace A { } namespace { }', ['A', '']];
        yield 'no namespace' => ['<?php class X {}', ['']];
        yield 'inline html only' => ['plain text', ['']];
        yield 'relative name is not a declaration' => ['<?php namespace\\foo(); class X {}', ['']];
        yield 'comments and strings' => [
            "<?php\n// namespace Hidden;\n/* namespace Hidden2; */\n\$s = 'namespace Hidden3;';\n\$h = <<<T\nnamespace Hidden4;\nT;\nclass X {}",
            [''],
        ];
        yield 'comment between keyword and name' => ['<?php namespace /* c */ A\\B; class X {}', ['A\\B']];
        yield 'repeated name is listed once' => ['<?php namespace A; namespace A;', ['A']];
        yield 'namespace as member name' => ['<?php $o->namespace = 1; X::namespace();', ['']];
        yield 'namespace as constant, case, method and argument name' => [
            '<?php class X { const NAMESPACE = 1; function namespace() {} } enum E { case Namespace; }'
            . ' echo X::NAMESPACE . X::NAMESPACE, f(namespace: 1); trait T {} class Y { use T { a as namespace; } }',
            [''],
        ];
        foreach (['Match', 'List', 'Fn', 'Readonly', 'Class', 'Default', 'Array', 'Print', 'Exit', 'Static', 'Abstract', 'Enum'] as $keyword) {
            yield 'keyword name ' . $keyword => ['<?php namespace ' . $keyword . '; class X {}', [$keyword]];
        }
        yield 'braced keyword name' => ['<?php namespace Match { class X {} }', ['Match']];
    }

    /** @return iterable<string, array{string}> */
    public static function provideUnrecognisedDeclarations(): iterable
    {
        yield 'fully qualified name' => ['<?php namespace \\Foo; class X {}'];
        yield 'no name' => ['<?php namespace; class X {}'];
        yield 'after a statement' => ['<?php declare(strict_types=1); namespace $x;'];
        yield 'at the end of the file' => ['<?php namespace'];
    }

    #[Test]
    #[DataProvider('provideUnrecognisedDeclarations')]
    public function itCannotReadAFileWithANamespaceStatementItDoesNotRecognise(string $source): void
    {
        $path = $this->directory . '/f.php';
        file_put_contents($path, $source);

        self::assertNull(RemovedNamespaceScan::declaredIn($path));
    }

    #[Test]
    public function itReadsALargeFileAsTextWithinABoundedHeap(): void
    {
        $path = $this->directory . '/large.php';
        file_put_contents($path, "<?php\nnamespace App\\Data;\nconst DATA = [" . str_repeat('1,', 1_000_000) . "];\n");
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $declared = RemovedNamespaceScan::declaredIn($path);

        self::assertSame(['App\\Data', ''], $declared);
        self::assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    /** @return iterable<string, array{string, list<string>|null}> */
    public static function provideLargeFiles(): iterable
    {
        $padding = '/* ' . str_repeat('x', RemovedNamespaceScan::TOKENIZED_BYTES) . ' */';
        yield 'name after a comment' => ["<?php\n{$padding}\nnamespace /* c */ # d\n Big\\Ns;", ['Big\\Ns', '']];
        yield 'keyword name' => ["<?php\n{$padding}\nnamespace Match;", ['Match', '']];
        yield 'braced global block' => ["<?php\n{$padding}\nnamespace { }", ['']];
        yield 'words in comments count' => ["<?php\n{$padding}\n// the namespace Mentioned\nclass X {}", ['Mentioned', '']];
        yield 'member and relative names do not' => ["<?php\n{$padding}\n\$o->namespace = namespace\\f(); X::namespace();", ['']];
        yield 'a comment longer than the lookahead is undecided' => ["<?php\n{$padding}\nnamespace /* " . str_repeat('y', 5000) . ' */ Late;', null];
        yield 'an unterminated comment at the end of the file ends it' => ["<?php\n{$padding}\nnamespace /* never closed", ['']];
    }

    /** @param list<string>|null $expected */
    #[Test]
    #[DataProvider('provideLargeFiles')]
    public function itListsEveryNamespaceAWordCouldDeclareInALargeFile(string $source, ?array $expected): void
    {
        $path = $this->directory . '/large.php';
        file_put_contents($path, $source);

        self::assertSame($expected, RemovedNamespaceScan::declaredIn($path));
    }

    #[Test]
    public function itFindsANameThatStraddlesTwoChunks(): void
    {
        $path = $this->directory . '/straddle.php';
        $prefix = "<?php\n/* " . str_repeat('x', 2 * RemovedNamespaceScan::TOKENIZED_BYTES - 20) . ' */ ';
        file_put_contents($path, $prefix . 'namespace Straddling\\Name;' . str_repeat(' ', 5000));

        self::assertSame(['Straddling\\Name', ''], RemovedNamespaceScan::declaredIn($path));
    }

    #[Test]
    public function itCannotReadAFileBeyondItsByteAllowance(): void
    {
        $path = $this->directory . '/sized.php';
        file_put_contents($path, '<?php namespace Sized;' . str_repeat(' ', 978));
        $tight = 999;
        $exact = 1000;

        self::assertNull(RemovedNamespaceScan::declaredIn($path, $tight));
        self::assertSame(['Sized'], RemovedNamespaceScan::declaredIn($path, $exact));
        self::assertSame(0, $exact);
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('provideDeclarations')]
    public function itReadsDeclaredNamespacesFromTokens(string $source, array $expected): void
    {
        $path = $this->directory . '/f.php';
        file_put_contents($path, $source);

        self::assertSame($expected, RemovedNamespaceScan::declaredIn($path));
        self::assertSame($expected, RemovedNamespaceScan::declaredIn(AbsolutePath::fromString($path)));
    }

    #[Test]
    public function itReadsPastTheFirstTwoKilobytes(): void
    {
        $path = $this->directory . '/late.php';
        file_put_contents($path, "<?php\n/* " . str_repeat('x', 5000) . " */\nnamespace Late\\Ns;\nclass X {}");

        self::assertSame(['Late\\Ns'], RemovedNamespaceScan::declaredIn($path));
    }

    #[Test]
    public function itReturnsNullForAMissingUnreadableOrNonRegularFile(): void
    {
        self::assertNull(RemovedNamespaceScan::declaredIn($this->directory . '/missing.php'));
        self::assertNull(RemovedNamespaceScan::declaredIn($this->directory));

        $locked = $this->directory . '/locked.php';
        file_put_contents($locked, '<?php namespace A;');
        chmod($locked, 0o000);
        if (is_readable($locked)) {
            self::markTestSkipped('Permissions are not enforced for this user');
        }
        self::assertNull(RemovedNamespaceScan::declaredIn($locked));
    }
}
