<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;

/**
 * The one place that knows **which capabilities declare project-scoped
 * channels**, assembled into the {@see ChannelFileScope} the exclusion filters
 * consult.
 *
 * Each capability owns the fact itself: the channel keys live on the contract
 * that already publishes that capability's producer rule name, and nothing
 * here restates them. What lives here is only the roll-call — the list of
 * capabilities to ask. That is composition knowledge, and it belongs to the
 * composition root of the projection pipeline rather than to
 * {@see \Qualimetrix\Reporting\FindingProjection\FindingProjector}, which
 * orders policy operations and should not also carry an import per capability.
 *
 * It equally does not belong to `Finding`, where {@see ChannelFileScope}
 * lives: a factory there would make `Finding` depend on the capabilities that
 * depend on it, which is the wrong way round. `Finding` states what file scope
 * *means*; the capabilities state which of their channels have it; this class
 * only puts the two together.
 *
 * A channel no capability declares is file-scoped — the safe default for an
 * open-ended vocabulary such as `computed.*`.
 */
final class DeclaredChannelFileScope
{
    public static function create(): ChannelFileScope
    {
        return new ChannelFileScope([
            ...LayerPolicyPreparationInterface::PROJECT_SCOPED_CHANNELS,
            ...CircularDependencyPreparationInterface::PROJECT_SCOPED_CHANNELS,
        ]);
    }
}
