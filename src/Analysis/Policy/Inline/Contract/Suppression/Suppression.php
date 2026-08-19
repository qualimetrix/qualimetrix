<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Suppression;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Represents a suppression tag from docblock.
 *
 * Example: `@qmx-ignore complexity.cyclomatic.callable Reason why it's ignored`
 *
 * `$rule` keeps the authored text; what it actually filters on is
 * {@see SuppressionTarget}, derived from it once here.
 */
final readonly class Suppression
{
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
     * Checks whether this suppression addresses the given violation code.
     *
     * The directive addresses a **channel**, fully qualified: an exact
     * `violationCode`, or `X.*` for its strict descendants. A rule name is not
     * a channel — `@qmx-ignore coupling.instability` no longer covers
     * `coupling.instability.class`. The one form that filters on nothing is
     * `@qmx-ignore *` (and a bare `@qmx-ignore-file`), see
     * {@see SuppressionTarget}.
     */
    public function matches(string $violationCode): bool
    {
        return $this->target->matches($violationCode);
    }
}
