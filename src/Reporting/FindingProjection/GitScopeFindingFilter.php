<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\SymbolLevel;

final readonly class GitScopeFindingFilter implements FindingFilterInterface
{
    /**
     * @param array<string, true> $paths
     * @param array<string, true> $namespaces
     */
    public function __construct(
        private array $paths,
        private array $namespaces,
        private bool $includeAggregates,
        private ChannelFileScope $fileScope,
    ) {}

    public function shouldInclude(Finding $finding): bool
    {
        if (!$this->fileScope->isFileScoped($finding->channel())) {
            return true;
        }

        if ($finding->location->file !== null && isset($this->paths[$finding->location->file->value()])) {
            return true;
        }

        if (!$this->includeAggregates) {
            return false;
        }

        return match ($finding->level()) {
            SymbolLevel::Namespace_ => isset($this->namespaces[$finding->subject->toSymbolPath()->namespace ?? '']),
            SymbolLevel::Project => $finding->location->isNone() && $this->paths !== [],
            default => false,
        };
    }
}
