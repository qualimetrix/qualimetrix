<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

/**
 * Why an authored `@qmx-` tag was read but not carried out.
 *
 * These are the ways a directive can fail before its channel is ever
 * consulted — the spelling of the tag, its missing argument, the comment it
 * was written in, and the place it was written at — and they are named here
 * rather than dropped so that the author hears about them exactly once, on
 * the line they wrote. Everything downstream of extraction judges directives
 * it was handed; a form that never became one is invisible to all of it,
 * which is what made each of these silent for as long as they were discarded.
 */
enum DirectiveRefusalReason: string
{
    /** The tag name is not one this tool reads (`@qmx-ignore-lines`). */
    case FormNotRecognised = 'form-not-recognised';

    /**
     * A tag this tool reads, written without the argument it requires:
     * `@qmx-ignore` or `@qmx-ignore-next-line` with no channel on the tag's
     * line, `@qmx-threshold` with no rule.
     */
    case NamesNoTarget = 'names-no-target';

    /**
     * A declaration form written where nothing it can act on is measured —
     * above a statement, on a property without hooks. The physical forms are
     * bound to a line and a file and are unaffected.
     */
    case NoDeclarationToBind = 'no-declaration-to-bind';

    /** `@qmx-threshold` written in a line or block comment; only a docblock carries it. */
    case ThresholdOutsideDocblock = 'threshold-outside-docblock';
}
