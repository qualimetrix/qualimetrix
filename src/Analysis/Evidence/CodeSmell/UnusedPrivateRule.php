<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\KindIn;

use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

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
    public const string DOCS_PAGE = 'rules/code-smell.md';

    public const int REMEDIATION_MINUTES = 15;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    private const ENTRY_KEYS = [
        MetricName::CODE_SMELL_UNUSED_PRIVATE_METHOD => 'Unused private method',
        MetricName::CODE_SMELL_UNUSED_PRIVATE_PROPERTY => 'Unused private property',
        MetricName::CODE_SMELL_UNUSED_PRIVATE_CONSTANT => 'Unused private constant',
    ];

    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Detects unused private methods, properties, and constants';
    }

    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $findings = [];
        $populationDeclaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->allClassDeclarations() as $classInfo) {
            $findings = [...$findings, ...$this->findingsForDeclaration($classInfo, $context, $populationDeclaration)];
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function findingsForDeclaration(SymbolInfo $classInfo, AnalysisContext $context, ChannelDeclaration $populationDeclaration): array
    {
        $subject = $classInfo->subject ?? throw new LogicException('Unused private findings require an exact class subject');
        $declaration = $subject->declarationPath() ?? throw new LogicException('Unused private findings require a declaration subject');
        $metrics = null;
        if (!$context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Class_, PopulationIdentity::subject($subject), $populationDeclaration, (static function () use ($context, $subject, $declaration, &$metrics): iterable {
            yield GateInput::kind('class-coordinate', $declaration->logical->getType());
            $metrics = $context->metrics->getSubject($subject);
            yield GateInput::metrics('published-value', $metrics);
        })())) {
            return [];
        }
        $total = (int) $metrics->get(MetricName::CODE_SMELL_UNUSED_PRIVATE_TOTAL);
        if ($total === 0) {
            return [];
        }

        $findings = [];
        foreach (self::ENTRY_KEYS as $entryKey => $label) {
            foreach ($metrics->entries($entryKey) as $entry) {
                $line = (int) $entry['line'];

                $findings[] = new Finding(
                    location: new Location($classInfo->file, $line, precise: true),
                    subject: $subject,
                    symbolPath: $declaration->logical,
                    ruleName: $this->getName(),
                    code: $this->getName(),
                    message: $this->entryMessage($label, $entry),
                    severity: Severity::Warning,
                    metricValue: $total,
                    recommendation: 'Remove the unused symbol to reduce dead code.',
                );
            }
        }

        return $findings;
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
     * Quirk worth pinning: every `Finding` in the group reports the
     * *same* class-wide `$total` (see the emission in {@see analyze()}) —
     * a class with three unused private members emits three findings
     * that each report `metricValue: 3`. Under the ceiling, `count` and
     * `magnitudes` therefore move together for this channel: redundant,
     * not wrong.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::judging(
                WorseDirection::Higher,
                JudgedMetrics::of(MetricName::CODE_SMELL_UNUSED_PRIVATE_TOTAL),
                SymbolLevel::Class_,
            )->withGates(
                new PopulationGate('class-coordinate', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KindIn('class-coordinate', [SymbolType::Class_]), 'The subject is outside the declared symbol coordinate.'),
                new PopulationGate('published-value', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KeyPresent('published-value', [MetricName::CODE_SMELL_UNUSED_PRIVATE_TOTAL]), 'The rule metric was not published.'),
            ),
        ];
    }
}
