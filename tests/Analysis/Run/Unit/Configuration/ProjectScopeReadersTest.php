<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\UnmatchedFrameworkNamespaceRule;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionOptions;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeOptions;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The report names the channels a narrowed run did not judge from
 * {@see ProjectScopeCoverage::WHOLE_PROJECT_CHANNELS}, a list nothing derives:
 * the readers of the predicate are silent when they are silent, and say
 * nothing about it. So the list is held to the readers from outside — every
 * production file that names the predicate is either a reader whose channels
 * are listed or a carrier that judges nothing, and a file that is neither
 * reddens this test by name.
 */
#[CoversClass(ProjectScopeCoverage::class)]
final class ProjectScopeReadersTest extends TestCase
{
    private const string PREDICATE = '/coversProjectScope|pathsCoverProjectScope|ProjectScopeState/';

    /**
     * Every production PHP file naming the predicate, and the channels it
     * withholds on a narrowed run; an empty list is a carrier.
     *
     * @return array<string, list<string>>
     */
    private static function readers(): array
    {
        return [
            'src/Analysis/Policy/Architecture/LayerViolation/LayerDeclarationValidator.php' => [
                LayerPolicyPreparationInterface::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
                LayerPolicyPreparationInterface::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
            ],
            'src/Analysis/Policy/Architecture/LayerViolation/LayerViolationRule.php' => [
                LayerPolicyPreparationInterface::UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME,
            ],
            'src/Analysis/Evidence/Coupling/UnmatchedFrameworkNamespaceRule.php' => [UnmatchedFrameworkNamespaceRule::NAME],
            'src/Analysis/Run/ExcludeBinding/UnmatchedExcludeAudit.php' => [UnmatchedExcludeOptions::CHANNEL],
            'src/Infrastructure/Console/FindingFilterOrchestrator.php' => [
                UnboundSuppressionOptions::UNMATCHED_PATH,
                UnboundSuppressionOptions::UNMATCHED_NAMESPACE,
                UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER,
            ],
            'src/Analysis/Finding/Contract/Rule/AnalysisContext.php' => [],
            'src/Analysis/Policy/Inline/Directive/Audit/ThresholdDirectiveAudit.php' => [],
            'src/Analysis/Run/Configuration/ProjectScopeCoverage.php' => [],
            'src/Analysis/Run/Configuration/ProjectScopeMeasurement.php' => [],
            'src/Analysis/Run/Configuration/ProjectScopeState.php' => [],
            'src/Analysis/Run/Configuration/RunConfigurationResolver.php' => [],
            'src/Analysis/Run/Contract/Configuration/RunConfiguration.php' => [],
            'src/Analysis/Run/Pipeline/AnalysisPipeline.php' => [],
            'src/Infrastructure/Console/CheckScopeResolver.php' => [],
            'src/Infrastructure/Console/Command/CheckCommand.php' => [],
            'src/Infrastructure/Console/ResolvedCheckScope.php' => [],
        ];
    }

    #[Test]
    public function itAccountsForEveryProductionFileThatNamesThePredicate(): void
    {
        $root = \dirname(__DIR__, 5);
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

        self::assertSame($channels, ProjectScopeCoverage::WHOLE_PROJECT_CHANNELS);
    }
}
