<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use LogicException;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Rules\AbstractRule;

/**
 * Detects unused private methods, properties, and constants.
 *
 * Private members that are declared but never referenced within the same class
 * are dead code and should be removed.
 *
 * Limitations:
 * - Dynamic access ($this->$name) is not detected
 * - Callable syntax [$this, 'method'] is not detected
 * - Traits are not analyzed
 * - If __get/__set exist, private properties are not flagged
 * - If __call/__callStatic exist, private methods are not flagged
 */
final class UnusedPrivateRule extends AbstractRule
{
    public const string NAME = 'code-smell.unused-private';

    private const ENTRY_KEYS = [
        MetricName::STRUCTURE_UNUSED_PRIVATE_METHOD => 'Unused private method',
        MetricName::STRUCTURE_UNUSED_PRIVATE_PROPERTY => 'Unused private property',
        MetricName::STRUCTURE_UNUSED_PRIVATE_CONSTANT => 'Unused private constant',
    ];

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects unused private methods, properties, and constants';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::CodeSmell;
    }

    public function requires(): array
    {
        return [
            MetricName::STRUCTURE_UNUSED_PRIVATE_TOTAL,
        ];
    }

    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $violations = [...$violations, ...$this->violationsForDeclaration($classInfo, $context)];
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private function violationsForDeclaration(SymbolInfo $classInfo, AnalysisContext $context): array
    {
        $subject = $classInfo->subject ?? throw new LogicException('Unused private findings require an exact class subject');
        $declaration = $subject->declarationPath() ?? throw new LogicException('Unused private findings require a declaration subject');
        if ($declaration->logical->getType() !== SymbolType::Class_) {
            return [];
        }

        $metrics = $context->metrics->getSubject($subject);
        $total = (int) ($metrics->get(MetricName::STRUCTURE_UNUSED_PRIVATE_TOTAL) ?? 0);
        if ($total === 0) {
            return [];
        }

        $violations = [];
        foreach (self::ENTRY_KEYS as $entryKey => $label) {
            foreach ($metrics->entries($entryKey) as $entry) {
                $line = (int) $entry['line'];

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $line, precise: true),
                    subject: $subject,
                    symbolPath: $declaration->logical,
                    ruleName: $this->getName(),
                    violationCode: $this->getName(),
                    message: $this->entryMessage($label, $entry),
                    severity: Severity::Warning,
                    metricValue: $total,
                    recommendation: 'Remove the unused symbol to reduce dead code.',
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<string, scalar> $entry
     */
    private function entryMessage(string $label, array $entry): string
    {
        return isset($entry['name']) ? \sprintf('%s `%s`', $label, (string) $entry['name']) : $label;
    }

    public static function getOptionsClass(): string
    {
        return UnusedPrivateOptions::class;
    }

    /**
     * `code-smell.unused-private` is declared `magnitude` / `higher` as a
     * **decision, not a derivation** (ADR 0017) — the same
     * class of decision as `architecture.circular-dependency`. There is no
     * gating threshold comparison to read a direction from (the rule fires
     * on any nonzero `$total`, and severity is the fixed constant
     * `Severity::Warning`; {@see UnusedPrivateOptions::getSeverity()} exists
     * but is never called), but a threshold is not what establishes
     * direction — the meaning of the measured value does. `$total` is a
     * count of unused private members for the class, and more unused
     * private members is unambiguously worse debt, independent of whether
     * anything currently gates on it.
     *
     * Quirk worth pinning: every `Violation` in the group reports the
     * *same* class-wide `$total` (see the emission in {@see analyze()}) —
     * a class with three unused private members emits three violations
     * that each report `metricValue: 3`. Under the ceiling, `count` and
     * `magnitudes` therefore move together for this channel: redundant,
     * not wrong.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher),
        ];
    }
}
