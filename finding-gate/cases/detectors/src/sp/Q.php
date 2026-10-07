<?php
namespace App\Q;
use Vendor\Audit\SensitiveParameter;
final class Q
{
    public function mis(#[SensitiveParameter] string $password): void {}
}
