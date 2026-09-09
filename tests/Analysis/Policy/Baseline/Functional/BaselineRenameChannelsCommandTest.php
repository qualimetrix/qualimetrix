<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\BaselineFormatVersion;
use Qualimetrix\Infrastructure\Console\Command\BaselineRenameChannelsCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;

/**
 * The command through the binary a user actually runs — registration
 * included, because a service the container knows and `bin/qmx` does not
 * offer is a command nobody can call.
 */
#[CoversClass(BaselineRenameChannelsCommand::class)]
final class BaselineRenameChannelsCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-rename-channels-');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    #[Test]
    public function itIsRegisteredAsAPublicServiceAndOfferedByTheBinary(): void
    {
        $container = (new ContainerFactory())->create();

        self::assertTrue($container->has(BaselineRenameChannelsCommand::class));
        $status = 0;
        self::assertStringContainsString('baseline:rename-channels', $this->qmx('list', $status));
    }

    /**
     * The command measures nothing, so it must not offer the input that
     * defines a measured set: an accepted-and-inert `--preset` would tell a
     * user their carry honoured a configuration it never read.
     */
    #[Test]
    public function itOffersNoMeasuredRunInput(): void
    {
        $command = (new ContainerFactory())->create()->get(BaselineRenameChannelsCommand::class);
        self::assertInstanceOf(BaselineRenameChannelsCommand::class, $command);
        $definition = $command->getDefinition();

        foreach (['config', 'preset', 'rule-opt', 'only-rule', 'disable-rule'] as $option) {
            self::assertFalse($definition->hasOption($option));
        }

        self::assertFalse($definition->hasArgument('paths'));
        self::assertTrue($definition->hasArgument('map'));
        self::assertTrue($definition->hasOption('format'));
    }

    #[Test]
    public function itCarriesAndReportsInJson(): void
    {
        $baseline = $this->baseline([
            'class:App\Foo' => [['channel' => 'alpha.one', 'count' => 1]],
        ]);
        $map = $this->map("alpha.one\talpha.renamed");

        $status = 0;

        $output = $this->qmx(\sprintf(
            'baseline:rename-channels %s %s --format=json',
            escapeshellarg($baseline),
            escapeshellarg($map),
        ), $status);

        self::assertSame(0, $status, $output);

        $report = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($report['written']);
        self::assertSame(1, $report['renamed']);
        self::assertSame(['alpha.one' => 1], $report['rows']);
        self::assertStringContainsString('"channel":"alpha.renamed"', (string) file_get_contents($baseline));
    }

    /**
     * A malformed baseline envelope is the user's to fix — the version they
     * pointed the command at, not a defect this tool caused
     * (`01-refusal-verdicts.md` §7, decision on `rename-channels`'s exit
     * codes). Distinct from `BaselineConflictException` and from
     * `ChannelRenameRefusal`'s own docblock, which groups it with an
     * unreadable file under one exit code the other four `baseline:*`
     * commands never use for either — that grouping is what this round
     * changes for the case it can reach without editing 03/P5's files.
     */
    #[Test]
    public function itAnswersAContentRefusalWithThree(): void
    {
        $baseline = $this->baseline([], version: 5);
        $before = (string) file_get_contents($baseline);

        $status = 0;

        $output = $this->qmx(\sprintf(
            'baseline:rename-channels %s %s',
            escapeshellarg($baseline),
            escapeshellarg($this->map("alpha.one\talpha.renamed")),
        ), $status);

        self::assertSame(3, $status, $output);
        self::assertSame($before, (string) file_get_contents($baseline));
    }

    /**
     * A caller that asked for a machine format asked for every outcome in
     * it, in the same `{error, exit_code}` envelope every other
     * machine-readable refusal in this tool uses — not this command's own
     * former ad hoc `{"error": ...}` shape, which carried no exit code at
     * all.
     */
    #[Test]
    public function itAnswersARefusalInTheChosenFormat(): void
    {
        $map = escapeshellarg($this->map("alpha.one\talpha.renamed"));

        foreach ([$this->baseline([], version: 5), $this->tempDir . '/absent.json'] as $baseline) {
            $status = 0;

            $output = $this->qmx(\sprintf(
                'baseline:rename-channels %s %s --format=json',
                escapeshellarg($baseline),
                $map,
            ), $status);

            self::assertSame(3, $status, $output);

            $decoded = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('error', $decoded);
            self::assertNotSame('', $decoded['error']);
            self::assertSame(3, $decoded['exit_code']);
        }
    }

    #[Test]
    public function itAnswersAMalformedMapWithThree(): void
    {
        $baseline = $this->baseline(['class:App\Foo' => [['channel' => 'alpha.one', 'count' => 1]]]);
        $before = (string) file_get_contents($baseline);
        $map = $this->tempDir . '/bad.tsv';
        file_put_contents($map, "from\tto\treason\n");

        $status = 0;

        $output = $this->qmx(\sprintf(
            'baseline:rename-channels %s %s',
            escapeshellarg($baseline),
            escapeshellarg($map),
        ), $status);

        self::assertSame(3, $status, $output);
        self::assertSame($before, (string) file_get_contents($baseline));
    }

    /**
     * A missing/unreadable file is a refusal by user input (a path they
     * named), same as `baseline:generate`'s own missing-argument checks —
     * not the code-2 `--format`/`--group` family, and no longer the code-1
     * this command used to share with a malformed envelope either.
     */
    #[Test]
    public function itAnswersAnUnreachableFileWithThree(): void
    {
        $map = $this->map("alpha.one\talpha.renamed");
        $status = 0;

        $this->qmx(\sprintf(
            'baseline:rename-channels %s %s',
            escapeshellarg($this->tempDir . '/absent.json'),
            escapeshellarg($map),
        ), $status);
        self::assertSame(3, $status);

        $this->qmx(\sprintf(
            'baseline:rename-channels %s %s',
            escapeshellarg($this->baseline([])),
            escapeshellarg($this->tempDir . '/absent.tsv'),
        ), $status);
        self::assertSame(3, $status);
    }

    /**
     * @param array<string, mixed> $entries
     */
    private function baseline(array $entries, int $version = BaselineFormatVersion::CURRENT): string
    {
        $path = $this->tempDir . '/baseline-' . $version . '-' . \count($entries) . '.json';
        file_put_contents($path, (string) json_encode([
            'version' => $version,
            'generated' => '2026-01-01T00:00:00+00:00',
            'scope' => ['src'],
            'entries' => $entries,
        ], \JSON_THROW_ON_ERROR));

        return $path;
    }

    private function map(string $row): string
    {
        $path = $this->tempDir . '/map.tsv';
        file_put_contents($path, "old\tnew\treason\n" . $row . "\twhy\n");

        return $path;
    }

    private function qmx(string $arguments, int &$status): string
    {
        $output = [];
        exec(
            \sprintf('%s %s %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg(\dirname(__DIR__, 5) . '/bin/qmx'), $arguments),
            $output,
            $status,
        );

        return implode("\n", $output);
    }
}
