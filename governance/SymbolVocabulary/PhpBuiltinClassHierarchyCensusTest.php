<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SymbolVocabulary;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;
use ReflectionClass;

/**
 * The hierarchy table is hand-kept for the reason the registry is: layer
 * membership read from the running PHP made `class X extends
 * \Uri\InvalidUriException` a member of a layer on PHP 8.5 and of none on 8.4.
 * This control compares the table and refuses; it never writes it.
 *
 * A name is compared wherever this PHP declares it and nowhere else, the
 * policy {@see PhpBuiltinClassRegistryCensusTest} already applies to the
 * names. A name no developer machine loads — `Pdo\Firebird`, `EnchantBroker`
 * and `EnchantDictionary` — is verified by the CI job, which pins `enchant` and
 * `pdo_firebird`; {@see PhpBuiltinClassRegistryCensusTest} refuses a CI run in
 * which a pinned extension did not load, because this comparison would skip
 * its names without a red.
 *
 * No version cells: on every name PHP 8.4 and 8.5 both declare, their parent,
 * interfaces and class-level attributes were measured identical. A later PHP
 * that moves a supertype reddens the name on that PHP, and the name then needs
 * a decision, not a cell.
 */
final class PhpBuiltinClassHierarchyCensusTest extends TestCase
{
    /**
     * The fewest names a supported environment compares, so that a run which
     * quietly compared less is refused rather than read as agreement.
     *
     * The registry census measured 240 names declared by a stock `php:8.4-cli`,
     * the leanest environment this repository supports.
     */
    private const int COMPARED_NAMES_FLOOR = 240;

    private const array MAPS = ['INTERFACE_NAMES', 'PARENTS', 'INTERFACES', 'ATTRIBUTES'];

    /**
     * The table answers for the registry's names by delegation; a key the
     * registry does not list would be a fact no lookup ever reaches.
     */
    #[Test]
    public function itKeysEveryMapByRegisteredNamesOnly(): void
    {
        $unregistered = [];
        foreach (self::MAPS as $map) {
            foreach (array_keys(self::map($map)) as $name) {
                if (!PhpBuiltinClassRegistry::isBuiltin($name)) {
                    $unregistered[] = \sprintf('%s in %s', $name, $map);
                }
            }
        }

        $interfacesWithAParent = array_keys(array_intersect_key(self::map('PARENTS'), self::map('INTERFACE_NAMES')));

        foreach ($interfacesWithAParent as $name) {
            $unregistered[] = $name . ' is an interface with a parent class';
        }

        self::assertSame([], $unregistered, \sprintf(
            "The hierarchy maps disagree with the registry:\n  %s",
            implode("\n  ", $unregistered),
        ));
    }

    /**
     * A walk that reaches a name through the table must find that name in the
     * table again; otherwise the chain would end on a name nothing answers.
     */
    #[Test]
    public function itNamesOnlyRegisteredTypesAboveAnyName(): void
    {
        $unregistered = [];
        foreach (self::registeredNames() as $name) {
            $claimed = self::claimed($name);
            $above = [...($claimed['parent'] === null ? [] : [$claimed['parent']]), ...$claimed['interfaces'], ...$claimed['attributes']];
            foreach ($above as $supertype) {
                if (!PhpBuiltinClassRegistry::isBuiltin($supertype)) {
                    $unregistered[] = \sprintf('%s above %s', $supertype, $name);
                }
            }
        }

        self::assertSame([], $unregistered, \sprintf(
            "The table names a supertype the registry does not list:\n  %s",
            implode("\n  ", $unregistered),
        ));
    }

    #[Test]
    public function itAgreesWithThisPhpOnEveryNameItDeclares(): void
    {
        $compared = 0;
        $divergent = [];

        foreach (self::registeredNames() as $name) {
            if (!class_exists($name, false) && !interface_exists($name, false)) {
                continue;
            }
            $reflection = new ReflectionClass($name);
            // A user-land class under a PHP name is a polyfill, not PHP.
            if (!$reflection->isInternal()) {
                continue;
            }
            $compared++;

            $parent = $reflection->getParentClass();
            $actual = self::normalised([
                'interface' => $reflection->isInterface(),
                'parent' => $parent === false ? null : $parent->getName(),
                'interfaces' => $reflection->getInterfaceNames(),
                'attributes' => array_map(static fn($attribute): string => $attribute->getName(), $reflection->getAttributes()),
            ]);
            $claimed = self::normalised(self::claimed($name));

            if ($actual !== $claimed) {
                $divergent[$name] = ['this PHP' => $actual, 'table' => $claimed];
            }
        }

        self::assertSame([], $divergent, "The hierarchy table and this PHP disagree:\n" . print_r($divergent, true));

        self::assertGreaterThanOrEqual(self::COMPARED_NAMES_FLOOR, $compared, \sprintf(
            'Only %d names were compared, below the %d this environment must reach. A run that compares '
                . 'less is not a run that found agreement.',
            $compared,
            self::COMPARED_NAMES_FLOOR,
        ));
    }

    /**
     * @return array{interface: bool, parent: ?string, interfaces: list<string>, attributes: list<string>}
     */
    private static function claimed(string $name): array
    {
        /** @var string|null $parent */
        $parent = self::map('PARENTS')[$name] ?? null;
        /** @var list<string> $interfaces */
        $interfaces = self::map('INTERFACES')[$name] ?? [];
        /** @var list<string> $attributes */
        $attributes = self::map('ATTRIBUTES')[$name] ?? [];

        return [
            'interface' => isset(self::map('INTERFACE_NAMES')[$name]),
            'parent' => $parent,
            'interfaces' => $interfaces,
            'attributes' => $attributes,
        ];
    }

    /**
     * @param array{interface: bool, parent: ?string, interfaces: list<string>, attributes: list<string>} $facts
     *
     * @return array{interface: bool, parent: ?string, interfaces: list<string>, attributes: list<string>}
     */
    private static function normalised(array $facts): array
    {
        // Reflection's order is an implementation detail; the table's is sorted.
        sort($facts['interfaces']);
        sort($facts['attributes']);

        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(string $constant): array
    {
        $map = (new ReflectionClass(PhpBuiltinClassHierarchy::class))->getConstant($constant);
        self::assertIsArray($map, $constant);

        /** @var array<string, mixed> $map */
        return $map;
    }

    /**
     * @return list<string>
     */
    private static function registeredNames(): array
    {
        $classes = (new ReflectionClass(PhpBuiltinClassRegistry::class))->getConstant('BUILTIN_CLASSES');
        self::assertIsArray($classes);

        /** @var list<string> $names */
        $names = array_keys($classes);

        return $names;
    }
}
