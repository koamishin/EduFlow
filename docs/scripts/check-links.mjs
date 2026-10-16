/**
 * Verify every internal link in the built site resolves to a real file.
 *
 * Run after `npm run build`:
 *   node scripts/check-links.mjs
 *
 * Catches root-relative links that escape the configured `base` (they resolve
 * against the domain root on GitHub Pages and 404), and typos in relative paths.
 */
import { readdirSync, readFileSync, statSync, existsSync } from 'node:fs';
import { join, posix } from 'node:path';

const DIST = 'dist';
const BASE = '/OpenSinop';
const pages = [];

(function walk(dir) {
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) {
            walk(full);
        } else if (entry.endsWith('.html')) {
            pages.push(full);
        }
    }
})(DIST);

const failures = [];
let checked = 0;

for (const page of pages) {
    // Served URL of this page, e.g. dist/guides/workflows/index.html -> /OpenSinop/guides/workflows/
    const relativePath = page.replace(/\\/g, '/').replace(/^dist\//, '');
    const servedUrl = `${BASE}/${relativePath}`;
    const servedDir = servedUrl.endsWith('/')
        ? servedUrl
        : servedUrl.slice(0, servedUrl.lastIndexOf('/') + 1);
    const html = readFileSync(page, 'utf8');

    for (const [, href] of html.matchAll(/href="([^"]+)"/g)) {
        if (/^(https?:|mailto:|tel:|#|\/\/)/.test(href)) {
            continue;
        }

        const [pathPart, fragment] = href.split('#');

        let target;
        if (pathPart.startsWith('/')) {
            if (!pathPart.startsWith(BASE + '/') && pathPart !== BASE) {
                failures.push(`${page}: "${href}" escapes the ${BASE} base`);
                continue;
            }
            target = pathPart.slice(BASE.length) || '/';
        } else {
            // Resolve relative to the page's served directory, keeping the base.
            target = posix.normalize(posix.join(servedDir, pathPart || '.'));
            if (!target.startsWith(BASE)) {
                failures.push(`${page}: "${href}" resolves above the base (${target})`);
                continue;
            }
            target = target.slice(BASE.length) || '/';
        }

        checked++;

        const candidates = [
            join(DIST, target, 'index.html'),
            join(DIST, target.replace(/\/$/, ''), 'index.html'),
            join(DIST, target.replace(/^\//, '')),
        ];

        if (!candidates.some((candidate) => existsSync(candidate))) {
            failures.push(`${page}: "${href}" -> ${target} (missing${fragment ? `, #${fragment}` : ''})`);
            continue;
        }

        if (fragment && target.endsWith('/')) {
            const html = readFileSync(join(DIST, target, 'index.html'), 'utf8');
            if (!html.includes(`id="${fragment}"`)) {
                failures.push(`${page}: "${href}" -> ${target} (anchor #${fragment} not found)`);
            }
        }
    }
}

console.log(`Checked ${checked} internal links across ${pages.length} pages.`);

if (failures.length > 0) {
    console.error(`\n${failures.length} broken link(s):`);
    for (const failure of failures) {
        console.error(`  - ${failure}`);
    }
    process.exit(1);
}

console.log('All internal links resolve.');