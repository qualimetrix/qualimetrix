<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

/**
 * One entry of the audit's `directives[]`, read strictly.
 *
 * Every field is required. The audit publishes all five on every entry, so an
 * entry missing one is a report of a shape this library does not know how to
 * judge — and the defaults that used to stand in for the missing ones
 * (`form ?? 'threshold'`) were how one parameter came to accept two different
 * shapes of data.
 */
final readonly class AuditedVerdict
{
    private function __construct(
        public string $file,
        public int $line,
        public string $form,
        public string $target,
        public string $effect,
    ) {}

    /**
     * @param array<mixed, mixed> $row
     *
     * @throws AuditReportError
     */
    public static function fromRow(array $row, int|string $index): self
    {
        $where = \sprintf('directives[%s]', $index);

        return new self(
            self::requireString($row, 'file', $where),
            self::requireInt($row, 'line', $where),
            self::requireString($row, 'form', $where),
            self::requireString($row, 'target', $where),
            self::requireString($row, 'effect', $where),
        );
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
