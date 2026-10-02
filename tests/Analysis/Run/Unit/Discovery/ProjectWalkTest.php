<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Run\Contract\Configuration\AuthoredExclude;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Discovery\EntryInspector;
use Qualimetrix\Analysis\Run\Discovery\EntryInspectorInterface;
use Qualimetrix\Analysis\Run\Discovery\EntryKind;
use Qualimetrix\Analysis\Run\Discovery\ProjectFiles;
use Qualimetrix\Analysis\Run\Discovery\ProjectWalk;
use Qualimetrix\Analysis\Run\Discovery\WalkRequest;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ProjectWalk::class)]
#[CoversClass(ProjectFiles::class)]
final class ProjectWalkTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-project-walk-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Legacy/Old', 0777, true);
        file_put_contents($this->root . '/src/A.php', '<?php');
        file_put_contents($this->root . '/src/Legacy/Old/O.php', '<?php');
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function itKeepsAnOutsideExcludedDirectoryUnknownWithoutDescendingIntoIt(): void
    {
        $inspector = new class implements EntryInspectorInterface {
            /** @var list<string> */
            public array $listed = [];
            private EntryInspector $delegate;

            public function __construct()
            {
                $this->delegate = new EntryInspector();
            }

            public function inspect(string $path): EntryKind
            {
                return $this->delegate->inspect($path);
            }

            public function list(string $directory): ?array
            {
                $this->listed[] = $directory;

                return $this->delegate->list($directory);
            }
        };
        $walked = (new ProjectWalk($inspector))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src/A.php'],
            [$this->selector(SelectorKind::Subtree, 'src/Legacy', ConfigurationSource::ConfigFile)],
        )));

        self::assertSame([], $walked->facts->missingByPaths, json_encode([
            'hidden' => $walked->facts->hiddenOutsideDirectories,
            'verdicts' => $walked->verdicts,
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(['src/Legacy'], array_map(static fn($path): string => $path->value(), $walked->facts->hiddenOutsideDirectories));
        self::assertTrue($walked->facts->denominatorUnknown());
        self::assertSame(ExcludeSelectorOutcome::Removed, $walked->verdicts[0]->outcome);
        self::assertNotContains($this->root . '/src/Legacy', $inspector->listed);
    }

    #[Test]
    public function itFindsPhpInTheSecondRemovedRunSubtree(): void
    {
        mkdir($this->root . '/src/Legacy/Empty');
        file_put_contents($this->root . '/src/Legacy/Empty/a.txt', 'asset');
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [$this->selector(SelectorKind::Regex, 'src/Legacy/(?:Empty|Old)', ConfigurationSource::ConfigFile)],
        )));

        self::assertSame('php-file', $walked->verdicts[0]->phpEvidence);
    }

    #[Test]
    public function itPrefersOtherSourceForPossibleHiddenRegexMatch(): void
    {
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [
                $this->selector(SelectorKind::Subtree, 'src/Legacy', ConfigurationSource::CommandLine),
                $this->selector(SelectorKind::Regex, 'Nope', ConfigurationSource::ConfigFile),
            ],
        )));

        self::assertSame(ExcludeSelectorOutcome::CoveredByOtherSource, $walked->verdicts[1]->outcome);
        self::assertSame('subtree:src/Legacy', $walked->verdicts[1]->coveredBy);
    }

    #[Test]
    public function itPrefersOtherSourceWhenTheHiddenSelectorHasMixedHistory(): void
    {
        $hider = new AuthoredExclude(
            new PathPattern(new SelectorDefinition(SelectorKind::Subtree, 'src/Legacy')),
            [
                ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'),
                ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--exclude'),
            ],
        );
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [$hider, $this->selector(SelectorKind::Regex, 'Nope', ConfigurationSource::ConfigFile)],
        )));

        self::assertSame(ExcludeSelectorOutcome::CoveredByOtherSource, $walked->verdicts[1]->outcome);
    }

    #[Test]
    public function itKeepsSameSourceWhenHiderAndHiddenSelectorShareMixedHistory(): void
    {
        $sources = [
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'),
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--exclude'),
        ];
        $hider = new AuthoredExclude(
            new PathPattern(new SelectorDefinition(SelectorKind::Subtree, 'src/Legacy')),
            $sources,
        );
        $hidden = new AuthoredExclude(
            new PathPattern(new SelectorDefinition(SelectorKind::Regex, 'Nope')),
            $sources,
        );
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [$hider, $hidden],
        )));

        self::assertSame(ExcludeSelectorOutcome::CoveredBySameSource, $walked->verdicts[1]->outcome);
        self::assertSame('subtree:src/Legacy', $walked->verdicts[1]->coveredBy);
    }

    #[Test]
    public function itBindsAllSelectorsAtTheSameDirectoryBeforePruning(): void
    {
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [
                $this->selector(SelectorKind::Regex, 'src/Legacy', ConfigurationSource::ConfigFile),
                $this->selector(SelectorKind::Subtree, 'src/Legacy', ConfigurationSource::ConfigFile),
            ],
        )));

        self::assertSame(ExcludeSelectorOutcome::Removed, $walked->verdicts[0]->outcome);
        self::assertSame(ExcludeSelectorOutcome::Removed, $walked->verdicts[1]->outcome);
        self::assertSame(['src/Legacy'], $walked->verdicts[0]->removedEntries);
        self::assertSame(['src/Legacy'], $walked->verdicts[1]->removedEntries);
        self::assertSame('php-file', $walked->verdicts[0]->phpEvidence);
        self::assertSame('php-file', $walked->verdicts[1]->phpEvidence);
    }

    #[Test]
    public function itCoversHiddenLiteralFromSameSource(): void
    {
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [
                $this->selector(SelectorKind::Exact, 'src/Legacy/Old', ConfigurationSource::ConfigFile),
                $this->selector(SelectorKind::Subtree, 'src/Legacy', ConfigurationSource::ConfigFile),
            ],
        )));

        self::assertSame(ExcludeSelectorOutcome::CoveredBySameSource, $walked->verdicts[0]->outcome);
        self::assertSame('subtree:src/Legacy', $walked->verdicts[0]->coveredBy);
    }

    #[Test]
    public function itCoversHiddenRegexFromSameSource(): void
    {
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [
                $this->selector(SelectorKind::Regex, 'src/Legacy/.+', ConfigurationSource::ConfigFile),
                $this->selector(SelectorKind::Subtree, 'src/Legacy', ConfigurationSource::ConfigFile),
            ],
        )));

        self::assertSame(ExcludeSelectorOutcome::CoveredBySameSource, $walked->verdicts[0]->outcome);
        self::assertSame('subtree:src/Legacy', $walked->verdicts[0]->coveredBy);
    }

    #[Test]
    public function itJudgesNamedFileRosterAgainstCapturedUniverse(): void
    {
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src/A.php'],
            [$this->selector(SelectorKind::Exact, 'src/Missing', ConfigurationSource::ConfigFile)],
        )));

        self::assertTrue($walked->facts->namedFilesOnly);
        self::assertSame(ExcludeSelectorOutcome::Unmatched, $walked->verdicts[0]->outcome);
        self::assertSame('exact:src/Missing', $walked->verdicts[0]->display);
    }

    #[Test]
    public function itKeepsSiblingPrefixesSeparate(): void
    {
        mkdir($this->root . '/src/LegacyExtra');
        file_put_contents($this->root . '/src/LegacyExtra/Kept.php', '<?php');
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [$this->selector(SelectorKind::Subtree, 'src/Legacy', ConfigurationSource::ConfigFile)],
        )));

        self::assertSame(ExcludeSelectorOutcome::Removed, $walked->verdicts[0]->outcome);
        self::assertContains('src/Legacy', $walked->verdicts[0]->removedEntries);
        self::assertContains('Kept.php', array_map(static fn(SplFileInfo $file): string => $file->getFilename(), $walked->candidates));
    }

    #[Test]
    public function itKeepsAnUnrelatedLiteralUnmatchedBehindAnExcludedDirectory(): void
    {
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [
                $this->selector(SelectorKind::Subtree, 'src/Legacy', ConfigurationSource::ConfigFile),
                $this->selector(SelectorKind::Exact, 'src/Missing', ConfigurationSource::ConfigFile),
            ],
        )));

        self::assertSame(ExcludeSelectorOutcome::Removed, $walked->verdicts[0]->outcome);
        self::assertSame(ExcludeSelectorOutcome::Unmatched, $walked->verdicts[1]->outcome);
    }

    #[Test]
    public function itDoesNotBindAnOutsideProjectPathWithTheSameSuffix(): void
    {
        $outside = sys_get_temp_dir() . '/qmx-outside-selector-' . bin2hex(random_bytes(6));
        mkdir($outside . '/src/OutsideOnly', 0777, true);
        try {
            $run = $this->configuration(
                [$this->root . '/src/A.php'],
                [$this->selector(SelectorKind::Exact, 'src/OutsideOnly', ConfigurationSource::ConfigFile)],
            );
            $universe = new ProjectScopeUniverse(
                $run->projectRoot,
                true,
                [['target' => 'outside', 'path' => AbsolutePath::fromString($outside)]],
                [],
                [],
                true,
                [],
            );
            $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($run->withProjectScope(
                new ProjectScopeMeasurement($universe, $run->paths, ProjectScopeState::Covered, []),
            )));
        } finally {
            rmdir($outside . '/src/OutsideOnly');
            rmdir($outside . '/src');
            rmdir($outside);
        }

        self::assertSame(ExcludeSelectorOutcome::Unmatched, $walked->verdicts[0]->outcome);
    }

    #[Test]
    public function itKeepsAnUnlistableOutsideTargetUnknownWithoutCurrentRunFailure(): void
    {
        $inspector = new class implements EntryInspectorInterface {
            private EntryInspector $delegate;

            public function __construct()
            {
                $this->delegate = new EntryInspector();
            }

            public function inspect(string $path): EntryKind
            {
                return $this->delegate->inspect($path);
            }

            public function list(string $directory): ?array
            {
                return str_ends_with($directory, '/src') ? null : $this->delegate->list($directory);
            }
        };
        $walked = (new ProjectWalk($inspector))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src/A.php'],
            [],
        )));

        self::assertSame([], $walked->skipped);
        self::assertSame(['src'], array_map(static fn($path): string => $path->value(), $walked->facts->unlistableOutside));
        self::assertTrue($walked->facts->denominatorUnknown());
    }

    #[Test]
    public function itKeepsAnUnlistableOutsideProjectRootUnknown(): void
    {
        $inspector = new class implements EntryInspectorInterface {
            private EntryInspector $delegate;

            public function __construct()
            {
                $this->delegate = new EntryInspector();
            }

            public function inspect(string $path): EntryKind
            {
                return $this->delegate->inspect($path);
            }

            public function list(string $directory): ?array
            {
                return str_contains($directory, 'qmx-project-walk-') && !str_contains($directory, '/src')
                    ? null : $this->delegate->list($directory);
            }
        };
        $root = AbsolutePath::fromString($this->root);
        $configuration = $this->configuration([$this->root . '/src/A.php'], []);
        $universe = new ProjectScopeUniverse($root, true, [
            ['target' => '.', 'path' => $root],
        ], [], [], true, []);
        $configuration = $configuration->withProjectScope(new ProjectScopeMeasurement(
            $universe,
            $configuration->paths,
            ProjectScopeState::Covered,
            [],
        ));
        $walked = (new ProjectWalk($inspector))->walk(new WalkRequest($configuration));

        self::assertSame([], $walked->skipped);
        self::assertSame($this->root, $walked->facts->unlistableOutsideRoot?->value());
        self::assertTrue($walked->facts->denominatorUnknown());
    }

    #[Test]
    public function itJudgesEachSelectorAgainstItsOwnInaccessibleLocation(): void
    {
        $inspector = new class implements EntryInspectorInterface {
            private EntryInspector $delegate;

            public function __construct()
            {
                $this->delegate = new EntryInspector();
            }

            public function inspect(string $path): EntryKind
            {
                return $this->delegate->inspect($path);
            }

            public function list(string $directory): ?array
            {
                return str_ends_with($directory, '/Legacy') ? null : $this->delegate->list($directory);
            }
        };
        $walked = (new ProjectWalk($inspector))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src/A.php'],
            [
                $this->selector(SelectorKind::Exact, 'src/Legacy/Inner.php', ConfigurationSource::ConfigFile),
                $this->selector(SelectorKind::Exact, 'src/Missing.php', ConfigurationSource::ConfigFile),
            ],
        )));

        self::assertSame(ExcludeSelectorOutcome::Unjudgeable, $walked->verdicts[0]->outcome);
        self::assertSame('src/Legacy', $walked->verdicts[0]->blockedAt);
        self::assertSame(ExcludeSelectorOutcome::Unmatched, $walked->verdicts[1]->outcome);
    }

    #[Test]
    public function itKeepsASelectorBelowAnUnlistableNamedRootUnjudgeable(): void
    {
        $blocked = $this->root . '/src/Legacy';
        $inspector = new class ($blocked) implements EntryInspectorInterface {
            private EntryInspector $delegate;

            public function __construct(private readonly string $blocked)
            {
                $this->delegate = new EntryInspector();
            }

            public function inspect(string $path): EntryKind
            {
                return $this->delegate->inspect($path);
            }

            public function list(string $directory): ?array
            {
                return $directory === $this->blocked ? null : $this->delegate->list($directory);
            }
        };
        $walked = (new ProjectWalk($inspector))->walk(new WalkRequest($this->configuration(
            [$blocked],
            [$this->selector(SelectorKind::Exact, 'src/Legacy/Inner', ConfigurationSource::ConfigFile)],
        )));

        self::assertSame(ExcludeSelectorOutcome::Unjudgeable, $walked->verdicts[0]->outcome);
        self::assertSame('src/Legacy', $walked->verdicts[0]->blockedAt);
        self::assertSame(AnalysisFailureKind::UnreadableDirectory, $walked->skipped[0]->reason);
    }

    #[Test]
    public function itReportsUnreadableHeaderAsFailureInsteadOfTreatingItAsOrdinary(): void
    {
        $filter = new class implements GeneratedFileFilterInterface {
            public function isGenerated(SplFileInfo $file): ?bool
            {
                return null;
            }
        };
        $result = (new ProjectFiles(new ProjectWalk(new EntryInspector()), $filter))->discover($this->configuration(
            [$this->root . '/src/A.php'],
            [],
        ));

        self::assertSame([], $result->eligibleFiles);
        self::assertCount(1, $result->skippedEntries);
        self::assertSame(AnalysisFailureKind::UnreadableFile, $result->skippedEntries[0]->reason);
    }

    #[Test]
    public function itPrefersASelectedRegularTargetOverANamedFileLinkRegardlessOfOrder(): void
    {
        symlink($this->root . '/src/A.php', $this->root . '/src/Alias.php');
        $filter = new class implements GeneratedFileFilterInterface {
            public function isGenerated(SplFileInfo $file): ?bool
            {
                return false;
            }
        };
        $result = (new ProjectFiles(new ProjectWalk(new EntryInspector()), $filter))->discover($this->configuration(
            [$this->root . '/src/Alias.php', $this->root . '/src/A.php'],
            [],
        ));

        self::assertSame(['A.php'], array_map(static fn(SplFileInfo $file): string => $file->getFilename(), $result->eligibleFiles));
    }

    #[Test]
    public function itKeepsTheFirstNamedLinkWithoutASelectedRegularTargetAndKeepsHardlinksDistinct(): void
    {
        symlink($this->root . '/src/A.php', $this->root . '/src/First.php');
        symlink($this->root . '/src/A.php', $this->root . '/src/Second.php');
        link($this->root . '/src/A.php', $this->root . '/src/Hard.php');
        $filter = new class implements GeneratedFileFilterInterface {
            public function isGenerated(SplFileInfo $file): ?bool
            {
                return false;
            }
        };
        $result = (new ProjectFiles(new ProjectWalk(new EntryInspector()), $filter))->discover($this->configuration(
            [$this->root . '/src/Second.php', $this->root . '/src/First.php', $this->root . '/src/Hard.php'],
            [],
        ));

        self::assertSame(['Second.php', 'Hard.php'], array_map(
            static fn(SplFileInfo $file): string => $file->getFilename(),
            $result->eligibleFiles,
        ));
    }

    #[Test]
    public function itKeepsBothUnresolvedNamedLinksVisibleAsFailures(): void
    {
        symlink($this->root . '/src/Gone.php', $this->root . '/src/First.php');
        symlink($this->root . '/src/Gone.php', $this->root . '/src/Second.php');
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src/First.php', $this->root . '/src/Second.php'],
            [],
        )));

        self::assertSame([], $walked->candidates);
        self::assertSame(['First.php', 'Second.php'], array_map(
            static fn($skip): string => basename($skip->path->value()),
            $walked->skipped,
        ));
    }

    #[Test]
    public function itDistinguishesObservedOutsidePhpFromAnOutsideAsset(): void
    {
        file_put_contents($this->root . '/src/notes.txt', 'asset');
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src/A.php'],
            [],
        )));

        self::assertSame(['src/Legacy/Old/O.php'], array_map(
            static fn($path): string => $path->value(),
            $walked->facts->missingByPaths,
        ));
        self::assertFalse($walked->facts->denominatorUnknown());
    }

    #[Test]
    public function itKeepsAnUnreadableRemovedRunSubtreeAsUncertainPhpEvidence(): void
    {
        $inspector = new class implements EntryInspectorInterface {
            private EntryInspector $delegate;

            public function __construct()
            {
                $this->delegate = new EntryInspector();
            }

            public function inspect(string $path): EntryKind
            {
                return $this->delegate->inspect($path);
            }

            public function list(string $directory): ?array
            {
                return str_ends_with($directory, '/Legacy') ? null : $this->delegate->list($directory);
            }
        };
        $walked = (new ProjectWalk($inspector))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [$this->selector(SelectorKind::Subtree, 'src/Legacy', ConfigurationSource::ConfigFile)],
        )));

        self::assertSame(ExcludeSelectorOutcome::Removed, $walked->verdicts[0]->outcome);
        self::assertSame('unlistable', $walked->verdicts[0]->phpEvidence);
    }

    #[Test]
    public function itRemovesAnAuthoredExcludedFifoBeforeHeaderClassification(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('POSIX named pipes are unavailable.');
        }
        self::assertTrue(posix_mkfifo($this->root . '/src/Pipe.php', 0o600));
        $read = [];
        $filter = $this->createMock(GeneratedFileFilterInterface::class);
        $filter->expects(self::exactly(2))->method('isGenerated')->willReturnCallback(
            static function (SplFileInfo $file) use (&$read): bool {
                $read[] = $file->getFilename();

                return false;
            },
        );
        $result = (new ProjectFiles(new ProjectWalk(new EntryInspector()), $filter))->discover($this->configuration(
            [$this->root . '/src'],
            [$this->selector(SelectorKind::Exact, 'src/Pipe.php', ConfigurationSource::ConfigFile)],
        ));

        self::assertSame(['A.php', 'O.php'], array_map(
            static fn(SplFileInfo $file): string => $file->getFilename(),
            $result->eligibleFiles,
        ));
        self::assertSame(['A.php', 'O.php'], $read);
        self::assertSame([], $result->skippedEntries);
        self::assertSame(ExcludeSelectorOutcome::Removed, $result->selectorVerdicts[0]->outcome);
        self::assertSame(['src/Pipe.php'], $result->selectorVerdicts[0]->removedEntries);
        self::assertNull($result->selectorVerdicts[0]->phpEvidence);
    }

    #[Test]
    public function itRemovesARegularFileMatchingAFileShapedRegexBeforeHeaderClassification(): void
    {
        self::assertSame(EntryKind::RegularFile, (new EntryInspector())->inspect($this->root . '/src/A.php'));
        $read = [];
        $filter = $this->createMock(GeneratedFileFilterInterface::class);
        $filter->expects(self::once())->method('isGenerated')->willReturnCallback(
            static function (SplFileInfo $file) use (&$read): bool {
                $read[] = $file->getFilename();

                return false;
            },
        );
        $result = (new ProjectFiles(new ProjectWalk(new EntryInspector()), $filter))->discover($this->configuration(
            [$this->root . '/src'],
            [$this->selector(SelectorKind::Regex, 'src/A\\.php', ConfigurationSource::ConfigFile)],
        ));

        self::assertSame(['O.php'], array_map(
            static fn(SplFileInfo $file): string => $file->getFilename(),
            $result->eligibleFiles,
        ));
        self::assertSame(['O.php'], $read);
        self::assertSame([], $result->skippedEntries);
        self::assertSame(ExcludeSelectorOutcome::Removed, $result->selectorVerdicts[0]->outcome);
        self::assertSame(['src/A.php'], $result->selectorVerdicts[0]->removedEntries);
        self::assertSame('php-file', $result->selectorVerdicts[0]->phpEvidence);
    }

    #[Test]
    public function itRecordsAChildStatFailureAndKeepsItsSiblingsAndBlockedSelector(): void
    {
        $blocked = $this->root . '/src/Blocked';
        mkdir($blocked);
        file_put_contents($blocked . '/Hidden.php', '<?php');
        $delegate = new EntryInspector();
        $inspector = self::createStub(EntryInspectorInterface::class);
        $inspector->method('inspect')->willReturnCallback(
            static function (string $path) use ($delegate, $blocked): EntryKind {
                self::assertNotSame($blocked . '/Hidden.php', $path);

                return $path === $blocked ? EntryKind::StatFailed : $delegate->inspect($path);
            },
        );
        $inspector->method('list')->willReturnCallback(
            static function (string $directory) use ($delegate, $blocked): ?array {
                self::assertNotSame($blocked, $directory);

                return $delegate->list($directory);
            },
        );
        $walked = (new ProjectWalk($inspector))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [$this->selector(SelectorKind::Exact, 'src/Blocked/Hidden.php', ConfigurationSource::ConfigFile)],
        )));

        self::assertSame(['A.php', 'O.php'], array_map(
            static fn(SplFileInfo $file): string => $file->getFilename(),
            $walked->candidates,
        ));
        self::assertCount(1, $walked->skipped);
        self::assertSame($blocked, $walked->skipped[0]->path->value());
        self::assertSame(AnalysisFailureKind::UnreadableEntry, $walked->skipped[0]->reason);
        self::assertSame(ExcludeSelectorOutcome::Unjudgeable, $walked->verdicts[0]->outcome);
        self::assertSame('src/Blocked', $walked->verdicts[0]->blockedAt);
        self::assertSame([], $walked->verdicts[0]->removedEntries);
    }

    #[Test]
    public function itRefusesAReadableNamedDirectoryWithoutSearchPermissionAndClassifiesItsChild(): void
    {
        if (!\function_exists('posix_geteuid') || posix_geteuid() === 0) {
            self::markTestSkipped('Requires a non-root POSIX user; root bypasses directory search permissions.');
        }
        $locked = $this->root . '/src/ReadOnly';
        mkdir($locked);
        file_put_contents($locked . '/Hidden.php', '<?php');
        try {
            self::assertTrue(chmod($locked, 0o444));
            clearstatcache(true);
            $mode = fileperms($locked);
            self::assertIsInt($mode);
            self::assertSame(0o444, $mode & 0o777);
            $inspector = new EntryInspector();
            self::assertSame(EntryKind::Directory, $inspector->inspect($locked));
            self::assertSame(EntryKind::StatFailed, $inspector->inspect($locked . '/Hidden.php'));
            self::assertNull($inspector->list($locked));
            $walked = (new ProjectWalk($inspector))->walk(new WalkRequest($this->configuration(
                [$locked, $this->root . '/src/A.php'],
                [],
            )));

            self::assertSame(['A.php'], array_map(
                static fn(SplFileInfo $file): string => $file->getFilename(),
                $walked->candidates,
            ));
            self::assertCount(1, $walked->skipped);
            self::assertSame($locked, $walked->skipped[0]->path->value());
            self::assertSame(AnalysisFailureKind::UnreadableDirectory, $walked->skipped[0]->reason);
        } finally {
            chmod($locked, 0o755);
            clearstatcache(true);
        }
    }

    #[Test]
    public function itReportsAWalkedFileLinkAsFailureWhileKeepingTheRegularTarget(): void
    {
        symlink($this->root . '/src/A.php', $this->root . '/src/Linked.php');
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [],
        )));

        self::assertSame(['A.php', 'O.php'], array_map(
            static fn(SplFileInfo $file): string => $file->getFilename(),
            $walked->candidates,
        ));
        self::assertCount(1, $walked->skipped);
        self::assertSame(AnalysisFailureKind::FileSymlink, $walked->skipped[0]->reason);
    }

    #[Test]
    public function itReportsAWalkedPhpNamedPipeWithoutPhpEvidence(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('ext-posix is required to create a FIFO');
        }
        posix_mkfifo($this->root . '/src/Pipe.php', 0644);
        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
            [$this->root . '/src'],
            [],
        )));

        self::assertSame(AnalysisFailureKind::NotRegularFile, $walked->skipped[0]->reason);
        self::assertSame('Pipe.php', basename($walked->skipped[0]->path->value()));
    }

    #[Test]
    public function itRefusesANamedFileLinkToANamedPipeBeforeOpeningIt(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('ext-posix is required to create a FIFO');
        }
        posix_mkfifo($this->root . '/src/Pipe', 0644);
        symlink($this->root . '/src/Pipe', $this->root . '/src/Named.php');
        $configuration = $this->configuration([$this->root . '/src/Named.php'], []);

        $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($configuration));
        self::assertSame([], $walked->candidates);

        $filter = self::createMock(GeneratedFileFilterInterface::class);
        $filter->expects(self::never())->method('isGenerated');
        $files = (new ProjectFiles(new ProjectWalk(new EntryInspector()), $filter))->discover($configuration);
        self::assertSame([], $files->eligibleFiles);
        self::assertCount(1, $files->skippedEntries);
        self::assertSame(AnalysisFailureKind::NotRegularFile, $files->skippedEntries[0]->reason);
    }

    #[Test]
    public function itWalksAnExplicitExternalAliasIntoTheProjectWithoutFollowingChildDirectoryLinks(): void
    {
        $alias = sys_get_temp_dir() . '/qmx-walk-alias-' . bin2hex(random_bytes(6));
        symlink($this->root . '/src', $alias);
        symlink($this->root . '/src/Legacy', $this->root . '/src/ChildLink');
        try {
            $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
                [$alias],
                [],
            )));
        } finally {
            unlink($alias);
        }

        self::assertSame(['A.php', 'O.php'], array_map(
            static fn(SplFileInfo $file): string => $file->getFilename(),
            $walked->candidates,
        ));
        self::assertCount(1, $walked->skipped);
        self::assertSame(AnalysisFailureKind::DirectorySymlink, $walked->skipped[0]->reason);
    }

    #[Test]
    public function itRecordsANamedDirectoryAliasRemovedByAnExcludeAsHidden(): void
    {
        mkdir($this->root . '/vendor/acme', 0777, true);
        file_put_contents($this->root . '/vendor/acme/Named.php', '<?php');
        $alias = sys_get_temp_dir() . '/qmx-removed-alias-' . bin2hex(random_bytes(6));
        symlink($this->root . '/vendor/acme', $alias);
        try {
            $run = $this->configuration(
                [$alias],
                [
                    $this->selector(SelectorKind::Subtree, 'vendor/acme', ConfigurationSource::ConfigFile),
                    $this->selector(SelectorKind::Regex, 'Nope', ConfigurationSource::ConfigFile),
                ],
            );
            $universe = new ProjectScopeUniverse(
                $run->projectRoot,
                true,
                [['target' => 'vendor/acme', 'path' => AbsolutePath::fromString($this->root . '/vendor/acme')]],
                [],
                [],
                true,
                [],
            );
            $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($run->withProjectScope(
                new ProjectScopeMeasurement($universe, $run->paths, ProjectScopeState::Covered, []),
            )));
        } finally {
            unlink($alias);
        }

        self::assertSame(ExcludeSelectorOutcome::Removed, $walked->verdicts[0]->outcome);
        self::assertSame(ExcludeSelectorOutcome::CoveredBySameSource, $walked->verdicts[1]->outcome);
        self::assertSame('subtree:vendor/acme', $walked->verdicts[1]->coveredBy);
    }

    #[Test]
    public function itPublishesWalkedDirectoryLinksToTheRootAndOutsideByTheirWrittenNames(): void
    {
        $outside = sys_get_temp_dir() . '/qmx-walk-outside-' . bin2hex(random_bytes(6));
        mkdir($outside);
        symlink($this->root, $this->root . '/src/RootLink');
        symlink($outside, $this->root . '/src/OutsideLink');
        try {
            $walked = (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
                [$this->root . '/src'],
                [],
            )));
        } finally {
            rmdir($outside);
        }

        self::assertSame(['src/OutsideLink', 'src/RootLink'], array_map(
            fn($skip): string => substr($skip->path->value(), \strlen($this->root) + 1),
            $walked->skipped,
        ));
        self::assertSame(
            [AnalysisFailureKind::DirectorySymlink, AnalysisFailureKind::DirectorySymlink],
            array_map(static fn($skip): AnalysisFailureKind => $skip->reason, $walked->skipped),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideNamedReservedRoots(): iterable
    {
        yield 'vendor' => ['vendor', 'vendor'];
        yield 'nested vendor' => ['lib/vendor', 'lib/vendor'];
        yield 'trailing slash' => ['lib/vendor/', 'lib/vendor'];
        yield 'node_modules' => ['node_modules', 'node_modules'];
        yield '.git' => ['.git', '.git'];
    }

    #[Test]
    #[DataProvider('provideNamedReservedRoots')]
    public function itRefusesEveryNamedReservedDirectoryBeforeWalking(string $written, string $shown): void
    {
        mkdir($this->root . '/' . $shown, 0777, true);

        try {
            (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
                [$this->root . '/' . $written],
                [],
            )));
            self::fail('A named reserved directory must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringStartsWith('"' . $shown . '" is a vendor, node_modules or .git directory', $refusal->summary());
        }
    }

    #[Test]
    public function itRefusesMultipleReservedRootsBeforeReturningASelectedSibling(): void
    {
        mkdir($this->root . '/lib/vendor', 0777, true);
        mkdir($this->root . '/node_modules', 0777, true);

        try {
            (new ProjectWalk(new EntryInspector()))->walk(new WalkRequest($this->configuration(
                [$this->root . '/src/A.php', $this->root . '/lib/vendor', $this->root . '/node_modules'],
                [],
            )));
            self::fail('The complete run must refuse before returning its first selected file.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringStartsWith(
                '"lib/vendor", "node_modules" are vendor, node_modules or .git directories',
                $refusal->summary(),
            );
        }
    }

    #[Test]
    public function itWalksNamedEntriesInsideAReservedAncestor(): void
    {
        mkdir($this->root . '/vendor/acme', 0777, true);
        file_put_contents($this->root . '/vendor/acme/Named.php', '<?php');
        $walker = new ProjectWalk(new EntryInspector());

        foreach ([$this->root . '/vendor/acme', $this->root . '/vendor/acme/Named.php'] as $path) {
            $walked = $walker->walk(new WalkRequest($this->configuration([$path], [])));
            self::assertSame(['Named.php'], array_map(
                static fn(SplFileInfo $file): string => $file->getFilename(),
                $walked->candidates,
            ));
        }
    }

    /** @param list<string> $paths
     * @param list<AuthoredExclude> $selectors
     */
    private function configuration(array $paths, array $selectors): RunConfiguration
    {
        $root = AbsolutePath::fromString($this->root);
        $absolutePaths = array_map(AbsolutePath::fromString(...), $paths);
        $universe = new ProjectScopeUniverse($root, true, [
            ['target' => 'src', 'path' => AbsolutePath::fromString($this->root . '/src')],
        ], [], [], true, []);

        return new RunConfiguration(
            pathExcludes: array_map(static fn(AuthoredExclude $selector): PathPattern => $selector->pattern, $selectors),
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: new ProjectScopeMeasurement($universe, $absolutePaths, ProjectScopeState::Covered, []),
            authoredPathExcludes: $selectors,
            autoloadDevPolicy: AutoloadDevPolicy::Exclude,
        );
    }

    private function selector(SelectorKind $kind, string $value, ConfigurationSource $source): AuthoredExclude
    {
        return new AuthoredExclude(
            new PathPattern(new SelectorDefinition($kind, $value)),
            [ConfigurationOrigin::of($source, $source === ConfigurationSource::CommandLine ? '--exclude' : 'qmx.yaml')],
        );
    }
}
