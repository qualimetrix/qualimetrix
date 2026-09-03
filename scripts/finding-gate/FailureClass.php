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

    /**
     * A published fingerprint the gate could not replace with the identity it
     * hashes, so that surface would be compared as opaque hex.
     */
    public const FINGERPRINT_OPAQUE = 'fingerprint-opaque';

    /** The HTML report carries no payload the gate can read, so its surface would compare as nothing. */
    public const REPORT_PAYLOAD_UNREADABLE = 'report-payload-unreadable';

    /** A declared channel that no case observes: a lost fixture, or a channel that stopped firing. */
    public const COVERAGE_SHORTFALL = 'coverage-shortfall';

    /** An observed channel that nothing declares. */
    public const COVERAGE_SURPLUS = 'coverage-surplus';

    /** A channel fires in more than one case, so the union cannot notice a lost fixture. */
    public const COVERAGE_MULTIPLICITY = 'coverage-multiplicity';

    /** The container and the tracked fixture disagree about the static declarations. */
    public const WITNESS_DISAGREEMENT = 'witness-disagreement';

    /** The gate's level vocabulary is not the product's own. */
    public const LEVEL_VOCABULARY_DRIFT = 'level-vocabulary-drift';

    /** A case's own `channels` claim is not what the case fires. */
    public const CASE_CLAIM_MISMATCH = 'case-claim-mismatch';

    /** A normalization rule matched nothing in the whole run. */
    public const NORMALIZATION_STALE = 'normalization-stale';

    /** A declared map row translated nothing in the whole run. */
    public const MAP_STALE = 'map-stale';

    /** A normalization rule reaches a field the equivalence tuple compares. */
    public const NORMALIZATION_OVERREACH = 'normalization-overreach';

    /** A half a declared split cannot translate occurs where no declared row explains it. */
    public const SPLIT_UNMAPPED = 'split-unmapped';

    /** The measured diff of a surface is not the one the declared delta states. */
    public const DELTA_MISMATCH = 'delta-mismatch';

    /** A delta is declared for a surface the two trees agree on. */
    public const DELTA_STALE = 'delta-stale';

    /** A declared delta is larger than a declaration may be. */
    public const DELTA_TOO_LARGE = 'delta-too-large';

    /** A declared delta changes a field the equivalence tuple compares, unexplained. */
    public const DELTA_OVERREACH = 'delta-overreach';

    /** A declared field move no diff line performed. */
    public const FIELD_MOVE_STALE = 'field-move-stale';

    /** The reference binary was handed input in a vocabulary it does not know. */
    public const REFERENCE_INPUT_UNTRANSLATED = 'reference-input-untranslated';

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
        self::FINGERPRINT_OPAQUE,
        self::REPORT_PAYLOAD_UNREADABLE,
        self::COVERAGE_SHORTFALL,
        self::COVERAGE_SURPLUS,
        self::COVERAGE_MULTIPLICITY,
        self::WITNESS_DISAGREEMENT,
        self::LEVEL_VOCABULARY_DRIFT,
        self::CASE_CLAIM_MISMATCH,
        self::NORMALIZATION_STALE,
        self::MAP_STALE,
        self::NORMALIZATION_OVERREACH,
        self::SPLIT_UNMAPPED,
        self::DELTA_MISMATCH,
        self::DELTA_STALE,
        self::DELTA_TOO_LARGE,
        self::DELTA_OVERREACH,
        self::FIELD_MOVE_STALE,
        self::REFERENCE_INPUT_UNTRANSLATED,
        self::NONDETERMINISM_UNDECLARED,
        self::PATH_LEAK,
    ];
}
