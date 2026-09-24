<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Suppression;

use InvalidArgumentException;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationBinding;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusal;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Represents a suppression tag from docblock.
 *
 * Example: `@qmx-ignore complexity.ccn -- Reason why it's ignored`
 *
 * `$rule` keeps the authored text; what it actually filters on is
 * {@see SuppressionTarget}, derived from it once here.
 *
 * It also carries the directives that filter **nothing**: a tag the extractor
 * read and refused ({@see DirectiveRefusal}). They travel here because the
 * store, the validator and the audit all read this list, and a form dropped
 * before it reaches them is answered by none of the three.
 */
final readonly class Suppression
{
    /**
     * The token that divides this type's two authored fields.
     *
     * The channel argument and the reason are both bare words, so
     * `@qmx-ignore-file Generated code` is genuinely ambiguous: the first
     * word reads as a channel that addresses nothing, and the author's prose
     * is reported as a configuration error. `--` is how an author says "the
     * reason starts here" — mandatory only where the ambiguity exists, which
     * is the file form's optional channel, and accepted on all three forms so
     * the family reads the same way.
     */
    public const string REASON_SEPARATOR = '--';

    private SuppressionTarget $target;

    /**
     * @param ?DeclarationBinding $binding the measured declaration this directive was bound to;
     *                                     present exactly for a carried-out symbol control
     * @param ?DirectiveRefusal $refusal what the extractor could not carry out here, if anything;
     *                                   a refused directive has no binding, which is what makes it
     *                                   the one case where the symbol form carries none
     */
    public function __construct(
        public string $rule,
        public ?string $reason,
        public int $line,
        public SuppressionType $type,
        public ?DeclarationBinding $binding = null,
        public ?DirectiveRefusal $refusal = null,
    ) {
        $isSymbolControl = $type === SuppressionType::Symbol && $refusal === null;
        if ($isSymbolControl !== ($binding !== null)) {
            throw new InvalidArgumentException('Symbol suppressions require a declaration binding; physical and refused suppressions require none');
        }

        $this->target = SuppressionTarget::fromAnnotation($rule);
    }

    /**
     * The form a report prints this directive under: its type, or for a
     * refused directive the form the refusal names — every refusal shares
     * one type, and two of them on one line are still two directives.
     */
    public function form(): string
    {
        return $this->refusal->form ?? $this->type->value;
    }

    /**
     * One authored directive, whatever it was bound to: the key every reader
     * that counts directives rather than bindings groups by.
     *
     * The refusal reason is part of it because one form can be refused for
     * two reasons on one line — an unbound declaration form and the same tag
     * with no channel — and each is a mistake of its own.
     */
    public function authoredSite(): string
    {
        return implode("\0", [(string) $this->line, $this->form(), $this->rule, $this->refusal->reason->value ?? '']);
    }

    /** What this directive filters on — a channel selector, or nothing at all. */
    public function target(): SuppressionTarget
    {
        return $this->target;
    }

    /**
     * Checks whether this suppression addresses the given channel.
     *
     * The directive addresses a **channel**, by its own name: an exact name,
     * or `X.*` for the strict descendants of `X`. A level is addressed beside
     * the name — `@qmx-ignore coupling.cbo:namespace` silences the namespace
     * aggregate and leaves the class findings of the same channel reported.
     * The one form that filters on nothing is `@qmx-ignore *` (and a bare
     * `@qmx-ignore-file`), see {@see SuppressionTarget}.
     *
     * A refused directive addresses nothing at all: it was read so that it
     * could be reported, not so that it could silence something.
     */
    public function matches(string $code, ?SymbolLevel $level): bool
    {
        return $this->refusal === null && $this->target->matches($code, $level);
    }
}
