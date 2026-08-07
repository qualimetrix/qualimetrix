<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineEntryParser;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineUpdater;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Tests\Support\Console\StubBaselineRun;
use Qualimetrix\Tests\Support\Console\TempDirectory;
use Qualimetrix\Tests\Support\Time\FixedClock;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `baseline:update` moves entries toward stricter and never toward more
 * permissive.
 *
 * The `lower`-direction channel carries most of these cases on purpose:
 * `maintainability.index` is worse the *smaller* it gets, so a rule written
 * as "the number may only go down" — the shape 10.1 was written to fix —
 * would look right on every `higher` channel and quietly widen this one.
 */
#[CoversClass(BaselineUpdateCommand::class)]
final class BaselineUpdateCommandTest extends TestCase
{
    private const string LOWER_CHANNEL = 'maintainability.index#maintainability.index.class';
    private const string HIGHER_CHANNEL = 'duplication.code-duplication#duplication.code-duplication';

    private string $tempDir;
    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-update-');
        $this->baselinePath = $this->tempDir . '/baseline.json';
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    /**
     * 50 is *worse* than the accepted 60 on a channel where lower is worse,
     * so the entry is written back untouched.
     */
    #[Test]
    public function itRefusesAMagnitudeThatIsWiderOnALowerChannel(): void
    {
        $this->writeBaseline([self::entry(self::LOWER_CHANNEL, [60.0], 1)], ['src']);

        $tester = $this->execute([self::finding(self::LOWER_CHANNEL, 50.0)]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('refused', $tester->getDisplay());
        self::assertSame([60.0], $this->storedMagnitudesOf(self::LOWER_CHANNEL));
    }

    /**
     * The other direction on the same channel: 70 is better than 60, so it
     * is recorded. Without this case the test above would also pass on an
     * `update` that refused everything.
     */
    #[Test]
    public function itAcceptsAMagnitudeThatIsStricterOnALowerChannel(): void
    {
        $this->writeBaseline([self::entry(self::LOWER_CHANNEL, [60.0], 1)], ['src']);

        $tester = $this->execute([self::finding(self::LOWER_CHANNEL, 70.0)]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('updated', $tester->getDisplay());
        self::assertSame([70.0], $this->storedMagnitudesOf(self::LOWER_CHANNEL));
    }

    /**
     * The case a per-position rule gets wrong: `[40, 100]` with the 40-line
     * duplicate deleted leaves `[100]`. Element-wise, rank 0 grew from 40 to
     * 100 and the update would decline — leaving a user no way to record an
     * improvement short of regenerating the whole file.
     */
    #[Test]
    public function itAcceptsAGroupThatShrankAndWritesTheSurvivor(): void
    {
        $this->writeBaseline([self::entry(self::HIGHER_CHANNEL, [40.0, 100.0], 2)], ['src']);

        $tester = $this->execute([self::finding(self::HIGHER_CHANNEL, 100.0)]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([100.0], $this->storedMagnitudesOf(self::HIGHER_CHANNEL));
        self::assertSame(1, $this->storedCountOf(self::HIGHER_CHANNEL));
    }

    /**
     * Two findings where one was accepted, each of them individually better
     * than the stored magnitude. The group is still more permissive than
     * what was accepted — one level of severity now holds two members where
     * it held one — so the entry does not move.
     */
    #[Test]
    public function itRefusesACountWideningOnALowerChannel(): void
    {
        $this->writeBaseline([self::entry(self::LOWER_CHANNEL, [60.0], 1)], ['src']);

        $tester = $this->execute([
            self::finding(self::LOWER_CHANNEL, 70.0),
            self::finding(self::LOWER_CHANNEL, 80.0),
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('refused', $tester->getDisplay());
        self::assertSame([60.0], $this->storedMagnitudesOf(self::LOWER_CHANNEL));
        self::assertSame(1, $this->storedCountOf(self::LOWER_CHANNEL));
    }

    /**
     * A run narrower than the recorded scope cannot see the identities
     * outside it, so writing from it would record measurements the run never
     * made.
     */
    #[Test]
    public function itRefusesARunThatDoesNotCoverTheRecordedScope(): void
    {
        $this->writeBaseline([self::entry(self::LOWER_CHANNEL, [60.0], 1)], ['src', 'tests']);

        $before = (string) file_get_contents($this->baselinePath);
        $tester = $this->execute([self::finding(self::LOWER_CHANNEL, 70.0)], [], ['src']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not cover tests', $tester->getDisplay());
        self::assertSame($before, file_get_contents($this->baselinePath));
    }

    /**
     * The opposite direction is harmless: a wider run measures more than the
     * file remembers, so it proceeds without a flag.
     */
    #[Test]
    public function itAllowsARunWiderThanTheRecordedScope(): void
    {
        $this->writeBaseline([self::entry(self::LOWER_CHANNEL, [60.0], 1)], ['src/Domain']);

        $tester = $this->execute([self::finding(self::LOWER_CHANNEL, 70.0)], [], ['src']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([70.0], $this->storedMagnitudesOf(self::LOWER_CHANNEL));
    }

    #[Test]
    public function itWritesANarrowedRunUnderForce(): void
    {
        $this->writeBaseline([self::entry(self::LOWER_CHANNEL, [60.0], 1)], ['src', 'tests']);

        $tester = $this->execute([self::finding(self::LOWER_CHANNEL, 70.0)], ['--force' => true], ['src']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([70.0], $this->storedMagnitudesOf(self::LOWER_CHANNEL));
    }

    /**
     * §6 requires a no-op command to leave the bytes alone; a `generated`
     * that moved on every scheduled run would show up as a change in version
     * control that nothing caused.
     */
    #[Test]
    public function itLeavesTheFileUntouchedWhenNothingMoved(): void
    {
        $this->writeBaseline([self::entry(self::LOWER_CHANNEL, [60.0], 1)], ['src']);

        $before = (string) file_get_contents($this->baselinePath);
        $tester = $this->execute([self::finding(self::LOWER_CHANNEL, 60.0)]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
        self::assertSame($before, file_get_contents($this->baselinePath));
    }

    /**
     * @param list<Violation> $measured
     * @param array<string, mixed> $options
     * @param list<string> $runScope
     */
    private function execute(array $measured, array $options = [], array $runScope = ['src']): CommandTester
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();

        $command = new BaselineUpdateCommand(
            new StubBaselineRun($measured, $runScope, AbsolutePath::fromString($this->tempDir)),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            new BaselineUpdater($declarations, new FixedClock('2026-09-01T00:00:00+00:00')),
            new BaselineWriter(),
        );

        $tester = new CommandTester($command);
        $tester->execute(['baseline' => $this->baselinePath, 'paths' => $runScope, ...$options]);

        return $tester;
    }

    /**
     * @param list<BaselineEntry> $entries
     * @param list<string> $scope
     */
    private function writeBaseline(array $entries, array $scope): void
    {
        (new BaselineWriter())->write(
            new Baseline(
                generated: (new FixedClock())->now(),
                scope: $scope,
                entries: $entries,
            ),
            $this->baselinePath,
            AbsolutePath::fromString($this->tempDir),
        );
    }

    /**
     * @param list<float> $magnitudes
     */
    private static function entry(string $channelKey, array $magnitudes, int $count): BaselineEntry
    {
        return new BaselineEntry(self::identity($channelKey), $magnitudes, $count);
    }

    private static function identity(string $channelKey): BaselineIdentity
    {
        return new BaselineIdentity(
            SymbolPath::forClass('App', 'Legacy')->toCanonical(),
            ViolationChannel::fromKey($channelKey),
        );
    }

    private static function finding(string $channelKey, float $magnitude): Violation
    {
        $channel = ViolationChannel::fromKey($channelKey);

        return new Violation(
            location: new Location(RelativePath::fromString('src/Legacy.php'), 7),
            symbolPath: SymbolPath::forClass('App', 'Legacy'),
            ruleName: $channel->ruleName,
            violationCode: $channel->violationCode,
            message: 'finding',
            severity: Severity::Warning,
            metricValue: $magnitude,
        );
    }

    /**
     * @return list<float>
     */
    private function storedMagnitudesOf(string $channelKey): array
    {
        // JSON drops a trailing `.0`, so a normalised 40.0 reloads as int —
        // stated in §6 and harmless for a numeric comparison, but it means
        // the read side has to normalise before asserting.
        /** @var list<int|float> $magnitudes */
        $magnitudes = self::entryData($this->baselinePath, $channelKey)['magnitudes'] ?? [];

        return array_map(static fn(int|float $value): float => (float) $value, $magnitudes);
    }

    private function storedCountOf(string $channelKey): int
    {
        return (int) self::entryData($this->baselinePath, $channelKey)['count'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function entryData(string $path, string $channelKey): array
    {
        /** @var array{entries: array<string, list<array<string, mixed>>>} $data */
        $data = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        foreach ($data['entries'] as $forSymbol) {
            foreach ($forSymbol as $entry) {
                if (($entry['channel'] ?? null) === $channelKey) {
                    return $entry;
                }
            }
        }

        self::fail(\sprintf('No entry for channel %s in %s', $channelKey, $path));
    }
}
