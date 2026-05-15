<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\PathsConfiguration;

/**
 * Fully resolved configuration after pipeline processing.
 *
 * Phase 4.6 (ADR 0008): the `$architecture` field is non-nullable. Production
 * code in {@see ConfigurationPipeline::resolve()} has always populated it with
 * a (possibly empty) {@see ArchitectureConfiguration}; the type now matches
 * that runtime invariant. Tests that construct fixtures explicitly pass
 * {@see ArchitectureConfiguration::empty()}.
 *
 * **Deferred warnings.** {@see $deferredWarnings} carries PSR-3 records that
 * the configuration pipeline produced *before* the user-facing logger was
 * configured. {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator}
 * drains this list to the configured logger after
 * {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator::configureLogger()}
 * runs, ensuring those warnings reach the user instead of being dropped into
 * the placeholder {@see \Psr\Log\NullLogger}.
 */
final readonly class ResolvedConfiguration
{
    /**
     * @param array<string, mixed> $ruleOptions
     * @param array<string, mixed> $computedMetrics
     * @param list<string> $appliedSources Names of configuration sources that contributed values
     * @param list<DeferredWarning> $deferredWarnings PSR-3 records to replay once the user logger is configured
     */
    public function __construct(
        public PathsConfiguration $paths,
        public AnalysisConfiguration $analysis,
        public array $ruleOptions,
        public ArchitectureConfiguration $architecture,
        public array $computedMetrics = [],
        public array $appliedSources = [],
        public array $deferredWarnings = [],
    ) {}
}
