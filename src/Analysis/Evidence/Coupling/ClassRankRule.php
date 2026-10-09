<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use LogicException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\ContextGuard;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;

use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyThreshold;
use Qualimetrix\Analysis\Finding\Contract\Population\KindIn;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Judges exact class declarations using multiples of uniform graph probability.
 * All named PHP kinds participate in PageRank, while only classes are judged.
 */
#[CliAlias('class-rank-warning', 'warning')]
#[CliAlias('class-rank-error', 'error')]
final class ClassRankRule extends AbstractRule
{
    public const string NAME = 'coupling.class-rank';
    public const string DOCS_PAGE = 'rules/coupling.md';

    public const int REMEDIATION_MINUTES = 30;

    public const ChannelShape SHAPE = ChannelShape::Occurrence;
    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Checks ClassRank (PageRank on dependency graph) to identify critical hub classes';
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof ClassRankOptions || !$this->options->isEnabled()) {
            return [];
        }

        $declaration = self::channelDeclarations()[self::NAME];
        if ($context->dependencyGraph === null) {
            $context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Class_, PopulationIdentity::invocation(self::NAME), $declaration, (static function (): iterable {
                yield GateInput::context('graphAvailable', false);
            })());
            return [];
        }
        $classes = iterator_to_array($context->metrics->allClassDeclarations(), false);
        $facts = $this->classFacts($context, $classes);
        $findings = [];
        foreach ($classes as $info) {
            $subject = $info->subject ?? throw new LogicException('ClassRank requires an exact declaration subject.');
            $fact = $facts[$subject->toCanonical()];
            $metrics = null;
            if (!$context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Class_, PopulationIdentity::subject($subject), $declaration, (static function () use ($context, $subject, $fact, &$metrics): iterable {
                yield GateInput::context('graphAvailable', true);
                yield GateInput::kind('class-coordinate', $subject->toSymbolPath()->getType());
                yield GateInput::kind('php-class', $fact->type);
                $metrics = $context->metrics->getSubject($subject);
                yield GateInput::metrics('class-rank-share', $metrics);
                yield GateInput::metrics('dependents', $metrics);
            })())) {
                continue;
            }
            $rank = (float) $metrics->require(MetricName::COUPLING_CLASS_RANK_SHARE);
            /** @var ClassRankOptions $options */
            $options = $this->getEffectiveOptions($context, $this->options, $subject);
            $severity = $options->getSeverity($rank);
            if ($severity === null) {
                continue;
            }
            $threshold = $severity === Severity::Error ? $options->error : $options->warning;
            [$valueText, $thresholdText, $rounded] = self::display($rank, $threshold);
            $dependents = (int) $metrics->require(MetricName::COUPLING_CA);
            $findings[] = new Finding(
                location: new Location($info->file, $info->line),
                subject: $subject,
                symbolPath: $subject->toSymbolPath(),
                ruleName: self::NAME,
                code: self::NAME,
                message: \sprintf('ClassRank share is %s× uniform, %s threshold of %s×%s. This class is a critical hub — changes have wide impact', $valueText, ThresholdCrossing::of($rank, $threshold)->value, $thresholdText, $rounded ? ' (display rounded)' : ''),
                severity: $severity,
                metricValue: $rank,
                threshold: $threshold,
                recommendation: \sprintf('ClassRank share: %s× uniform (threshold: %s×%s) — coupling hotspot, %d %s on this', $valueText, $thresholdText, $rounded ? ', display rounded' : '', $dependents, $dependents === 1 ? 'class depends' : 'classes depend'),
            );
        }
        return $findings;
    }

    /** @param list<SymbolInfo> $classes
     * @return array<string, ClassLikeDeclaration>
     */
    private function classFacts(AnalysisContext $context, array $classes): array
    {
        $graph = $context->dependencyGraph ?? throw new LogicException('ClassRank declaration join requires a graph.');
        $facts = [];
        foreach ($graph->getClassLikeDeclarations() as $fact) {
            $key = $fact->declaration->toCanonical();
            if (isset($facts[$key]) && $facts[$key]->type !== $fact->type) {
                throw new LogicException('Conflicting exact ClassRank PHP-kind facts.');
            }
            $facts[$key] = $fact;
        }
        $measured = [];
        foreach ($classes as $info) {
            $subject = $info->subject ?? throw new LogicException('ClassRank requires an exact declaration subject.');
            $path = $subject->declarationPath() ?? throw new LogicException('ClassRank requires declaration identity.');
            $key = $path->toCanonical();
            if (!isset($facts[$key])) {
                throw new LogicException('Measured ClassRank declaration has no exact PHP-kind fact.');
            }
            $measured[$key] = true;
        }
        if (array_diff_key($facts, $measured) !== []) {
            throw new LogicException('ClassRank graph and measured declaration rosters disagree.');
        }
        return $facts;
    }

    /** @return array{string, string, bool} */
    private static function display(float $value, float $threshold): array
    {
        $precision = 2;
        while (true) {
            $valueText = \sprintf('%.*f', $precision, $value);
            $thresholdText = \sprintf('%.*f', $precision, $threshold);
            if ($value === $threshold || $valueText !== $thresholdText) {
                return [$valueText, $thresholdText, false];
            }
            if ($precision === 6) {
                return [$valueText, $thresholdText, true];
            }
            $precision++;
        }
    }

    /**
     * @return class-string<ClassRankOptions>
     */
    public static function getOptionsClass(): string
    {
        return ClassRankOptions::class;
    }

    /**
     * Graph-relative share remains occurrence debt: a later graph changes the
     * population behind the same exact declaration, so an accepted hotspot is
     * bounded by count rather than an earlier graph's numeric share.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::occurrence(SymbolLevel::Class_)->readingRunEvidence()->withGates(
                new PopulationGate('graph-available', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new ContextGuard('graphAvailable'), 'The dependency graph is unavailable.', 'invocation'),
                new PopulationGate('class-coordinate', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KindIn('class-coordinate', [SymbolType::Class_]), 'ClassRank requires a class coordinate.'),
                new PopulationGate('php-class', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KindIn('php-class', [ClassType::Class_]), 'Only exact PHP classes are judged.'),
                new PopulationGate('class-rank-share', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KeyPresent('class-rank-share', [MetricName::COUPLING_CLASS_RANK_SHARE]), 'ClassRank share was not published.'),
                new PopulationGate('dependents', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KeyThreshold('dependents', [MetricName::COUPLING_CA], '>', 0, 'refuse', true), 'A class with no dependents is outside hotspot judgement.'),
            ),
        ];
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
