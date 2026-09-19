// The one place the viewer's distance to the repository root is written down.
//
// metric-key-catalog.mjs is the only remaining importer — collect-metric-keys.mjs
// used to be a second one, collapsed into this single module at stage 01 of
// the viewer relocation and deleted outright at stage 02 (git history holds
// it). One importer or two, moving this directory stays one edit, and the
// hop `metric-key-catalog.test.js` exercises on every `composer test:js` is
// the same hop the deleted script would have used, so nothing here can drift
// silently.
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const REPO_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

export function fromRoot(...parts) {
  return resolve(REPO_ROOT, ...parts);
}
