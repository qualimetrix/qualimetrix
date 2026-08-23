<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The gate's failure vocabulary, in one place.
 *
 * These strings are a contract: other packages of the vocabulary pass assert on
 * them, so renaming one is a breaking change to those assertions, not an
 * editorial edit.
 */
final class FailureClass
{
    /** The two trees do not share a dependency set, so nothing they output can be compared. */
    public const ENV_MISMATCH = 'env-mismatch';

    /** A case, a map or the corpus layout is not usable as declared. */
    public const CORPUS_INVALID = 'corpus-invalid';

    /** A run that was supposed to produce an artifact did not. */
    public const RUN_FAILED = 'run-failed';

    /** A normalized artifact differs between the two trees beyond what the maps declare. */
    public const SURFACE_MISMATCH = 'surface-mismatch';

    /** The two trees report a different number of findings for the same case. */
    public const FINDING_COUNT_MISMATCH = 'finding-count-mismatch';

    /** A published finding object's key set differs from the tracked equivalence tuple. */
    public const FINDING_TUPLE_MISMATCH = 'finding-tuple-mismatch';

    /** The tracked tuple no longer matches what the publishing code derives. */
    public const TUPLE_FIELD_DRIFT = 'tuple-field-drift';

    /** A published fingerprint does not match the one recomputed from the same side's own fields. */
    public const FINGERPRINT_MISMATCH = 'fingerprint-mismatch';

    /** A declared channel that no case observes: a lost fixture, or a channel that stopped firing. */
    public const COVERAGE_SHORTFALL = 'coverage-shortfall';

    /** An observed channel that nothing declares. */
    public const COVERAGE_SURPLUS = 'coverage-surplus';

    /** A channel fires in more than one case, so the union cannot notice a lost fixture. */
    public const COVERAGE_MULTIPLICITY = 'coverage-multiplicity';

    /** The container and the tracked fixture disagree about the static declarations. */
    public const WITNESS_DISAGREEMENT = 'witness-disagreement';

    /** A case's own `channels` claim is not what the case fires. */
    public const CASE_CLAIM_MISMATCH = 'case-claim-mismatch';

    /** A normalization rule matched nothing in the whole run. */
    public const NORMALIZATION_STALE = 'normalization-stale';

    /** A declared map row translated nothing in the whole run. */
    public const MAP_STALE = 'map-stale';

    /** A normalization rule reaches a field the equivalence tuple compares. */
    public const NORMALIZATION_OVERREACH = 'normalization-overreach';

    /** Two runs of one tree differ after normalization. */
    public const NONDETERMINISM_UNDECLARED = 'nondeterminism-undeclared';

    /** An artifact contains a path of the machine the gate ran on. */
    public const PATH_LEAK = 'path-leak';

    /** @var list<string> */
    public const ALL = [
        self::ENV_MISMATCH,
        self::CORPUS_INVALID,
        self::RUN_FAILED,
        self::SURFACE_MISMATCH,
        self::FINDING_COUNT_MISMATCH,
        self::FINDING_TUPLE_MISMATCH,
        self::TUPLE_FIELD_DRIFT,
        self::FINGERPRINT_MISMATCH,
        self::COVERAGE_SHORTFALL,
        self::COVERAGE_SURPLUS,
        self::COVERAGE_MULTIPLICITY,
        self::WITNESS_DISAGREEMENT,
        self::CASE_CLAIM_MISMATCH,
        self::NORMALIZATION_STALE,
        self::MAP_STALE,
        self::NORMALIZATION_OVERREACH,
        self::NONDETERMINISM_UNDECLARED,
        self::PATH_LEAK,
    ];
}
