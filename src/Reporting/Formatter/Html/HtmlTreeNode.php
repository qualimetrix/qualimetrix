<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

/**
 * Internal mutable VO for tree construction.
 *
 * Used as a builder during tree construction, then serialized to array via toArray().
 *
 * @internal
 */
final class HtmlTreeNode
{
    public string $name;

    /** Full namespace path (e.g., "App\Payment"). */
    public string $path;

    /** Node type: "project", "namespace", or "class". */
    public string $type;

    /** @var array<string, int|float|null> */
    public array $metrics = [];

    /** @var list<array{ruleName: string, violationCode: string, message: string, recommendation: ?string, severity: string, metricValue: int|float|null, symbolPath: string, file: string, line: int|null}> */
    public array $violations = [];

    public int $violationCountTotal = 0;

    public int $debtMinutes = 0;

    /** @var list<self> */
    public array $children = [];

    public function __construct(string $name, string $path, string $type)
    {
        $this->name = $name;
        $this->path = $path;
        $this->type = $type;
    }

    /**
     * Converts to JSON-serializable array. Recursive for children.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'name' => $this->name,
            'path' => $this->path,
            'type' => $this->type,
            'metrics' => (object) $this->metrics, // Force {} in JSON even when empty
            'violations' => $this->violations,
            'violationCountTotal' => $this->violationCountTotal,
            'debtMinutes' => $this->debtMinutes,
        ];

        if ($this->children !== []) {
            $result['children'] = array_map(
                static fn(self $child): array => $child->toArray(),
                $this->children,
            );
        }

        return $result;
    }
}
