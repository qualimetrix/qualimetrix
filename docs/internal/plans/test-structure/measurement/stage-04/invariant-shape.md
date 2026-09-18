# What the invariant can actually assert

Two drafts of this file were wrong, in the two different ways this campaign keeps
producing, and both are recorded because the corrections are the content.

**Draft 1** stated part 3 as *the path below the level equals the `#[CoversClass]`
namespace minus the owner*. Measured against the tree as it will be **after** this
stage, that is false for 155 of 616 test classes. The control would have been
unimplementable.

**Draft 2** weakened part 3 to a prefix test and published the buckets 377 / 132 / 84
/ 19. Those sum to **612, not 616**: four files match no bucket, and the draft's
derivation had quietly counted them into the wrong-owner list. A table whose parts do
not sum to its population is not a measurement, and the control built from it would
have been red on day one.

## The measurement

Population `tests/**/*Test.php`, each file's post-stage path taken from
`relocation-map.csv` (or its current path where the map does not name it).

| Part                                                         | Holds after stage 04                      |
| ------------------------------------------------------------ | ----------------------------------------- |
| (1) owner segments name one of the 37 manifest owners        | **616 / 616**                             |
| (2) the level segment is immediately after the owner         | **616 / 616**                             |
| (3) remainder equals the covered class's namespace remainder | 377 / 616 — false as an invariant         |
| (3) remainder is a **prefix** of it                          | 509 / 616, with 107 in three capped lists |

Part 3 is a prefix test, not an equality, because filing a test flat under
`{owner}/{level}/` while its subject sits in a sub-namespace is this tree's
convention: 132 already-correct files are filed that way. The prefix form still
refuses what the equality form was for — a file at
`tests/Reporting/Unit/Formatter/Whatever/` covering `Reporting\Formatter\Html\X` has
remainder `Formatter/Whatever` against an actual `Formatter/Html`, which is not a
prefix.

| Bucket                                                                                           | Count   |
| ------------------------------------------------------------------------------------------------ | ------: |
| remainder exact                                                                                  | 377     |
| remainder a prefix — flattened, nothing invented                                                 | 132     |
| **list A** — no `#[CoversClass]` at all                                                          | **84**  |
| **list B** — covers only classes owned by someone else                                           | **19**  |
| **list C** — path owner is among the covered owners, but the remainder drops an interior segment | **4**   |
| **total**                                                                                        | **616** |

List A splits further, and the split matters for whether a row can ever be retired: 5
files carry `#[CoversNothing]`, which is a deliberate declaration that the file covers
nothing nameable, and 79 declare nothing at all, which is an omission someone could
fix. The list is derived with that distinction in its rows.

List C is the four `tests/Analysis/Run/Unit/...` files whose path drops an interior
`Contract` segment — `Unit/Collection/` against an actual `Contract/Collection`. They
truncate in the middle rather than from the right, which is why a prefix test refuses
them and a human reading the tree would not notice.

## The three ceilings

84, 19 and 4, each derived, each lowering only, on the terms
`governance/TestSuiteHygiene/namespace-path-allow-list.php` already uses: a derive
command writes the rows, raising a ceiling is a hand edit and therefore a decision,
and a row that no longer describes a real exception is refused as loudly as a missing
one.

**List B is a real signal and it is stage 05's.** Its members are tests filed under one
owner whose only coverage claim names another — every
`tests/Analysis/Policy/Baseline/Functional/Baseline*CommandTest.php` covers
`Infrastructure\Console\Command\Baseline\...`, which is the adapter-exclusion principle
showing through. Five of the 19 are already rows in `defect-ledger.tsv`.

## Reproducing

Not prose. Run this from the repository root; it prints the table above and exits
non-zero if the buckets do not sum to the population.

```python
import json, os, re, csv, subprocess, sys
M = json.load(open('docs/internal/modular-architecture-manifest.json'))
DECL = M['declarations']
OP = {o: ('Core' if o == 'Core.Neutral' else o.replace('.', '/')) for o in M['owners']}
LEV = {'Unit', 'Integration', 'Functional'}
rows = {r['current']: r['target'] for r in csv.DictReader(
    open('docs/internal/plans/test-structure/measurement/stage-04/relocation-map.csv'))}
files = subprocess.run(['bash', '-c', "find tests -name '*Test.php' | sort"],
                       capture_output=True, text=True).stdout.split()
b = {'exact': 0, 'prefix': 0, 'A': 0, 'B': 0, 'C': 0}
for f in files:
    p = rows.get(f, f); s = open(f).read(); parts = p[len('tests/'):].split('/')
    i = [n for n, x in enumerate(parts) if x in LEV][0]
    owner = '/'.join(parts[:i]); rem = parts[i + 1:-1]
    uses = dict(re.findall(r'^use\s+([\w\\]+\\(\w+));', s, re.M))
    cc = re.findall(r'#\[CoversClass\(([^)]*?)::class\)\]', s)
    if not cc:
        b['A'] += 1; continue
    owners = set(); best = None
    for c in cc:
        c = c.strip().lstrip('\\'); full = [k for k, v in uses.items() if v == c]
        fq = full[0] if full else c
        if fq not in DECL: continue
        owners.add(OP[DECL[fq]['owner']])
        if OP[DECL[fq]['owner']] != owner: continue
        nsd = os.path.dirname(fq.replace('Qualimetrix\\', '').replace('\\', '/'))
        want = [x for x in nsd[len(owner):].strip('/').split('/') if x]
        if rem == want: best = 'exact'; break
        if rem == want[:len(rem)]: best = best or 'prefix'
    if best: b[best] += 1
    elif owners and owner not in owners: b['B'] += 1
    else: b['C'] += 1
print(b, 'sum', sum(b.values()), 'of', len(files))
sys.exit(0 if sum(b.values()) == len(files) else 1)
```

**What this cannot see.** `#[CoversClass]` written as a string rather than `::class`;
coverage claimed by `#[CoversMethod]` or `@covers`; a file that covers more than it
declares. All three would move a file between buckets without moving the total, which
is why the script asserts the sum rather than trusting it.
