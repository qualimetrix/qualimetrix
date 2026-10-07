<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;

#[CoversClass(BaselineDocumentReader::class)]
#[CoversClass(BaselineEntryParser::class)]
#[CoversClass(BaselineLoader::class)]
final class RetiredDependencyTypeBaselineTest extends TestCase
{
    #[Test]
    #[DataProvider('retiredTypes')]
    public function itKeepsARetiredDependencyTypeInertWithoutRejectingItsValidNeighbour(string $type): void
    {
        $directory = TempDirectory::create('qmx-retired-edge-');
        try {
            $subject = 'declaration:class:App\\Source@src/Source.php';
            $raw = ['channel' => 'architecture.layer-violation', 'occurrence' => 'retired-edge', 'edge' => ['target' => 'class:App\\Target', 'type' => $type], 'count' => 1];
            $valid = ['channel' => 'architecture.layer-violation', 'occurrence' => 'current-edge', 'edge' => ['target' => 'class:App\\Target', 'type' => 'new'], 'count' => 1];
            $path = $directory . '/baseline.json';
            file_put_contents($path, json_encode([
                'version' => 14,
                'generated' => '2026-10-07T12:00:00+00:00',
                'scope' => ['src'],
                'exclusions' => ['patterns' => [], 'generated' => 'excluded'],
                'entries' => [$subject => [$raw, $valid]],
            ], \JSON_THROW_ON_ERROR));
            $loader = new BaselineLoader(new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()));
            $baseline = $loader->load((new BaselineDocumentReader())->preflight($path));

            self::assertCount(1, $baseline->entries);
            self::assertSame(DependencyType::New_, $baseline->entries[0]->identity->edge?->type);
            self::assertCount(1, $baseline->inertEntries);
            $inert = $baseline->inertEntries[0];
            self::assertSame(InertEntryReason::Malformed, $inert->reason);
            self::assertStringContainsString($type, $inert->detail);
            self::assertSame($subject, $inert->subjectKey);
            self::assertSame('architecture.layer-violation', $inert->channelKey);
            self::assertSame($raw, $inert->raw);
            self::assertNull($inert->identity);
            self::assertNotSame($baseline->entries[0]->selector()->value, $inert->selector->value);
        } finally {
            TempDirectory::remove($directory);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function retiredTypes(): iterable
    {
        yield 'union' => ['union_type'];
        yield 'intersection' => ['intersection_type'];
    }
}
