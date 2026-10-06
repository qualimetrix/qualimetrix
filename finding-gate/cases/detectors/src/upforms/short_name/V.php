<?php
namespace App\SHORT_NAME;

final class V
{
    private const C = 1;
    private static int $p = 0;
    public function run(): array
    {
        $a = V::m();
        $b = V::C; $c = V::$p;
        return [$a];
    }
    private static function m(): int { return 1; }
    private function im(): int { return 1; }
}
