# Baseline Rules

Baseline rules audit the acceptance file after comparison.

## Unused Entry

**Rule ID:** `baseline.unused-entry`
**Severity:** Warning
**Level:** Project
**Remediation estimate:** 5 minutes

<!-- llms:skip-begin -->
### What it measures

Complete comparable missing entries are stale; entries the loader cannot apply
are inert. Unknown, outside or unmeasured entries do not prove remediation.
Each audited entry produces a project warning after ceiling judgement, except
inert contenders for one duplicate identity share a warning with their count. The audit
is not a judged metric and has no warning/error threshold.

### How to fix

Inspect the entry and current coverage with baseline:explain or baseline:cleanup.
Remove only reviewed selectors with --remove; repair malformed entries or migrate
retired channels deliberately. Do not regenerate merely to silence the warning.

<!-- llms:skip-end -->

### Configuration

```yaml
rules:
  baseline.unused-entry:
    enabled: false
```

```bash
bin/qmx check src/ --baseline=baseline.json --disable-rule=baseline.unused-entry
```

The audit cannot itself be accepted in a baseline or by --accept-new. Disabled or
unselected audit retains count-only stderr diagnostics. Path/namespace suppression
and Git projection cannot hide selected project warnings. An isolated warning
exits 0 by default/error/none and 1 with --fail-on=warning; incomplete analysis exits
4. Finding formats publish it; metrics, health and suppressed do not publish it.
