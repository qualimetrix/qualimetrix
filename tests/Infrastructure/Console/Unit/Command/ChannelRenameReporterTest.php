<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameReport;
use Qualimetrix\Infrastructure\Console\Command\ChannelRenameReporter;
use stdClass;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(ChannelRenameReporter::class)]
final class ChannelRenameReporterTest extends TestCase
{
    /**
     * A map that declares nothing is accepted — it is a map, not a defect —
     * but it is not the same outcome as a map whose renames matched nothing,
     * and the report used one sentence for both.
     */
    #[Test]
    public function itSaysSoWhenTheMapDeclaresNoRename(): void
    {
        $text = $this->text(new ChannelRenameReport(5, 0, [], [], written: false));

        self::assertStringContainsString('The map declares no rename', $text);
        self::assertStringNotContainsString('matched the map', $text);
    }

    #[Test]
    public function itKeepsTheMatchedNothingSentenceForAMapWithDeclaredRows(): void
    {
        $text = $this->text(new ChannelRenameReport(5, 0, ['nosuch.channel' => 0], [], written: false));

        self::assertStringContainsString('No entry of the 5 in this baseline matched the map', $text);
        self::assertStringContainsString('Declared rename of "nosuch.channel" matched no entry.', $text);
    }

    #[Test]
    public function itCountsTheDeclaredRowsInJson(): void
    {
        self::assertSame(0, $this->json(new ChannelRenameReport(5, 0, [], [], written: false))->declared_rows);
        self::assertSame(1, $this->json(new ChannelRenameReport(5, 0, ['a.b' => 0], [], written: false))->declared_rows);
    }

    /**
     * `rows` and `unreadable` are maps. An empty PHP array encodes as `[]`, so
     * their JSON type used to depend on whether they had anything in them.
     */
    #[Test]
    public function itPublishesTheTwoMapsAsObjectsEvenWhenEmpty(): void
    {
        $empty = $this->json(new ChannelRenameReport(5, 0, [], [], written: false));
        $full = $this->json(new ChannelRenameReport(5, 1, ['a.b' => 1], ['a reason' => 2], written: true));

        self::assertInstanceOf(stdClass::class, $empty->rows);
        self::assertInstanceOf(stdClass::class, $empty->unreadable);
        self::assertInstanceOf(stdClass::class, $full->rows);
        self::assertInstanceOf(stdClass::class, $full->unreadable);
        self::assertIsArray($empty->idle_rows);
    }

    private function text(ChannelRenameReport $report): string
    {
        $output = new BufferedOutput();
        ChannelRenameReporter::report($report, 'text', $output);

        return $output->fetch();
    }

    private function json(ChannelRenameReport $report): stdClass
    {
        $output = new BufferedOutput();
        ChannelRenameReporter::report($report, 'json', $output);

        $decoded = json_decode($output->fetch(), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $decoded);

        return $decoded;
    }
}
