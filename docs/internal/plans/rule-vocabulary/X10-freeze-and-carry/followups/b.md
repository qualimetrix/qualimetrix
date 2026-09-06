# Followup for FOLLOWUPS.md — package B of X10-freeze-and-carry

## X10 (2026-09-06) — B: invariant (ii) proven by a declared-rename control; one open decision

### What landed

One new green control in the existing harness, and nothing else in the tree:

`scripts/finding-gate-controls/Controls.php::occurrenceFrozenUnderDeclaredRename()`,
id `occurrence-declared-rename`. It is the composition `01-freeze-kind.md`
prescribes: `security.sensitive-parameter -> security.sensitive-paramete2` in
`SensitiveParameterRule::NAME` (leaving `OCCURRENCE_KIND` where it is), the two
declarations of that channel moved with it (the case claim, the tracked
`declared.txt` witness), and one clone-local `finding-gate/maps/channels.tsv`
row declaring the rename. The row is written into the clone by
`trackedChannelMapPlus()` and never reaches the tree.

It closes the gap `fingerprintDeclaredRename()` names in its own docblock: that
control renames a channel whose findings publish the two-part identity
`channel:subject`, so until now no control had ever carried a rename across an
identity with an `occurrence` in it. That docblock is updated to point here.

### The open decision — the field-level witness is not in the tree

The control gives **one** verdict for four assertions. The decomposition —
findings paired by an explicit key, `channel` and `occurrence` compared field by
field, both exit codes and both `N` printed — was measured with a throwaway
script that reuses the stand's `Scratch`/`Mutation`/`Shell` and runs `bin/qmx`
twice over one case. It was deliberately **not** tracked: the package's
instruction was to use the existing stand and build no second one, and a tracked
entry point wired into no `composer` command rots.

It exists only as a machine-local file outside the repository, named in the
package's execution report. **Decision for the owner:** either accept the gate
control as the durable form and let the witness go, or adopt the witness under
`scripts/finding-gate-controls/` with a `composer` entry and a place in the
package's own DoD. Do not leave it half-way: an untracked witness that gets
cited in later rounds is a measurement nobody can repeat.

### What the control does not see

- It is one family of the six. The other five carry the same shape of constant,
  and nothing in this control observes them; the durable per-family protection
  is package A's pins, not this.
- A green run is a statement about the whole normalized artifact, so it cannot
  by itself separate "the occurrence field is equal" from "the occurrence field
  is gone". The counterfactual is what shows the field is really compared.
- The gate sorts both sides deterministically and then compares bytes, which is
  equality of the *multiset* of findings with the channel translated — not a
  pairing. One caveat comes with that: `JsonFindingSection::identitySortKey()`
  sorts by `channel` first, so a renamed channel that sorts into a different
  neighbourhood reddens a byte comparison whose multisets agree. This control's
  new spelling sorts where the old one did, and is the same length because two
  surfaces pad the channel column. The field-level witness is what adds a
  pairing, by a key built from neither the mutated field nor the checked one.
