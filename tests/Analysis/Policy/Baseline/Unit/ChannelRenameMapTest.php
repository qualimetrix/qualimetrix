<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameMap;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameRefusal;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures\ChannelRenameTsvCorpus;

#[CoversClass(ChannelRenameMap::class)]
final class ChannelRenameMapTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function provideCorpus(): iterable
    {
        foreach (ChannelRenameTsvCorpus::cases() as $case) {
            yield $case['id'] => [$case['contents'], $case['product'], $case['note']];
        }
    }

    #[Test]
    #[DataProvider('provideCorpus')]
    public function itAnswersTheSharedCorpusAsDeclared(string $contents, bool $accepted, string $note): void
    {
        if (!$accepted) {
            $this->expectException(ChannelRenameRefusal::class);
        }

        $map = ChannelRenameMap::fromString($contents, 'corpus.tsv');

        self::assertSame(array_keys($map->renames), $map->oldNames(), $note);

        foreach ($map->oldNames() as $old) {
            self::assertNotNull($map->translate($old), $note);
        }
    }

    #[Test]
    public function itReadsTheRowsItAccepted(): void
    {
        $map = ChannelRenameMap::fromString(
            "old\tnew\treason\na.b\tc.d\twhy\ne.f\tg.h\twhy\n",
            'corpus.tsv',
        );

        self::assertSame(['a.b' => 'c.d', 'e.f' => 'g.h'], $map->renames);
        self::assertSame('c.d', $map->translate('a.b'));
        self::assertNull($map->translate('c.d'));
        self::assertSame(['a.b', 'e.f'], $map->oldNames());
    }

    /**
     * The tracked map of this repository is a live input of the carry, not
     * only of the gate: a row that lands in it has to be one the shipped
     * command can read.
     */
    #[Test]
    public function itReadsTheRepositorysOwnDeclaredChannelMap(): void
    {
        $map = ChannelRenameMap::fromFile(\dirname(__DIR__, 5) . '/finding-gate/maps/channels.tsv');

        self::assertCount(\count($map->oldNames()), $map->renames);

        foreach ($map->renames as $old => $new) {
            self::assertNotSame('', $old);
            self::assertNotSame('', $new);
        }
    }

    #[Test]
    public function itRefusesAMapFileItCannotRead(): void
    {
        $this->expectException(ChannelRenameRefusal::class);

        ChannelRenameMap::fromFile(__DIR__ . '/no-such-map.tsv');
    }
}
