<?php

namespace Corpus\Utf8\Public;

final class Kþ
{
    public function mé(array $rows): array
    {
        $out = [];
        foreach ($rows as $key => $row) {
            if ($row['active']) {
                $out[$key] = myÿfn($row['name']);
            } elseif ($row['pending']) {
                $out[$key] = null;
            }
        }
        ksort($out);
        return array_filter($out);
    }

    public function dependencies(): array
    {
        return [new Kÿ(), new \Corpus\Utf8\Private\WÿX()];
    }
}
