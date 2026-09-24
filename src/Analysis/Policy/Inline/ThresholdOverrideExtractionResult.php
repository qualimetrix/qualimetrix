<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline;

use PhpParser\Comment\Doc;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;

/**
 * Result of extracting `@qmx-threshold` annotations from a single AST node.
 *
 * Contains both valid overrides and diagnostics for invalid annotations, and
 * which authored tags each list came from. The tags are what lets the
 * suppression sweep refuse exactly the `@qmx-threshold` tags this reader did
 * not answer for, instead of carrying a second copy of its grammar.
 */
final readonly class ThresholdOverrideExtractionResult
{
    /**
     * @param list<ThresholdOverride> $overrides Valid threshold overrides
     * @param list<ThresholdDiagnostic> $diagnostics Diagnostics for invalid annotations
     * @param list<array{Doc, int}> $overrideTags the docblock and offset of each tag an override came from
     * @param list<array{Doc, int}> $diagnosticTags the docblock and offset of each tag a diagnostic came from
     */
    public function __construct(
        public array $overrides,
        public array $diagnostics,
        public array $overrideTags = [],
        public array $diagnosticTags = [],
    ) {}
}
