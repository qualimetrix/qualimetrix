<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

use Closure;
use LogicException;

/** Fixed keys or named entries declared by one map. */
final readonly class NodeMapFacts
{
    /**
     * @param array<string, string> $retiredKeys
     * @param ?Closure(string): ?NodeSchema $namedEntrySchema
     */
    public function __construct(
        public KeyDictionary $keys,
        public ?string $bareField = null,
        public array $retiredKeys = [],
        public ?NameVocabulary $names = null,
        public ?NodeSchema $entry = null,
        public ?Closure $namedEntrySchema = null,
    ) {}

    public function withBareField(string $field): self
    {
        $target = $this->keys->fields()[$field] ?? null;
        if ($target?->scalar->forms !== [ScalarForm::Boolean]) {
            throw new LogicException('A bare map value requires a declared boolean field.');
        }
        return new self($this->keys, $field, $this->retiredKeys, $this->names, $this->entry, $this->namedEntrySchema);
    }

    /** @param array<string, string> $retiredKeys */
    public function withRetiredKeys(array $retiredKeys): self
    {
        foreach ($retiredKeys as $key => $sentence) {
            KeyDictionary::assertCanonical($key);
            if (\in_array($key, $this->keys->keys(), true)) {
                throw new LogicException(\sprintf('Retired key "%s" is still declared.', $key));
            }
            if ($sentence === '') {
                throw new LogicException('A retired key needs replacement wording.');
            }
        }
        return new self($this->keys, $this->bareField, $retiredKeys, $this->names, $this->entry, $this->namedEntrySchema);
    }

    public function describe(): string
    {
        return $this->bareField === null ? 'a map' : \sprintf('a map or a boolean for "%s"', $this->bareField);
    }

    public function entryForName(string $name): ?NodeSchema
    {
        return $this->namedEntrySchema === null ? $this->entry : ($this->namedEntrySchema)($name);
    }
}
