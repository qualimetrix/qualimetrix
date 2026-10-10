<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\TraitDefinitionFacts;
use Qualimetrix\Core\Symbol\TraitMethodSource;
use Qualimetrix\Core\Symbol\TraitUseFacts;

/** Decodes one declaration's worker-safe rows for global response resolution. */
final class RfcDeclarationFacts
{
    /** @var array<string, TraitMethodSource> */
    public array $methods = [];

    /** @var array<string, TraitMethodSource> */
    public array $hooks = [];

    /** @var array<string, list<array<string, scalar>>> */
    public array $responses = [];

    /** @var array<string, string> */
    public array $properties = [];

    /** @var array<string, true> */
    public array $propertyNames = [];

    /** @var list<TraitUseFacts> */
    public array $uses = [];

    public string $kind = '';

    /** @param list<array<string, scalar>> $rows
     * @param array<string, true> $members
     */
    public function __construct(public readonly DeclarationPath $declaration, array $rows, array $members)
    {
        $groups = [];
        foreach ($rows as $row) {
            switch ($row['tag']) {
                case 'declaration':
                    $this->kind = (string) $row['kind'];
                    break;
                case 'method':
                    $this->member($row, $members);
                    break;
                case 'response':
                    $this->responses[(string) $row['caller']][] = $row;
                    break;
                case 'property':
                    $this->property($row);
                    break;
                case 'trait':
                case 'adaptation':
                    $groups[(int) $row['group']][] = $row;
                    break;
            }
        }
        foreach ($groups as $group) {
            $this->uses[] = self::useFacts($group);
        }
    }

    /** @param array<string, scalar> $row
     * @param array<string, true> $members
     */
    private function member(array $row, array $members): void
    {
        $member = (string) $row['member'];
        if (isset($members[$member])) {
            $visibility = match ($row['visibility']) {
                'private' => 'private', 'protected' => 'protected', default => 'public',
            };
            $source = new TraitMethodSource($this->declaration->logical->toString(), $member, $visibility, (bool) $row['abstract']);
            if ($row['hook'] === true) {
                $this->hooks[$member] = $source;
            } else {
                $this->methods[$member] = $source;
            }
        }
    }

    /** @param array<string, scalar> $row */
    private function property(array $row): void
    {
        $this->propertyNames[(string) $row['name']] = true;
        if (isset($row['type'])) {
            $this->properties[(string) $row['name']] = (string) $row['type'];
        }
    }

    public function traitFacts(): TraitDefinitionFacts
    {
        return new TraitDefinitionFacts($this->methods, $this->uses);
    }

    /** @param list<array<string, scalar>> $rows */
    private static function useFacts(array $rows): TraitUseFacts
    {
        $traits = $aliases = $precedences = [];
        foreach ($rows as $row) {
            if ($row['tag'] === 'trait') {
                $traits[] = (string) $row['trait'];
            } elseif (isset($row['insteadOf'])) {
                $precedences[] = ['trait' => (string) $row['trait'], 'method' => (string) $row['method'], 'insteadOf' => [(string) $row['insteadOf']]];
            } else {
                $visibility = match ($row['visibility']) {
                    'private' => 'private', 'protected' => 'protected', 'public' => 'public', default => null,
                };
                $aliases[] = ['trait' => isset($row['trait']) ? (string) $row['trait'] : null, 'method' => (string) $row['method'], 'alias' => isset($row['alias']) ? (string) $row['alias'] : null, 'visibility' => $visibility];
            }
        }

        return new TraitUseFacts($traits, $aliases, $precedences);
    }
}
