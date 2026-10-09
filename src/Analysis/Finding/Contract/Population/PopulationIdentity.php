<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

final readonly class PopulationIdentity
{
    private const array UNITS = [
        'authored-type-occurrence', 'baseline-diagnostic-record', 'callable', 'configuration-site',
        'configured-discovery-selector', 'configured-framework-selector', 'configured-method-selector',
        'configured-suppression-value-occurrence', 'copy-occurrence', 'cycle', 'declaration',
        'declared-exclude-clause', 'dependency-edge', 'directive-site', 'invocation', 'logical-class',
        'namespace', 'occurrence', 'precedence-pair', 'project',
    ];

    private function __construct(public string $canonical, public string $unit)
    {
        if ($canonical === '' || !self::knowsUnit($unit)) {
            throw new LogicException('Population identity requires a canonical member and declared unit.');
        }
    }

    public static function knowsUnit(string $unit): bool
    {
        return \in_array($unit, self::UNITS, true);
    }

    public static function declaration(DeclarationPath $path): self
    {
        return new self($path->toCanonical(), 'declaration');
    }

    public static function aggregate(SymbolPath $path): self
    {
        return new self($path->toCanonical(), match ($path->getType()->value) {
            'namespace' => 'namespace', 'project' => 'project',
            default => throw new LogicException('Population aggregate requires namespace or project identity.'),
        });
    }

    public static function subject(MetricSubject $subject, string $unit = 'declaration'): self
    {
        return new self($subject->toCanonical(), $unit);
    }

    public static function occurrence(string $authority, int $ordinal, string $unit = 'occurrence'): self
    {
        if ($authority === '' || $ordinal < 0) {
            throw new LogicException('Native population occurrence requires authority and ordinal.');
        }
        return new self(json_encode([$authority, $ordinal], \JSON_THROW_ON_ERROR), $unit);
    }

    /** @param non-empty-list<string> $members */
    public static function cycle(array $members): self
    {
        if ($members === [] || \in_array('', $members, true)) {
            throw new LogicException('Cycle population requires named members.');
        }
        sort($members, \SORT_STRING);
        return new self(json_encode(array_values(array_unique($members)), \JSON_THROW_ON_ERROR), 'cycle');
    }

    public static function edge(string $source, string $target): self
    {
        if ($source === '' || $target === '') {
            throw new LogicException('Edge population requires both logical endpoints.');
        }
        return new self(json_encode([$source, $target], \JSON_THROW_ON_ERROR), 'dependency-edge');
    }

    public static function selector(string $canonical, string $unit): self
    {
        return new self($canonical, $unit);
    }

    public static function clause(string $canonical): self
    {
        return new self($canonical, 'declared-exclude-clause');
    }

    public static function invocation(string $producer): self
    {
        return new self($producer, 'invocation');
    }
}
