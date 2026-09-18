# Stage 06 — the receiving group names, and what was measured to choose them

The stage plan settled the verdict and left exactly one thing open: what the
receiving groups are called. This is that decision and the evidence under it.

## The names

| Control                                    | Receiving group           | Where the name comes from                                                                                 |
| ------------------------------------------ | ------------------------- | --------------------------------------------------------------------------------------------------------- |
| `AnalysisContextScopeArgumentGuardTest`    | `ProjectScopeCoverage`    | `src/Analysis/Run/Configuration/ProjectScopeCoverage.php`, the identifier itself                          |
| `FrameworkClassificationSiteCountTest`     | `FrameworkClassification` | `src/Analysis/Evidence/Coupling/FrameworkClassificationSites.php`, less its `Sites` suffix                |
| `GlobAlphabetSoleEnumerationTest`          | `SelectorSyntax`          | the grammar the two guarded primitives share; see the fork below                                          |
| `NamespaceMatcherNormalizationSurfaceTest` | `SelectorSyntax`          | same                                                                                                      |
| `SuppressionOptionKeyReaderCensusTest`     | `SuppressionOptionKeys`   | the third `*OptionKeys` governance group, after `FormatOptionKeys` and `RuleOptionKeys`                   |
| `TraversalCompletenessTest`                | `MeasurementIdentity`     | existing group; no new group                                                                              |
| `VersionRootPackageIndependenceTest`       | `PackageVersion`          | the guarded primitive is `Core\Version`; the name states the defect, which is *whose* version is reported |

**One of the five new names is a class `src/` already declares**:
`ProjectScopeCoverage`, named in fifteen files. A second, `FrameworkClassification`,
is the stem of the class `FrameworkClassificationSites`, which is named in four —
close, but not itself an identifier. The remaining three appear in `src/` not at
all, and the claim is narrower for them: each is named from the vocabulary of the
code it guards or of the sibling groups it joins, so that no name invents a word.
Measured as whole identifiers, not as substrings, because the distinction is the
whole point of the previous paragraph:

```
$ for n in ProjectScopeCoverage FrameworkClassification FrameworkClassificationSites \
           SelectorSyntax SuppressionOptionKeys PackageVersion; do
    printf '%-32s %s\n' "$n" \
      "$(git ls-files -z 'src/*' | xargs -0 grep -lE "\\b$n\\b" | wc -l)"
  done
ProjectScopeCoverage             15
FrameworkClassification          0
FrameworkClassificationSites     4
SelectorSyntax                   0
SuppressionOptionKeys            0
PackageVersion                   0
```

The second and third rows are the whole of the `FrameworkClassification` case:
the group's name appears nowhere in `src/` on its own, and the four files are the
ones naming the class it is the stem of. Of those four, one is a `README.md`, as
are three of `ProjectScopeCoverage`'s fifteen — so the counts that matter for
"rooted in the code" are three and twelve.

## `TraversalCompletenessTest` joins an existing group

The only one of the seven that needed no new group. The two docblocks state the
same subject from opposite ends: the control exists because "declaration
numbering rests on the registrar and every producer seeing the same nodes", and
`MeasurementIdentity`'s sitting member exists because "the position identifying a
declaration and the position it was collected at come from one place".

## The glob / namespace fork: one subject, and why

Whether the two `Core/Util` primitives are one subject or two is settled in the
present tense, by an edge that exists today:

```
$ grep -n 'GlobSyntax' src/Core/Util/NamespaceMatcher.php
101:     * alphabet is {@see GlobSyntax}'s, shared with the code that judges a
106:        return GlobSyntax::isGlob($pattern);
```

`NamespaceMatcher` reads its alphabet from `GlobSyntax`. One primitive already
depends on the other for the grammar, which is one subject by the code rather
than by a forecast.

**The parked pattern-matching unification is a second, weaker reason and is not
what decides this.** A placement argued from work not yet done is the mirror
image of one argued from a migration already finished, and the repository rejects
that form of reasoning for placement. It is recorded here only as the thing that
would make a split expensive later, not as the thing that makes the join correct
now.

**What co-change does and does not support.** `GlobSyntax.php` has exactly one
commit in its history, `6a833ab8`, and that commit also touched
`NamespaceMatcher.php`; `NamespaceMatcher.php` has seven. So from `GlobSyntax`'s
side every commit it has ever had is a shared one, and from `NamespaceMatcher`'s
side one in seven is. The first figure is consistent with one subject but rests
on a population of one, so it corroborates the import edge above and cannot carry
the decision by itself.

If the unification is abandoned *and* the import at line 106 goes away, the
condition to revisit has arrived and splitting the group costs two lines per
group.

## The three refusals, planted and quoted

A guard that has never gone red is not evidence. Each was planted into a green
tree and rolled back from a copy taken **before** the planting, not with
`git checkout --`.

1. A new group missing its `<directory>` in `phpunit.xml.dist`
   — `composer architecture:check` exit 1:
   > `governance/SelectorSyntax is suite Governance in currentSuite() but is not declared under that <testsuite> in phpunit.xml.dist`

2. A new group missing its `testSuitePrefixTable()` row — exit 1 from the other
   side of the same mirror:
   > `governance/PackageVersion is suite Governance in phpunit.xml.dist, none in currentSuite()`

3. A namespace that disagrees with its new path —
   `TestNamespacesFollowTheirPathTest` exit 1, which is what proves the control's
   population actually includes the new directories rather than merely passing:
   > `governance/SelectorSyntax/GlobAlphabetSoleEnumerationTest.php declares Qualimetrix\Governance\SolePrimitiveOwnership, and its path says Qualimetrix\Governance\SelectorSyntax`

## Two facts this stage did not use, recorded for stage 05

- `move-oracle.py` derives a namespace by slicing the literal `tests/` off a path
  (lines 75-86). Pointed at `governance/`, it answers about nothing while
  reporting cleanly, so stage 06 judged its moves with the two registration
  checks and the namespace control instead.
- `\dirname(__DIR__, 2)` is correct in every moved file both before and after,
  because a group directory is flat and all seven stayed at depth two. Nothing
  here exercises the stale-depth failure the layout note warns is silent.
