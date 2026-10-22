<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(FileNamespaceIndex::class)]
final class FileNamespaceIndexTest extends TestCase
{
    #[Test]
    public function itReadsSerializedFileEntriesWithoutAddingDeclaredNamespaceContributions(): void
    {
        $file = RelativePath::fromString('src/Script.php');
        $bag = (new MetricBag())
            ->withEntry(FileNamespaceIndex::LEXICAL_NAMESPACE_ENTRY, ['namespace' => 'A'])
            ->withEntry(FileNamespaceIndex::LEXICAL_NAMESPACE_ENTRY, ['namespace' => 'B'])
            ->withEntry(FileNamespaceIndex::LEXICAL_NAMESPACE_ENTRY, ['namespace' => 'A']);
        $restored = unserialize(serialize($bag));
        self::assertInstanceOf(MetricBag::class, $restored);
        $repository = new InMemoryMetricRepository();
        $repository->add(SymbolPath::forFile($file), $restored, $file, 1);

        $index = FileNamespaceIndex::fromRepository($repository);
        self::assertSame(['A', 'B'], $index->lexicalNamespacesOf($file));
        self::assertSame([], $index->namespacesOf($file));
        self::assertSame([], FileNamespaceIndex::fromRepository(null)->lexicalNamespacesOf($file));
    }
}
