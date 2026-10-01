<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule\Override;

use LogicException;

/** Numeric override values together with the axes their author actually wrote. */
final readonly class ThresholdOverrideRequest
{
    /** @param list<OverrideAxis> $writtenAxes */
    public function __construct(
        public int|float|null $warning,
        public int|float|null $error,
        public OverrideSyntax $syntax,
        public array $writtenAxes,
    ) {
        match ($syntax) {
            OverrideSyntax::Shorthand => $this->requireShorthand(),
            OverrideSyntax::ExplicitAxes => $this->requireExplicitAxes(),
        };
    }

    public function hasAuthored(OverrideAxis $axis): bool
    {
        return \in_array($axis, $this->writtenAxes, true);
    }

    private function requireShorthand(): void
    {
        if ($this->warning === null || $this->error === null || ($this->warning <=> $this->error) !== 0 || $this->writtenAxes !== []) {
            throw new LogicException('A shorthand override requires equal non-null values and no authored axes.');
        }
    }

    private function requireExplicitAxes(): void
    {
        if ($this->writtenAxes === []) {
            throw new LogicException('An explicit override requires at least one authored axis.');
        }

        $seen = [];
        foreach ($this->writtenAxes as $axis) {
            if (isset($seen[$axis->value])) {
                throw new LogicException('An override axis cannot be authored twice.');
            }
            $seen[$axis->value] = true;
        }

        if ($this->hasAuthored(OverrideAxis::Warning) !== ($this->warning !== null)
            || $this->hasAuthored(OverrideAxis::Error) !== ($this->error !== null)) {
            throw new LogicException('Authored override axes must correspond exactly to non-null values.');
        }
    }
}
