<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\DrillDown;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingNamespace;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\FormatterContext;

/**
 * What a `--namespace` or `--class` value selects from a report's findings and
 * worst offenders.
 *
 * `DrillDownBinding` counts what such a value binds to before a report is
 * rendered, and has to compare the same way this filters: a value accepted
 * there and matching nothing here produces the empty report that refusal
 * exists to explain.
 *
 * File findings match any namespace declared in their physical file; without
 * declarations or a repository they use the global namespace. Class selection
 * keeps file aggregates outside the selection.
 *
 * Project-wide findings are excluded from every namespace selection. Their
 * symbol path carries the internal project sentinel where a namespace would
 * be, which a regex selector such as `.*` would otherwise capture — selecting a
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
    public function filterFindings(array $findings, FormatterContext $context, ?FileNamespaceIndex $fileNamespaces = null): array
    {
        if ($context->namespace === null && $context->class === null) {
            return $findings;
        }

        return array_values(array_filter($findings, function (Finding $v) use ($context, $fileNamespaces): bool {
            $ns = FindingNamespace::declared($v);
            $class = $v->symbolPath->type;

            if ($context->namespace !== null) {
                if ($v->symbolPath->getType() === SymbolType::Project) {
                    return false;
                }

                if ($ns !== null) {
                    return $context->namespace->matches($ns);
                }
                if ($v->subject->toSymbolPath()->getType() !== SymbolType::File) {
                    return false;
                }

                $namespaces = $v->location->file !== null
                    ? ($fileNamespaces?->namespacesOf($v->location->file) ?? [])
                    : [];
                foreach ($namespaces !== [] ? $namespaces : [''] as $namespace) {
                    if ($context->namespace->matches($namespace)) {
                        return true;
                    }
                }

                return false;
            }

            if ($context->class !== null && $class !== null) {
                $fqcn = $ns !== null && $ns !== '' ? $ns . '\\' . $class : $class;

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

                return $context->namespace->matches($canonical);
            }

            if ($context->class !== null) {
                return $canonical === $context->class;
            }

            return true;
        }));
    }
}
