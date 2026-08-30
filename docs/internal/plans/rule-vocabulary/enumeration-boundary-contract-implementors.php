<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../vendor/autoload.php';
$root = dirname(__DIR__, 4);
$roots = [$root . '/src', $root . '/tests'];
$files = [];
foreach ($roots as $r) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r));
    foreach ($it as $f) { if ($f->isFile() && $f->getExtension() === 'php') { $files[] = $f->getPathname(); } }
}
sort($files);
$before = get_declared_classes();
foreach ($files as $f) {
    $src = file_get_contents($f);
    if (!preg_match('/^namespace\s+([^;]+);/m', $src, $ns)) { continue; }
    if (!preg_match_all('/^(?:final\s+|readonly\s+|abstract\s+)*class\s+(\w+)/m', $src, $cl)) { continue; }
    foreach ($cl[1] as $c) {
        $fq = trim($ns[1]) . '\\' . $c;
        if (!class_exists($fq, false)) { @class_exists($fq); }
    }
}
$rows = [];
foreach (get_declared_classes() as $c) {
    $rc = new ReflectionClass($c);
    if ($rc->isAbstract() || $rc->isInterface()) { continue; }
    $f = $rc->getFileName();
    if ($f === false || !str_starts_with($f, $root . '/src') && !str_starts_with($f, $root . '/tests')) { continue; }
    $isRule = $rc->implementsInterface(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);
    $isLevel = $rc->implementsInterface(\Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface::class);
    if (!$isRule && !$isLevel) { continue; }
    $hier = $rc->implementsInterface(\Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface::class);
    $aware = $rc->implementsInterface(\Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface::class);
    $rows[] = implode("\t", [
        str_replace($root . '/', '', $f),
        $c,
        $isRule ? 'rule' : '-', $isLevel ? 'level' : '-', $hier ? 'hierarchical' : '-', $aware ? 'threshold-aware' : '-',
    ]);
}
sort($rows);
echo "file\tclass\trule_options\tlevel_options\thierarchical\tthreshold_aware\n" . implode("\n", $rows) . "\n";
