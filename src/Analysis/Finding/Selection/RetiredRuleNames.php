<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Selection;

/** Consumer migration facts that cannot be inferred from name similarity. */
final class RetiredRuleNames
{
    public static function replacementFor(string $name): ?string
    {
        return $name === 'design.lcom' ? 'cohesion.lcom' : null;
    }

    public static function diagnosticFor(string $selector): ?string
    {
        return $selector === 'code-smell.*'
            ? 'The group "code-smell.*" no longer includes "design.god-class" or "design.data-class". Name those producers explicitly if they should remain selected.'
            : null;
    }
}
