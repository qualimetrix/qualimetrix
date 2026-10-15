<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Channel;

/** How a channel participates in a rule publication filter. */
enum ChannelSelectionRole
{
    case Selectable;
    case FollowsAddressedRule;
    case FilterExempt;
}
