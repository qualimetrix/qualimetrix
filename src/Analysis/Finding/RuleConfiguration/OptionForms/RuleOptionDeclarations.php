<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;

/** The four disjoint recognition states of an option key. */
final readonly class RuleOptionDeclarations
{
    /**
     * @param array<string, string> $accepted
     * @param array<string, RuleOptionShape> $shapes
     * @param array<string, string> $validated
     * @param array<string, string> $answered
     */
    private function __construct(
        private array $accepted,
        private array $shapes,
        private array $validated,
        private array $answered,
    ) {}

    /** @param array<string, RuleOptionShape> $accepted */
    public static function of(array $accepted): self
    {
        $indexed = self::index(array_map(strval(...), array_keys($accepted)), []);
        $shapes = [];
        foreach ($accepted as $key => $shape) {
            $shapes[ConfigKeySpelling::normalize((string) $key)] = $shape;
        }
        return new self($indexed, $shapes, [], []);
    }

    public function alsoAnsweredByTheClass(string ...$keys): self
    {
        $taken = $this->accepted + $this->validated + $this->answered;
        return new self($this->accepted, $this->shapes, $this->validated, $this->answered + self::index(array_values($keys), $taken));
    }

    public function alsoAcceptedAndValidatedByTheClass(string $key, RuleOptionShape $shape): self
    {
        $taken = $this->accepted + $this->validated + $this->answered;
        $indexed = self::index([$key], $taken);
        return new self($this->accepted, $this->shapes + [ConfigKeySpelling::normalize($key) => $shape], $this->validated + $indexed, $this->answered);
    }

    /** @param array<string, class-string<LevelOptionsInterface>> $levelOptionsClasses */
    public function withLevelSlots(array $levelOptionsClasses): self
    {
        $slots = array_map(strval(...), array_keys($levelOptionsClasses));
        $shapes = $this->shapes;
        $taken = $this->accepted + $this->validated + $this->answered;
        foreach ($slots as $slot) {
            $shapes[ConfigKeySpelling::normalize($slot)] = RuleOptionShape::block()->orNull();
        }
        return new self($this->accepted + self::index($slots, $taken), $shapes, $this->validated, $this->answered);
    }

    public function knows(string $key): bool
    {
        return isset($this->accepted[$key]) || isset($this->validated[$key]) || isset($this->answered[$key]);
    }

    public function accepts(string $key): bool
    {
        return isset($this->accepted[$key]) || isset($this->validated[$key]);
    }

    public function spellingOf(string $key): ?string
    {
        return $this->accepted[$key] ?? $this->validated[$key] ?? null;
    }

    public function shapeOf(string $key): ?RuleOptionShape
    {
        return $this->shapes[$key] ?? null;
    }

    /** @return list<string> */
    public function acceptedForDisplay(): array
    {
        $spellings = array_values($this->accepted + $this->validated);
        sort($spellings);
        return $spellings;
    }

    /**
     * @param list<string> $keys
     * @param array<string, string> $taken
     *
     * @return array<string, string>
     */
    private static function index(array $keys, array $taken): array
    {
        $indexed = [];
        foreach ($keys as $key) {
            $normalized = ConfigKeySpelling::normalize($key);
            if ($normalized === '') {
                throw new LogicException('A rule option key set cannot declare a blank key.');
            }
            if (ConfigKeySpelling::rewriteLike($normalized, 'a-b') !== $key) {
                throw new LogicException(\sprintf('Rule option key "%s" must be declared in canonical kebab spelling ("%s").', $key, ConfigKeySpelling::rewriteLike($normalized, 'a-b')));
            }
            if (isset($taken[$normalized]) || isset($indexed[$normalized])) {
                throw new LogicException(\sprintf('Rule option key "%s" is declared twice; the four key states must stay disjoint.', $key));
            }
            $indexed[$normalized] = $key;
        }
        return $indexed;
    }
}
