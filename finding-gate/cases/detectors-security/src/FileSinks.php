<?php

declare(strict_types=1);

namespace Corpus\Security;

echo $_GET['name'];
$database->query('SELECT * FROM records WHERE id = ' . $_GET['id']);
exec('ls ' . $_GET['directory']);

$anonymous = new class {
    public function authenticate(string $password): string
    {
        return $password;
    }
};
