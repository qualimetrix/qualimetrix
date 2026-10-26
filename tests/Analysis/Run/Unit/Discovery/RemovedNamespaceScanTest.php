<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Discovery\RemovedNamespaceScan;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(RemovedNamespaceScan::class)]
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
