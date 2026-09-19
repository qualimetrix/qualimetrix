// The one place the viewer's distance to the repository root is written down.
//
// Both consumers import it, so moving this directory is one edit rather than
// two — and the copy nothing executes cannot drift away from the copy
// `metric-key-catalog.test.js` exercises on every `composer test:js`.
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const REPO_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..');

export function fromRoot(...parts) {
  return resolve(REPO_ROOT, ...parts);
}
