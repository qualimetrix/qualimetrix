<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CircularDependency;

use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Short display labels for the members of a single cycle.
 *
 * A cycle is rendered with short class names so the path stays readable, but two
 * classes of the same cycle may share a short name (`App\Billing\Service` and
 * `App\Orders\Service` both render as `Service`), which turns the path into
 * `Service → Service → Service` and tells the reader nothing.
 *
 * Each member therefore gets the shortest trailing namespace suffix that no
 * other member of the cycle also ends with: members with an unambiguous short
 * name keep it (`Exporter`), the rest grow by whole namespace segments until
 * they are telling (`Billing\Service` vs `Orders\Service`).
 *
 * "No other member ends with it" is a stronger requirement than "no other
 * member picked the same label", and deliberately so: with `App\Log\Writer` and
 * `Acme\App\Log\Writer` in one cycle, the labels `App\Log\Writer` and
 * `Acme\App\Log\Writer` are distinct strings, yet the first still reads as a
 * shortening of the second. A member whose own fully qualified name is a
 * suffix of another member's — including a class in the global namespace facing
 * a namespaced namesake — has no telling suffix at all, so it is anchored at
 * the root instead: `\Writer`, the way PHP itself writes it.
 *
 * These labels are for reading only. Machine consumers must use the fully
 * qualified names from `Cycle::toStructuredData()`, and the cycle's identity
 * comes from `Cycle::getClasses()`.
 */
final readonly class CycleMemberLabels
{
    /**
     * @param array<string, string> $labels Fully qualified name => display label
     */
    private function __construct(
        private array $labels,
    ) {}

    /**
     * @param list<SymbolPath> $members All members of one cycle, in any order and
     *                                  with duplicates. Pass the whole membership
     *                                  rather than just the displayed path: a
     *                                  namesake that only lies on a longer route
     *                                  back still makes a short name ambiguous.
     */
    public static function forMembers(array $members): self
    {
        /** @var array<string, list<string>> $segments */
        $segments = [];
        foreach ($members as $member) {
            $name = $member->toString();
            if (!isset($segments[$name])) {
                $segments[$name] = explode('\\', $name);
            }
        }

        $bearers = self::countSuffixBearers($segments);

        $labels = [];
        foreach ($segments as $name => $parts) {
            $labels[$name] = self::tellingSuffix($parts, $bearers);
        }

        return new self($labels);
    }

    /**
     * Returns the display label for a cycle member.
     *
     * Members that were not part of the cycle this instance was built from fall
     * back to their fully qualified name.
     */
    public function labelFor(SymbolPath $path): string
    {
        $name = $path->toString();

        return $this->labels[$name] ?? $name;
    }

    /**
     * Counts, for every suffix of every member, how many members end with it.
     *
     * A member contributes each of its own suffixes exactly once, so a count
     * above one means the suffix cannot tell its bearers apart.
     *
     * @param array<string, list<string>> $segments
     *
     * @return array<string, int>
     */
    private static function countSuffixBearers(array $segments): array
    {
        $bearers = [];
        foreach ($segments as $parts) {
            $total = \count($parts);
            for ($depth = 1; $depth <= $total; $depth++) {
                $suffix = self::suffix($parts, $depth);
                $bearers[$suffix] = ($bearers[$suffix] ?? 0) + 1;
            }
        }

        return $bearers;
    }

    /**
     * @param list<string> $parts
     * @param array<string, int> $bearers
     */
    private static function tellingSuffix(array $parts, array $bearers): string
    {
        $total = \count($parts);
        for ($depth = 1; $depth <= $total; $depth++) {
            $suffix = self::suffix($parts, $depth);
            if (($bearers[$suffix] ?? 0) === 1) {
                return $suffix;
            }
        }

        // Not even the full name tells this member apart — another member ends
        // with it. Anchoring at the root does, and says which of the two it is.
        return '\\' . implode('\\', $parts);
    }

    /**
     * @param list<string> $parts
     */
    private static function suffix(array $parts, int $depth): string
    {
        return implode('\\', \array_slice($parts, -$depth));
    }
}
