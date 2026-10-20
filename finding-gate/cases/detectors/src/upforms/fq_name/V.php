<?php
namespace App\FQ_NAME;

final class V
{
    private const C = 1;
    private static int $p = 0;
    public function run(): array
    {
        $a = \App\FQ_NAME\V::m();
        $b = \App\FQ_NAME\V::C; $c = \App\FQ_NAME\V::$p;
        return [$a];
    }
    private static function m(): int { return 1; }
    private function im(): int { return 1; }
}
