<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\CheckScope;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\CheckScope\ProjectScopePresenter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
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
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            \assert($entry instanceof SplFileInfo);
            @chmod($entry->getPathname(), 0o755);
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
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
        self::assertStringContainsString(
            'Code removed by exclude "exact:src/Removed.php" could not be read in full (too many files, an unreadable file or a link) and may declare the namespaces of 1 suppression value, so it was not judged (' . self::CHANNEL . '). Exclude fewer files (and no unreadable file or link) to judge it.',
            $run['stderr'],
        );
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
        self::assertStringContainsString(
            'Warning: Code removed by the generated-file policy may declare the namespaces of 1 suppression value, so it was not judged (' . self::CHANNEL . '). Pass --include-generated to judge it.',
            $run['stderr'],
        );
    }

    #[Test]
    public function itOffersBothRemediesWhenAnExclusionAndTheGeneratedPolicyRemovedCode(): void
    {
        file_put_contents($this->root . '/src/Gen.php', "<?php\n// @generated\nnamespace App\\Gen;\nfinal class Gen {}\n");
        $run = $this->check("exclude:\n  - {exact: src/Removed.php}\nsuppress_namespaces:\n  - {exact: 'App\\Gen'}\n");

        self::assertStringContainsString(
            'Code removed by exclude "exact:src/Removed.php" and the generated-file policy may declare the namespaces of 1 suppression value, so it was not judged (' . self::CHANNEL . '). Narrow the exclusion and/or pass --include-generated to judge it.',
            $run['stderr'],
        );
    }

    /**
     * An exclusion that removed no PHP is not named as a cause: the stale
     * candidate is the generated file. Removal without PHP evidence cannot be
     * told from removal outside the measured universe, so it is still
     * mentioned, without a name, and neither remedy is promised alone.
     */
    #[Test]
    public function itDoesNotNameAnExclusionThatRemovedNoPhp(): void
    {
        mkdir($this->root . '/src/Docs');
        file_put_contents($this->root . '/src/Docs/notes.md', "# notes\n");
        file_put_contents($this->root . '/src/Gen.php', "<?php\n// @generated\nnamespace App\\Gen;\nfinal class Gen {}\n");
        $run = $this->check("exclude:\n  - {subtree: src/Docs}\nsuppress_namespaces:\n  - {exact: 'App\\Gen'}\n");

        self::assertStringNotContainsString('src/Docs', $run['stderr']);
        self::assertStringContainsString(
            'Code removed by an exclude entry and the generated-file policy may declare the namespaces of 1 suppression value, so it was not judged (' . self::CHANNEL . '). Narrow the exclusion and/or pass --include-generated to judge it.',
            $run['stderr'],
        );
    }

    #[Test]
    public function itNamesTheExclusionThatRemovedPhpBesideOneThatRemovedNone(): void
    {
        mkdir($this->root . '/src/Docs');
        file_put_contents($this->root . '/src/Docs/notes.md', "# notes\n");
        $run = $this->check("exclude:\n  - {exact: src/Removed.php}\n  - {subtree: src/Docs}\nsuppress_namespaces:\n  - {exact: 'App\\Removed'}\n");

        self::assertStringNotContainsString('src/Docs', $run['stderr']);
        self::assertStringContainsString('Code removed by exclude "exact:src/Removed.php" and another exclude entry may declare the namespaces of 1 suppression value', $run['stderr']);
    }

    /**
     * Removed code can only add namespaces, so a value the analysed tree
     * already binds is judged and bound beside an excluded sub-tree, in both
     * channels: neither unjudged nor counted by the warning. Only the value
     * the excluded sub-tree alone may declare stays unjudged.
     */
    #[Test]
    public function itJudgesAValueTheAnalysedTreeBindsBesideAnExcludedSubTree(): void
    {
        mkdir($this->root . '/src/Legacy/Old', 0o777, true);
        file_put_contents($this->root . '/src/Legacy/A.php', "<?php\nnamespace App\\Legacy;\nfinal class A {}\n");
        file_put_contents($this->root . '/src/Legacy/Old/B.php', "<?php\nnamespace App\\Legacy\\Old;\nfinal class B {}\n");

        $run = $this->check(
            "exclude:\n  - {subtree: src/Legacy/Old}\n"
            . "suppress_namespaces:\n  - {subtree: 'App\\Legacy'}\n  - {exact: 'App\\Legacy'}\n  - {exact: 'App\\Legacy\\Old'}\n"
            . "rules:\n  complexity.ccn:\n    suppress_namespaces: [{subtree: 'App\\Legacy'}]\n",
        );

        self::assertSame(0, $this->staleFindings($run['report']));
        self::assertSame(0, $this->staleFindings($run['report'], 'suppression.unmatched-rule-ledger'));
        self::assertSame(
            [['channel' => self::CHANNEL, 'option' => 'suppress_namespaces', 'pattern' => 'exact:App\\Legacy\\Old']],
            $this->unjudgedNamespaceValues($run['report']),
        );
        self::assertNotContains('suppression.unmatched-rule-ledger', $run['report']['projectScope']['unjudgedChannels']);
        self::assertStringContainsString('may declare the namespaces of 1 suppression value, so it was not judged', $run['stderr']);
    }

    /**
     * A file that failed to parse declares nothing in the namespace tree, so a
     * selector written for it is not stale: the value stays unjudged, alone
     * and beside an unrelated exclusion, and the exclusion is not blamed.
     */
    #[Test]
    public function itDoesNotJudgeTheNamespaceOfAFileThatFailedToParse(): void
    {
        file_put_contents($this->root . '/src/Broken.php', "<?php\nnamespace App\\Broken;\nfinal class Broken {\n");
        $runs = [
            'alone' => "suppress_namespaces:\n  - {exact: 'App\\Broken'}\n",
            'beside an unrelated exclusion' => "exclude:\n  - {exact: src/Removed.php}\nsuppress_namespaces:\n  - {exact: 'App\\Broken'}\n",
        ];

        foreach ($runs as $label => $yaml) {
            $run = $this->check($yaml);

            self::assertSame(0, $this->staleFindings($run['report']), $label);
            self::assertSame(
                [['channel' => self::CHANNEL, 'option' => 'suppress_namespaces', 'pattern' => 'exact:App\\Broken']],
                $this->unjudgedNamespaceValues($run['report']),
                $label,
            );
            self::assertContains(self::CHANNEL, $run['report']['projectScope']['unjudgedChannels'], $label);
            self::assertStringNotContainsString('may declare the namespaces', $run['stderr'], $label);
        }
    }

    /**
     * A value served from an unanalysed PSR-4 root stays unjudged whatever
     * the exclusion removed, so the warning neither names nor counts it.
     */
    #[Test]
    public function itDoesNotBlameTheExclusionForAValueAnUnanalysedRootWithholds(): void
    {
        mkdir($this->root . '/tests');
        file_put_contents($this->root . '/tests/KeptTest.php', "<?php\nnamespace App\\Tests;\nfinal class KeptTest {}\n");
        file_put_contents($this->root . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']], 'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/']]], \JSON_THROW_ON_ERROR));

        $alone = $this->check("exclude:\n  - {exact: src/Removed.php}\nsuppress_namespaces:\n  - {exact: 'App\\Tests\\Foo'}\n", ['src']);
        $beside = $this->check("exclude:\n  - {exact: src/Removed.php}\nsuppress_namespaces:\n  - {exact: 'App\\Tests\\Foo'}\n  - {exact: 'App\\Removed'}\n", ['src']);

        self::assertCount(1, $this->unjudgedNamespaceValues($alone['report']));
        self::assertStringNotContainsString('may declare the namespaces', $alone['stderr']);
        self::assertCount(2, $this->unjudgedNamespaceValues($beside['report']));
        self::assertStringContainsString('may declare the namespaces of 1 suppression value, so it was not judged', $beside['stderr']);
    }

    /**
     * An exclude entry outside the measured universe (the development root
     * on `check src tests`) opens no scope door, yet its code still leaves
     * the namespace tree: a selector it may declare stays unjudged and the
     * warning names that entry, while a stale neighbour is still reported.
     */
    #[Test]
    public function itDoesNotJudgeANamespaceRemovedOutsideTheMeasuredUniverse(): void
    {
        mkdir($this->root . '/tests/Legacy', 0o777, true);
        file_put_contents($this->root . '/tests/KeptTest.php', "<?php\nnamespace App\\Tests;\nfinal class KeptTest {}\n");
        file_put_contents($this->root . '/tests/Legacy/OldTest.php', "<?php\nnamespace App\\Tests\\Legacy;\nfinal class OldTest {}\n");
        file_put_contents($this->root . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']], 'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/']]], \JSON_THROW_ON_ERROR));

        $run = $this->check("exclude:\n  - {subtree: tests/Legacy}\nsuppress_namespaces:\n  - {exact: 'App\\Tests\\Legacy'}\n  - {exact: 'App\\Tests\\Gone'}\n", ['src', 'tests']);

        self::assertSame(1, $this->staleFindings($run['report']));
        self::assertSame(
            [['channel' => self::CHANNEL, 'option' => 'suppress_namespaces', 'pattern' => 'exact:App\\Tests\\Legacy']],
            $this->unjudgedNamespaceValues($run['report']),
        );
        self::assertNotContains(self::CHANNEL, $run['report']['projectScope']['unjudgedChannels']);
        self::assertStringContainsString('Code removed by an exclude entry may declare the namespaces of 1 suppression value, so it was not judged (' . self::CHANNEL . '). Narrow the exclusion to judge it.', $run['stderr']);
    }

    #[Test]
    public function itAgreesInNumberAcrossTheWarning(): void
    {
        $one = $this->check("exclude:\n  - {exact: src/Removed.php}\nsuppress_namespaces:\n  - {exact: 'App\\Removed'}\n");
        $two = $this->check("exclude:\n  - {exact: src/Removed.php}\nsuppress_namespaces:\n  - {exact: 'App\\Removed'}\n  - {subtree: 'App\\Removed'}\n");

        self::assertStringContainsString('of 1 suppression value, so it was not judged (' . self::CHANNEL . '). Narrow the exclusion to judge it.', $one['stderr']);
        self::assertStringContainsString('of 2 suppression values, so they were not judged (' . self::CHANNEL . '). Narrow the exclusion to judge them.', $two['stderr']);
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
