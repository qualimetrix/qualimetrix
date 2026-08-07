<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineMigrator;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Baseline\V5BaselineReader;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\Command\BaselineMigrateCommand;
use Qualimetrix\Tests\Support\Console\StubBaselineRun;
use Qualimetrix\Tests\Support\Console\TempDirectory;
use Qualimetrix\Tests\Support\Time\FixedClock;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BaselineMigrateCommand::class)]
final class BaselineMigrateCommandTest extends TestCase
{
    private string $tempDir;
    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-migrate-');
        $this->baselinePath = $this->tempDir . '/baseline.json';
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    /**
     * One run: the version 5 file goes in, a version 10 capture comes out,
     * and the entries the old file recorded that nothing reports any more are
     * named individually — they are the acceptances the conversion drops, and
     * a count of them could not be looked up afterwards.
     */
    #[Test]
    public function itConvertsAVersion5FileAndNamesWhatItDropped(): void
    {
        $this->writeV5([
            'file:src/Legacy.php' => [['rule' => 'code-smell.goto', 'hash' => 'aaa']],
            'file:src/Gone.php' => [['rule' => 'code-smell.goto', 'hash' => 'bbb']],
        ]);

        $tester = $this->execute([], [self::gotoFinding('src/Legacy.php')]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertStringContainsString('carried: 1 version 5 entry still reported', $display);
        self::assertStringContainsString('dropped: 1 version 5 entry no longer reported', $display);
        self::assertStringContainsString('file:src/Gone.php code-smell.goto', $display);

        /** @var array{version: int, entries: array<string, mixed>} $data */
        $data = json_decode((string) file_get_contents($this->baselinePath), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(10, $data['version']);
        self::assertArrayHasKey('file:src/Legacy.php', $data['entries']);
        self::assertArrayNotHasKey('file:src/Gone.php', $data['entries']);
    }

    /**
     * A typo'd path pointing at a healthy version 10 baseline must not be
     * replaced by a fresh capture: that would accept every finding the file
     * was holding the line on, and nothing would say so.
     */
    #[Test]
    public function itRefusesADestinationThatIsNotAVersion5File(): void
    {
        file_put_contents($this->baselinePath, (string) json_encode([
            'version' => 10,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => ['src'],
            'entries' => new stdClass(),
        ], \JSON_THROW_ON_ERROR));

        $before = (string) file_get_contents($this->baselinePath);
        $tester = $this->execute([], [self::gotoFinding('src/Legacy.php')]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is not a version 5 baseline', $tester->getDisplay());
        self::assertSame($before, file_get_contents($this->baselinePath));
    }

    #[Test]
    public function itReplacesANonVersion5DestinationUnderForce(): void
    {
        file_put_contents($this->baselinePath, (string) json_encode([
            'version' => 10,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => ['src'],
            'entries' => new stdClass(),
        ], \JSON_THROW_ON_ERROR));

        $tester = $this->execute(['--force' => true], [self::gotoFinding('src/Legacy.php')]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        /** @var array{entries: array<string, mixed>} $data */
        $data = json_decode((string) file_get_contents($this->baselinePath), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('file:src/Legacy.php', $data['entries']);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<Violation> $measured
     */
    private function execute(array $options, array $measured): CommandTester
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $reader = new V5BaselineReader();

        $command = new BaselineMigrateCommand(
            new StubBaselineRun($measured, ['src'], AbsolutePath::fromString($this->tempDir)),
            new BaselineGenerator($declarations, new FixedClock()),
            new BaselineMigrator($reader),
            $reader,
            new BaselineWriter(),
        );

        $tester = new CommandTester($command);
        $tester->execute(['baseline' => $this->baselinePath, 'paths' => ['src'], ...$options]);

        return $tester;
    }

    /**
     * @param array<string, list<array{rule: string, hash: string}>> $entries
     */
    private function writeV5(array $entries): void
    {
        file_put_contents($this->baselinePath, (string) json_encode([
            'version' => 5,
            'generated' => '2026-01-01T00:00:00+00:00',
            'entries' => $entries,
        ], \JSON_THROW_ON_ERROR));
    }

    private static function gotoFinding(string $file): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString($file), 3),
            symbolPath: SymbolPath::forFile(RelativePath::fromString($file)),
            ruleName: 'code-smell.goto',
            violationCode: 'code-smell.goto',
            message: 'finding',
            severity: Severity::Warning,
        );
    }
}
