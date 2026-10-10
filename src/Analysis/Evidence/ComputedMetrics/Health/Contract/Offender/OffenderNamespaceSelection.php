<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender;

use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

final readonly class OffenderNamespaceSelection
{
    /** @param list<NamespacePattern> $patterns */
    public function __construct(private array $patterns) {}

    public function matches(SymbolPath $symbol): bool
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($symbol->namespace ?? '')
                || ($symbol->getType() === SymbolType::Class_ && $pattern->matches($symbol->toString()))) {
                return true;
            }
        }

        return false;
    }
}
