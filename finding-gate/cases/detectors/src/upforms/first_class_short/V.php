<?php
namespace App\FIRST_CLASS_SHORT;

final class V
{
    private const C = 1;
    private static int $p = 0;
    public function run(): array
    {
        $a = V::m(...);
        
        return [$a];
    }
    private static function m(): int { return 1; }
    private function im(): int { return 1; }
}
