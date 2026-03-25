<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Filter;

use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\WorstOffender;

/**
 * Filters violations and worst offenders by namespace/class context.
 *
 * Shared between SummaryFormatter and JsonFormatter to avoid duplication.
 */
final class ViolationFilter
{
    /**
     * Filters violations by namespace/class context.
     *
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    public function filterViolations(array $violations, FormatterContext $context): array
    {
        if ($context->namespace === null && $context->class === null) {
            return $violations;
        }

        return array_values(array_filter($violations, function (Violation $v) use ($context): bool {
            $ns = $v->symbolPath->namespace ?? '';
            $class = $v->symbolPath->type;

            if ($context->namespace !== null) {
                return $this->matchesNamespace($ns, $context->namespace);
            }

            if ($context->class !== null && $class !== null) {
                $fqcn = $ns !== '' ? $ns . '\\' . $class : $class;

                return $fqcn === $context->class;
            }

            return false;
        }));
    }

    /**
     * Filters worst offenders by namespace/class context.
     *
     * @param list<WorstOffender> $offenders
     *
     * @return list<WorstOffender>
     */
    public function filterWorstOffenders(array $offenders, FormatterContext $context): array
    {
        if ($context->namespace === null && $context->class === null) {
            return $offenders;
        }

        return array_values(array_filter($offenders, function (WorstOffender $offender) use ($context): bool {
            $canonical = $offender->symbolPath->toString();

            if ($context->namespace !== null) {
                return $this->matchesNamespace($canonical, $context->namespace);
            }

            if ($context->class !== null) {
                return $canonical === $context->class;
            }

            return true;
        }));
    }

    /**
     * Boundary-aware namespace prefix match.
     *
     * App\Payment matches App\Payment and App\Payment\Gateway but not App\PaymentGateway.
     */
    private function matchesNamespace(string $subject, string $prefix): bool
    {
        if ($subject === $prefix) {
            return true;
        }

        return str_starts_with($subject, $prefix . '\\');
    }
}
