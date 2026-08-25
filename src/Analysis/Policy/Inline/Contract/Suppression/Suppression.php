<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Suppression;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Represents a suppression tag from docblock.
 *
 * Example: `@qmx-ignore complexity.cyclomatic -- Reason why it's ignored`
 *
 * `$rule` keeps the authored text; what it actually filters on is
 * {@see SuppressionTarget}, derived from it once here.
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

    public function __construct(
        public string $rule,
        public ?string $reason,
        public int $line,
        public SuppressionType $type,
        public ?int $endLine = null,
        public ?MetricSubject $subject = null,
        public ?ControlScope $controlScope = null,
    ) {
        $isSymbolControl = $type === SuppressionType::Symbol;
        if ($isSymbolControl !== ($subject !== null) || $isSymbolControl !== ($controlScope !== null)) {
            throw new InvalidArgumentException('Symbol suppressions require a subject and control scope; physical suppressions require neither');
        }

        $this->target = SuppressionTarget::fromAnnotation($rule);
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
     */
    public function matches(string $code, ?SymbolLevel $level): bool
    {
        return $this->target->matches($code, $level);
    }
}
