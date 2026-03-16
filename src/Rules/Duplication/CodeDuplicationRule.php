<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Duplication;

use AiMessDetector\Core\Duplication\DuplicateBlock;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Detects duplicated code blocks across files.
 *
 * Generates one violation per duplicate block, pointing to the primary location.
 * Related locations (other copies) are included in the message.
 */
final class CodeDuplicationRule extends AbstractRule
{
    public const string NAME = 'duplication.code-duplication';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects duplicated code blocks';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Duplication;
    }

    public function requires(): array
    {
        return [];
    }

    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->duplicateBlocks as $block) {
            $violations[] = $this->createViolation($block);
        }

        return $violations;
    }

    public static function getOptionsClass(): string
    {
        return CodeDuplicationOptions::class;
    }

    private function createViolation(DuplicateBlock $block): Violation
    {
        $primary = $block->primaryLocation();
        $related = $block->relatedLocations();

        $otherLocations = implode(', ', array_map(
            static fn($loc) => $loc->toString(),
            $related,
        ));

        $message = \sprintf(
            'Duplicated code block (%d lines, %d occurrences) — also at %s',
            $block->lines,
            $block->occurrences(),
            $otherLocations,
        );

        $severity = $this->options->getSeverity($block->lines);

        // Build related locations for SARIF support
        $relatedViolationLocations = array_map(
            static fn($loc) => new Location($loc->file, $loc->startLine),
            $related,
        );

        return new Violation(
            location: new Location($primary->file, $primary->startLine),
            symbolPath: SymbolPath::forFile($primary->file),
            ruleName: $this->getName(),
            violationCode: $this->getName(),
            message: $message,
            severity: $severity ?? Severity::Warning,
            metricValue: $block->lines,
            relatedLocations: $relatedViolationLocations,
            recommendation: 'Extract duplicated code into a shared method or class.',
        );
    }
}
