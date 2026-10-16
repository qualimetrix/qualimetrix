<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;

final readonly class AnnotationSuppressionResult
{
    /**
     * @param list<Finding> $retained
     * @param list<Finding> $suppressed
     * @param list<DirectiveSite> $suppressors the first directive that actually matched each suppressed finding
     */
    public function __construct(
        public array $retained,
        public array $suppressed,
        private array $suppressors,
    ) {
        if (\count($suppressed) !== \count($suppressors)) {
            throw new LogicException('Suppressed findings and their directive sites must have equal counts');
        }
    }

    public function suppressorOf(Finding $finding): DirectiveSite
    {
        foreach ($this->suppressed as $index => $suppressed) {
            if ($suppressed === $finding) {
                return $this->suppressors[$index];
            }
        }

        throw new LogicException('No annotation suppressor was recorded for this finding identity');
    }
}
