<?php
namespace App\CALLABLE_STRING_FQ;

final class V
{
    private const C = 1;
    private static int $p = 0;
    public function run(): array
    {
        $a = array_map('App\CALLABLE_STRING_FQ\V::m', []);
        
        return [$a];
    }
    private static function m(): int { return 1; }
    private function im(): int { return 1; }
}
