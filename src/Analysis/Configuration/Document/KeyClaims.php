<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * The keys one layer wrote into one map: each recognised against the map's
 * dictionary and claimed once, whichever of its accepted spellings wrote it.
 */
final class KeyClaims
{
    /** @var array<string, string> canonical key => the spelling that claimed it */
    private array $claimed = [];

    /** @param list<string> $dictionary canonical keys */
    private function __construct(
        private readonly array $dictionary,
        private readonly ReadingContext $at,
    ) {}

    /** @param list<string> $dictionary canonical keys */
    public static function of(array $dictionary, ReadingContext $at): self
    {
        return new self($dictionary, $at);
    }

    /**
     * @throws ConfigurationRefusal for a key the map does not accept, or a second spelling of a claimed one
     *
     * @return string the canonical key
     */
    public function claim(string $written, AuthoredNode $node): string
    {
        $canonical = KeyRecognition::recognise($written, $this->dictionary, $this->at->child($written, $written, $node));

        $first = $this->claimed[$canonical] ?? null;
        if ($first !== null) {
            throw $this->at->key($written, $canonical)->refusal(\sprintf(
                'Keys "%s" and "%s" in %s are two spellings of one key, and a layer may set it only once. Keep one of them.',
                $first,
                $written,
                $this->at->where(),
            ));
        }

        $this->claimed[$canonical] = $written;

        return $canonical;
    }

    /** The spelling that claimed a canonical key. */
    public function spellingOf(string $canonical): string
    {
        return $this->claimed[$canonical];
    }
}
