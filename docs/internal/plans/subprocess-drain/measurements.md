# What actually deadlocks — measured, not reasoned

macOS 25.6.0, PHP from `PHP_BINARY`, 2026-09-20. Scripts: `/private/tmp/claude-501/probe-single-pipe.php`,
`/private/tmp/claude-501/probe-stdin.php`.

| Shape                                                                   | Result          | Evidence                                                                                                                                                                               |
| ----------------------------------------------------------------------- | --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Two read pipes, read sequentially, child floods the second              | **deadlock**    | #102, reproduced three times + a three-line standalone repro                                                                                                                           |
| One read pipe, never read, then `proc_close()`, child floods it         | **no deadlock** | child died with `errno=32 Broken pipe`, parent returned exit 255 immediately. `proc_close()` closes the pipes it created *before* waiting, so the child gets EPIPE instead of blocking |
| One **write** (stdin) pipe, parent writes 1 MB, child never reads stdin | **deadlock**    | parent still blocked in `fwrite()` at 5 s, killed with SIGKILL (exit 137)                                                                                                              |

## Consequence for the control's rule

Codex's conclusion — "at most one pipe cannot deadlock" is unsound as a rule — is **correct**.
Its stated mechanism (`proc_close()` without draining a single stdout pipe) is **not**: that
case was probed and does not hang.

The real single-pipe deadlock is in the **write** direction. This matters because it is the
mirrored stdin hazard the runner's contract already addresses, and because a rule that
counts pipes without distinguishing direction would have been wrong for a reason nobody had
measured.

Both facts are moot under the revised control rule — allowlisting *every* `proc_open`
rather than only the two-or-more-pipe ones removes the need to reason about pipe counts at
all — but they are recorded because the revised rule must not be justified by an argument
that is itself false.

# The detector's blind spot, measured

A `token_get_all()` scan of tracked PHP finds **29** real `proc_open` calls and **13**
occurrences inside comments or string literals. Both independent enumerations found **30**
call sites.

The missing one is `scripts/finding-gate/ProcessHandle.php:202`. It sits inside a nowdoc
(`<<<'PHP'`) that is handed to a spawned `php -r` — a string literal in *this* file and a
real `proc_open` in the child process. A token-level detector classifies it as a literal and
would exempt it from an allowlist.

Consequence for the control: token analysis must not *exempt* anything. Every textual
occurrence of `proc_open` in tracked PHP needs either the module or a declared entry; the
token pass only classifies the entry (call / embedded source / documentation) to enrich the
refusal message. This is fail-closed and needs no descriptor parsing at all.

Derivation:

```bash
php -r '$f=explode("\n",trim(shell_exec("git ls-files \"*.php\"")));$c=0;
foreach($f as $x){$t=token_get_all(file_get_contents($x));
for($i=0;$i<count($t);$i++){$k=$t[$i];
if(is_array($k)&&$k[0]===T_STRING&&$k[1]==="proc_open"){$j=$i+1;
while(isset($t[$j])&&is_array($t[$j])&&$t[$j][0]===T_WHITESPACE){$j++;}
if(isset($t[$j])&&$t[$j]==="("){$c++;}}}} echo $c,"\n";'
```

# A pty blocks exactly like a pipe — measured

The native plan review raised this as CRITICAL and said explicitly it had not measured it
("свойство ядра, не измерено здесь"). Measured now, `/private/tmp/claude-501/probe-pty.php`:

```php
proc_open([PHP_BINARY,'-r','fwrite(STDERR, str_repeat("E",1048576)); fwrite(STDOUT,"done");'],
          [1 => ['pipe','w'], 2 => ['pty']], $pipes);
stream_get_contents($pipes[1]);   // read stdout to EOF; stderr pty never read
```

Result: parent still blocked at 5 s, killed (exit 137). The child filled the pty master's
kernel buffer and blocked mid-write, so it never closed stdout, so the parent's read of
stdout never reached EOF. **Identical deadlock, non-pipe descriptor.**

Consequences:

1. A rule that counts `['pipe'` occurrences is unsound: `[1 => ['pipe','w'], 2 => ['pty']]`
   has one `['pipe'` and two blocking streams. (Moot under the revised rule, which counts
   nothing — recorded so the revised rule is not justified by a false argument.)
2. The enumeration's `S` verdict must mean "at most one **blocking** stream", where blocking
   is `pipe` | `pty` | `socket`, and safe is `file` | a passthrough constant | no descriptor.
3. Row 29 (`PseudoTerminalRun::isSupported`, `[0=>['pty'], 1=>['pipe','w'], 2=>['pty']]`,
   all closed unread) is **not** `S` under that definition. It is safe only because its child
   is `php -r 'exit(0);'` and writes nothing — an argument about the child's output volume,
   which is exactly what `02-migration.md` forbids as a basis. Re-verdicted.
