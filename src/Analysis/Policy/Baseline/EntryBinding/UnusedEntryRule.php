<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\EntryBinding;

use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineAuditChannels;
use Qualimetrix\Core\Symbol\SymbolLevel;

final class UnusedEntryRule extends AbstractRule
{
    public const string NAME = BaselineAuditChannels::UNUSED_ENTRY;

    public const string DESCRIPTION = 'Reports baseline entries that no longer bind a measured finding or cannot be applied.';
    public const string DOCS_PAGE = 'rules/baseline.md';
    public const int REMEDIATION_MINUTES = 5;
    public const ChannelShape SHAPE = ChannelShape::Occurrence;
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = false;

    /** @return class-string<UnusedEntryOptions> */
    public static function getOptionsClass(): string
    {
        return UnusedEntryOptions::class;
    }

    /** @return array<string, ChannelDeclaration> */
    public static function channelDeclarations(): array
    {
        return [self::NAME => ChannelDeclaration::occurrence(SymbolLevel::Project)];
    }

    /** @return list<Finding> */
    public function analyze(AnalysisContext $context): array
    {
        return [];
    }
}
