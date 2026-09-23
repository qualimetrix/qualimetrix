<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

/**
 * Why an authored `@qmx-` tag was read but not carried out.
 *
 * These are the two ways a directive can fail before its channel is ever
 * consulted — the spelling of the tag, and the place it was written in — and
 * they are named here rather than dropped so that the author hears about them
 * exactly once, on the line they wrote. Everything downstream of extraction
 * judges directives it was handed; a form that never became one is invisible to
 * all of it, which is what made both of these silent for as long as they were
 * discarded here.
 */
enum DirectiveRefusalReason: string
{
    /**
     * No directive grammar matched the tag: a tag name this tool does not
     * read (`@qmx-ignore-lines`), or a known tag written without the channel
     * it requires (`@qmx-ignore` alone).
     */
    case FormNotRecognised = 'form-not-recognised';

    /**
     * The declaration form was written where nothing is measured — above a
     * statement, on a property — so it has no declaration to bind to. The
     * physical forms are bound to a line and a file and are unaffected; this
     * is only about the form that names a symbol.
     */
    case NoDeclarationToBind = 'no-declaration-to-bind';
}
