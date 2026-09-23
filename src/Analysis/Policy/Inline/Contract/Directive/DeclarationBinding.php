<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * The declaration a symbol-form directive was bound to, and how far it reaches.
 *
 * The three facts are one fact. A declaration control silences findings on
 * exactly one measured declaration, over exactly the lines that declaration
 * occupies, at exactly one scope — so a {@see Suppression} either has all
 * three or is not a declaration control at all: the physical forms bind to a
 * line and a file instead, and a {@see DirectiveRefusal} binds to nothing.
 * Carried apart, the three were three optional constructor arguments whose
 * only legal combinations were all-or-nothing, and the constructor had to say
 * so in a condition rather than in a type.
 *
 * It lives beside the refusal rather than beside the suppression because the
 * two are the same question answered either way — what extraction could and
 * could not carry out — and because a third concrete type in the suppression
 * vocabulary was measured to push that namespace past its distance threshold.
 */
final readonly class DeclarationBinding
{
    /**
     * @param ?int $endLine the last line of the bound declaration, absent when the
     *                      parser reported no end position for it
     */
    public function __construct(
        public MetricSubject $subject,
        public ControlScope $controlScope,
        public ?int $endLine = null,
    ) {}
}
