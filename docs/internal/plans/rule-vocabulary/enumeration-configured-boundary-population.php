<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../vendor/autoload.php';
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
$c = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create();
$cmd = $c->get(\Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand::class);
$ro = new ReflectionObject($cmd);
$svc = $ro->getProperty('configuredThresholds')->getValue($cmd);
$so = new ReflectionObject($svc);
$rules = $so->getProperty('rules')->getValue($svc);
$factory = $so->getProperty('optionsFactory')->getValue($svc);
$current = $svc->resolve();
$short = fn(string $x): string => substr(strrchr($x, '\\') ?: $x, 1);
// second witness: behavioural switch points of getSeverity
$switchOf = function (object $t): string {
    $grid = [0,0.01,0.02,0.03,0.05,0.1,0.2,0.3,0.31,0.33,0.34,0.5,0.7,0.79,0.8,0.81,0.9,0.95,0.96,1,2,3,4,5,6,7,8,9,10,11,12,14,15,16,20,21,25,30,31,40,41,47,50,51,70,80,81,200,201,300,500,501,1000,1001,2000];
    $prev = null; $sw = [];
    foreach ($grid as $v) {
        // An exception is NOT an absent severity: swallowing it would print
        // "no boundary" for a broken getSeverity(). It gets its own marker.
        try { $s = $t->getSeverity($v); $cur = $s === null ? '-' : strtoupper(substr((string) $s->value, 0, 1)); }
        catch (Throwable $e) { $cur = 'X'; }
        if ($prev !== null && $cur !== $prev) { $sw[] = $v . ':' . $prev . '>' . $cur; }
        $prev = $cur;
    }
    return $sw === [] ? 'constant(' . $prev . ')' : implode(' ', $sw);
};
$rows = [];
foreach ($rules->getClasses() as $rc) {
    $name = RuleNameReader::read($rc);
    try { $opts = $factory->create($name, $rc::getOptionsClass()); } catch (Throwable $e) { continue; }
    foreach (ChannelDeclarationReader::read($rc) as $key => $d) {
        foreach ($d->levels as $lvl) {
            $t = $opts;
            if ($opts instanceof HierarchicalRuleOptionsInterface) { try { $t = $opts->forLevel($lvl); } catch (Throwable $e) { continue; } }
            $warn = [];
            foreach ((new ReflectionObject($t))->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
                $v = $p->getValue($t);
                if ((is_int($v) || is_float($v)) && ($p->getName() === 'warning' || str_ends_with($p->getName(), 'Warning'))) { $warn[] = $p->getName() . '=' . $v; }
            }
            $aware = ($t instanceof ThresholdAwareOptionsInterface) ? 'yes' : 'no';
            $rows[] = implode("\t", [
                $key, $lvl->value, $short($rc), $short(get_class($t)), $aware,
                $warn === [] ? '(none)' : implode(',', $warn),
                $switchOf($t),
                isset($current[$key][$lvl->value]) ? (string) $current[$key][$lvl->value] : 'NULL',
            ]);
        }
    }
}
sort($rows);
echo "channel\tlevel\trule\tresolved_options\tthreshold_aware\twarning_named_members\tseverity_switch_points\tcurrent_explain_output\n";
echo implode("\n", $rows), "\n";
