<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\CheckScope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\CheckScope\ProjectScopePresenter;
use Symfony\Component\Process\Process;

/**
 * A stale namespace selector is reported although a file was removed from the
 * run, as long as that file cannot have declared a namespace the selector
 * binds; when it could, the value stays unjudged and the run says so on stderr.
 */
#[CoversClass(ProjectScopePresenter::class)]
final class RemovedCodeNamespaceJudgementTest extends TestCase
{
    private const string CHANNEL = 'suppression.unmatched-namespace';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-removed-namespaces-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/src/Kept.php', "<?php\nnamespace App;\nfinal class Kept {}\n");
        file_put_contents($this->root . '/src/Removed.php', "<?php\nnamespace App\\Removed;\nfinal class Removed {}\n");
    }

    protected function tearDown(): void
    {
        $sources = glob($this->root . '/src/*');
        foreach ($sources === false ? [] : $sources as $file) {
            @chmod($file, 0o644);
            @unlink($file);
        }
        foreach (['composer.json', 'qmx.yaml'] as $file) {
            @unlink($this->root . '/' . $file);
        }
        @rmdir($this->root . '/src');
        @rmdir($this->root);
    }

    #[Test]
    public function itJudgesAStaleGlobalSelectorUnderAnUnrelatedExclude(): void
    {
        $run = $this->check("exclude:\n  - {exact: src/Removed.php}\nsuppress_namespaces:\n  - {exact: 'App\\Does\\Not\\Exist'}\n");

        self::assertSame(1, $this->staleFindings($run['report']));
        self::assertNotContains(self::CHANNEL, $run['report']['projectScope']['unjudgedChannels']);
        self::assertSame([], $this->unjudgedNamespaceValues($run['report']));
    }

    #[Test]
    public function itJudgesAStalePerRuleSelectorUnderAnUnrelatedExclude(): void
    {
        $run = $this->check("exclude:\n  - {exact: src/Removed.php}\nrules:\n  complexity.ccn:\n    suppress_namespaces: [{exact: 'App\\Does\\Not\\Exist'}]\n");

        self::assertSame(1, $this->staleFindings($run['report'], 'suppression.unmatched-rule-ledger'));
        self::assertSame([], $this->unjudgedNamespaceValues($run['report']));
    }

    #[Test]
    public function itDoesNotJudgeASelectorOfTheNamespaceTheExcludedFileDeclares(): void
    {
        $run = $this->check("exclude:\n  - {exact: src/Removed.php}\nsuppress_namespaces:\n  - {exact: 'App\\Removed'}\n");

        self::assertSame(0, $this->staleFindings($run['report']));
        self::assertContains(self::CHANNEL, $run['report']['projectScope']['unjudgedChannels']);
        self::assertStringContainsString('Warning: Code removed by exclude "exact:src/Removed.php" may declare the namespaces of 1 suppression value', $run['stderr']);
        self::assertStringContainsString(self::CHANNEL, $run['stderr']);
    }

    #[Test]
    public function itSilencesTheWarningUnderQuiet(): void
    {
        $run = $this->check("exclude:\n  - {exact: src/Removed.php}\nsuppress_namespaces:\n  - {exact: 'App\\Removed'}\n", ['-q']);

        self::assertStringNotContainsString('may declare the namespaces', $run['stderr']);
    }

    #[Test]
    public function itStaysQuietWhenEveryNamespaceValueWasJudged(): void
    {
        $run = $this->check("exclude:\n  - {exact: src/Removed.php}\nsuppress_namespaces:\n  - {exact: 'App\\Does\\Not\\Exist'}\n");

        self::assertStringNotContainsString('may declare the namespaces', $run['stderr']);
    }

    #[Test]
    public function itDoesNotJudgeWhenTheExcludedFileCannotBeRead(): void
    {
        if (!\function_exists('posix_geteuid') || posix_geteuid() === 0) {
            self::markTestSkipped('File permissions do not restrict this user.');
        }
        self::assertTrue(chmod($this->root . '/src/Removed.php', 0o000));
        if (is_readable($this->root . '/src/Removed.php')) {
            self::markTestSkipped('chmod does not restrict reads here.');
        }

        $run = $this->check("exclude:\n  - {exact: src/Removed.php}\nsuppress_namespaces:\n  - {exact: 'App\\Does\\Not\\Exist'}\n");

        self::assertSame(0, $this->staleFindings($run['report']));
        self::assertContains(self::CHANNEL, $run['report']['projectScope']['unjudgedChannels']);
    }

    #[Test]
    public function itJudgesAroundAGeneratedFileAndWithholdsWhatItCouldDeclare(): void
    {
        file_put_contents($this->root . '/src/Gen.php', "<?php\n// @generated\nnamespace App\\Gen;\nfinal class Gen {}\n");
        $run = $this->check("suppress_namespaces:\n  - {exact: 'App\\Does\\Not\\Exist'}\n  - {exact: 'App\\Gen'}\n");

        self::assertSame(1, $this->staleFindings($run['report']));
        self::assertSame(
            [['channel' => self::CHANNEL, 'option' => 'suppress_namespaces', 'pattern' => 'exact:App\\Gen']],
            $this->unjudgedNamespaceValues($run['report']),
        );
        self::assertStringContainsString('generated-file filtering', $run['stderr']);
    }

    /**
     * @param list<string> $extra
     *
     * @return array{report: array<string, mixed>, stderr: string}
     */
    private function check(string $yaml, array $extra = []): array
    {
        file_put_contents($this->root . '/qmx.yaml', $yaml);
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 5) . '/bin/qmx', 'check', '--working-dir=' . $this->root, '--no-cache', '--workers=0', '--format=json', '--config=' . $this->root . '/qmx.yaml', ...$extra]);
        $process->run();
        $report = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($report, $process->getErrorOutput());

        return ['report' => $report, 'stderr' => $process->getErrorOutput()];
    }

    /** @param array<string, mixed> $report */
    private function staleFindings(array $report, string $channel = self::CHANNEL): int
    {
        $count = 0;
        foreach ($report['violations'] ?? [] as $violation) {
            $count += ($violation['channel'] ?? null) === $channel ? 1 : 0;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<array<string, string>>
     */
    private function unjudgedNamespaceValues(array $report): array
    {
        return array_values(array_filter(
            $report['projectScope']['unjudgedValues'],
            static fn(array $value): bool => str_contains($value['option'], 'suppress_namespace'),
        ));
    }
}
