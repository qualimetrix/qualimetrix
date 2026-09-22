<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Pattern;

/**
 * The three explicit ways a selector can address an open string universe.
 */
enum SelectorKind: string
{
    case Exact = 'exact';
    case Subtree = 'subtree';
    case Regex = 'regex';
}
