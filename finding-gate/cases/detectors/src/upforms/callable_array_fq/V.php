<?php
namespace App\CALLABLE_ARRAY_FQ;

final class V
{
    private const C = 1;
    private static int $p = 0;
    public function run(): array
    {
        $a = array_map([\App\CALLABLE_ARRAY_FQ\V::class, 'm'], []);
        
        return [$a];
    }
    private static function m(): int { return 1; }
    private function im(): int { return 1; }
}
