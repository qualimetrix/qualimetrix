<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Threshold;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideAxis;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionForms;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Core\Symbol\MetricSubject;

/** Every level an annotation retunes must admit each of its written axes. */
final readonly class DeclaredOverrideForms
{
    public function __construct(
        private RuleOptionForms $forms,
        private string $rulePattern,
        private int $line,
        private MetricSubject $subject,
    ) {}

    public function problem(ThresholdOverrideRequest $request): ?ThresholdDiagnostic
    {
        foreach ($this->forms->levels() as $level) {
            foreach (OverrideAxis::cases() as $axis) {
                $value = $axis === OverrideAxis::Warning ? $request->warning : $request->error;
                $problem = $this->axisProblem($level, $axis, $value, $request);
                if ($problem !== null) {
                    return $problem;
                }
            }
        }
        return null;
    }

    private function axisProblem(
        ?string $level,
        OverrideAxis $axis,
        int|float|null $value,
        ThresholdOverrideRequest $request,
    ): ?ThresholdDiagnostic {
        if ($value === null || $this->isUnwrittenUnsupportedError($level, $axis, $request)) {
            return null;
        }

        if (!$this->forms->hasAxis($this->rulePattern, $level, $axis->value)) {
            return new ThresholdDiagnostic(
                line: $this->line,
                subject: $this->subject,
                rulePattern: $this->rulePattern,
                code: 'unsupported_' . $axis->value . '_axis',
                message: \sprintf('@qmx-threshold %s: %s threshold has no declared override form%s', $this->rulePattern, $axis->value, $level === null ? '' : ' at ' . $level . ' level'),
            );
        }
        $form = $this->forms->formOf($this->rulePattern, $level, $axis->value);
        if (!self::admits($form, $value)) {
            return new ThresholdDiagnostic(
                line: $this->line,
                subject: $this->subject,
                rulePattern: $this->rulePattern,
                code: 'invalid_' . $axis->value . '_form',
                message: \sprintf('@qmx-threshold %s: %s threshold%s must be %s (got %s)', $this->rulePattern, $axis->value, $level === null ? '' : ' at ' . $level . ' level', $form->describe(), $value),
            );
        }
        return null;
    }

    private function isUnwrittenUnsupportedError(?string $level, OverrideAxis $axis, ThresholdOverrideRequest $request): bool
    {
        return $axis === OverrideAxis::Error
            && !$request->hasAuthored($axis)
            && !$this->forms->hasAxis($this->rulePattern, $level, $axis->value);
    }

    private static function admits(NodeSchema $form, int|float $value): bool
    {
        return array_any($form->scalar->forms, static fn($scalarForm): bool => $scalarForm->accepts($value))
            && !($form->scalar->minimum !== null && $value < $form->scalar->minimum);
    }
}
