<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

enum SymbolType: string
{
    case Method = 'method';
    case Function_ = 'function';
    case Class_ = 'class';
    case File = 'file';
    case Namespace_ = 'namespace';
    case Project = 'project';

    /**
     * The kind a {@see SymbolPath::toCanonical()} string was built for, read
     * off the prefix it starts with, or null when no kind writes it.
     */
    public static function ofCanonical(string $canonical): ?self
    {
        foreach (self::cases() as $type) {
            if (str_starts_with($canonical, $type->canonicalPrefix())) {
                return $type;
            }
        }

        return null;
    }

    /** The prefix {@see SymbolPath::toCanonical()} writes for this kind. */
    public function canonicalPrefix(): string
    {
        return match ($this) {
            self::Method => 'callable:',
            self::Function_ => 'func:',
            self::Class_ => 'class:',
            self::File => 'file:',
            self::Namespace_ => 'ns:',
            self::Project => 'project:',
        };
    }
}
