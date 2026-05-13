<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\PathsConfiguration;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;

/**
 * Fully resolved configuration after pipeline processing.
 *
 * The `$architecture` field is nullable so that existing test fixtures and
 * non-architecture-aware call sites can construct a ResolvedConfiguration
 * without depending on the architecture domain. Production code in
 * {@see ConfigurationPipeline::resolve()} always populates this field with
 * a (possibly empty) {@see ArchitectureConfiguration}; consumers that want
 * a guaranteed non-null value can fall back to an empty configuration when
 * they encounter null.
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
        public array $computedMetrics = [],
        public array $appliedSources = [],
        public ?ArchitectureConfiguration $architecture = null,
        public array $deferredWarnings = [],
    ) {}
}
