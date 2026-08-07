<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\V5BaselineReader;
use RuntimeException;

#[CoversClass(V5BaselineReader::class)]
final class V5BaselineReaderTest extends TestCase
{
    private V5BaselineReader $reader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->reader = new V5BaselineReader();
        $this->tempDir = sys_get_temp_dir() . '/qmx_v5_reader_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function itReadsAValidV5File(): void
    {
        $baseline = $this->readJson(<<<'JSON'
            {
                "version": 5,
                "generated": "2026-01-01T00:00:00+00:00",
                "entries": {
                    "method:App\\OrderService::calculate": [
                        {"rule": "complexity.cyclomatic", "hash": "0123456789abcdef"},
                        {"rule": "coupling.cbo", "hash": "abcdef0123456789"}
                    ],
                    "class:App\\Web\\Controller": [
                        {"rule": "design.god-class", "hash": "fedcba9876543210"}
                    ]
                }
            }
            JSON);

        self::assertCount(3, $baseline->entries);

        $bySymbol = [];
        foreach ($baseline->entries as $entry) {
            $bySymbol[$entry->symbolKey][] = $entry->rule;
        }

        self::assertSame(
            ['complexity.cyclomatic', 'coupling.cbo'],
            $bySymbol['method:App\\OrderService::calculate'],
        );
        self::assertSame(['design.god-class'], $bySymbol['class:App\\Web\\Controller']);

        self::assertSame('0123456789abcdef', $baseline->entries[0]->hash);
    }

    #[Test]
    public function itSkipsAMalformedRowWithinAnOtherwiseValidFile(): void
    {
        $baseline = $this->readJson(<<<'JSON'
            {
                "version": 5,
                "generated": "2026-01-01T00:00:00+00:00",
                "entries": {
                    "class:App\\Foo": [
                        {"rule": "complexity.cyclomatic", "hash": "0123456789abcdef"},
                        {"rule": "no-hash-here"},
                        "not-even-an-object"
                    ]
                }
            }
            JSON);

        self::assertCount(1, $baseline->entries);
        self::assertSame('complexity.cyclomatic', $baseline->entries[0]->rule);
    }

    #[Test]
    public function itRejectsAVersionTenFileAndNamesWhy(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already version 10/');

        $this->readJson(<<<'JSON'
            {"version": 10, "generated": "2026-01-01T00:00:00+00:00", "scope": [], "entries": {}}
            JSON);
    }

    #[Test]
    public function itRejectsGarbageThatIsNotValidJson(): void
    {
        $this->expectException(RuntimeException::class);

        $this->readJson('not { valid json at all');
    }

    #[Test]
    public function itRejectsJsonThatIsNotAnObjectAtAll(): void
    {
        $this->expectException(RuntimeException::class);

        $this->readJson('[1, 2, 3]');
    }

    #[Test]
    public function itRejectsAnyOtherVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/version 5.*version 9/');

        $this->readJson(<<<'JSON'
            {"version": 9, "generated": "2026-01-01T00:00:00+00:00", "entries": {}}
            JSON);
    }

    #[Test]
    public function itRecognizesAValidV5FileForTheForceGuard(): void
    {
        $path = $this->writeJson(<<<'JSON'
            {"version": 5, "generated": "2026-01-01T00:00:00+00:00", "entries": {}}
            JSON);

        self::assertTrue($this->reader->isV5File($path));
    }

    #[Test]
    public function itDoesNotRecognizeAVersionTenFileAsV5(): void
    {
        $path = $this->writeJson(<<<'JSON'
            {"version": 10, "generated": "2026-01-01T00:00:00+00:00", "scope": [], "entries": {}}
            JSON);

        self::assertFalse($this->reader->isV5File($path));
    }

    #[Test]
    public function itDoesNotRecognizeGarbageAsV5(): void
    {
        $path = $this->writeJson('not json at all');

        self::assertFalse($this->reader->isV5File($path));
    }

    #[Test]
    public function itDoesNotRecognizeAMissingFileAsV5(): void
    {
        self::assertFalse($this->reader->isV5File($this->tempDir . '/does-not-exist.json'));
    }

    private function readJson(string $json): \Qualimetrix\Baseline\V5Baseline
    {
        return $this->reader->read($this->writeJson($json));
    }

    private function writeJson(string $json): string
    {
        $path = $this->tempDir . '/baseline.json';
        file_put_contents($path, $json);

        return $path;
    }
}
