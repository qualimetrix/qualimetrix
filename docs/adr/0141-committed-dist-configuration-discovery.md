# 0141. Committed Dist Configuration Discovery

**Date:** 2026-10-30
**Status:** Accepted

**Builds on:** [ADR 0139](0139-configuration-imports.md), whose `imports:` list
is how a local file builds on another file.

## Context

A team commits its configuration and lets each developer keep a local
variant: PHPStan reads `phpstan.neon`, else `phpstan.neon.dist`; PHPUnit
`phpunit.xml`, else `phpunit.xml.dist`. Qualimetrix discovered only `qmx.yaml`
and `qmx.yml`. A repository committing `qmx.dist.yaml` with a git-ignored
`qmx.yaml` got the dist file only through `--config`, which the pre-commit
hook never passes and the GitHub Action passes only when its `config` input is
set; without it the run warned about an ignored near name and used the
defaults.

## Decision

### 1. `qmx.dist.yaml` and `qmx.dist.yml`, after the local pair

When the working directory has neither `qmx.yaml` nor `qmx.yml`, discovery
accepts `qmx.dist.yaml` or `qmx.dist.yml`, compared byte for byte like the
local pair. Both together are refused, naming both — beside a local file and
beside a dist file selected as a preset too, because a run without either
would refuse them. A near name is still reported beside a loaded dist file:
with no local file present, it is usually the local file misnamed. `qmx.yaml.dist` stays a
near name: an extension after `.yaml` hides the YAML type from editors and
from the editor schema mapping, and an `imports` entry must end in `.yaml` or
`.yml`, so a local file could not import it.

### 2. A local file wins alone

A found local file is the root and the dist file is not merged into it. An
implicit merge would make the dist file a layer no file names, outside the
written order ADR 0139 established, and a local file could not opt out of it.
A local file that wants the committed defaults imports them.

### 3. An unused dist file is reported

When an auto-discovered local file's import chain reads no file with the dist
file's identity, a diagnostic says the dist file is not used and how to import
it. The harm it names is silent: a local run whose configuration differs from
the one CI reads. Identity, not spelling, decides, so `./qmx.dist.yaml` or a
symlink to it count as importing it. A dist file that is not a regular file
— a directory, a dangling link — is reported as one a run without the local
file refuses; a regular file reporting inode 0 cannot be compared and is not
judged. A local file that is a symlink resolves its imports beside its target,
so the advice names the dist file by its full path. An explicit `--config` is a deliberate
choice and gets no such diagnostic. Rejected: staying silent as PHPStan does —
the divergence is exactly what the committed-defaults pattern exists to
prevent, and the diagnostic has a one-line cure.

### 4. A dist file selected as a preset stays in that band

A dist file whose physical path is a selected `--preset` is neither
discovered nor reported, as a near name selected as a preset is not reported
today. Discovering it as well would place one file in two bands without the
author writing it twice.

### 5. A layer of notices is not a source

A configuration stage that read nothing but carries diagnostics — the
near-name notice when no file was found — is not an applied source, so
`-v` no longer lists a phantom `config_file`.

## Consequences

- The hook, the Action and every command that reads configuration (`check`,
  `directives`, `rules`, `graph:export`, `debug:layer-assignment` and the
  baseline commands that read it) pick up a committed dist file, because they
  all share the discovery stage.
- The write guard of ADR 0139 covers a discovered dist file without change: it
  compares the identity of every file read.
- A repository that kept a `qmx.dist.yaml` unused beside a `qmx.yaml` now sees
  a warning on each run; importing the file, or removing it, ends it.
