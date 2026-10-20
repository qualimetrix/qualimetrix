<?php
namespace App;
final class S
{
    public function xss_plain(): void { echo $_GET['x']; }
    public function sql_plain(object $db): void { $db->query('SELECT * FROM t WHERE id = ' . $_GET['id']); }
    public function cmd_plain(): void { exec('ls ' . $_GET['d']); }
    public function xss_braced_single(): void { echo ${'_GET'}['x']; }
    public function sql_braced_single(object $db): void { $db->query('SELECT * FROM t WHERE id = ' . ${'_GET'}['id']); }
    public function cmd_braced_single(): void { exec('ls ' . ${'_GET'}['d']); }
    public function xss_braced_double(): void { echo ${"_GET"}['x']; }
    public function sql_braced_double(object $db): void { $db->query('SELECT * FROM t WHERE id = ' . ${"_GET"}['id']); }
    public function cmd_braced_double(): void { exec('ls ' . ${"_GET"}['d']); }
    public function xss_var_var(): void { $n = '_GET'; echo $$n['x']; }
    public function sql_var_var(object $db): void { $n = '_GET'; $db->query('SELECT * FROM t WHERE id = ' . $$n['id']); }
    public function cmd_var_var(): void { $n = '_GET'; exec('ls ' . $$n['d']); }
    public function xss_braced_concat(): void { echo ${'_' . 'GET'}['x']; }
    public function sql_braced_concat(object $db): void { $db->query('SELECT * FROM t WHERE id = ' . ${'_' . 'GET'}['id']); }
    public function cmd_braced_concat(): void { exec('ls ' . ${'_' . 'GET'}['d']); }
    public function xss_globals_key(): void { echo $GLOBALS['_GET']['x']; }
    public function sql_globals_key(object $db): void { $db->query('SELECT * FROM t WHERE id = ' . $GLOBALS['_GET']['id']); }
    public function cmd_globals_key(): void { exec('ls ' . $GLOBALS['_GET']['d']); }
    public function xss_request_plain(): void { echo $_REQUEST['x']; }
    public function sql_request_plain(object $db): void { $db->query('SELECT * FROM t WHERE id = ' . $_REQUEST['id']); }
    public function cmd_request_plain(): void { exec('ls ' . $_REQUEST['d']); }
}
