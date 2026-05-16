<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Lifecycle;

use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;

/**
 * Feature-side lifecycle hook invoked by {@code RuntimeConfigurator} during
 * per-run setup.
 *
 * Vertical-slice features that hold per-run state (e.g. an Architecture
 * processor binding the user's layer policy) implement this contract and
 * register an autowired hook in their slice configurator. The runtime
 * configurator collects all tagged hooks via {@code !tagged_iterator
 * qmx.analysis.lifecycle_hook} and replays them once configuration has been
 * resolved — Infrastructure stays feature-agnostic and the slice owns the
 * "what gets reset / re-bound between runs" decision.
 *
 * Hooks must be idempotent: the configurator may invoke them multiple times
 * within a single process (e.g. {@code check} followed by
 * {@code debug:layer-assignment} in the same container).
 */
interface AnalysisLifecycleHookInterface
{
    /**
     * Resets any per-run state and applies the resolved configuration so the
     * feature is ready for the upcoming analysis pass.
     */
    public function applyResolvedConfiguration(ResolvedConfiguration $resolved): void;
}
