<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Detects identical sub-expressions that indicate copy-paste errors or logic bugs.
 *
 * Checks for:
 * - Identical operands in binary operations ($a === $a, $a - $a)
 * - Duplicate conditions in if/elseif chains
 * - Identical ternary branches ($cond ? $x : $x)
 * - Duplicate match arm conditions
 * - Duplicate switch case values
 *
 * Reads the five `identicalSubExpression.*` collector fact keys (one per
 * type above) directly by string, not through
 * {@see \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName} — they are
 * per-occurrence entries produced by the collector, not catalog metrics.
 */
final class IdenticalSubExpressionRule extends AbstractRule
{
    public const string NAME = 'code-smell.identical-subexpression';
    public const string DOCS_PAGE = 'rules/code-smell.md';

    public const int REMEDIATION_MINUTES = 15;

    public const ChannelShape SHAPE = ChannelShape::Occurrence;
    /**
     * Finding types with corresponding finding messages.
     * Keys must match the types used by IdenticalSubExpressionCollector.
     *
     * @var array<string, string>
     */
    private const FINDING_TYPES = [
        'identical_operands' => 'Identical sub-expressions on both sides of operator',
        'duplicate_condition' => 'Duplicate condition in if/elseif chain',
        'identical_ternary' => 'Identical expressions in both branches of ternary operator',
        'duplicate_match_arm' => 'Duplicate condition in match expression',
        'duplicate_switch_case' => 'Duplicate case value in switch statement',
    ];

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects identical sub-expressions indicating copy-paste errors or logic bugs';
    }

    /**
     * @return class-string<IdenticalSubExpressionOptions>
     */
    public static function getOptionsClass(): string
    {
        return IdenticalSubExpressionOptions::class;
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $findings = [];

        foreach ($context->metrics->all(SymbolLevel::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);

            foreach (self::FINDING_TYPES as $type => $message) {
                foreach ($metrics->entries("identicalSubExpression.{$type}") as $entry) {
                    $line = (int) $entry['line'];
                    $subject = MetricSubjectCodec::decodeEntry($entry, $fileInfo->file ?? throw new LogicException('File symbol must carry a relative path'));

                    $findings[] = new Finding(
                        location: new Location($fileInfo->file, $line, precise: true),
                        subject: $subject,
                        symbolPath: $fileInfo->symbolPath,
                        ruleName: $this->getName(),
                        code: self::NAME,
                        message: $message,
                        severity: Severity::Warning,
                        metricValue: 1.0,
                        recommendation: 'This looks like a copy-paste error. Verify the intended logic.',
                        occurrenceKey: OccurrenceKey::semantic(self::NAME, [
                            'type' => $type,
                            'detail' => (string) ($entry['detail'] ?? ''),
                        ]),
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * `code-smell.identical-subexpression` reports a fixed `1.0` occurrence
     * marker (see the emission above) — severity is always `Warning` and
     * {@see IdenticalSubExpressionOptions::getSeverity()} is never called, so
     * there is no threshold comparison to read a direction from.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::occurrence(SymbolLevel::Callable),
        ];
    }
}
