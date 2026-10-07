<?php
namespace App\NEW_SHORT_RECEIVER;

final class V
{
    private const C = 1;
    private static int $p = 0;
    public function run(): array
    {
        $a = (new V())->im();
        
        return [$a];
    }
    private static function m(): int { return 1; }
    private function im(): int { return 1; }
}
