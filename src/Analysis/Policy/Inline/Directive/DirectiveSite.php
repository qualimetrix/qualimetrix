<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Core\Path\RelativePath;

/**
 * One authored directive, as the author wrote it.
 *
 * The identity of a directive is never a binding: an annotation on a class
 * docblock is materialised once per declaration that docblock governs, and
 * those are bindings of one annotation. What identifies it is where it was
 * written, which tag it is, and what it addresses — the same four facts the
 * three grouping keys in this capability already agree on, named once instead
 * of spelled out at every place that carries them.
 *
 * `$form` keeps the directive's own type rather than a family, because that is
 * what those keys do: a report that collapses `symbol`, `next-line` and `file`
 * into "an ignore" cannot say which tag to remove.
 */
final readonly class DirectiveSite
{
    /**
     * @param string $form the tag as authored — a suppression type, or `threshold`
     * @param string $target the selector as authored: a channel for a suppression, a rule
     *                       name for a threshold
     */
    public function __construct(
        public RelativePath $file,
        public int $line,
        public string $form,
        public string $target,
    ) {}
}
