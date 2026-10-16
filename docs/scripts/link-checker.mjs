/** Resolve links as a browser does, including same-page fragments and a project base. */
export function resolveInternalHref(href, pageUrl, base = '/OpenSinop') {
    if (/^(?:mailto:|tel:|data:)/i.test(href)) {
        return null;
    }
    const source = new URL(pageUrl);
    const target = new URL(href.replaceAll('&amp;', '&'), source);
    if (!['http:', 'https:'].includes(target.protocol)) {
        throw new Error(`Unsupported link protocol: ${target.protocol}`);
    }
    if (target.origin !== source.origin) {
        return null;
    }
    const prefix = base.replace(/\/+$/, '');
    const pathname = decodeURIComponent(target.pathname);
    if (prefix && pathname !== prefix && !pathname.startsWith(`${prefix}/`)) {
        throw new Error(`Link escapes the ${prefix} base`);
    }
    const path = pathname.slice(prefix.length).replace(/^\//, '');
    return { path, fragment: decodeURIComponent(target.hash.slice(1)) };
}

/** Validate both directory pages and files; existing directories are not valid targets. */
export function checkDocumentLinks({
    file,
    html,
    files,
    documents,
    base = '/OpenSinop',
    origin = 'https://koamishin.github.io',
    servedPath,
}) {
    const prefix = base.replace(/\/+$/, '');
    const pagePath = servedPath ?? `${prefix}/${file.replace(/index\.html$/, '')}`;
    const pageUrl = new URL(pagePath, origin).href;
    const failures = [];
    let checked = 0;

    for (const match of html.matchAll(/\bhref\s*=\s*(?:"([^"]*)"|'([^']*)')/gi)) {
        const href = match[1] ?? match[2];
        try {
            const target = resolveInternalHref(href, pageUrl, base);
            if (target === null) {
                continue;
            }
            checked++;
            const candidates = target.path.endsWith('/') || target.path === ''
                ? [`${target.path}index.html`]
                : [target.path, `${target.path}/index.html`];
            const destination = candidates.find((candidate) => files.has(candidate));
            if (!destination) {
                // The same 404 document can be served at any unknown path.
                if (file === '404.html' && target.fragment && new URL(href, pageUrl).pathname === new URL(pageUrl).pathname) {
                    if (html.includes(`id="${target.fragment}"`)) {
                        continue;
                    }
                }
                throw new Error(`Missing page or file: ${target.path}`);
            }
            if (target.fragment && documents.has(destination)) {
                const destinationHtml = documents.get(destination);
                if (!destinationHtml.includes(`id="${target.fragment}"`) && !destinationHtml.includes(`id='${target.fragment}'`)) {
                    throw new Error(`Missing anchor #${target.fragment} in ${destination}`);
                }
            }
        } catch (error) {
            failures.push(`${file}: "${href}" — ${error.message}`);
        }
    }
    return { checked, failures };
}
