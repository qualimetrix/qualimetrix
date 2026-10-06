<?php
namespace App\A;
final class X
{
    public function m(string $code, $password = 'hunter2secretvalue'): void
    {
        eval($code);
        var_dump($code);
        try { $this->w(); } catch (\Throwable $e) {}
        echo $_GET['x'];
        $a = @file_get_contents('x');
        goto end;
        end:
        exit(1);
    }
    private function w(): void {}
    public function f(bool $flag, string $password): void { for ($i = 0; $i < count([1]); $i++) {} }
}
final class Y
{
    private int $dead = 0;
    public function a(int $x): int { if ($x == $x) { return 1; } return 2; echo 'dead'; }
    public function b($a, $b, $c, $d, $e, $f, $g, $h): void {}
    public function c(string $sql, $db): void { $db->query("SELECT * FROM t WHERE id = " . $_GET['id']); exec("ls " . $_GET['d']); }
}
