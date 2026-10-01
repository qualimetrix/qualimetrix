<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDeclarations;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionKeyMetadata;

/**
 * The option keys one options class — or one level slot of one — answers for.
 *
 * The owning class declares these forms before options are constructed.
 * The document schema, readers and CLI surface consume the same declaration.
 *
 * A key is in exactly one of four states, and the four are disjoint and
 * exhaustive:
 *
 * - **accepted** — written here, read here, printed in the "allowed here"
 *   sentence;
 * - **accepted and validated by the class** — writable and printed like an
 *   accepted key; its coarse ingress form is declared while the options class
 *   validates its own detailed semantics;
 * - **answered by the class** — a recognised key whose refusal wording is
 *   declared by its owner rather than inferred from its value form;
 * - **unknown** — everything else.
 *
 * Keys are declared in the canonical kebab spelling users type
 * (`docs/internal/CLI_CONVENTIONS.md`), and that is the only spelling
 * {@see self::acceptedForDisplay()} prints. Comparison folds both sides through
 * {@see ConfigKeySpelling::normalize()}, so snake, camel and kebab spellings of
 * one key stay the same key.
 */
final readonly class RuleOptionKeySet
{
    private function __construct(
        private RuleOptionDeclarations $declarations,
        private RuleOptionKeyMetadata $metadata,
    ) {}

    /** @param array<string, RuleOptionShape> $accepted */
    public static function of(array $accepted): self
    {
        return new self(RuleOptionDeclarations::of($accepted), new RuleOptionKeyMetadata());
    }

    public function alsoAnsweredByTheClass(string ...$keys): self
    {
        return new self($this->declarations->alsoAnsweredByTheClass(...$keys), $this->metadata);
    }

    public function alsoAcceptedAndValidatedByTheClass(string $key, RuleOptionShape $ingressShape): self
    {
        return new self($this->declarations->alsoAcceptedAndValidatedByTheClass($key, $ingressShape), $this->metadata);
    }

    /** @param array<string, class-string<LevelOptionsInterface>> $levelOptionsClasses */
    public function withLevelSlots(array $levelOptionsClasses): self
    {
        return new self($this->declarations->withLevelSlots($levelOptionsClasses), $this->metadata);
    }

    public function band(string $shorthand, string $warning, string $error, BandDirection $direction = BandDirection::Rising): self
    {
        foreach ([$shorthand, $warning, $error] as $key) {
            if (!$this->accepts(ConfigKeySpelling::normalize($key))) {
                throw new LogicException('A band must name declared option keys.');
            }
        }
        return new self($this->declarations, $this->metadata->withBand(new RuleOptionBand($shorthand, $warning, $error, $direction)));
    }

    /** @param non-empty-list<string> $paths */
    public function spreadingInto(string $key, array $paths): self
    {
        if (!$this->accepts(ConfigKeySpelling::normalize($key))) {
            throw new LogicException('A spreading key must be declared.');
        }
        return new self($this->declarations, $this->metadata->spreadingInto($key, $paths));
    }

    /** @param array<string, string> $axes */
    public function overriddenAs(array $axes): self
    {
        foreach ($axes as $key) {
            if (!$this->accepts(ConfigKeySpelling::normalize($key))) {
                throw new LogicException('An override axis must name a declared option.');
            }
        }
        return new self($this->declarations, $this->metadata->overriddenAs($axes));
    }

    public function retiring(string $key, string $summary): self
    {
        return new self($this->declarations, $this->metadata->retiring($key, $summary));
    }

    /** @return list<RuleOptionBand> */
    public function bands(): array
    {
        return $this->metadata->bands;
    }

    /** @return array<string, non-empty-list<string>> */
    public function spreading(): array
    {
        return $this->metadata->spreading;
    }

    /** @return array<string, string> */
    public function overrideAxes(): array
    {
        return $this->metadata->overrideAxes;
    }

    /** @return array<string, string> */
    public function retired(): array
    {
        return $this->metadata->retired;
    }

    public function knows(string $key): bool
    {
        return $this->declarations->knows($key);
    }

    public function accepts(string $key): bool
    {
        return $this->declarations->accepts($key);
    }

    public function spellingOf(string $key): ?string
    {
        return $this->declarations->spellingOf($key);
    }

    public function shapeOf(string $key): ?RuleOptionShape
    {
        return $this->declarations->shapeOf($key);
    }

    /** @return list<string> */
    public function acceptedForDisplay(): array
    {
        return $this->declarations->acceptedForDisplay();
    }
}
