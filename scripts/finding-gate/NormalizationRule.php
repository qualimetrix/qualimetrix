<?php

declare(strict_types=1);

namespace QmxFindingGate;

final class NormalizationRule
{
    public const KIND_JSON_PATH = 'json-path';
    public const KIND_HTML_REPORT_DATA_PATH = 'html-report-data-path';
    public const KIND_LINE_REGEX = 'line-regex';

    public function __construct(
        public readonly string $surface,
        public readonly string $locator,
        public readonly string $kind,
        public readonly string $reason,
    ) {
        if (!\in_array($kind, [self::KIND_JSON_PATH, self::KIND_HTML_REPORT_DATA_PATH, self::KIND_LINE_REGEX], true)) {
            throw new GateError(\sprintf('Unknown normalization kind "%s" for %s / %s.', $kind, $surface, $locator));
        }
    }

    /** @return list<string> */
    public function row(): array
    {
        return [$this->surface, $this->locator, $this->kind, $this->reason];
    }
}
