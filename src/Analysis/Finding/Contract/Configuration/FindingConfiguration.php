<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Configuration;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;

final readonly class FindingConfiguration
{
    public ResolvedDocument $document;
    public function __construct(
        public RuleOptionsDocument $ruleOptions,
        public FindingCliOverrides $cliOverrides,
        public RuleSelection $selection,
        public ?ResolvedRuleOptions $resolvedOptions = null,
        ?ResolvedDocument $document = null,
    ) {
        $this->document = $document ?? ResolvedDocument::empty();
    }

    public static function fromDocument(ConfigurationDocument $configuration): self
    {
        $document = $configuration->resolved();
        $rules = $document->get('rules')?->plain() ?? [];
        if (!\is_array($rules)) {
            throw new LogicException('The resolved rules root must be a map.');
        }
        $transport = [];
        foreach ($rules as $name => $value) {
            if (!\is_string($name)) {
                throw new LogicException('A resolved rule producer must be named by a string.');
            }
            $transport[$name] = $value;
        }
        return new self(
            new RuleOptionsDocument($transport),
            new FindingCliOverrides(),
            new RuleSelection(self::strings($document, 'only_rules'), self::strings($document, 'disabled_rules')),
            document: $document,
        );
    }

    /** @return list<string> */
    private static function strings(ResolvedDocument $document, string $root): array
    {
        $node = $document->get($root);
        if ($node === null) {
            return [];
        }
        if (!$node instanceof ResolvedListInterface) {
            throw new LogicException('A resolved rule selection must be a list.');
        }
        $result = [];
        foreach ($node->items() as $item) {
            $value = $item->plain();
            if (!\is_string($value)) {
                throw new LogicException('A resolved rule selector must be a string.');
            }
            $result[] = $value;
        }
        return $result;
    }

    public function withResolvedOptions(ResolvedRuleOptions $options): self
    {
        return new self($this->ruleOptions, $this->cliOverrides, $this->selection, $options, $this->document);
    }

    /** No rule options, no command-line overrides, every rule selected. */
    public static function none(): self
    {
        return new self(new RuleOptionsDocument(), new FindingCliOverrides(), new RuleSelection());
    }

    /** @param array<string, mixed> $rules */
    public function withRuleOptions(array $rules): self
    {
        return new self(new RuleOptionsDocument($rules), $this->cliOverrides, $this->selection);
    }

    /** @param array<string, array<string, mixed>> $options */
    public function withCliOverrides(array $options): self
    {
        return new self($this->ruleOptions, new FindingCliOverrides($options), $this->selection);
    }

    public function withSelection(RuleSelection $selection): self
    {
        return new self($this->ruleOptions, $this->cliOverrides, $selection, $this->resolvedOptions, $this->document);
    }
}
