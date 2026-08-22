<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Detects duplicated code blocks across files.
 *
 * Generates one violation per duplicate block, pointing to the primary location.
 * Related locations (other copies) are included in the message.
 */
final class CodeDuplicationRule extends AbstractRule
{
    public const string NAME = 'duplication.code-duplication';
    public const string DOCS_PAGE = 'rules/duplication.md';

    public const int REMEDIATION_MINUTES = 15;
    public function __construct(
        RuleOptionsInterface $options,
        private readonly DuplicationResultProvider $resultProvider,
    ) {
        parent::__construct($options);
    }

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

        foreach ($this->resultProvider->all() as $block) {
            $violations[] = $this->createViolation($context, $block);
        }

        return $violations;
    }

    public static function getOptionsClass(): string
    {
        return CodeDuplicationOptions::class;
    }

    /**
     * `duplication.code-duplication` reports the duplicated block's line
     * count (`$block->lines` — see the emission above) as `metricValue`,
     * judged worse the higher it goes:
     * {@see CodeDuplicationOptions::getSeverity()}'s `$value >=
     * $this->error` (line 58) / `$value >= $this->warning` (line 62).
     * Emission itself is unconditional — every `DuplicateBlock` produces a
     * `Violation` regardless of size (`$severity ?? Severity::Warning` at
     * line 102 is only ever a fallback) — but that does not change the
     * direction question: the threshold comparison genuinely gates
     * *severity*, and severity is monotone in `$block->lines`, so `higher`
     * is a real fact about the code, not an inference from the channel's
     * unconditional trigger.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher),
        ];
    }

    private function createViolation(AnalysisContext $context, DuplicateBlock $block): Violation
    {
        $primary = $block->primaryLocation();
        $related = $block->relatedLocations();

        $otherLocations = implode(', ', array_map(
            static fn($loc) => $loc->toString(),
            $related,
        ));

        $hintPart = $block->hint !== null
            ? \sprintf(': "%s"', $block->hint)
            : '';

        $message = \sprintf(
            'Duplicated code block (%d lines, %d occurrences)%s — also at %s',
            $block->lines,
            $block->occurrences(),
            $hintPart,
            $otherLocations,
        );

        $projectPath = SymbolPath::forProject();
        $subject = MetricSubject::aggregate($projectPath);
        $severity = $this->getEffectiveSeverity($context, $this->options, $subject, $block->lines);

        // Build related locations for SARIF support
        $relatedViolationLocations = array_map(
            static fn($loc) => new Location($loc->file, $loc->startLine, precise: true),
            $related,
        );

        return new Violation(
            location: new Location($primary->file, $primary->startLine, precise: true),
            subject: $subject,
            symbolPath: $projectPath,
            ruleName: $this->getName(),
            violationCode: $this->getName(),
            message: $message,
            severity: $severity ?? Severity::Warning,
            metricValue: $block->lines,
            relatedLocations: $relatedViolationLocations,
            recommendation: 'Extract duplicated code into a shared method or class.',
            occurrenceKey: OccurrenceKey::semantic(self::NAME, ['contentHash' => $block->contentHash]),
        );
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
