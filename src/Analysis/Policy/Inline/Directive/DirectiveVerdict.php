<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Core\Path\RelativePath;

/**
 * One authored directive and what it did.
 *
 * The identity is the authored site, never a binding: a directive written on a
 * class docblock is materialised once per declaration that docblock governs,
 * and those are bindings of one annotation. `$form` keeps the directive's own
 * type rather than a family, because that is what the two existing authored-site
 * keys do — a report that collapses `symbol`, `next-line` and `file` into "an
 * ignore" cannot say which tag to remove.
 */
final readonly class DirectiveVerdict
{
    public function __construct(
        public RelativePath $file,
        public int $line,
        public string $form,
        public string $target,
        public DirectiveEffect $effect,
        public ?DirectiveUnmeasurableReason $reason = null,
    ) {}
}
