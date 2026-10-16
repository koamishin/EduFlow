import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { checkDocumentLinks } from './link-checker.mjs';

const root = 'dist';
const files = new Set();
const documents = new Map();

function collect(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) {
            collect(path);
            continue;
        }
        const file = relative(root, path).replaceAll('\\', '/');
        files.add(file);
        if (file.endsWith('.html')) {
            documents.set(file, readFileSync(path, 'utf8'));
        }
    }
}

collect(root);

let checked = 0;
const failures = [];
for (const [file, html] of documents) {
    const result = checkDocumentLinks({ file, html, files, documents });
    checked += result.checked;
    failures.push(...result.failures);
}

console.log(`Checked ${checked} internal links across ${documents.size} pages.`);
if (failures.length > 0) {
    for (const failure of failures) {
        console.error(failure);
    }
    process.exitCode = 1;
} else {
    console.log('All internal links and anchors resolve.');
}
