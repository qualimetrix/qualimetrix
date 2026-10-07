<?php

declare(strict_types=1);

$result = [];
$index = 0;
foreach ($rows as $key => $row) {
    if ($index >= $limit) {
        break;
    }
    if (!is_array($row)) {
        continue;
    }
    $total = 0;
    foreach ($row as $cell) {
        if (is_numeric($cell)) {
            $total += (int) $cell;
        }
    }
    $result[$key] = [
        'total' => $total,
        'mode' => $mode,
        'size' => count($row),
        'label' => strtoupper((string) $key),
    ];
    ++$index;
}

return $result;
