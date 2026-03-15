<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration;

/**
 * Provides the list of known/registered rule names.
 *
 * Implemented by the Infrastructure layer (RuleRegistry adapter) and injected
 * into ConfigFileStage to enable unknown-rule-name warnings.
 */
interface KnownRuleNamesProviderInterface
{
    /**
     * Returns all registered rule names (e.g. "complexity.cyclomatic").
     *
     * @return list<string>
     */
    public function getKnownRuleNames(): array;
}
