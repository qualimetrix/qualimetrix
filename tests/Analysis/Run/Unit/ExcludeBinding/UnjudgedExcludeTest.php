<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\ExcludeBinding;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Run\Contract\Configuration\AuthoredExclude;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Discovery\EntryInspector;
use Qualimetrix\Analysis\Run\Discovery\ProjectWalk;
use Qualimetrix\Analysis\Run\Discovery\WalkedProject;
use Qualimetrix\Analysis\Run\Discovery\WalkRequest;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeOptions;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(ProjectWalk::class)]
#[CoversClass(UnmatchedExcludeAudit::class)]
final class UnjudgedExcludeTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('Root ignores directory permission bits.');
        }
        $this->root = sys_get_temp_dir() . '/qmx-unjudged-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/blocked/Inner', 0o755, true);
        mkdir($this->root . '/Kept', 0o755, true);
        file_put_contents($this->root . '/Kept/File.php', "<?php\n");
        chmod($this->root . '/blocked', 0o000);
    }

    protected function tearDown(): void
    {
        if ($this->root === '' || !is_dir($this->root)) {
            return;
        }
        chmod($this->root . '/blocked', 0o755);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function itNamesTheSelectorItCouldNotJudgeAndTheDirectoryThatStoppedIt(): void
    {
        $walked = $this->walk([$this->selector(SelectorKind::Exact, 'blocked/Inner')]);

        self::assertCount(1, $walked->verdicts);
        self::assertSame('exact:blocked/Inner', $walked->verdicts[0]->display);
        self::assertSame(ExcludeSelectorOutcome::Unjudgeable, $walked->verdicts[0]->outcome);
        self::assertSame('blocked', $walked->verdicts[0]->blockedAt);
    }

    #[Test]
    public function itNamesASelectorHiddenByAnotherExclude(): void
    {
        $walked = $this->walk([
            $this->selector(SelectorKind::Exact, 'Kept/Gone'),
            $this->selector(SelectorKind::Subtree, 'Kept'),
        ]);

        self::assertSame(ExcludeSelectorOutcome::CoveredBySameSource, $walked->verdicts[0]->outcome);
        self::assertSame('subtree:Kept', $walked->verdicts[0]->coveredBy);
    }

    #[Test]
    public function itSaysNothingAboutASelectorThatBound(): void
    {
        $walked = $this->walk([$this->selector(SelectorKind::Regex, '(?:[^/]+/)*Kept')]);

        self::assertSame(ExcludeSelectorOutcome::Removed, $walked->verdicts[0]->outcome);
        self::assertSame([], (new UnmatchedExcludeAudit(new UnmatchedExcludeOptions()))->findings($walked->verdicts, AbsolutePath::fromString($this->root)));
    }

    #[Test]
    public function itReportsTheUnjudgedSelectorAsItsOwnFinding(): void
    {
        $findings = $this->findings($this->selector(SelectorKind::Exact, 'blocked/Inner'));

        self::assertCount(1, $findings);
        self::assertStringContainsString('could not be checked', $findings[0]->message);
        self::assertStringContainsString('exact:blocked/Inner', $findings[0]->message);
        self::assertStringContainsString('blocked', $findings[0]->message);
    }

    #[Test]
    public function itGivesTheUnjudgedVerdictAnIdentityOfItsOwn(): void
    {
        $unjudged = $this->findings($this->selector(SelectorKind::Exact, 'blocked/Inner'));
        $stale = $this->findings($this->selector(SelectorKind::Exact, 'NoSuchDir'));

        self::assertCount(1, $unjudged);
        self::assertCount(1, $stale);
        self::assertNotSame($unjudged[0]->occurrenceKey?->value, $stale[0]->occurrenceKey?->value);
    }

    /** @return list<Finding> */
    private function findings(AuthoredExclude $selector): array
    {
        $walked = $this->walk([$selector]);

        return (new UnmatchedExcludeAudit(new UnmatchedExcludeOptions()))->findings(
            $walked->verdicts,
            AbsolutePath::fromString($this->root),
        );
    }

    /** @param list<AuthoredExclude> $selectors */
    private function walk(array $selectors): WalkedProject
    {
        $root = AbsolutePath::fromString($this->root);
        $universe = new ProjectScopeUniverse($root, true, [], [], [], true, []);
        $run = new RunConfiguration(
            pathExcludes: array_map(static fn(AuthoredExclude $selector): PathPattern => $selector->pattern, $selectors),
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Include,
            projectScope: new ProjectScopeMeasurement($universe, [$root], ProjectScopeState::Covered, []),
            authoredPathExcludes: $selectors,
            autoloadDevPolicy: AutoloadDevPolicy::Exclude,
        );

        return (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($run));
    }

    private function selector(SelectorKind $kind, string $value): AuthoredExclude
    {
        return new AuthoredExclude(
            new PathPattern(new SelectorDefinition($kind, $value)),
            [ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml')],
        );
    }
}
