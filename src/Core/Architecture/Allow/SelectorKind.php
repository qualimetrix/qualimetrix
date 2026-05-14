<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Allow;

/**
 * Three kinds of {@see LayerSelector}, decided per the D4 grammar.
 *
 * - {@see Exact} — bare literal layer name.
 * - {@see Glob} — fnmatch-style wildcard ({@code *}, {@code ?}, {@code [...]}).
 * - {@see Captured} — at least one {@code {var}} placeholder.
 */
enum SelectorKind: string
{
    case Exact = 'exact';

    case Glob = 'glob';

    case Captured = 'captured';
}
