<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
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
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(ProjectWalk::class)]
#[CoversClass(RunConfigurationResolver::class)]
final class ExcludeSelectorBindingTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-exclude-probe-' . bin2hex(random_bytes(6));
        foreach (['Kept', 'Legacy/Deep', 'nested/Legacy/Inner', 'vendor/acme', '7/Seven'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o755, true);
            file_put_contents($this->root . '/' . $directory . '/File.php', "<?php\n");
        }
    }

    protected function tearDown(): void
    {
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
    public function itUsesProjectRelativeSelectorSemantics(): void
    {
        $walked = $this->walk([
            $this->selector(SelectorKind::Exact, 'Legacy/Deep'),
            $this->selector(SelectorKind::Subtree, 'nested/Legacy'),
            $this->selector(SelectorKind::Regex, '(?:[^/]+/)*Inner'),
        ]);

        self::assertSame([
            ExcludeSelectorOutcome::Removed,
            ExcludeSelectorOutcome::Removed,
            ExcludeSelectorOutcome::CoveredBySameSource,
        ], array_map(static fn($verdict): ExcludeSelectorOutcome => $verdict->outcome, $walked->verdicts));
    }

    #[Test]
    public function itDoesNotRestoreFindersImplicitBasenameAtAnyDepthRule(): void
    {
        $walked = $this->walk([$this->selector(SelectorKind::Exact, 'Deep')]);

        self::assertSame('exact:Deep', $walked->verdicts[0]->display);
        self::assertSame(ExcludeSelectorOutcome::Unmatched, $walked->verdicts[0]->outcome);
    }

    #[Test]
    public function itReportsEachDistinctUnmatchedAuthoredSelectorOnce(): void
    {
        $run = (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerManifestReader())))->resolve(
            LayeredDocument::of([
                ['source' => 'qmx.yaml', 'values' => [
                    'paths' => ['.'],
                    'excludes' => [['exact' => 'Missing'], ['subtree' => 'AlsoMissing']],
                ]],
                ['source' => 'cli', 'values' => [
                    'excludes' => [['exact' => 'Missing']],
                ]],
            ], AbsolutePath::fromString($this->root)),
        );
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($run));

        self::assertSame(['exact:Missing', 'subtree:AlsoMissing'], array_map(static fn($verdict): string => $verdict->display, $walked->verdicts));
        self::assertSame([ExcludeSelectorOutcome::Unmatched, ExcludeSelectorOutcome::Unmatched], array_map(static fn($verdict): ExcludeSelectorOutcome => $verdict->outcome, $walked->verdicts));
        self::assertSame([ConfigurationSource::ConfigFile, ConfigurationSource::CommandLine], array_map(
            static fn(ConfigurationOrigin $origin): ConfigurationSource => $origin->source(),
            $walked->verdicts[0]->sources,
        ));
    }

    #[Test]
    public function itBindsASelectorToTheDirectoryThatPrunesIt(): void
    {
        $walked = $this->walk([$this->selector(SelectorKind::Subtree, 'vendor')]);

        self::assertSame(ExcludeSelectorOutcome::Removed, $walked->verdicts[0]->outcome);
        self::assertContains('vendor', $walked->verdicts[0]->removedEntries);
        self::assertNull($walked->verdicts[0]->phpEvidence);
    }

    #[Test]
    public function itCoversALiteralSelectorBelowAnExcludedParent(): void
    {
        $walked = $this->walk([
            $this->selector(SelectorKind::Exact, 'Legacy/Deep'),
            $this->selector(SelectorKind::Subtree, 'Legacy'),
        ]);

        self::assertSame(ExcludeSelectorOutcome::CoveredBySameSource, $walked->verdicts[0]->outcome);
        self::assertSame('subtree:Legacy', $walked->verdicts[0]->coveredBy);
    }

    #[Test]
    public function itCoversARegexWhenAnExcludedSubtreeCouldContainAMatch(): void
    {
        $walked = $this->walk([
            $this->selector(SelectorKind::Regex, 'missing/.+'),
            $this->selector(SelectorKind::Subtree, 'Legacy'),
        ]);

        self::assertSame(ExcludeSelectorOutcome::CoveredBySameSource, $walked->verdicts[0]->outcome);
    }

    #[Test]
    public function itStillReportsAnUnmatchedLiteralOutsideThePrunedParent(): void
    {
        $walked = $this->walk([
            $this->selector(SelectorKind::Exact, 'missing'),
            $this->selector(SelectorKind::Subtree, 'Legacy'),
        ]);

        self::assertSame(ExcludeSelectorOutcome::Unmatched, $walked->verdicts[0]->outcome);
        self::assertSame('exact:missing', $walked->verdicts[0]->display);
    }

    #[Test]
    public function itJudgesWhenNoRunRootIsADirectory(): void
    {
        $walked = $this->walk(
            [$this->selector(SelectorKind::Exact, 'Missing')],
            [$this->root . '/Kept/File.php'],
        );

        self::assertTrue($walked->facts->namedFilesOnly);
        self::assertSame(ExcludeSelectorOutcome::Unmatched, $walked->verdicts[0]->outcome);
    }

    #[Test]
    public function itDoesNotDuplicateAnAnswerAcrossOverlappingRoots(): void
    {
        $walked = $this->walk(
            [$this->selector(SelectorKind::Exact, 'Missing')],
            [$this->root, $this->root . '/Legacy', $this->root],
        );

        self::assertCount(1, $walked->verdicts);
        self::assertSame(ExcludeSelectorOutcome::Unmatched, $walked->verdicts[0]->outcome);
    }

    #[Test]
    public function itKeepsASelectorBelowAnUnlistableSubtreeUnjudgeable(): void
    {
        $this->withUnlistable('blocked', function (): void {
            $walked = $this->walk([$this->selector(SelectorKind::Exact, 'blocked/Inner')]);
            self::assertSame(ExcludeSelectorOutcome::Unjudgeable, $walked->verdicts[0]->outcome);
            self::assertSame('blocked', $walked->verdicts[0]->blockedAt);
        });
    }

    #[Test]
    public function itStillReportsAnUnmatchedSelectorOutsideTheUnlistableSubtree(): void
    {
        $this->withUnlistable('blocked', function (): void {
            $walked = $this->walk([$this->selector(SelectorKind::Exact, 'Missing')]);
            self::assertSame(ExcludeSelectorOutcome::Unmatched, $walked->verdicts[0]->outcome);
        });
    }

    #[Test]
    public function itAnswersWhenTheScannedRootItselfIsUnlistable(): void
    {
        $this->withUnlistable('blocked', function (): void {
            $walked = $this->walk(
                [$this->selector(SelectorKind::Exact, 'blocked/Inner')],
                [$this->root . '/blocked'],
            );
            self::assertSame(ExcludeSelectorOutcome::Unjudgeable, $walked->verdicts[0]->outcome);
            self::assertSame('blocked', $walked->verdicts[0]->blockedAt);
        });
    }

    private function withUnlistable(string $relative, callable $body): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('Root ignores directory permission bits.');
        }
        $path = $this->root . '/' . $relative;
        mkdir($path . '/Inner', 0o755, true);
        chmod($path, 0o000);
        try {
            $body();
        } finally {
            chmod($path, 0o755);
        }
    }

    /** @param list<AuthoredExclude> $selectors
     * @param list<string>|null $paths
     */
    private function walk(array $selectors, ?array $paths = null): WalkedProject
    {
        $root = AbsolutePath::fromString($this->root);
        $absolutePaths = array_map(AbsolutePath::fromString(...), $paths ?? [$this->root]);
        $universe = new ProjectScopeUniverse($root, true, [['target' => '.', 'path' => $root]], [], [], true, []);
        $run = new RunConfiguration(
            pathExcludes: array_map(static fn(AuthoredExclude $selector): PathPattern => $selector->pattern, $selectors),
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Include,
            projectScope: new ProjectScopeMeasurement($universe, $absolutePaths, ProjectScopeState::Covered, []),
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
