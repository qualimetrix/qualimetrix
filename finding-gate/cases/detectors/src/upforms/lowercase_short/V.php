<?php
namespace App\LOWERCASE_SHORT;

final class V
{
    private const C = 1;
    private static int $p = 0;
    public function run(): array
    {
        $a = v::m();
        $b = v::C; $c = v::$p;
        return [$a];
    }
    private static function m(): int { return 1; }
    private function im(): int { return 1; }
}
