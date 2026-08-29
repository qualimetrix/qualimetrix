<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\FindingChannel;

/**
 * Which channels are **file-scoped** — that is, which findings are about a
 * particular piece of code in a particular file, and can therefore be dropped
 * by `exclude_paths` / `exclude_namespaces`.
 *
 * A project-scoped channel reports on the shape of the project itself: a
 * dependency cycle, a layer boundary, a gap in the declared layers. Those
 * findings do have a location to print, but the location is an example, not
 * the subject — so "I don't want metrics for this directory" is not an answer
 * to them, and letting it silently be one would turn a noisy-metric exclusion
 * into an undocumented way to switch off architecture enforcement.
 *
 * **The scope is declared per channel, never derived from the name.** The
 * exclusion filters used to ask whether the rule name started with
 * `architecture.`; that read a behavioural property out of a naming
 * convention, and it broke the moment selectors stopped matching on prefixes.
 * Each capability now declares its own project-scoped channel keys
 * ({@see \Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface::PROJECT_SCOPED_CHANNELS},
 * {@see \Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface::PROJECT_SCOPED_CHANNELS}),
 * and the composition root hands them here.
 *
 * Anything not declared is file-scoped. That is the safe default for an
 * open-ended vocabulary — a user-defined `computed.*` metric measures code in
 * a file, and excluding that file's namespace should exclude it.
 */
final readonly class ChannelFileScope
{
    /** @var array<string, true> */
    private array $projectScoped;

    /**
     * @param list<string> $projectScopedChannelKeys channel names
     */
    public function __construct(array $projectScopedChannelKeys)
    {
        $this->projectScoped = array_fill_keys($projectScopedChannelKeys, true);
    }

    public function isFileScoped(FindingChannel $channel): bool
    {
        return !isset($this->projectScoped[$channel->code]);
    }
}
