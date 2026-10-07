<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ProjectScopeCoverage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\UnmatchedFrameworkNamespaceRule;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeChannels;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionOptions;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeOptions;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Direct mentions of the two measured question APIs and their judgement type
 * are either registered readers with exact channels or registered carriers.
 * Indirect wrappers and variable provenance are outside this literal scan.
 */
#[CoversClass(ProjectScopeChannels::class)]
final class ProjectScopeReadersTest extends TestCase
{
    private const string PREDICATE = '/judgesNamespaceClaims|judgesExcludeSelectors|ProjectScopeJudgement/';

    /**
     * Every production PHP file naming a measured question or its judgement type,
     * and the channels it judges; an empty list is a carrier or projection.
     *
     * @return array<string, list<string>>
     */
    private static function readers(): array
    {
        return [
            'src/Analysis/Policy/Architecture/LayerDeclaration/LayerDeclarationValidator.php' => [
                ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
                ArchitectureChannels::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
            ],
            'src/Analysis/Policy/Architecture/LayerDeclaration/LayerDeclarationRule.php' => [
                ArchitectureChannels::UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME,
                ArchitectureChannels::UNMATCHED_TYPE_DIAGNOSTIC_NAME,
            ],
            'src/Analysis/Policy/Architecture/ArchitecturePolicy.php' => [],
            'src/Analysis/Policy/Architecture/Contract/UnmatchedTypeWarningInterface.php' => [],
            'src/Analysis/Policy/Architecture/Layer/KnownTypes.php' => [],
            'src/Analysis/Policy/Architecture/LayerDeclaration/UnmatchedTypeDiagnostic.php' => [],
            'src/Analysis/Evidence/Coupling/UnmatchedFrameworkNamespaceRule.php' => [UnmatchedFrameworkNamespaceRule::NAME],
            'src/Analysis/Evidence/Cohesion/LcomExcludedMethods.php' => ['cohesion.unmatched-exclude-method'],
            'src/Analysis/Run/ExcludeBinding/UnmatchedExcludeAudit.php' => [UnmatchedExcludeOptions::CHANNEL],
            'src/Analysis/Run/RuleProducerPreparation.php' => [],
            'src/Analysis/Finding/SuppressionBinding/ValueScopeJudgement.php' => [
                UnboundSuppressionOptions::UNMATCHED_PATH,
                UnboundSuppressionOptions::UNMATCHED_NAMESPACE,
                UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER,
            ],
            'src/Analysis/Finding/Contract/ProjectScope/ProjectScopeJudgement.php' => [],
            'src/Analysis/Finding/Contract/ProjectScope/SubjectCoverageFacts.php' => [],
            'src/Analysis/Finding/Contract/Rule/AnalysisContext.php' => [],
            'src/Analysis/Run/Contract/Configuration/ProjectScopeMeasurement.php' => [],
            'src/Analysis/Run/Contract/Pipeline/MeasuredRunResult.php' => [],
            'src/Reporting/ReportProjectScope.php' => [],
        ];
    }

    #[Test]
    public function itAccountsForEveryProductionFileThatNamesThePredicate(): void
    {
        $root = \dirname(__DIR__, 2);
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php' && preg_match(self::PREDICATE, (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }
        sort($found);

        $declared = array_keys(self::readers());
        sort($declared);

        self::assertNotSame([], $found, 'The sweep found no file at all, so it proves nothing.');
        self::assertSame($declared, $found);
    }

    #[Test]
    public function itListsExactlyTheChannelsTheReadersWithhold(): void
    {
        $channels = array_merge(...array_values(self::readers()));
        sort($channels);

        $declared = ProjectScopeChannels::PROJECT_SCOPED_CHANNELS;
        sort($declared);

        self::assertSame($channels, $declared);
    }
}
