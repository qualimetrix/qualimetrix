<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Cache\AstEntryCodec;
use SplFileInfo;

#[CoversClass(AstEntryCodec::class)]
final class AstEntryProducerDigestTest extends TestCase
{
    private const string SOURCE = '<?php namespace Sample; /** docs */ final class Example { public function value(?float $input): float { return $input ?? 0.12345678901234568; } }';

    #[Test]
    public function itRequiresAFormatVersionBumpWhenTheSameParserProducesDifferentEntryBytes(): void
    {
        $record = json_decode((string) file_get_contents(__DIR__ . '/ast-entry-producer.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($record['parserVersion'], InstalledVersions::getVersion('nikic/php-parser'), 'Parser version changed: re-record the AST producer digest.');
        $nodes = (new PhpFileParser())->parseContent(new SplFileInfo(__FILE__), self::SOURCE);
        $bytes = (new AstEntryCodec())->encode(array_values($nodes));
        self::assertNotNull($bytes);
        $digest = hash('sha256', $bytes);
        self::assertSame($record['digest'], $digest, 'AST producer bytes changed: bump FORMAT_VERSION and re-record the digest.');
        self::assertSame($record['formatVersion'], AstEntryCodec::FORMAT_VERSION);
    }
}
