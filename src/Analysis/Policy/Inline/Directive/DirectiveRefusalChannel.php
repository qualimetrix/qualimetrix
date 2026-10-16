<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;

enum DirectiveRefusalChannel: string
{
    case Unresolved = InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME;
    case Unsupported = InlineDirectivePolicyInterface::UNSUPPORTED_THRESHOLD_NAME;
    case Invalid = InlineDirectivePolicyInterface::INVALID_THRESHOLD_NAME;
}
