<?php

namespace Corpus\Security;

class Queries
{
    public function byId(\PDO $pdo, string $id): mixed
    {
        return $pdo->query("SELECT * FROM users WHERE id = " . $_GET['id'] . $id);
    }

    public function byName(\mysqli $link): mixed
    {
        return mysqli_query($link, "SELECT * FROM users WHERE name = '{$_POST['name']}'");
    }
}
