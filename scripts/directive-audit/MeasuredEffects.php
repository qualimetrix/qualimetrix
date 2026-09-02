<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

/**
 * Which verdict values count as a measurement, frozen as a table.
 *
 * Deliberately not derived from `DirectiveEffect::cases()`. A derived floor
 * would enrol a fifth verdict silently on whichever side of the line the
 * derivation happens to put it, and what a new verdict means for a CI floor is
 * an authoring decision, not a consequence of the enum growing. So a value
 * outside this table is a refusal, and the author who adds the case decides
 * here what it is worth.
 *
 * Two tests hold the table honest, and neither alone would: one demands the
 * keys match the enum in both directions, the other demands that on the four
 * values known today the table still says what `effect !== 'unmeasured'` used
 * to say. A table with a boolean flipped passes the first and fails only the
 * second.
 */
final class MeasuredEffects
{
    /** @var array<string, bool> verdict value => whether it is a measurement */
    public const array TABLE = [
        'effective' => true,
        'overrun' => true,
        'inert' => true,
        'unmeasured' => false,
    ];

    /** @throws AuditReportError on a value this table does not name */
    public static function isMeasured(string $effect): bool
    {
        return self::TABLE[$effect] ?? self::refuse($effect);
    }

    /**
     * The refusal on its own, for a reader that must reject an unnamed verdict
     * before anybody asks what it is worth.
     *
     * Every published verdict goes through this, not only the threshold half:
     * `DirectiveEffect` is one vocabulary over both forms, so a fifth case
     * would first appear wherever the product happens to produce it. A floor
     * that only inspected the half it counts would have let it through in
     * silence — and on an empty threshold population, before the floor is even
     * reached.
     *
     * @throws AuditReportError
     */
    public static function requireNamed(string $effect): void
    {
        self::isMeasured($effect);
    }

    /** @throws AuditReportError */
    private static function refuse(string $effect): never
    {
        throw new AuditReportError(\sprintf(
            'Unknown directive verdict "%s". The CI floor cannot decide whether an unnamed verdict counts as a'
            . ' measurement; name it in MeasuredEffects::TABLE.',
            $effect,
        ));
    }
}
