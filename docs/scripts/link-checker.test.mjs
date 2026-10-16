import assert from 'node:assert/strict';
import test from 'node:test';
import { checkDocumentLinks, resolveInternalHref } from './link-checker.mjs';

const pageUrl = 'https://koamishin.github.io/OpenSinop/guides/planning/';

test('relative links keep the project base at different page depths', () => {
    assert.deepEqual(resolveInternalHref('../../institution/overview/#start', pageUrl), {
        path: 'institution/overview/', fragment: 'start',
    });
    assert.deepEqual(resolveInternalHref('../collections/', pageUrl), {
        path: 'guides/collections/', fragment: '',
    });
});

test('same-page fragments and queries use the current document', () => {
    assert.deepEqual(resolveInternalHref('#budget', pageUrl), { path: 'guides/planning/', fragment: 'budget' });
    assert.deepEqual(resolveInternalHref('?view=all&amp;filter=held#budget', pageUrl), {
        path: 'guides/planning/', fragment: 'budget',
    });
});

test('domain-root links and similar-looking base names are rejected', () => {
    for (const href of ['/guides/planning/', '/OpenSinop-other/', '../../../outside/']) {
        assert.throws(() => resolveInternalHref(href, pageUrl), /escapes/);
    }
});

test('same-origin absolute links are checked and external links are skipped', () => {
    assert.deepEqual(resolveInternalHref(pageUrl, pageUrl), { path: 'guides/planning/', fragment: '' });
    assert.equal(resolveInternalHref('https://example.org/guide', pageUrl), null);
    assert.equal(resolveInternalHref('mailto:operator@example.org', pageUrl), null);
    assert.throws(() => resolveInternalHref('javascript:void(0)', pageUrl), /Unsupported/);
});

test('root hosting works without a project base', () => {
    assert.deepEqual(resolveInternalHref('/guides/planning/', 'https://docs.example.org/', ''), {
        path: 'guides/planning/', fragment: '',
    });
});

test('missing local and cross-page anchors fail validation', () => {
    const documents = new Map([
        ['guides/planning/index.html', '<h1 id="_top">Plan</h1><h2 id="budget">Budget</h2>'],
        ['guides/collections/index.html', '<h1 id="_top">Collections</h1>'],
    ]);
    const files = new Set(documents.keys());
    const result = checkDocumentLinks({ file: 'guides/planning/index.html', files, documents,
        html: '<a href="#budget">Good</a><a href="#missing">Bad</a><a href="../collections/#missing">Bad</a>',
    });
    assert.equal(result.checked, 3);
    assert.equal(result.failures.length, 2);
    assert.ok(result.failures.every((failure) => failure.includes('Missing anchor')));
});

test('missing targets and escaping paths fail instead of passing because a directory exists', () => {
    const result = checkDocumentLinks({ file: 'index.html', files: new Set(['index.html']),
        documents: new Map([['index.html', '']]),
        html: '<a href="missing/">Bad</a><a href="/outside/">Bad</a>',
    });
    assert.equal(result.failures.length, 2);
});

test('404 navigation works when served at an arbitrary missing URL', () => {
    const result = checkDocumentLinks({ file: '404.html', files: new Set(['index.html']),
        documents: new Map([['index.html', '<h1 id="_top">Home</h1>']]),
        servedPath: '/OpenSinop/unknown/nested/page/',
        html: '<a href="/OpenSinop/">Home</a>',
    });
    assert.deepEqual(result.failures, []);
});

test('the checker validates linked images and stylesheets when present in hrefs', () => {
    const result = checkDocumentLinks({ file: 'index.html', files: new Set(['index.html', '_astro/styles.css']),
        documents: new Map([['index.html', '<h1 id="_top">Home</h1>']]),
        html: '<link href="/OpenSinop/_astro/styles.css"><a href="?view=all#_top">Here</a>',
    });
    assert.deepEqual(result.failures, []);
    assert.equal(result.checked, 2);
});
