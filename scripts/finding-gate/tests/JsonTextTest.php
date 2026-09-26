<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\JsonText;
use QmxFindingGate\ReportPayload;

/**
 * A redaction that leaves every byte it does not redact where it was, and
 * matches what decoding the document would have matched.
 */
final class JsonTextTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function provideRedactions(): iterable
    {
        yield 'a key' => ['{"a": 1, "b": 2}', 'a', '{"a": "x", "b": 2}', 1];
        yield 'a nested key' => ["{\n  \"m\": {\"t\": \"now\"}\n}\n", 'm.t', "{\n  \"m\": {\"t\": \"x\"}\n}\n", 1];
        yield 'every element' => ['{"v":[{"s":1},{"s":[2,3]},{"t":4}]}', 'v.*.s', '{"v":[{"s":"x"},{"s":"x"},{"t":4}]}', 2];
        yield 'an index' => ['[1, 2, 3]', '1', '[1, "x", 3]', 1];
        yield 'an escaped key' => ['{"a\\/b": 1}', 'a/b', '{"a\\/b": "x"}', 1];
        yield 'a whole object' => ['{"a": {"b": [1, {"c": null}]}, "d": true}', 'a', '{"a": "x", "d": true}', 1];
        yield 'strings that look like structure' => ['{"a": "}{,:\\"", "b": "x\\\\"}', 'b', '{"a": "}{,:\\"", "b": "x"}', 1];
        yield 'numbers of every spelling' => ['{"a": -1.50e+3, "b": 0}', 'a', '{"a": "x", "b": 0}', 1];
        yield 'nothing at a deeper path' => ['{"a": 1}', 'a.b', '{"a": 1}', 0];
        yield 'nothing at a shallower path' => ['{"a": {"b": 1}}', 'b', '{"a": {"b": 1}}', 0];
    }

    #[Test]
    public function itKeepsTheExactBytesOfTheEmbeddedReadableHtmlPayload(): void
    {
        foreach (["{\n  \"a\": 1\n}", '{"a":"b\\/c"}', '{"a":1.50}', '{"a":0,"a":1}'] as $json) {
            self::assertSame($json, ReportPayload::of('<script type="application/json" id="report-data">' . $json . '</script>', 'case:x|format:html', 'candidate'));
        }
    }

    #[Test]
    #[DataProvider('provideRedactions')]
    public function itReplacesOnlyTheValueSpanItAddresses(string $text, string $path, string $expected, int $hits): void
    {
        self::assertSame([$expected, $hits], JsonText::redact($text, explode('.', $path), '"x"'));
    }
}
