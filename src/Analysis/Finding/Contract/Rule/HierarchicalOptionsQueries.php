<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Queries the exact metric-owned level slots, keeping their declaration order. */
trait HierarchicalOptionsQueries
{
    /** @return non-empty-array<string, LevelOptionsInterface> */
    abstract protected function levelOptions(): array;

    public function isEnabled(): bool
    {
        foreach ($this->levelOptions() as $option) {
            if ($option->isEnabled()) {
                return true;
            }
        }
        return false;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        $options = $this->levelOptions();
        return $options[array_key_first($options)]->getSeverity($value);
    }

    public function forLevel(SymbolLevel $level): LevelOptionsInterface
    {
        $options = $this->levelOptions();
        if (!isset($options[$level->value])) {
            $separator = strrpos(self::class, '\\');
            $class = $separator === false ? self::class : substr(self::class, $separator + 1);
            throw new InvalidArgumentException(\sprintf('Level %s is not supported by %s', $level->value, str_replace('Options', 'Rule', $class)));
        }
        return $options[$level->value];
    }

    public function isLevelEnabled(SymbolLevel $level): bool
    {
        $options = $this->levelOptions();
        return isset($options[$level->value]) && $options[$level->value]->isEnabled();
    }

    /** @return list<SymbolLevel> */
    public function getSupportedLevels(): array
    {
        return array_map(SymbolLevel::from(...), array_keys($this->levelOptions()));
    }
}
