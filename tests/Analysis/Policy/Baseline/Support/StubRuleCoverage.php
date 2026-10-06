<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Support;

use LogicException;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\OptionActivity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunRuleCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeSnapshot;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;

/**
 * A {@see RunRuleCoverage} with known selection decisions and the current
 * channel universe, including each channel's actual producer and levels.
 *
 * The baseline tests are about what a command says once it knows which rules
 * ran, not about how the run decided it; that decision belongs to rule
 * execution and is exercised there.
 */
final class StubRuleCoverage
{
    private function __construct() {}

    public static function everyRuleRan(): RunRuleCoverage
    {
        return self::withSkipped();
    }

    /**
     * @param list<string> $notSelected producers a selector left out of the run
     * @param list<string> $disabledEverywhere producers configuration switched off at every level
     */
    public static function withSkipped(array $notSelected = [], array $disabledEverywhere = []): RunRuleCoverage
    {
        return new RunRuleCoverage(
            self::execution($notSelected, $disabledEverywhere),
            self::universe(),
        );
    }

    /**
     * @param list<string> $notSelected
     * @param list<string> $disabledEverywhere
     */
    private static function execution(array $notSelected, array $disabledEverywhere): RuleExecutionInterface
    {
        $decisions = [];
        $universe = self::universe();
        foreach ($universe->channels() as $channel) {
            $producer = $universe->producerOf($channel->code);
            if ($producer === null) {
                continue;
            }
            foreach (SymbolLevel::cases() as $level) {
                $decisions[] = new EnablementDecision(
                    new SelectionCellAddress($producer, $channel, $level, ChannelSelectionRole::Selectable),
                    new AuthoredCellDecision(
                        CellSwitch::On,
                        \in_array($producer, $notSelected, true) ? CellAdmission::Filtered : CellAdmission::Direct,
                    ),
                    new OptionActivity(!\in_array($producer, $disabledEverywhere, true)),
                );
            }
        }
        $enablement = new RuleEnablement($decisions, null);

        return new readonly class ($notSelected, $disabledEverywhere, $enablement) implements RuleExecutionInterface {
            /**
             * @param list<string> $notSelected
             * @param list<string> $disabledEverywhere
             */
            public function __construct(private array $notSelected, private array $disabledEverywhere, private RuleEnablement $enablement) {}

            public function execute(AnalysisContext $context, ?string $restrictToProducer = null): RuleExecutionResult
            {
                throw new LogicException('A coverage stub executes nothing.');
            }

            public function publishable(array $findings): array
            {
                return $findings;
            }

            public function publication(): ChannelPublication
            {
                return new ChannelPublication($this->enablement);
            }

            public function allRules(): array
            {
                return array_map(
                    static fn(string $name): RuleMetadata => new RuleMetadata($name, ComplexityOptions::class, '', [], false),
                    $this->notSelected,
                );
            }

            public function levelActivity(): LevelActivity
            {
                return LevelActivity::fromMap(array_fill_keys($this->disabledEverywhere, ['callable' => false]));
            }
        };
    }

    private static function universe(): ChannelUniverseInterface
    {
        static $universe = null;
        if ($universe === null) {
            $factory = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
            \assert($factory instanceof RuleChannelSnapshotFactoryInterface);
            $universe = $factory->snapshot(new ResolvedComputedMetricDefinitions([]));
        }
        return $universe;
    }
    /**
     * @param list<string> $additionalFiles
     */
    public static function completeFor(Baseline $baseline, array $additionalFiles = [], ?RunScope $scope = null, ?AnalysisCoverage $analysis = null): RunCoverage
    {
        $files = [];
        foreach (['src/Foo.php', ...$additionalFiles] as $path) {
            $files[$path] = RelativePath::fromString($path);
        }
        foreach ($baseline->entries as $entry) {
            $subject = $entry->identity->subjectKey;
            if (str_starts_with($subject, 'file:')) {
                $path = substr($subject, 5);
                $files[$path] = RelativePath::fromString($path);
            } elseif (str_starts_with($subject, 'declaration:') && str_contains($subject, '@')) {
                $path = explode('#', explode('@', $subject, 2)[1], 2)[0];
                $files[$path] = RelativePath::fromString($path);
            }
        }
        $root = AbsolutePath::fromString('/tmp/qmx-ceiling-fixture');
        $tree = new class (array_values($files)) implements ProjectTreeQueryInterface {
            /** @param list<RelativePath> $files */
            public function __construct(private array $files) {}

            public function snapshot(ProjectScopeUniverse $universe): ProjectTreeSnapshot
            {
                return new ProjectTreeSnapshot($this->files, [], true);
            }

            public function hasFile(AbsolutePath $root, RelativePath $file): ProjectEntryPresence
            {
                foreach ($this->files as $present) {
                    if ($present->equals($file)) {
                        return ProjectEntryPresence::Present;
                    }
                }

                return ProjectEntryPresence::Absent;
            }

            public function hasDirectory(AbsolutePath $directory): ProjectEntryPresence
            {
                return ProjectEntryPresence::Present;
            }
        };

        return new RunCoverage(
            $scope ?? RunScope::fromRecorded($baseline->scope),
            $analysis ?? new AnalysisCoverage(array_values($files), [], []),
            $baseline->exclusions,
            new ProjectScopeUniverse(
                $root,
                false,
                [['target' => 'src', 'path' => $root->joinRelative(RelativePath::fromString('src'))]],
                [],
                [],
                true,
                [],
            ),
            ['App\\' => ['src/']],
            $tree,
            \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(
                new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(),
                ($analysis ?? new AnalysisCoverage(array_values($files), [], []))->analyzedFiles,
                [],
            ),
        );
    }
}
