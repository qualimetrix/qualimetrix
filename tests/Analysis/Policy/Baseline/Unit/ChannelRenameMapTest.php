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

/**
 * The repository-topology half of this subject —
 * {@see \Qualimetrix\Governance\Channel\ChannelRenameMapTest} — reads the
 * repository's own declared channel map and stays a repo-control.
 */
#[CoversClass(ChannelRenameMap::class)]
final class ChannelRenameMapTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, array<string, string>, string}>
     */
    public static function provideCorpus(): iterable
    {
        foreach (ChannelRenameTsvCorpus::cases() as $case) {
            yield $case['id'] => [$case['contents'], $case['product'], $case['renames'], $case['note']];
        }
    }

    /**
     * @param array<string, string> $renames what the corpus says this case's lines mean
     */
    #[Test]
    #[DataProvider('provideCorpus')]
    public function itAnswersTheSharedCorpusAsDeclared(string $contents, bool $accepted, array $renames, string $note): void
    {
        if (!$accepted) {
            $this->expectException(ChannelRenameRefusal::class);
        }

        $map = ChannelRenameMap::fromString($contents, 'corpus.tsv');

        self::assertSame($renames, $map->renames, $note);
        self::assertSame(array_keys($renames), $map->oldNames(), $note);

        foreach ($renames as $old => $new) {
            self::assertSame($new, $map->translate($old), $note);
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

    #[Test]
    public function itRefusesAMapFileItCannotRead(): void
    {
        $this->expectException(ChannelRenameRefusal::class);

        ChannelRenameMap::fromFile(__DIR__ . '/no-such-map.tsv');
    }
}
