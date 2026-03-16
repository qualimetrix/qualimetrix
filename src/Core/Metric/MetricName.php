<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Metric;

/**
 * Canonical metric name constants shared between collectors and rules.
 *
 * Both Metrics and Rules layers depend on Core, so placing metric names
 * here avoids cross-layer dependencies between Metrics and Rules.
 *
 * Constants follow the naming pattern: CATEGORY_METRIC (e.g., COMPLEXITY_CCN).
 * Values match the metric key strings used in MetricBag.
 */
final class MetricName
{
    // -- Complexity ----------------------------------------------------------

    public const string COMPLEXITY_CCN = 'ccn';
    public const string COMPLEXITY_COGNITIVE = 'cognitive';
    public const string COMPLEXITY_NPATH = 'npath';

    // -- Coupling ------------------------------------------------------------

    public const string COUPLING_CA = 'ca';
    public const string COUPLING_CE = 'ce';
    public const string COUPLING_CBO = 'cbo';
    public const string COUPLING_INSTABILITY = 'instability';
    public const string COUPLING_ABSTRACTNESS = 'abstractness';
    public const string COUPLING_DISTANCE = 'distance';
    public const string COUPLING_CLASS_RANK = 'classRank';

    // -- Design --------------------------------------------------------------

    public const string TYPE_COVERAGE_PARAM_TOTAL = 'typeCoverage.paramTotal';
    public const string TYPE_COVERAGE_PARAM_TYPED = 'typeCoverage.paramTyped';
    public const string TYPE_COVERAGE_PARAM = 'typeCoverage.param';
    public const string TYPE_COVERAGE_RETURN_TOTAL = 'typeCoverage.returnTotal';
    public const string TYPE_COVERAGE_RETURN_TYPED = 'typeCoverage.returnTyped';
    public const string TYPE_COVERAGE_RETURN = 'typeCoverage.return';
    public const string TYPE_COVERAGE_PROPERTY_TOTAL = 'typeCoverage.propertyTotal';
    public const string TYPE_COVERAGE_PROPERTY_TYPED = 'typeCoverage.propertyTyped';
    public const string TYPE_COVERAGE_PROPERTY = 'typeCoverage.property';
    public const string TYPE_COVERAGE_PCT = 'typeCoverage.pct';

    // -- Halstead ------------------------------------------------------------

    public const string HALSTEAD_VOLUME = 'halstead.volume';
    public const string HALSTEAD_DIFFICULTY = 'halstead.difficulty';
    public const string HALSTEAD_EFFORT = 'halstead.effort';
    public const string HALSTEAD_BUGS = 'halstead.bugs';
    public const string HALSTEAD_TIME = 'halstead.time';
    public const string HALSTEAD_METHOD_LOC = 'methodLoc';

    // -- Maintainability -----------------------------------------------------

    public const string MAINTAINABILITY_MI = 'mi';

    // -- Security ------------------------------------------------------------

    public const string SECURITY_HARDCODED_CREDENTIALS = 'security.hardcodedCredentials';
    public const string SECURITY_SENSITIVE_PARAMETER = 'security.sensitiveParameter';

    // -- Cohesion ------------------------------------------------------------

    public const string COHESION_TCC = 'tcc';
    public const string COHESION_LCC = 'lcc';

    // -- Size ----------------------------------------------------------------

    public const string SIZE_CLASS_LOC = 'classLoc';
    public const string SIZE_CLASS_COUNT = 'classCount';
    public const string SIZE_ABSTRACT_CLASS_COUNT = 'abstractClassCount';
    public const string SIZE_INTERFACE_COUNT = 'interfaceCount';
    public const string SIZE_TRAIT_COUNT = 'traitCount';
    public const string SIZE_ENUM_COUNT = 'enumCount';
    public const string SIZE_FUNCTION_COUNT = 'functionCount';
    public const string SIZE_LOC = 'loc';
    public const string SIZE_LLOC = 'lloc';
    public const string SIZE_CLOC = 'cloc';

    // -- Structure -----------------------------------------------------------

    public const string STRUCTURE_DIT = 'dit';
    public const string STRUCTURE_LCOM = 'lcom';
    public const string STRUCTURE_METHOD_COUNT = 'methodCount';
    public const string STRUCTURE_PROPERTY_COUNT = 'propertyCount';
    public const string STRUCTURE_IS_READONLY = 'isReadonly';
    public const string STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY = 'isPromotedPropertiesOnly';
    public const string STRUCTURE_IS_DATA_CLASS = 'isDataClass';
    public const string STRUCTURE_NOC = 'noc';
    public const string STRUCTURE_UNUSED_PRIVATE_TOTAL = 'unusedPrivate.total';
    public const string STRUCTURE_UNUSED_PRIVATE_METHOD = 'unusedPrivate.method';
    public const string STRUCTURE_UNUSED_PRIVATE_PROPERTY = 'unusedPrivate.property';
    public const string STRUCTURE_UNUSED_PRIVATE_CONSTANT = 'unusedPrivate.constant';
    public const string STRUCTURE_IS_ABSTRACT = 'isAbstract';
    public const string STRUCTURE_IS_INTERFACE = 'isInterface';
    public const string STRUCTURE_IS_EXCEPTION = 'isException';
    public const string STRUCTURE_WMC = 'wmc';
    public const string STRUCTURE_WOC = 'woc';

    // -- Code Smell ----------------------------------------------------------

    public const string CODE_SMELL_PARAMETER_COUNT = 'parameterCount';
    public const string CODE_SMELL_UNREACHABLE_CODE = 'unreachableCode';
    public const string CODE_SMELL_UNREACHABLE_CODE_FIRST_LINE = 'unreachableCode.firstLine';

    private function __construct()
    {
        // Static-only class
    }
}
