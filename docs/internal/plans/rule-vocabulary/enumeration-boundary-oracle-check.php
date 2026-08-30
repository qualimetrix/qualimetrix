<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../vendor/autoload.php';
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
$c = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create();
$cmd = $c->get(\Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand::class);
$ro = new ReflectionObject($cmd);
$svc = $ro->getProperty('configuredThresholds')->getValue($cmd);
$so = new ReflectionObject($svc);
$rules = $so->getProperty('rules')->getValue($svc);
$factory = $so->getProperty('optionsFactory')->getValue($svc);
$current = $svc->resolve();
// candidate boundary per row: the value the NEW contract is expected to declare.
// taken from current output, plus the one row it must repair.
$expected = $current;
$expected['coupling.distance']['namespace'] = 0.3;
$sev = function (object $t, int|float $v): string {
    try { $s = $t->getSeverity($v); } catch (Throwable $e) { return 'EXCEPTION'; }
    return $s === null ? 'null' : (string) $s->value;
};
$bad = 0; $rows = 0;
foreach ($rules->getClasses() as $rc) {
    $name = RuleNameReader::read($rc);
    try { $opts = $factory->create($name, $rc::getOptionsClass()); } catch (Throwable $e) { continue; }
    foreach (ChannelDeclarationReader::read($rc) as $key => $d) {
        foreach ($d->levels as $lvl) {
            if (!isset($expected[$key][$lvl->value])) { continue; }
            $b = $expected[$key][$lvl->value];
            $t = $opts;
            if ($opts instanceof HierarchicalRuleOptionsInterface) { $t = $opts->forLevel($lvl); }
            $delta = is_int($b) ? 1 : 0.001;
            $lo = $sev($t, $b - $delta); $hi = $sev($t, $b + $delta); $at = $sev($t, $b);
            $oneNull = ($lo === 'null') !== ($hi === 'null');
            $rows++;
            if (!$oneNull) { $bad++; }
            printf("%-45s %-10s b=%-6s below=%-9s at=%-9s above=%-9s %s\n", $key, $lvl->value, (string) $b, $lo, $at, $hi, $oneNull ? 'OK' : '*** FAILS ORACLE');
        }
    }
}
echo "\nrows=$rows failing=$bad\n";
