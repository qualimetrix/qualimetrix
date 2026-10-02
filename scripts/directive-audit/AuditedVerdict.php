<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

/**
 * One entry of the audit's `directives[]`, read strictly.
 *
 * Every field is required. The audit publishes all fields on every entry, so an
 * entry missing one is a report of a shape this library does not know how to
 * judge — and the defaults that used to stand in for the missing ones
 * (`form ?? 'threshold'`) were how one parameter came to accept two different
 * shapes of data.
 *
 * `reason` is required and nullable, which is not the same as optional: the
 * audit publishes the key on every entry and its value only beside
 * `unmeasured`. A reader treating the key as optional would read a report that
 * stopped publishing reasons at all as a population with none, which is exactly
 * the shape the heterogeneity floor is there to refuse.
 */
final readonly class AuditedVerdict
{
    /** @param list<array{channel: string, message: string}> $refusals */
    private function __construct(
        public string $file,
        public int $line,
        public string $form,
        public string $target,
        public string $effect,
        public ?string $reason,
        public array $refusals,
    ) {}

    /**
     * @param array<mixed, mixed> $row
     *
     * @throws AuditReportError
     */
    public static function fromRow(array $row, int|string $index): self
    {
        $where = \sprintf('directives[%s]', $index);

        $effect = self::requireString($row, 'effect', $where);
        $refusals = self::requireRefusals($row, $where);
        if (($effect === 'refused') !== ($refusals !== [])) {
            throw new AuditReportError($where . ': "refusals" must be non-empty exactly for a refused verdict.');
        }

        return new self(
            self::requireString($row, 'file', $where),
            self::requireInt($row, 'line', $where),
            self::requireString($row, 'form', $where),
            self::requireString($row, 'target', $where),
            $effect,
            self::requireNullableString($row, 'reason', $where),
            $refusals,
        );
    }

    /**
     * @param array<mixed, mixed> $row
     *
     * @return list<array{channel: string, message: string}>
     */
    private static function requireRefusals(array $row, string $where): array
    {
        $values = $row['refusals'] ?? null;
        if (!\is_array($values) || !array_is_list($values)) {
            throw new AuditReportError($where . ': "refusals" must be a list.');
        }
        $refusals = [];
        foreach ($values as $index => $value) {
            $at = $where . '.refusals[' . $index . ']';
            if (!\is_array($value)) {
                throw new AuditReportError($at . ' must be an object.');
            }
            $refusals[] = [
                'channel' => self::requireString($value, 'channel', $at),
                'message' => self::requireString($value, 'message', $at),
            ];
        }

        return $refusals;
    }

    /** What the population comparison is about: the authored site, without the tag. */
    public function site(): string
    {
        return \sprintf('%s:%d:%s', $this->file, $this->line, $this->target);
    }

    /**
     * The identity a verdict comparison is about.
     *
     * `form` is part of it: a suppression and a threshold authored on the same
     * line against the same channel are two distinct sites, and a key without
     * the tag would drop one of them on the collision.
     */
    public function keyedSite(): string
    {
        return \sprintf('%s:%d:%s:%s', $this->file, $this->line, $this->form, $this->target);
    }

    /**
     * Whether this verdict is a measurement, asked of {@see MeasuredEffects}
     * rather than answered here.
     *
     * Asked at the floor rather than checked at construction, and that is the
     * point: the refusal on an unnamed verdict reaches a caller exactly when
     * that caller asks this library what was measured. A gate that goes back to
     * deciding the floor itself stops receiving the refusal — which is the
     * breakage the control bench plants, and it would be invisible if the
     * refusal had already happened while the report was being read.
     *
     * @throws AuditReportError on a verdict value {@see MeasuredEffects::TABLE} does not name
     */
    public function isMeasured(): bool
    {
        return MeasuredEffects::isMeasured($this->effect);
    }

    public function isThreshold(): bool
    {
        return $this->form === 'threshold';
    }

    /**
     * @param array<mixed, mixed> $row
     *
     * @throws AuditReportError
     */
    private static function requireString(array $row, string $key, string $where): string
    {
        $value = $row[$key] ?? null;

        if (!\is_string($value)) {
            throw new AuditReportError(self::wrongType($where, $key, 'a string', $value));
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $row
     *
     * @throws AuditReportError
     */
    private static function requireNullableString(array $row, string $key, string $where): ?string
    {
        if (!\array_key_exists($key, $row)) {
            throw new AuditReportError(\sprintf('%s: "%s" is missing.', $where, $key));
        }

        $value = $row[$key];

        if ($value !== null && !\is_string($value)) {
            throw new AuditReportError(self::wrongType($where, $key, 'a string or null', $value));
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $row
     *
     * @throws AuditReportError
     */
    private static function requireInt(array $row, string $key, string $where): int
    {
        $value = $row[$key] ?? null;

        if (!\is_int($value)) {
            throw new AuditReportError(self::wrongType($where, $key, 'an integer', $value));
        }

        return $value;
    }

    private static function wrongType(string $where, string $key, string $expected, mixed $value): string
    {
        return \sprintf('%s: "%s" must be %s, got %s.', $where, $key, $expected, get_debug_type($value));
    }
}
