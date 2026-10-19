<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;

/**
 * @qmx-threshold coupling.cbo 24 -- This public fact or option contract combines its declaration vocabulary with bounded implementation dependencies; its incoming readers do not independently justify a popularity exception.
 */
final readonly class FindingConfiguration
{
    public function __construct(
        public ResolvedDocument $document,
        public ?ResolvedRuleOptions $resolvedOptions = null,
        public ?RuleEnablement $enablement = null,
        public ?ChannelUniverseInterface $channels = null,
        /** @var list<ConfigurationDiagnostic> */
        public array $diagnostics = [],
    ) {}

    public static function fromDocument(ConfigurationDocument $configuration): self
    {
        return new self($configuration->resolved());
    }

    public function withResolvedOptions(ResolvedRuleOptions $options): self
    {
        return new self($this->document, $options, $this->enablement, $this->channels, $this->diagnostics);
    }

    public function withChannelUniverse(ChannelUniverseInterface $channels): self
    {
        return new self($this->document, $this->resolvedOptions, $this->enablement, $channels, $this->diagnostics);
    }

    public function withEnablement(RuleEnablement $enablement): self
    {
        return new self($this->document, $this->resolvedOptions, $enablement, $this->channels, $this->diagnostics);
    }

    /** @param list<ConfigurationDiagnostic> $diagnostics */
    public function withDiagnostics(array $diagnostics): self
    {
        return new self($this->document, $this->resolvedOptions, $this->enablement, $this->channels, $diagnostics);
    }

    public static function none(): self
    {
        return new self(ResolvedDocument::empty());
    }
}
