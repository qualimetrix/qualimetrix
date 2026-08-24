<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Filter;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Util\NamespaceMatcher;
use Qualimetrix\Reporting\FormatterContext;

/**
 * Filters findings and worst offenders by namespace/class context.
 *
 * Shared between SummaryFormatter and JsonFormatter to avoid duplication.
 *
 * Project-wide findings are excluded from every namespace selection. Their
 * symbol path carries the internal project sentinel where a namespace would
 * be, which a glob selector such as `*` would otherwise capture — selecting a
 * namespace subtree must never surface a finding about the whole project.
 */
final class FindingFilter
{
    /**
     * Filters findings by namespace/class context.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public function filterFindings(array $findings, FormatterContext $context): array
    {
        if ($context->namespace === null && $context->class === null) {
            return $findings;
        }

        return array_values(array_filter($findings, function (Finding $v) use ($context): bool {
            $ns = $v->symbolPath->namespace ?? '';
            $class = $v->symbolPath->type;

            if ($context->namespace !== null) {
                if ($v->symbolPath->getType() === SymbolType::Project) {
                    return false;
                }

                return NamespaceMatcher::matchesSingle($context->namespace, $ns);
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
                if ($offender->symbolPath->getType() === SymbolType::Project) {
                    return false;
                }

                return NamespaceMatcher::matchesSingle($context->namespace, $canonical);
            }

            if ($context->class !== null) {
                return $canonical === $context->class;
            }

            return true;
        }));
    }
}
