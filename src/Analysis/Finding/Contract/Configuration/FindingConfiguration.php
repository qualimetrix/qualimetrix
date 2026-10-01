<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Configuration;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;

final readonly class FindingConfiguration
{
    public ResolvedDocument $document;
    public function __construct(
        public RuleOptionsDocument $ruleOptions,
        public FindingCliOverrides $cliOverrides,
        public ?ResolvedRuleOptions $resolvedOptions = null,
        ?ResolvedDocument $document = null,
        public ?RuleEnablement $enablement = null,
        public ?ChannelUniverseInterface $channels = null,
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
            document: $document,
        );
    }

    public function withResolvedOptions(ResolvedRuleOptions $options): self
    {
        return new self($this->ruleOptions, $this->cliOverrides, $options, $this->document, $this->enablement, $this->channels);
    }

    public function withChannelUniverse(ChannelUniverseInterface $channels): self
    {
        return new self($this->ruleOptions, $this->cliOverrides, $this->resolvedOptions, $this->document, $this->enablement, $channels);
    }

    public function withEnablement(RuleEnablement $enablement): self
    {
        return new self($this->ruleOptions, $this->cliOverrides, $this->resolvedOptions, $this->document, $enablement, $this->channels);
    }

    /** No rule options, no command-line overrides, every rule selected. */
    public static function none(): self
    {
        return new self(new RuleOptionsDocument(), new FindingCliOverrides());
    }

    /** @param array<string, mixed> $rules */
    public function withRuleOptions(array $rules): self
    {
        return new self(new RuleOptionsDocument($rules), $this->cliOverrides, $this->resolvedOptions, $this->document, $this->enablement, $this->channels);
    }

    /** @param array<string, array<string, mixed>> $options */
    public function withCliOverrides(array $options): self
    {
        return new self($this->ruleOptions, new FindingCliOverrides($options), $this->resolvedOptions, $this->document, $this->enablement, $this->channels);
    }

}
