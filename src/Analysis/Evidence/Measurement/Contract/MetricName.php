<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

/**
 * Canonical metric name constants shared between collectors and rules.
 *
 * Both Metrics and Rules layers depend on Core, so placing metric names
 * here avoids cross-layer dependencies between Metrics and Rules.
 *
 * Constants follow the naming pattern: CATEGORY_METRIC (e.g., COMPLEXITY_CCN).
 * Values match the metric key strings used in MetricBag.
 *
 * @qmx-threshold coupling.cbo 65 -- Canonical names are an intentional Measurement contract hub; current raw CBO 64 gets one-edge headroom. It rose from 62 when design.type-coverage became three rules, each naming its own dimension's metrics.
 */
final class MetricName
{
    // -- Complexity ----------------------------------------------------------

    public const string COMPLEXITY_CCN = 'complexity.ccn';
    public const string COMPLEXITY_COGNITIVE = 'complexity.cognitive';
    public const string COMPLEXITY_NPATH = 'complexity.npath';

    // -- Coupling ------------------------------------------------------------

    public const string COUPLING_CA = 'coupling.ca';
    public const string COUPLING_CE = 'coupling.ce';
    public const string COUPLING_CBO = 'coupling.cbo';
    public const string COUPLING_INSTABILITY = 'coupling.instability';
    public const string COUPLING_ABSTRACTNESS = 'coupling.abstractness';
    public const string COUPLING_DISTANCE = 'coupling.distance';
    public const string COUPLING_CLASS_RANK = 'coupling.class-rank';
    public const string COUPLING_CE_PACKAGES = 'coupling.ce-packages';
    public const string COUPLING_CBO_APP = 'coupling.cbo-app';
    public const string COUPLING_CE_FRAMEWORK = 'coupling.ce-framework';

    // -- Design --------------------------------------------------------------

    public const string TYPE_COVERAGE_PARAM_TOTAL = 'design.type-coverage.param.total';
    public const string TYPE_COVERAGE_PARAM_TYPED = 'design.type-coverage.param.typed';
    public const string TYPE_COVERAGE_PARAM = 'design.type-coverage.param';
    public const string TYPE_COVERAGE_RETURN_TOTAL = 'design.type-coverage.return.total';
    public const string TYPE_COVERAGE_RETURN_TYPED = 'design.type-coverage.return.typed';
    public const string TYPE_COVERAGE_RETURN = 'design.type-coverage.return';
    public const string TYPE_COVERAGE_PROPERTY_TOTAL = 'design.type-coverage.property.total';
    public const string TYPE_COVERAGE_PROPERTY_TYPED = 'design.type-coverage.property.typed';
    public const string TYPE_COVERAGE_PROPERTY = 'design.type-coverage.property';
    public const string TYPE_COVERAGE_PCT = 'design.type-coverage.pct';

    // -- Halstead ------------------------------------------------------------

    public const string HALSTEAD_VOLUME = 'maintainability.halstead.volume';
    public const string HALSTEAD_DIFFICULTY = 'maintainability.halstead.difficulty';
    public const string HALSTEAD_EFFORT = 'maintainability.halstead.effort';
    public const string HALSTEAD_BUGS = 'maintainability.halstead.bugs';
    public const string HALSTEAD_TIME = 'maintainability.halstead.time';

    // -- Maintainability -----------------------------------------------------

    public const string MAINTAINABILITY_MI = 'maintainability.mi';

    // -- Security ------------------------------------------------------------

    public const string SECURITY_HARDCODED_CREDENTIALS = 'security.hardcoded-credentials';
    public const string SECURITY_SENSITIVE_PARAMETER = 'security.sensitive-parameter';

    // -- Cohesion ------------------------------------------------------------

    public const string COHESION_TCC = 'cohesion.tcc';
    public const string COHESION_LCC = 'cohesion.lcc';
    public const string COHESION_PURE_METHOD_COUNT = 'cohesion.pure-method-count';

    // -- Size ----------------------------------------------------------------

    public const string SIZE_CLASS_LOC = 'size.class-loc';
    public const string SIZE_CLASS_COUNT = 'size.class-count';
    public const string SIZE_ABSTRACT_CLASS_COUNT = 'size.abstract-class-count';
    public const string SIZE_INTERFACE_COUNT = 'size.interface-count';
    public const string SIZE_TRAIT_COUNT = 'size.trait-count';
    public const string SIZE_ENUM_COUNT = 'size.enum-count';
    public const string SIZE_IMPLEMENTING_ENUM_COUNT = 'size.implementing-enum-count';
    public const string SIZE_FUNCTION_COUNT = 'size.function-count';
    public const string SIZE_LOC = 'size.loc';
    public const string SIZE_LLOC = 'size.lloc';
    public const string SIZE_CLOC = 'size.cloc';
    public const string SIZE_METHOD_STATEMENT_COUNT = 'size.method-statement-count';
    public const string SIZE_METHOD_COUNT_PUBLIC = 'size.method-count.public';
    public const string SIZE_METHOD_COUNT_PROTECTED = 'size.method-count.protected';
    public const string SIZE_METHOD_COUNT_PRIVATE = 'size.method-count.private';
    public const string SIZE_GETTER_COUNT = 'size.getter-count';
    public const string SIZE_SETTER_COUNT = 'size.setter-count';
    public const string SIZE_PROPERTY_COUNT_PUBLIC = 'size.property-count.public';
    public const string SIZE_PROPERTY_COUNT_PROTECTED = 'size.property-count.protected';
    public const string SIZE_PROPERTY_COUNT_PRIVATE = 'size.property-count.private';
    public const string SIZE_PROMOTED_PROPERTY_COUNT = 'size.promoted-property-count';

    /**
     * Symbol counters the aggregation pipeline injects at namespace and project
     * level, where they serve as denominators in the built-in health formulas.
     */
    public const string SIZE_SYMBOL_METHOD_COUNT = 'size.symbol-method-count';
    public const string SIZE_SYMBOL_CLASS_COUNT = 'size.symbol-class-count';

    // -- RFC (Response for a Class) ------------------------------------------

    public const string RFC_TOTAL = 'coupling.rfc';
    public const string RFC_OWN = 'coupling.rfc-own';
    public const string RFC_EXTERNAL = 'coupling.rfc-external';

    // -- Structure -----------------------------------------------------------

    public const string STRUCTURE_DIT = 'design.dit';
    public const string STRUCTURE_LCOM = 'cohesion.lcom';
    public const string STRUCTURE_METHOD_COUNT = 'size.method-count';
    public const string STRUCTURE_METHOD_COUNT_TOTAL = 'size.method-count.total';
    public const string STRUCTURE_PROPERTY_COUNT = 'size.property-count';
    public const string STRUCTURE_IS_READONLY = 'design.is-readonly';
    public const string STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY = 'design.is-promoted-properties-only';
    public const string STRUCTURE_IS_DATA_CLASS = 'design.is-data-class';
    public const string STRUCTURE_NOC = 'design.noc';
    public const string STRUCTURE_UNUSED_PRIVATE_TOTAL = 'code-smell.unused-private.total';
    public const string STRUCTURE_UNUSED_PRIVATE_METHOD = 'code-smell.unused-private.method';
    public const string STRUCTURE_UNUSED_PRIVATE_PROPERTY = 'code-smell.unused-private.property';
    public const string STRUCTURE_UNUSED_PRIVATE_CONSTANT = 'code-smell.unused-private.constant';
    public const string STRUCTURE_IS_ABSTRACT = 'design.is-abstract';
    public const string STRUCTURE_IS_INTERFACE = 'design.is-interface';
    public const string STRUCTURE_IS_EXCEPTION = 'design.is-exception';
    public const string STRUCTURE_WMC = 'complexity.wmc';
    public const string STRUCTURE_WOC = 'design.woc';

    // -- Code Smell ----------------------------------------------------------

    public const string CODE_SMELL_PARAMETER_COUNT = 'code-smell.parameter-count';
    public const string CODE_SMELL_IS_VO_CONSTRUCTOR = 'code-smell.is-vo-constructor';
    public const string CODE_SMELL_UNREACHABLE_CODE = 'code-smell.unreachable-code';
    public const string CODE_SMELL_UNREACHABLE_CODE_FIRST_LINE = 'code-smell.unreachable-code.first-line';

    /**
     * Returns the aggregated metric name for a base metric and strategy.
     *
     * Example: agg(COMPLEXITY_CCN, AggregationStrategy::Sum) → 'complexity.ccn.sum'
     */
    public static function agg(string $metric, AggregationStrategy $strategy): string
    {
        return $metric . '.' . $strategy->value;
    }

    private function __construct()
    {
        // Static-only class
    }
}
