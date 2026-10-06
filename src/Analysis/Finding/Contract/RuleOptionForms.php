<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionAddress;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionDocumentFormsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Rule\Override\OverrideValidationFailure;

/** The declared override forms and the existing value-pair strategy of one rule. */
final readonly class RuleOptionForms implements OverrideValidatorInterface
{
    public function __construct(
        public string $producer,
        private RuleOptionSurface $surface,
        public OverrideValidatorInterface $strategy,
        private RuleOptionDocumentFormsInterface $documentForms,
    ) {}

    /** @return list<string|null> */
    public function levels(): array
    {
        $levels = $this->surface->levels();

        return $levels === [] ? [null] : $levels;
    }

    public function hasAxis(string $producer, ?string $level, string $overrideAxis): bool
    {
        return $this->keyAt($producer, $level, $overrideAxis) !== null;
    }

    /** The form of an override axis at its declared depth. */
    public function formOf(string $producer, ?string $level, string $overrideAxis): NodeSchema
    {
        $key = $this->keyAt($producer, $level, $overrideAxis)
            ?? throw new LogicException('No declared form for this override axis.');

        return $this->documentForms->schemaAt($this->surface, new RuleOptionAddress($level, $key));
    }

    private function keyAt(string $producer, ?string $level, string $overrideAxis): ?string
    {
        if ($producer !== $this->producer) {
            throw new LogicException('Override forms belong to another rule.');
        }

        $set = $level === null ? $this->surface->ownKeySet() : $this->surface->keySetAtLevel($level);
        if ($set === null) {
            return null;
        }

        return self::keyForAxis($set, $overrideAxis);
    }

    public function validate(ThresholdOverrideRequest $request): ?OverrideValidationFailure
    {
        return $this->strategy->validate($request);
    }

    private static function keyForAxis(RuleOptionKeySet $set, string $axis): ?string
    {
        $axes = $set->overrideAxes();
        if ($axes !== []) {
            return $axes[$axis] ?? null;
        }

        $bands = $set->bands();
        if ($bands !== []) {
            $band = $bands[0];

            return match ($axis) {
                'warning' => $band->warning,
                'error' => $band->error,
                default => null,
            };
        }

        return null;
    }
}
