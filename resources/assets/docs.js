/**
 * API docs: renders the specs built by "php artisan api-docs:generate" and provides
 * search, the Try it console, token handling and server switching. No dependencies.
 */
(() => {
    'use strict';

    const config = JSON.parse(document.getElementById('api-docs-config').textContent);
    const root = document.getElementById('api-docs');

    // The page is built ahead of time, so "This server" is whatever origin it is opened from.
    config.servers = [
        { name: 'This server', url: window.location.origin },
        ...(config.servers || []).filter((server) => server.url !== window.location.origin),
    ];

    const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options'];
    const STATUS_TEXT = {
        200: 'OK', 201: 'Created', 202: 'Accepted', 204: 'No Content', 301: 'Moved', 302: 'Found', 304: 'Not Modified',
        400: 'Bad Request', 401: 'Unauthorized', 403: 'Forbidden', 404: 'Not Found', 405: 'Method Not Allowed',
        409: 'Conflict', 419: 'Page Expired', 422: 'Unprocessable Content', 429: 'Too Many Requests',
        500: 'Server Error', 502: 'Bad Gateway', 503: 'Service Unavailable',
    };
    const ICONS = {
        search: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
        lock: '<svg viewBox="0 0 24 24"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
        unlock: '<svg viewBox="0 0 24 24"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.9-1"/></svg>',
        key: '<svg viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.2-8.2M16 7l3 3M14 9l2 2"/></svg>',
        sun: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
        moon: '<svg viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>',
        menu: '<svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
        close: '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>',
        copy: '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/></svg>',
        check: '<svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
        download: '<svg viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>',
        play: '<svg viewBox="0 0 24 24" class="solid"><path d="M7 4.5v15l12.5-7.5z"/></svg>',
        chevron: '<svg viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>',
        chevronDown: '<svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>',
        arrowLeft: '<svg viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>',
        arrowRight: '<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
        logout: '<svg viewBox="0 0 24 24"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11"/></svg>',
        expand: '<svg viewBox="0 0 24 24"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>',
        collapse: '<svg viewBox="0 0 24 24"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg>',
    };

    const state = { portal: null, spec: null, ops: [], byId: new Map(), tags: new Map(), groups: [], intro: [] };
    const ui = {};
    const specs = {};
    const isMac = /Mac|iPhone|iPad/.test(navigator.platform);
    const SIDEBAR_MIN = 220;
    const SIDEBAR_MAX = 480;
    const SIDEBAR_STEP = 16;
    const SIDEBAR_WIDTH_KEY = 'api-docs.sidebar-width';
    let routeToken = 0;

    /**
     * Create an element with the given attributes, event handlers and children.
     */
    function h(tag, props = {}, ...children) {
        const el = document.createElement(tag);

        for (const [key, value] of Object.entries(props || {})) {
            if (value === null || value === undefined || value === false) {
                continue;
            }
            if (key === 'class') {
                el.className = value;
            } else if (key === 'html') {
                el.innerHTML = value;
            } else if (key === 'value') {
                el.value = value;
            } else if (key === 'dataset') {
                Object.assign(el.dataset, value);
            } else if (key.startsWith('on') && typeof value === 'function') {
                el.addEventListener(key.slice(2).toLowerCase(), value);
            } else {
                el.setAttribute(key, value === true ? '' : value);
            }
        }

        append(el, children);

        return el;
    }

    /**
     * Append children to an element, skipping null and false.
     */
    function append(el, children) {
        for (const child of [children].flat(Infinity)) {
            if (child === null || child === undefined || child === false) {
                continue;
            }
            el.append(child instanceof Node ? child : document.createTextNode(String(child)));
        }

        return el;
    }

    /**
     * Replace the children of an element.
     */
    function fill(el, ...children) {
        el.replaceChildren();

        return append(el, children);
    }

    /**
     * Create an inline SVG icon.
     */
    const icon = (name) => h('span', { class: 'icon', 'aria-hidden': 'true', html: ICONS[name] });

    /**
     * Wrap a Web Storage area with JSON encoding; access may throw when storage is blocked.
     */
    const makeStorage = (backend) => ({
        get(key) {
            try {
                return JSON.parse(backend().getItem(key));
            } catch {
                return null;
            }
        },
        set(key, value) {
            try {
                backend().setItem(key, JSON.stringify(value));
            } catch {}
        },
        remove(key) {
            try {
                backend().removeItem(key);
            } catch {}
        },
    });

    const storage = makeStorage(() => window.localStorage);
    const drafts = makeStorage(() => window.sessionStorage);

    /**
     * Storage key of a portal's token; tokens are kept per server, so a UAT login is never sent to Dev.
     */
    const sessionKey = (portal) => `api-docs.session.${state.server.url.replace(/^https?:\/\//, '')}.${portal.slug}`;
    const sessions = {
        get: (portal) => storage.get(sessionKey(portal)),
        set: (portal, session) => storage.set(sessionKey(portal), session),
        clear: (portal) => storage.remove(sessionKey(portal)),
    };

    /**
     * Escape text for safe use in HTML.
     */
    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    /**
     * Turn text into a URL-friendly slug.
     */
    const slugify = (value) => String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    /**
     * Find a portal by its slug.
     */
    const portalBySlug = (slug) => config.portals.find((portal) => portal.slug === slug);
    /**
     * Find the portal an API path belongs to.
     */
    const portalForPath = (pathname) => config.portals.find((portal) =>
        portal.prefixes.some((prefix) => pathname === `/${prefix}` || pathname.startsWith(`/${prefix}/`)));
    /**
     * Build the hash link to a docs page.
     */
    const link = (portal, kind, id) => `#/${portal.slug}${kind ? `/${kind}/${encodeURIComponent(id)}` : ''}`;
    /**
     * Parse JSON, or return undefined when it is invalid.
     */
    const safeJson = (text) => {
        try {
            return JSON.parse(text);
        } catch {
            return undefined;
        }
    };
    /**
     * Format a byte count, e.g. "5.5 KB".
     */
    const formatBytes = (bytes) => bytes < 1024 ? `${bytes} B` : bytes < 1048576 ? `${(bytes / 1024).toFixed(1)} KB` : `${(bytes / 1048576).toFixed(1)} MB`;
    /**
     * Get a display name for a logged-in user.
     */
    const displayName = (user = {}) =>
        user?.name || [user?.first_name, user?.last_name].filter(Boolean).join(' ') || user?.email || 'Signed in';

    /**
     * Delay a function until calls have stopped for the given time.
     */
    function debounce(fn, wait = 150) {
        let timer;

        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), wait);
        };
    }

    /**
     * Render inline Markdown (code, bold, italics, links) as HTML.
     */
    function inline(text) {
        const codes = [];
        let out = escapeHtml(text).replace(/`([^`]+)`/g, (_, code) => `\u0000${codes.push(code) - 1}\u0000`);

        out = out
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*\w])\*([^*\s][^*]*?)\*(?!\w)/g, '$1<em>$2</em>')
            .replace(/\[([^\]]+)\]\(((?:https?:\/\/|#|\/)[^)\s]*)\)/g, '<a href="$2">$1</a>')
            .replace(/&lt;(\/?)sub&gt;/g, '<$1sub>');

        return out.replace(/\u0000(\d+)\u0000/g, (_, index) => `<code>${codes[index]}</code>`);
    }

    /**
     * Render the Markdown used in descriptions as HTML.
     */
    function markdown(source = '') {
        const lines = String(source).replace(/\r\n/g, '\n').split('\n');
        const blockStart = /^(```|#{1,4}\s|\s*\||\s*(?:[-*]|\d+\.)\s)/;
        const html = [];
        let i = 0;

        while (i < lines.length) {
            const line = lines[i];

            if (line.startsWith('```')) {
                const lang = line.slice(3).trim();
                const code = [];
                i++;
                while (i < lines.length && !lines[i].startsWith('```')) {
                    code.push(lines[i++]);
                }
                i++;
                html.push(`<pre class="code"><code>${highlight(code.join('\n'), lang)}</code></pre>`);
                continue;
            }

            const heading = line.match(/^(#{1,4})\s+(.*)$/);
            if (heading) {
                const level = Math.min(heading[1].length + 1, 4);
                html.push(`<h${level} id="${slugify(heading[2])}">${inline(heading[2])}</h${level}>`);
                i++;
                continue;
            }

            if (/^\s*\|/.test(line) && /^\s*\|?\s*:?-{3,}/.test(lines[i + 1] || '')) {
                /**
                 * Split a Markdown table row into cells.
                 */
                const cells = (row) => row.trim().replace(/^\||\|$/g, '').split('|').map((cell) => cell.trim());
                const head = cells(line);
                const body = [];
                i += 2;
                while (i < lines.length && /^\s*\|/.test(lines[i])) {
                    body.push(cells(lines[i++]));
                }
                html.push(`<div class="table-wrap"><table><thead><tr>${head.map((c) => `<th>${inline(c)}</th>`).join('')}</tr></thead>`
                    + `<tbody>${body.map((row) => `<tr>${row.map((c) => `<td>${inline(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
                continue;
            }

            if (/^\s*(?:[-*]|\d+\.)\s+/.test(line)) {
                const ordered = /^\s*\d+\./.test(line);
                const items = [];
                while (i < lines.length && /^\s*(?:[-*]|\d+\.)\s+/.test(lines[i])) {
                    items.push(lines[i++].replace(/^\s*(?:[-*]|\d+\.)\s+/, ''));
                }
                const tag = ordered ? 'ol' : 'ul';
                html.push(`<${tag}>${items.map((item) => `<li>${inline(item)}</li>`).join('')}</${tag}>`);
                continue;
            }

            if (!line.trim()) {
                i++;
                continue;
            }

            const paragraph = [];
            while (i < lines.length && lines[i].trim() && (paragraph.length === 0 || !blockStart.test(lines[i]))) {
                paragraph.push(lines[i++]);
            }
            html.push(`<p>${inline(paragraph.join(' '))}</p>`);
        }

        return html.join('');
    }

    /**
     * Escape code and add JSON syntax highlighting.
     */
    function highlight(code, lang = '') {
        const escaped = escapeHtml(code);
        const looksJson = lang === 'json' || (!lang && /^\s*[[{]/.test(code));

        if (!looksJson) {
            return escaped;
        }

        return escaped.replace(/(&quot;(?:[^&\\]|\\.|&(?!quot;))*?&quot;)(\s*:)?|\b(true|false|null)\b|-?\b\d+(?:\.\d+)?(?:e[+-]?\d+)?\b/g, (match, string, colon, literal) => {
            if (string) {
                return colon ? `<span class="t-key">${string}</span>${colon}` : `<span class="t-str">${string}</span>`;
            }

            return literal ? `<span class="t-lit">${match}</span>` : `<span class="t-num">${match}</span>`;
        });
    }

    /**
     * Load a portal's spec; the ?v=<hash> in its URL lets the browser cache it per build.
     */
    function loadSpec(portal) {
        specs[portal.slug] ??= fetch(portal.url, { headers: { Accept: 'application/json' } }).then((response) => {
            if (!response.ok) {
                throw new Error(response.status === 404
                    ? `The ${portal.title} docs have not been built. Run "php artisan api-docs:generate".`
                    : `The ${portal.title} docs could not be loaded (HTTP ${response.status}).`);
            }

            return response.json();
        });

        return specs[portal.slug];
    }

    /**
     * Poll the manifest while --watch runs and swap in rebuilt specs.
     */
    function watchForRebuilds() {
        if (!config.live) {
            return;
        }

        const hashes = Object.fromEntries(config.portals.map((portal) => [portal.slug, portal.hash]));

        setInterval(async () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            try {
                const manifest = await (await fetch(config.manifestUrl, { cache: 'no-store' })).json();

                for (const portal of config.portals) {
                    const hash = manifest.portals?.[portal.slug]?.hash;
                    if (!hash || hash === hashes[portal.slug]) {
                        continue;
                    }

                    hashes[portal.slug] = hash;
                    portal.url = `${portal.url.split('?')[0]}?v=${hash}`;
                    delete specs[portal.slug];

                    if (state.portal === portal && state.spec) {
                        applyUpdatedSpec(await loadSpec(portal));
                    }
                }
            } catch {
                // The watcher may be mid-write or stopped; try again on the next tick.
            }
        }, 1500);
    }

    /**
     * Swap in a rebuilt spec without losing the current page.
     */
    function applyUpdatedSpec(spec) {
        const target = parseHash();
        const before = target.kind === 'op' ? JSON.stringify(state.byId.get(target.id)?.op) : null;

        Object.assign(state, { spec }, index(spec));
        renderSidebar();

        if (target.kind !== 'op') {
            route();
        } else if (before !== JSON.stringify(state.byId.get(target.id)?.op)) {
            // Re-rendering would wipe the console (and any response in it), so ask first.
            ui.main.querySelector('.op__docs')?.prepend(h('div', { class: 'notice notice--info update-banner' },
                'This endpoint changed in the code. ',
                h('button', { class: 'link-btn', type: 'button', onClick: route }, 'Reload it')));
            markActive(target);
        } else {
            markActive(target);
        }

        toast('Docs updated with your latest code changes.', 'success');
    }

    /**
     * Follow a $ref to its target in the spec.
     */
    function resolve(value, depth = 0) {
        if (value && value.$ref && depth < 12) {
            const target = value.$ref.replace(/^#\//, '').split('/')
                .reduce((node, key) => node?.[key.replace(/~1/g, '/').replace(/~0/g, '~')], state.spec);

            return resolve(target, depth + 1);
        }

        return value || {};
    }

    /**
     * Index a spec: operations, tags, sidebar groups and intro sections.
     */
    function index(spec) {
        const ops = [];

        for (const [path, item] of Object.entries(spec.paths || {})) {
            for (const method of METHODS) {
                const op = item[method];
                if (!op) {
                    continue;
                }

                const tag = (op.tags || ['General'])[0];
                ops.push({
                    id: op.operationId || `${method}-${path}`,
                    method,
                    path,
                    op,
                    tag,
                    haystack: `${method} ${path} ${op.summary || ''} ${tag}`.toLowerCase(),
                });
            }
        }

        const tagMeta = new Map((spec.tags || []).map((tag) => [tag.name, tag]));
        const byTag = new Map();
        ops.forEach((entry) => byTag.set(entry.tag, [...(byTag.get(entry.tag) || []), entry]));

        const groupDefs = spec['x-tagGroups'] || [{ name: 'Endpoints', tags: [...byTag.keys()] }];
        const tags = new Map();
        const groups = groupDefs.map((def) => {
            const group = { name: def.name, tags: [], count: 0 };
            group.tags = def.tags.filter((name) => byTag.has(name)).map((name) => {
                const tag = {
                    name,
                    label: tagMeta.get(name)?.['x-displayName'] || name,
                    description: tagMeta.get(name)?.description,
                    ops: byTag.get(name),
                    group,
                };
                group.count += tag.ops.length;
                tags.set(name, tag);

                return tag;
            });
            group.folders = folderTree(group.tags);

            return group;
        }).filter((group) => group.tags.length);

        return {
            ops,
            // Sidebar order (group → folders → endpoint); Previous / Next walk this, not route registration order.
            sequence: groups.flatMap((group) => group.folders.flatMap(folderOps)),
            byId: new Map(ops.map((entry) => [entry.id, entry])),
            tags,
            groups,
            intro: introSections(spec.info?.description || ''),
        };
    }

    /**
     * Nest a group's tags into folders by their "A / B" labels; a folder can hold endpoints, subfolders or both.
     */
    function folderTree(tags) {
        const root = { children: [] };

        for (const tag of tags) {
            const names = tag.label.split(' / ');
            let folder = root;

            names.forEach((name, depth) => {
                const isLast = depth === names.length - 1;
                // Two tags sharing a label stay separate rows, as a folder shows one tag's endpoints.
                let child = folder.children.find((item) => item.name === name && !(isLast && item.tag));

                if (!child) {
                    child = { name, parent: folder, tag: null, children: [], count: 0 };
                    folder.children.push(child);
                }

                child.count += tag.ops.length;
                folder = child;
            });

            folder.tag = tag;
            tag.folder = folder;
        }

        return root.children;
    }

    /**
     * List a folder's endpoints in sidebar order: its own, then each subfolder's.
     */
    const folderOps = (folder) => [...(folder.tag?.ops || []), ...folder.children.flatMap(folderOps)];

    /**
     * Split the intro Markdown into sections by "##" heading.
     */
    function introSections(description) {
        const sections = [];
        let current = { title: 'Overview', body: [] };

        for (const line of description.split('\n')) {
            const heading = line.match(/^##\s+(.*)$/);
            if (heading) {
                sections.push(current);
                current = { title: heading[1].trim(), body: [] };
            } else {
                current.body.push(line);
            }
        }
        sections.push(current);

        return sections
            .map((section) => ({ ...section, body: section.body.join('\n').trim(), slug: slugify(section.title) }))
            .filter((section) => section.body);
    }

    /**
     * Get how an operation is authenticated: public, bearer token or API key.
     */
    function securityFor(op) {
        const requirements = op.security ?? state.spec.security ?? [];
        const named = requirements.find((requirement) => Object.keys(requirement).length);

        if (!named) {
            return { kind: 'public' };
        }

        const name = Object.keys(named)[0];
        const scheme = state.spec.components?.securitySchemes?.[name] || {};

        return { kind: scheme.type === 'apiKey' ? 'apiKey' : 'bearer', name, scheme };
    }

    /**
     * Get the content type and schema of an operation's request body.
     */
    function requestBodyOf(op) {
        if (!op.requestBody) {
            return null;
        }

        const body = resolve(op.requestBody);
        const contentType = Object.keys(body.content || {})[0];

        return contentType
            ? { contentType, required: Boolean(body.required), schema: resolve(body.content[contentType].schema), multipart: contentType === 'multipart/form-data' }
            : null;
    }

    /**
     * Get a schema's types as an array.
     */
    const typesOf = (schema) => [].concat(resolve(schema).type ?? []);

    /**
     * Get a readable type label, e.g. "array<integer>".
     */
    function typeLabel(schema) {
        schema = resolve(schema);
        const types = typesOf(schema).filter((type) => type !== 'null');

        if (types.includes('array')) {
            return `array<${schema.items ? typeLabel(schema.items) : 'any'}>`;
        }

        let label = types.join(' | ') || (schema.properties ? 'object' : schema.enum ? 'enum' : 'any');
        if (schema.format) {
            label += ` · ${schema.format}`;
        }

        return label;
    }

    /**
     * Build an example value from a schema.
     */
    function exampleFor(schema, unknown = null, depth = 0) {
        schema = resolve(schema);

        if (depth > 6) {
            return unknown;
        }
        if (schema.examples?.length) {
            return schema.examples[0];
        }
        if ('example' in schema) {
            return schema.example;
        }
        if ('default' in schema) {
            return schema.default;
        }
        if (schema.enum?.length) {
            return schema.enum[0];
        }

        const type = typesOf(schema).find((t) => t !== 'null') || (schema.properties ? 'object' : schema.items ? 'array' : null);

        switch (type) {
            case 'object':
                return Object.fromEntries(Object.entries(schema.properties || {}).map(([key, value]) => [key, exampleFor(value, unknown, depth + 1)]));
            case 'array':
                return schema.items ? [exampleFor(schema.items, unknown, depth + 1)] : [];
            case 'integer':
                return schema.minimum ?? 1;
            case 'number':
                return schema.minimum ?? 1.5;
            case 'boolean':
                return true;
            case 'string':
                return stringExample(schema);
            default:
                return unknown;
        }
    }

    /**
     * Build an example string, honouring formats and date patterns.
     */
    function stringExample(schema) {
        const dateFormat = /Format: `([^`]+)`/.exec(schema.description || '');
        if (dateFormat) {
            return formatDate(dateFormat[1]);
        }

        const now = new Date();

        return {
            email: 'user@example.com',
            date: now.toISOString().slice(0, 10),
            'date-time': now.toISOString(),
            uri: 'https://example.com',
            uuid: '3fa85f64-5717-4562-b3fc-2c963f66afa6',
            binary: '',
            password: '',
        }[schema.format] ?? 'string';
    }

    /**
     * Format today's date with a PHP date format, e.g. "d/m/Y".
     */
    function formatDate(format) {
        const date = new Date();
        /**
         * Pad a number to two digits.
         */
        const pad = (value) => String(value).padStart(2, '0');
        const parts = {
            d: pad(date.getDate()), j: date.getDate(), m: pad(date.getMonth() + 1), n: date.getMonth() + 1,
            Y: date.getFullYear(), y: String(date.getFullYear()).slice(2),
            H: pad(date.getHours()), i: pad(date.getMinutes()), s: pad(date.getSeconds()),
        };

        return format.replace(/[djmnYyHis]/g, (token) => parts[token]);
    }

    /**
     * Send a request to the selected server, adding the stored token.
     */
    async function execute(request) {
        const pathname = request.path.split('?')[0];
        const portal = portalForPath(pathname);
        const session = portal ? sessions.get(portal) : null;
        const headers = new Headers(request.headers);

        if (session && !headers.has('Authorization')) {
            headers.set('Authorization', `Bearer ${session.token}`);
        }

        // Always hit the server, even when the response says it may be cached (e.g. PDFs are cacheable for an hour).
        const init = { method: request.method, headers, signal: request.signal, cache: 'no-store' };
        if (request.body !== undefined) {
            init.body = request.body;
        }

        const started = performance.now();
        let response;
        try {
            response = await fetch(state.server.url + request.path, init);
        } catch (error) {
            if (error.name === 'AbortError') {
                throw error;
            }
            throw new Error(`Could not reach ${state.server.name} (${state.server.url}). Check that it is running and allows CORS requests from ${window.location.origin}.`);
        }
        const blob = await response.blob();
        const elapsed = Math.round(performance.now() - started);
        const type = response.headers.get('content-type') || '';
        // Office formats contain "xml" in their media type, so match the textual types precisely.
        const textual = !type || /^(text\/|application\/(json|xml|javascript|problem\+json)\b)|\+(json|xml)\b/i.test(type);
        const text = textual ? await blob.text() : null;

        if (portal) {
            remember(portal, pathname, request.method, response.status, text, Boolean(session));
        }

        return { response, blob, text, elapsed };
    }

    /**
     * Store or clear the token after a login, logout or expired session.
     */
    function remember(portal, pathname, method, status, text, hadSession) {
        const ok = status >= 200 && status < 300;

        if (pathname === portal.login && method === 'POST' && ok) {
            const body = safeJson(text);
            const token = portal.tokenPath.split('.').reduce((value, key) => value?.[key], body);
            if (token) {
                const name = displayName(body?.data?.user ?? body?.user);
                sessions.set(portal, { token, name });
                toast(`Signed in to the ${portal.title} as ${name}. The token is now sent with every request.`, 'success');
            }
        } else if (pathname === portal.logout && status < 500) {
            sessions.clear(portal);
            toast(`Signed out of the ${portal.title}.`);
        } else if (status === 401 && hadSession) {
            sessions.clear(portal);
            toast(`Your ${portal.title} session has expired. Log in again.`, 'warning');
        }

        renderSessionState();
    }

    /**
     * Log out of a portal and forget its token.
     */
    async function signOut(portal) {
        try {
            await execute({ method: 'PUT', path: portal.logout, headers: { Accept: 'application/json' } });
        } catch {
            // The token is forgotten locally even if the API cannot be reached.
        } finally {
            sessions.clear(portal);
            renderSessionState();
        }
    }

    /**
     * Find the login endpoint of a portal.
     */
    function loginOperation(portal = state.portal) {
        return state.portal === portal ? state.ops.find((entry) => entry.method === 'post' && entry.path === portal.login) : null;
    }

    /**
     * Refresh everything that shows the login state.
     */
    function renderSessionState() {
        renderAuthButton();
        ui.consoleAuth?.isConnected && ui.consoleAuth.refresh();
        ui.authDialog?.isConnected && ui.authDialog.refresh();
    }

    /**
     * Build the page layout: header, sidebar, main area and toasts.
     */
    function buildShell() {
        ui.authButton = h('button', { class: 'btn btn--ghost auth-trigger', type: 'button', onClick: openAuthorize });
        ui.themeButton = h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Toggle dark mode', onClick: toggleTheme });
        ui.brand = h('a', { class: 'brand', href: '#/' },
            h('span', { class: 'brand__mark', 'aria-hidden': 'true' }, config.title.charAt(0)),
            h('span', { class: 'brand__name' }, config.title),
            h('span', { class: 'brand__badge' }, 'Docs'));

        const topbar = h('header', { class: 'topbar' },
            h('div', { class: 'topbar__start' },
                h('button', { class: 'icon-btn menu-btn', type: 'button', 'aria-label': 'Toggle navigation', onClick: () => document.body.classList.toggle('nav-open') }, icon('menu')),
                ui.brand),
            h('button', { class: 'search-trigger', type: 'button', onClick: openPalette, 'aria-label': 'Search endpoints' },
                icon('search'),
                h('span', { class: 'search-trigger__label' }, 'Search endpoints…'),
                h('kbd', {}, isMac ? '⌘ K' : 'Ctrl K')),
            h('div', { class: 'topbar__actions' }, buildServerPicker(), ui.authButton, ui.themeButton));

        ui.switcher = h('div', { class: 'portal-switch', role: 'tablist', 'aria-label': 'Portal' },
            config.portals.map((portal) => h('a', { class: 'portal-switch__item', role: 'tab', href: `#/${portal.slug}`, dataset: { portal: portal.slug } },
                portal.title.replace(/\s+API$/, ''))));
        ui.nav = h('nav', { class: 'nav', 'aria-label': 'API reference' });
        ui.main = h('main', { class: 'main', id: 'docs-main', tabindex: '-1' });
        ui.toasts = h('div', { class: 'toasts', 'aria-live': 'polite' });

        const resizer = h('div', {
            class: 'sidebar-resizer',
            role: 'separator',
            tabindex: '0',
            title: 'Drag to resize, double-click to reset',
            'aria-orientation': 'vertical',
            'aria-label': 'Resize sidebar',
            'aria-valuemin': SIDEBAR_MIN,
        });
        ui.sidebar = h('aside', { class: 'sidebar' }, ui.switcher, ui.nav, resizer);

        fill(root,
            h('a', { class: 'skip-link', href: '#docs-main', onClick: (event) => { event.preventDefault(); ui.main.focus(); } }, 'Skip to content'),
            topbar,
            h('div', { class: 'layout' }, ui.sidebar, ui.main),
            h('div', { class: 'scrim', onClick: () => document.body.classList.remove('nav-open') }),
            ui.toasts);

        initSidebarResizer(resizer);
    }

    /**
     * Get the widest the sidebar may be in this window; the 35vw also caps it in docs.css.
     */
    const sidebarMax = () => Math.min(SIDEBAR_MAX, Math.round(window.innerWidth * 0.35));

    /**
     * Make the sidebar resizable: drag the handle, use the arrow keys, or double-click to reset.
     */
    function initSidebarResizer(handle) {
        const rootStyle = document.documentElement.style;
        const syncAria = () => {
            handle.setAttribute('aria-valuemax', sidebarMax());
            handle.setAttribute('aria-valuenow', ui.sidebar.offsetWidth);
        };
        const resize = (width) => {
            rootStyle.setProperty('--sidebar', `${Math.max(SIDEBAR_MIN, Math.min(width, sidebarMax()))}px`);
            syncAria();
        };
        const save = () => storage.set(SIDEBAR_WIDTH_KEY, ui.sidebar.offsetWidth);

        handle.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }

            const startX = event.clientX;
            const startWidth = ui.sidebar.offsetWidth;
            const onMove = (move) => resize(startWidth + move.clientX - startX);

            handle.setPointerCapture(event.pointerId);
            handle.addEventListener('pointermove', onMove);
            handle.addEventListener('lostpointercapture', () => {
                handle.removeEventListener('pointermove', onMove);
                document.body.classList.remove('sidebar-resizing');
                save();
            }, { once: true });
            document.body.classList.add('sidebar-resizing');
        });

        handle.addEventListener('keydown', (event) => {
            const width = ui.sidebar.offsetWidth;
            const next = { ArrowLeft: width - SIDEBAR_STEP, ArrowRight: width + SIDEBAR_STEP, Home: SIDEBAR_MIN, End: sidebarMax() }[event.key];

            if (next !== undefined) {
                event.preventDefault();
                resize(next);
                save();
            }
        });

        handle.addEventListener('dblclick', () => {
            rootStyle.removeProperty('--sidebar');
            storage.remove(SIDEBAR_WIDTH_KEY);
            syncAria();
        });

        const saved = storage.get(SIDEBAR_WIDTH_KEY);
        if (Number.isFinite(saved)) {
            rootStyle.setProperty('--sidebar', `${saved}px`);
        }

        syncAria();
        window.addEventListener('resize', debounce(syncAria));
    }

    /**
     * Show "Authorize" or the signed-in user in the header.
     */
    function renderAuthButton() {
        const session = state.portal && sessions.get(state.portal);

        ui.authButton.classList.toggle('is-signed-in', Boolean(session));
        fill(ui.authButton,
            session ? h('span', { class: 'dot', 'aria-hidden': 'true' }) : icon('key'),
            h('span', { class: 'auth-trigger__label' }, session ? session.name : 'Authorize'));
    }

    /**
     * Get the server chosen last time, or this server.
     */
    function initialServer() {
        const saved = storage.get('api-docs.server');

        return config.servers.find((server) => server.url === saved?.url) || (saved?.custom && saved.url ? saved : config.servers[0]);
    }

    /**
     * Get the colour of a server from its name: prod, UAT, dev or local.
     */
    function envTone(server) {
        const label = `${server.name} ${server.url}`.toLowerCase();

        if (/prod|live/.test(label)) {
            return 'danger';
        }
        if (/uat|stag|qa|test/.test(label)) {
            return 'warn';
        }

        return /dev/.test(label) ? 'info' : 'ok';
    }

    /**
     * Build the server menu in the header.
     */
    function buildServerPicker() {
        ui.serverButton = h('button', {
            class: 'btn btn--ghost server-trigger', type: 'button', 'aria-haspopup': 'true', 'aria-expanded': 'false',
            onClick: () => toggleServerMenu(),
        });
        ui.serverMenu = h('div', { class: 'server-menu', role: 'menu', hidden: true });
        ui.serverPicker = h('div', { class: 'server-picker' }, ui.serverButton, ui.serverMenu);

        document.addEventListener('mousedown', (event) => !ui.serverPicker.contains(event.target) && !event.target.closest?.('.chip--server') && toggleServerMenu(false));
        document.addEventListener('keydown', (event) => event.key === 'Escape' && toggleServerMenu(false));

        return ui.serverPicker;
    }

    /**
     * Show the selected server in the header.
     */
    function renderServerButton() {
        ui.serverButton.title = `Requests go to ${state.server.url}`;
        fill(ui.serverButton,
            h('span', { class: `env-dot env-dot--${envTone(state.server)}`, 'aria-hidden': 'true' }),
            h('span', { class: 'server-trigger__label' }, state.server.name),
            icon('chevronDown'));
    }

    /**
     * Open or close the server menu.
     */
    function toggleServerMenu(open = ui.serverMenu.hidden) {
        ui.serverMenu.hidden = !open;
        ui.serverButton.setAttribute('aria-expanded', String(open));

        if (!open) {
            return;
        }

        const servers = [...config.servers];
        if (state.server.custom) {
            servers.push(state.server);
        }

        const customInput = h('input', {
            class: 'input', type: 'url', placeholder: 'https://other-server.example.com', 'aria-label': 'Custom server URL',
            value: state.server.custom ? state.server.url : '',
        });
        /**
         * Use the URL typed into the custom server field.
         */
        const useCustom = () => {
            try {
                const url = new URL(customInput.value.trim());
                selectServer({ name: url.host, url: `${url.origin}${url.pathname}`.replace(/\/+$/, ''), custom: true });
            } catch {
                customInput.setCustomValidity('Enter a full URL, e.g. https://uat-api.example.com');
                customInput.reportValidity();
            }
        };
        customInput.addEventListener('input', () => customInput.setCustomValidity(''));
        customInput.addEventListener('keydown', (event) => event.key === 'Enter' && useCustom());

        fill(ui.serverMenu,
            h('div', { class: 'server-menu__label' }, 'Send requests to'),
            servers.map((server) => h('button', {
                class: 'server-option', type: 'button', role: 'menuitemradio', 'aria-checked': String(server.url === state.server.url),
                onClick: () => selectServer(server),
            },
                h('span', { class: `env-dot env-dot--${envTone(server)}`, 'aria-hidden': 'true' }),
                h('span', { class: 'server-option__text' }, h('span', { class: 'server-option__name' }, server.name), h('code', {}, server.url)),
                server.url === state.server.url ? icon('check') : null)),
            h('div', { class: 'server-menu__custom' },
                h('div', { class: 'server-menu__label' }, 'Other server'),
                h('div', { class: 'server-menu__row' }, customInput, h('button', { class: 'btn', type: 'button', onClick: useCustom }, 'Use'))));
    }

    /**
     * Send requests to another server from now on.
     */
    function selectServer(server) {
        toggleServerMenu(false);

        if (server.url === state.server.url) {
            return;
        }

        state.server = server;
        storage.set('api-docs.server', server);
        renderServerButton();
        renderSessionState();
        toast(`Requests now go to ${server.name} (${server.url}). Tokens are kept separately for each server.`);

        if (state.spec) {
            route();
        }
    }

    /**
     * Mark the active portal in the switcher.
     */
    function renderSwitcher() {
        ui.switcher.querySelectorAll('[data-portal]').forEach((el) =>
            el.setAttribute('aria-selected', String(el.dataset.portal === state.portal.slug)));
        ui.brand.setAttribute('href', `#/${state.portal.slug}`);
    }

    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');
    /**
     * Get the chosen theme, or the system one.
     */
    const currentTheme = () => storage.get('api-docs.theme') || (prefersDark.matches ? 'dark' : 'light');

    /**
     * Apply the current theme.
     */
    function applyTheme() {
        const theme = currentTheme();
        document.documentElement.dataset.theme = theme;
        fill(ui.themeButton, icon(theme === 'dark' ? 'sun' : 'moon'));
    }

    /**
     * Switch between light and dark.
     */
    function toggleTheme() {
        storage.set('api-docs.theme', currentTheme() === 'dark' ? 'light' : 'dark');
        applyTheme();
    }

    /**
     * Show a short notification.
     */
    function toast(message, tone = 'info') {
        const el = h('div', { class: `toast toast--${tone}`, role: 'status' }, message);
        ui.toasts.append(el);
        setTimeout(() => el.classList.add('is-leaving'), 4200);
        setTimeout(() => el.remove(), 4600);
    }

    /**
     * Open a dialog that closes on Escape or a click outside it.
     */
    function modal(content, { label, onClose } = {}) {
        const previous = document.activeElement;
        const dialog = h('div', { class: 'dialog', role: 'dialog', 'aria-modal': 'true', 'aria-label': label }, content);
        const overlay = h('div', { class: 'overlay', onMousedown: (event) => event.target === overlay && close() }, dialog);
        /**
         * Close the dialog on Escape.
         */
        const onKey = (event) => event.key === 'Escape' && close();

        /**
         * Close the dialog and restore focus.
         */
        function close() {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            onClose?.();
            previous?.focus?.();
        }

        document.addEventListener('keydown', onKey);
        document.body.append(overlay);

        return { close, dialog };
    }

    /**
     * Create a button that copies text to the clipboard.
     */
    function copyButton(getText, label = 'Copy') {
        const button = h('button', { class: 'icon-btn icon-btn--sm', type: 'button', 'aria-label': label, title: label }, icon('copy'));
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(typeof getText === 'function' ? getText() : getText);
                fill(button, icon('check'));
                setTimeout(() => fill(button, icon('copy')), 1400);
            } catch {
                toast('Copying is blocked by the browser.', 'warning');
            }
        });

        return button;
    }

    /**
     * Create a highlighted code block with a copy button.
     */
    function codeBlock(code, lang = '', { copy = true } = {}) {
        return h('div', { class: 'codeblock' },
            h('pre', { class: 'code' }, h('code', { html: highlight(code, lang) })),
            copy ? h('div', { class: 'codeblock__actions' }, copyButton(code)) : null);
    }

    /**
     * Create a JSON block whose objects and arrays open and close with a caret.
     */
    function jsonBlock(value, { copy = true } = {}) {
        const text = JSON.stringify(value, null, 2);
        // A very large body would mean thousands of nodes, so it keeps the plain view.
        if (text.length > 200000) {
            return codeBlock(text, 'json', { copy });
        }

        return h('div', { class: 'codeblock' },
            h('div', { class: 'code json-tree' }, jsonNode(value, null, false)),
            copy ? h('div', { class: 'codeblock__actions' }, copyButton(text)) : null);
    }

    /**
     * Render one JSON value as lines, with a caret when it is a non-empty object or array.
     */
    function jsonNode(value, key, comma) {
        const label = key === null ? null : [h('span', { class: 't-key' }, JSON.stringify(key)), ': '];
        const tail = comma ? ',' : '';
        const entries = value !== null && typeof value === 'object' ? Object.entries(value) : null;

        if (!entries) {
            return h('div', { class: 'json-tree__line' }, label, jsonScalar(value), tail);
        }

        const [open, close] = Array.isArray(value) ? ['[', ']'] : ['{', '}'];
        if (!entries.length) {
            return h('div', { class: 'json-tree__line' }, label, open + close, tail);
        }

        const noun = Array.isArray(value) ? 'item' : 'key';
        const count = `${entries.length} ${noun}${entries.length === 1 ? '' : 's'}`;
        const node = h('div', { class: 'json-tree__node is-open' });
        const toggle = () => caret.setAttribute('aria-expanded', String(node.classList.toggle('is-open')));
        const caret = h('button', { class: 'json-tree__caret', type: 'button', 'aria-expanded': 'true', 'aria-label': `Toggle ${key ?? 'root'}`, onClick: toggle },
            icon('chevron'));

        return append(node, [
            h('div', { class: 'json-tree__line' }, caret, label, open,
                h('button', { class: 'json-tree__summary', type: 'button', tabindex: '-1', onClick: toggle }, `… ${count} ${close}${tail}`)),
            h('div', { class: 'json-tree__children' },
                entries.map(([childKey, child], index) => jsonNode(child, Array.isArray(value) ? null : childKey, index < entries.length - 1))),
            h('div', { class: 'json-tree__line json-tree__close' }, close + tail),
        ]);
    }

    /**
     * Render a JSON string, number, boolean or null with its highlight colour.
     */
    function jsonScalar(value) {
        if (typeof value === 'string') {
            return h('span', { class: 't-str' }, JSON.stringify(value));
        }

        return h('span', { class: typeof value === 'number' ? 't-num' : 't-lit' }, JSON.stringify(value));
    }

    /**
     * Create the coloured HTTP method badge.
     */
    const methodPill = (method) => h('span', { class: `method method--${method}` }, method === 'delete' ? 'DEL' : method.toUpperCase());

    /**
     * Create tabs; the selected tab can be remembered.
     */
    function tabs(items, storageKey) {
        const saved = storage.get(storageKey);
        let active = Math.max(0, items.findIndex((item) => item.label === saved));
        const bar = h('div', { class: 'tabs', role: 'tablist' });
        const panel = h('div', { class: 'tabs__panel', role: 'tabpanel' });

        /**
         * Show the given tab.
         */
        function select(index, persist = false) {
            active = index;
            if (persist && storageKey) {
                storage.set(storageKey, items[index].label);
            }
            [...bar.children].forEach((button, i) => button.setAttribute('aria-selected', String(i === index)));
            fill(panel, items[index].render());
        }

        items.forEach((item, index) => bar.append(h('button', { class: 'tab', type: 'button', role: 'tab', onClick: () => select(index, true) }, item.label)));
        select(active);

        return { el: h('div', { class: 'tabs-wrap' }, bar, panel), refresh: () => select(active) };
    }

    /**
     * Build the sidebar: intro links and endpoint groups.
     */
    function renderSidebar() {
        const portal = state.portal;

        const intro = h('div', { class: 'nav__section' },
            h('div', { class: 'nav__heading' }, 'Introduction'),
            state.intro.map((section) => h('a', { class: 'nav__link', href: link(portal, 'intro', section.slug), dataset: { key: `intro:${section.slug}` } }, section.title)));

        const groups = state.groups.map((group) => {
            const flat = group.tags.length === 1 && group.tags[0].label === group.name;
            group.details = h('details', { class: 'nav__group' },
                h('summary', { class: 'nav__group-title' }, icon('chevron'), h('span', { class: 'nav__label' }, group.name), h('span', { class: 'count' }, group.count)));

            if (flat) {
                const tag = group.tags[0];
                group.details.append(opsList(tag));
                tag.folder.details = group.details;
                group.details.addEventListener('toggle', () => group.details.open && tag.fill());
            } else {
                group.details.append(...group.folders.map((folder) => navFolder(folder)));
            }

            return group.details;
        });

        fill(ui.nav, intro, h('div', { class: 'nav__heading' }, 'Endpoints'), groups);
    }

    /**
     * Create a tag's endpoint list, filled when first opened.
     */
    function opsList(tag) {
        const list = h('div', { class: 'nav__ops' });
        tag.fill = () => {
            if (!list.childElementCount) {
                append(list, tag.ops.map((entry) => h('a', { class: 'nav__op', href: link(state.portal, 'op', entry.id), dataset: { key: `op:${entry.id}` } },
                    methodPill(entry.method),
                    h('span', { class: 'nav__label' }, entry.op.summary || entry.path))));
            }
        };

        return list;
    }

    /**
     * Create a collapsible folder in the sidebar, with its endpoints and then its subfolders.
     */
    function navFolder(folder) {
        const { tag } = folder;

        folder.details = h('details', { class: 'nav__tag' },
            h('summary', { class: 'nav__tag-title', dataset: tag && { key: `tag:${tag.name}` } },
                icon('chevron'),
                h('span', { class: 'nav__label', title: folder.name }, folder.name),
                h('span', { class: 'count' }, folder.count)),
            tag && opsList(tag),
            folder.children.map((child) => navFolder(child)));

        if (tag) {
            folder.details.addEventListener('toggle', () => folder.details.open && tag.fill());
        }

        return folder.details;
    }

    /**
     * Highlight the current page in the sidebar and scroll it into view.
     */
    function markActive({ kind, id }) {
        ui.nav.querySelectorAll('[aria-current]').forEach((el) => el.removeAttribute('aria-current'));

        let key = `intro:${id || state.intro[0]?.slug}`;
        const tag = kind === 'op' ? state.tags.get(state.byId.get(id)?.tag) : kind === 'tag' ? state.tags.get(id) : null;

        if (tag) {
            tag.group.details.open = true;
            for (let folder = tag.folder; folder.parent; folder = folder.parent) {
                folder.details.open = true;
            }
            tag.fill();
            key = kind === 'op' ? `op:${id}` : `tag:${id}`;
        }

        const el = [...ui.nav.querySelectorAll('[data-key]')].find((node) => node.dataset.key === key);
        if (el) {
            el.setAttribute('aria-current', 'page');
            el.scrollIntoView({ block: 'nearest' });
        }
    }

    /**
     * Show the loading state.
     */
    function showLoading(portal) {
        fill(ui.nav, h('div', { class: 'nav__skeleton' }, Array.from({ length: 10 }, () => h('span'))));
        fill(ui.main, h('div', { class: 'state' },
            h('div', { class: 'spinner', 'aria-hidden': 'true' }),
            h('h2', {}, `Loading the ${portal.title}…`),
            h('p', {}, 'Fetching the pre-built reference.')));
    }

    /**
     * Show an error with a retry button.
     */
    function showError(error) {
        fill(ui.main, h('div', { class: 'state' },
            h('h2', {}, 'The docs could not be loaded'),
            h('p', {}, error.message),
            h('button', { class: 'btn btn--primary', type: 'button', onClick: route }, 'Try again')));
    }

    /**
     * Render a portal's overview page.
     */
    function renderIntro(sectionSlug) {
        const portal = state.portal;
        const login = loginOperation();

        const hero = h('header', { class: 'hero' },
            h('div', { class: 'hero__meta' },
                h('span', { class: 'chip' }, `v${state.spec.info?.version || '1.0.0'}`),
                h('span', { class: 'chip' }, `OpenAPI ${state.spec.openapi}`),
                config.build?.generatedAt ? h('span', { class: 'chip', title: `Generated ${config.build.generatedAt}` },
                    `Built ${formatBuildTime(config.build.generatedAt)}${config.build.commit ? ` · ${config.build.commit}` : ''}`) : null,
                config.live ? h('span', { class: 'chip chip--public', title: 'api-docs:generate --watch is running' }, h('span', { class: 'dot', 'aria-hidden': 'true' }), 'Live') : null),
            h('h1', {}, portal.title),
            h('p', { class: 'hero__lead' }, `${state.ops.length.toLocaleString()} endpoints across ${state.groups.length} sections, generated from the code.`),
            h('div', { class: 'hero__actions' },
                login ? h('a', { class: 'btn btn--primary', href: link(portal, 'op', login.id) }, icon('key'), sessions.get(portal) ? 'Switch account' : 'Log in to try requests') : null,
                h('button', { class: 'btn', type: 'button', onClick: openPalette }, icon('search'), 'Find an endpoint'),
                h('a', { class: 'btn', href: portal.url, download: `${portal.slug}-openapi.json` }, icon('download'), 'OpenAPI JSON')));

        const sections = h('div', { class: 'intro-grid' }, state.intro.map((section) => h('section', {
            class: `prose intro-section${section.title === 'Overview' ? ' intro-section--lead' : ''}`,
            id: `intro-${section.slug}`,
            html: (section.title === 'Overview' ? '' : `<h2>${inline(section.title)}</h2>`) + markdown(section.body),
        })));

        const browse = h('section', { class: 'browse' },
            h('h2', {}, 'Browse the API'),
            h('div', { class: 'group-grid' }, state.groups.map((group) => h('div', { class: 'group-card' },
                h('h3', {}, h('span', {}, group.name), h('span', { class: 'count' }, group.count)),
                h('ul', {}, group.tags.map((tag) => h('li', {},
                    h('a', { href: link(portal, 'tag', tag.name) }, tag.label),
                    h('span', { class: 'count' }, tag.ops.length))))))));

        fill(ui.main, h('div', { class: 'page page--intro' }, hero, sections, browse));

        const target = sectionSlug && document.getElementById(`intro-${sectionSlug}`);
        if (target) {
            target.classList.add('is-focused');
            target.scrollIntoView();
        } else {
            ui.main.scrollTo(0, 0);
        }
        document.title = `${portal.title} · ${config.title} Docs`;
    }

    /**
     * Format the build time in the viewer's timezone, e.g. "27 Oct 2026, 20:05 GMT+5:30".
     */
    function formatBuildTime(iso) {
        return new Intl.DateTimeFormat(undefined, {
            day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short',
        }).format(new Date(iso));
    }

    /**
     * Create the group › folder › subfolder breadcrumb. A folder links to its page, unless it has
     * no endpoints of its own and so no page, or that page is the one being shown.
     */
    function breadcrumb(tag, currentTag = null) {
        const folders = [];
        for (let folder = tag.folder; folder.parent; folder = folder.parent) {
            folders.unshift(folder);
        }

        const crumb = (folder) => folder.tag && folder.tag !== currentTag
            ? h('a', { href: link(state.portal, 'tag', folder.tag.name) }, folder.name)
            : h('span', {}, folder.name);

        return h('nav', { class: 'breadcrumb', 'aria-label': 'Breadcrumb' },
            h('span', {}, tag.group.name),
            tag.label !== tag.group.name ? folders.map((folder) => [icon('chevron'), crumb(folder)]) : null);
    }

    /**
     * Render the endpoint list of a tag.
     */
    function renderTag(tag) {
        fill(ui.main, h('div', { class: 'page' },
            breadcrumb(tag, tag),
            h('h1', {}, tag.folder.name),
            tag.description ? h('div', { class: 'prose', html: markdown(tag.description) }) : null,
            h('div', { class: 'op-list' }, tag.ops.map((entry) => h('a', { class: 'op-row', href: link(state.portal, 'op', entry.id) },
                methodPill(entry.method),
                h('span', { class: 'op-row__text' },
                    h('span', { class: 'op-row__title' }, entry.op.summary || entry.path),
                    h('code', { class: 'op-row__path' }, entry.path)),
                securityFor(entry.op).kind === 'public' ? h('span', { class: 'chip' }, 'Public') : icon('lock'))))));

        ui.main.scrollTo(0, 0);
        document.title = `${tag.label} · ${state.portal.title} · ${config.title} Docs`;
    }

    /**
     * Render an endpoint page with its Try it console.
     */
    function renderOperation(entry) {
        const { op, method, path } = entry;
        const tag = state.tags.get(entry.tag);
        const security = securityFor(op);
        const params = (op.parameters || []).map(resolve);
        const body = requestBodyOf(op);

        const docs = h('div', { class: 'op__docs' },
            breadcrumb(tag),
            h('h1', { class: 'op__title' }, op.summary || path),
            h('div', { class: 'endpoint' },
                methodPill(method),
                h('code', { class: 'endpoint__path', html: escapeHtml(path).replace(/\{([^}]+)\}/g, '<span class="path-param">{$1}</span>') }),
                copyButton(state.server.url + path, 'Copy URL')),
            h('div', { class: 'op__badges' }, securityBadge(security), fileBadge(op)),
            op.description ? h('div', { class: 'prose', html: markdown(op.description) }) : null,
            paramsSection('Path parameters', params.filter((param) => param.in === 'path')),
            paramsSection('Query parameters', params.filter((param) => param.in === 'query')),
            paramsSection('Headers', params.filter((param) => param.in === 'header')),
            body ? h('section', { class: 'op__section' },
                h('h2', {}, 'Request body', h('span', { class: 'chip' }, body.contentType), body.required ? h('span', { class: 'chip chip--req' }, 'required') : null),
                schemaTree(body.schema) || h('p', { class: 'muted' }, 'Free-form body.')) : null,
            responsesSection(op.responses || {}));

        // The pager spans both columns: Previous under the docs, Next under the Try it panel.
        fill(ui.main, h('article', { class: 'op' },
            docs,
            h('aside', { class: 'op__console' }, consolePanel(entry, security, params, body)),
            pager(entry)));
        ui.main.scrollTo(0, 0);
        document.title = `${op.summary || path} · ${state.portal.title} · ${config.title} Docs`;
    }

    /**
     * Create the badge showing how an endpoint is authenticated.
     */
    function securityBadge(security) {
        if (security.kind === 'public') {
            return h('span', { class: 'chip chip--public' }, icon('unlock'), 'Public, no token needed');
        }
        if (security.kind === 'apiKey') {
            return h('span', { class: 'chip chip--auth' }, icon('key'), `Header ${security.scheme.name}`);
        }

        return h('span', { class: 'chip chip--auth' }, icon('lock'), `Bearer token · ${state.portal.title}`);
    }

    /**
     * Create the badge for endpoints that return a file.
     */
    function fileBadge(op) {
        const mime = fileResponseOf(op);
        if (!mime) {
            return null;
        }

        const [label, ext] = fileType(mime);

        return h('span', { class: 'chip chip--file' }, icon('download'), `Returns a file · ${label} (.${ext})`);
    }

    /**
     * Render a list of parameters.
     */
    function paramsSection(title, params) {
        if (!params.length) {
            return null;
        }

        return h('section', { class: 'op__section' },
            h('h2', {}, title),
            h('div', { class: 'props' }, params.map((param) =>
                propRow(param.name, { ...resolve(param.schema), description: param.description ?? resolve(param.schema).description }, param.required, 0))));
    }

    /**
     * Render the properties of a schema.
     */
    function schemaTree(schema, depth = 0) {
        schema = resolve(schema);
        const target = typesOf(schema).includes('array') && schema.items ? resolve(schema.items) : schema;
        const properties = Object.entries(target.properties || {});

        if (!properties.length || depth > 6) {
            return null;
        }

        const required = target.required || [];

        return h('div', { class: 'props' }, properties.map(([name, property]) => propRow(name, property, required.includes(name), depth)));
    }

    /**
     * Render one property, with its nested properties when it has any.
     */
    function propRow(name, property, required, depth) {
        property = resolve(property);
        const children = schemaTree(property, depth + 1);
        const constraints = [
            property.minimum !== undefined && `min ${property.minimum}`,
            property.maximum !== undefined && `max ${property.maximum}`,
            property.minLength !== undefined && `min length ${property.minLength}`,
            property.maxLength !== undefined && `max length ${property.maxLength}`,
            property.minItems !== undefined && `min items ${property.minItems}`,
            property.maxItems !== undefined && `max items ${property.maxItems}`,
            property.default !== undefined && `default ${JSON.stringify(property.default)}`,
        ].filter(Boolean);

        const head = h('div', { class: 'prop__head' },
            h('code', { class: 'prop__name' }, name),
            h('span', { class: 'prop__type' }, typeLabel(property)),
            required ? h('span', { class: 'chip chip--req' }, 'required') : null,
            typesOf(property).includes('null') ? h('span', { class: 'chip' }, 'nullable') : null);

        const meta = [
            property.description ? h('div', { class: 'prop__desc', html: inline(property.description) }) : null,
            property.enum?.length ? h('div', { class: 'prop__enum' }, h('span', { class: 'muted' }, 'One of'), property.enum.map((value) => h('code', {}, String(value)))) : null,
            constraints.length ? h('div', { class: 'prop__constraints' }, constraints.map((text) => h('span', {}, text))) : null,
        ];

        if (!children) {
            return h('div', { class: 'prop' }, head, meta);
        }

        return h('details', { class: 'prop prop--nested' },
            h('summary', {}, head, h('span', { class: 'prop__toggle' }, icon('chevron'), `${children.childElementCount} fields`)),
            meta,
            h('div', { class: 'prop__children' }, children));
    }

    /**
     * Render the possible responses of an endpoint.
     */
    function responsesSection(responses) {
        const codes = Object.keys(responses).sort();
        if (!codes.length) {
            return null;
        }

        return h('section', { class: 'op__section' },
            h('h2', {}, 'Responses'),
            h('div', { class: 'responses' }, codes.map((code, index) => {
                const response = resolve(responses[code]);
                const media = response.content?.['application/json'];
                const schema = media ? resolve(media.schema) : null;
                const example = media?.example ?? (schema ? exampleFor(schema) : undefined);
                const fileMime = Object.keys(response.content || {}).find((type) => type !== 'application/json');
                const fileNote = fileMime
                    ? h('p', { class: 'muted' }, `The body is ${/^[AEIOU]/.test(fileType(fileMime)[0]) ? 'an' : 'a'} ${fileType(fileMime)[0]} file (${fileMime}). Use "Send and download" in the Try it panel to save it.`)
                    : null;
                const tone = code.startsWith('2') ? 'ok' : code.startsWith('5') ? 'error' : 'warn';

                return h('details', { class: 'response', open: index === 0 },
                    h('summary', {},
                        h('span', { class: `status status--${tone}` }, code),
                        h('span', { class: 'response__desc' }, response.description || STATUS_TEXT[code] || ''),
                        icon('chevron')),
                    h('div', { class: 'response__body' },
                        fileNote,
                        schema ? schemaTree(schema) : null,
                        example !== undefined ? jsonBlock(example) : null));
            })));
    }

    /**
     * Create the Previous / Next links, in sidebar order.
     */
    function pager(entry) {
        const index = state.sequence.indexOf(entry);
        const previous = state.sequence[index - 1];
        const next = state.sequence[index + 1];
        /**
         * Create a Previous or Next card.
         */
        const card = (target, direction) => target ? h('a', { class: `pager__item pager__item--${direction}`, href: link(state.portal, 'op', target.id), rel: direction },
            h('span', { class: 'pager__arrow', 'aria-hidden': 'true' }, icon(direction === 'prev' ? 'arrowLeft' : 'arrowRight')),
            h('span', { class: 'pager__text' },
                h('span', { class: 'pager__label' }, direction === 'prev' ? 'Previous' : 'Next'),
                h('span', { class: 'pager__title' }, methodPill(target.method), h('span', { class: 'pager__summary' }, target.op.summary || target.path)),
                h('code', { class: 'pager__path' }, target.path))) : h('span', { class: `pager__item pager__item--${direction} pager__item--empty`, 'aria-hidden': 'true' });

        return h('nav', { class: 'pager', 'aria-label': 'Endpoint navigation' }, card(previous, 'prev'), card(next, 'next'));
    }

    /**
     * Build the Try it console of an endpoint.
     */
    function consolePanel(entry, security, params, body) {
        const portal = state.portal;
        const draftKey = `api-docs.draft.${portal.slug}.${entry.id}`;
        const draft = drafts.get(draftKey) || {};
        const inputs = [];
        let apiKeyInput = null;
        let bodyInput = null;
        const formInputs = [];

        /**
         * Remember the inputs of this endpoint for the session.
         */
        const saveDraft = debounce(() => {
            const values = Object.fromEntries(inputs.map(({ param, input }) => [`${param.in}:${param.name}`, input.value]));
            formInputs.filter(({ file }) => !file).forEach(({ name, input }) => (values[`form:${name}`] = input.value));
            if (bodyInput) {
                values.body = bodyInput.value;
            }
            drafts.set(draftKey, values);
        }, 300);

        /**
         * Save the draft and refresh the code samples.
         */
        const onChange = () => {
            saveDraft();
            refreshSamples();
        };

        /**
         * Create the input for a parameter.
         */
        const field = (param) => {
            const schema = resolve(param.schema);
            const placeholder = schema.default !== undefined ? `default: ${schema.default}` : schema.enum ? schema.enum.join(' | ') : typeLabel(schema);
            const input = h('input', {
                class: 'input', type: 'text', spellcheck: 'false', autocomplete: 'off', placeholder,
                value: draft[`${param.in}:${param.name}`] ?? '', 'aria-label': param.name, onInput: onChange,
            });
            inputs.push({ param, input });

            return h('label', { class: 'field' },
                h('span', { class: 'field__name' }, param.name, param.required ? h('span', { class: 'req', 'aria-label': 'required' }, '*') : null),
                input);
        };

        /**
         * Create a titled group of parameter inputs.
         */
        const groupOf = (title, list) => list.length ? h('div', { class: 'console__group' }, h('div', { class: 'console__label' }, title), list.map(field)) : null;

        let bodyGroup = null;
        if (body && body.multipart) {
            bodyGroup = h('div', { class: 'console__group' }, h('div', { class: 'console__label' }, 'Form data'),
                Object.entries(body.schema.properties || {}).map(([name, property]) => {
                    property = resolve(property);
                    const file = property.format === 'binary';
                    const input = file
                        ? h('input', { class: 'input input--file', type: 'file', 'aria-label': name, onChange })
                        : h('input', { class: 'input', type: 'text', spellcheck: 'false', value: draft[`form:${name}`] ?? '', placeholder: typeLabel(property), 'aria-label': name, onInput: onChange });
                    formInputs.push({ name, input, file });

                    return h('label', { class: 'field' },
                        h('span', { class: 'field__name' }, name, (body.schema.required || []).includes(name) ? h('span', { class: 'req' }, '*') : null),
                        input);
                }));
        } else if (body) {
            bodyInput = h('textarea', {
                class: 'input code-input', spellcheck: 'false', rows: '10', 'aria-label': 'Request body (JSON)',
                value: draft.body ?? JSON.stringify(exampleFor(body.schema, ''), null, 2), onInput: onChange,
            });
            bodyGroup = h('div', { class: 'console__group' },
                h('div', { class: 'console__label' }, 'Body', h('span', { class: 'muted' }, 'JSON'),
                    h('button', { class: 'link-btn', type: 'button', onClick: () => { bodyInput.value = JSON.stringify(exampleFor(body.schema, ''), null, 2); onChange(); } }, 'Reset')),
                bodyInput);
        }

        if (security.kind === 'apiKey') {
            apiKeyInput = h('input', { class: 'input', type: 'password', autocomplete: 'off', placeholder: 'Value', 'aria-label': security.scheme.name, onInput: onChange });
        }

        /**
         * Build the request from the inputs; "error" describes anything missing.
         */
        function buildRequest() {
            let error = null;
            const url = entry.path.replace(/\{([^}]+)\}/g, (match, name) => {
                const value = inputs.find(({ param }) => param.in === 'path' && param.name === name)?.input.value.trim();
                if (!value) {
                    error ??= `Fill in the "${name}" path parameter.`;

                    return match;
                }

                return encodeURIComponent(value);
            });

            const query = new URLSearchParams();
            inputs.filter(({ param }) => param.in === 'query').forEach(({ param, input }) => input.value.trim() !== '' && query.append(param.name, input.value.trim()));

            const headers = { Accept: 'application/json' };
            inputs.filter(({ param }) => param.in === 'header').forEach(({ param, input }) => input.value && (headers[param.name] = input.value));
            if (apiKeyInput?.value) {
                headers[security.scheme.name] = apiKeyInput.value;
            }

            let method = entry.method.toUpperCase();
            let payload;
            let formEntries = null;

            if (bodyInput && bodyInput.value.trim()) {
                payload = safeJson(bodyInput.value);
                if (payload === undefined) {
                    error ??= 'The body is not valid JSON.';
                }
                headers['Content-Type'] = 'application/json';
            } else if (body?.multipart) {
                // PHP only parses multipart bodies on POST, so Laravel's method spoofing is used.
                formEntries = formInputs
                    .map(({ name, input, file }) => file ? [name, input.files[0], true] : [name, input.value, false])
                    .filter(([, value]) => value !== undefined && value !== '');
                if (method !== 'POST') {
                    formEntries.push(['_method', method, false]);
                    method = 'POST';
                }
            }

            const path = url + (query.toString() ? `?${query}` : '');

            return { method, path, url: state.server.url + path, headers, payload, formEntries, error };
        }

        // Auth status line
        const authRow = h('div', { class: 'console__auth' });
        authRow.refresh = () => {
            const session = sessions.get(portal);
            const login = loginOperation(portal);

            if (security.kind === 'public') {
                fill(authRow, icon('unlock'), h('span', {}, 'No token needed'));
            } else if (security.kind === 'apiKey') {
                fill(authRow, h('label', { class: 'field' }, h('span', { class: 'field__name' }, security.scheme.name), apiKeyInput));
            } else if (session) {
                fill(authRow, h('span', { class: 'dot', 'aria-hidden': 'true' }),
                    h('span', {}, 'Sending token for ', h('strong', {}, session.name)),
                    h('button', { class: 'link-btn', type: 'button', onClick: () => signOut(portal) }, 'Sign out'));
            } else {
                fill(authRow, icon('lock'), h('span', {}, 'Not signed in. '),
                    login && login !== entry ? h('a', { href: link(portal, 'op', login.id) }, 'Log in') : null,
                    login && login !== entry ? ' or ' : null,
                    h('button', { class: 'link-btn', type: 'button', onClick: openAuthorize }, 'paste a token'));
            }
            authRow.classList.toggle('is-signed-in', Boolean(session) && security.kind === 'bearer');
        };
        authRow.refresh();
        ui.consoleAuth = authRow;

        // Response
        const result = h('div', { class: 'console__result', 'aria-live': 'polite' },
            h('div', { class: 'console__placeholder' }, 'Send the request to see the response here.'));

        // Endpoints that return a file default to "Send and download", like Postman's option of the same name.
        const returnsFile = Boolean(fileResponseOf(entry.op));
        const sendButton = h('button', { class: 'btn btn--primary btn--send', type: 'button' });
        const altButton = h('button', {
            class: 'btn btn--alt', type: 'button',
            title: returnsFile ? 'Send and show the response without saving it' : 'Send and save the response to a file',
        });
        const cancelButton = h('button', { class: 'btn btn--cancel', type: 'button', hidden: true }, icon('close'), 'Cancel');
        const sendHint = h('kbd', { class: 'console__hint', title: 'Send with the keyboard' }, isMac ? '⌘ ↵' : 'Ctrl ↵');
        let inFlight = null;

        /**
         * Restore the send buttons' labels.
         */
        const labelButtons = () => {
            fill(sendButton, icon(returnsFile ? 'download' : 'play'), returnsFile ? 'Send and download' : 'Send');
            fill(altButton, icon(returnsFile ? 'play' : 'download'), returnsFile ? 'Send' : 'Send and download');
        };
        labelButtons();

        /**
         * Show the sending state; returns a function that ends it.
         */
        function showSending(started) {
            const timer = h('span', { class: 'send-timer' }, '0.0s');
            const waitingTimer = h('span', { class: 'send-timer' }, '0.0s');

            sendButton.classList.add('is-sending');
            sendButton.setAttribute('aria-busy', 'true');
            fill(sendButton, h('span', { class: 'send-progress', 'aria-hidden': 'true' }), h('span', {}, 'Sending'), timer);
            altButton.hidden = true;
            cancelButton.hidden = false;
            fill(result, h('div', { class: 'console__waiting' },
                h('span', { class: 'waiting-dots', 'aria-hidden': 'true' }, h('span'), h('span'), h('span')),
                h('span', {}, `Waiting for ${state.server.name}`), waitingTimer));

            const tick = setInterval(() => {
                const seconds = `${((performance.now() - started) / 1000).toFixed(1)}s`;
                timer.textContent = waitingTimer.textContent = seconds;
            }, 100);

            return () => {
                clearInterval(tick);
                sendButton.classList.remove('is-sending');
                sendButton.removeAttribute('aria-busy');
                altButton.hidden = false;
                cancelButton.hidden = true;
                labelButtons();
            };
        }

        /**
         * Send the request, optionally saving the response to a file.
         */
        async function send({ save = returnsFile } = {}) {
            if (inFlight) {
                return;
            }

            const request = buildRequest();
            if (request.error) {
                fill(result, h('div', { class: 'notice notice--warn' }, request.error));

                return;
            }

            let bodyPayload;
            if (request.formEntries) {
                bodyPayload = new FormData();
                request.formEntries.forEach(([name, value]) => bodyPayload.append(name, value));
            } else if (request.payload !== undefined) {
                bodyPayload = JSON.stringify(request.payload);
            }

            const started = performance.now();
            inFlight = new AbortController();
            const done = showSending(started);

            try {
                const outcome = await execute({
                    method: request.method, path: request.path, headers: request.headers, body: bodyPayload, signal: inFlight.signal,
                });
                renderResult(result, outcome, entry);
                if (save && outcome.response.ok) {
                    saveResponse(outcome, entry);
                } else if (save) {
                    toast(`Nothing saved: the request returned ${outcome.response.status}. Use "Save response" if you still want it.`, 'warning');
                }
            } catch (error) {
                const cancelled = error.name === 'AbortError';
                fill(result, h('div', { class: `notice notice--${cancelled ? 'warn' : 'error'}` },
                    cancelled ? `Request cancelled after ${((performance.now() - started) / 1000).toFixed(1)}s.` : error.message));
            } finally {
                inFlight = null;
                done();
            }
        }

        sendButton.addEventListener('click', () => send());
        altButton.addEventListener('click', () => send({ save: !returnsFile }));
        cancelButton.addEventListener('click', () => inFlight?.abort());

        const paramGroups = [
            groupOf('Path', params.filter((param) => param.in === 'path')),
            groupOf('Query', params.filter((param) => param.in === 'query')),
            groupOf('Headers', params.filter((param) => param.in === 'header')),
        ];

        const sampleFile = returnsFile ? `${slugify(entry.op.summary || entry.path)}.${fileType(fileResponseOf(entry.op))[1]}` : null;
        const samples = tabs([
            { label: 'cURL', render: () => codeBlock(curlSample(buildRequest(), security, sampleFile), 'bash') },
            { label: 'JavaScript', render: () => codeBlock(fetchSample(buildRequest(), security, sampleFile), 'js') },
            { label: 'PHP', render: () => codeBlock(phpSample(buildRequest(), security, sampleFile), 'php') },
        ], 'api-docs.sample');
        const refreshSamples = debounce(samples.refresh, 200);

        const expandButton = h('button', { class: 'icon-btn icon-btn--sm', type: 'button', onClick: () => setExpanded(!panel.classList.contains('is-expanded')) });

        const samplesCard = h('div', { class: 'console__card console__card--samples' }, h('div', { class: 'console__label' }, 'Code'), samples.el);
        const output = h('div', { class: 'console__output' }, h('div', { class: 'console__label console__output-label' }, 'Response'), result);

        const panel = h('div', { class: 'console', 'aria-label': `Try ${entry.op.summary || entry.path}` },
            h('div', { class: 'console__card console__card--main' },
                h('div', { class: 'console__head' },
                    h('div', { class: 'console__title' }, 'Try it',
                        h('span', { class: 'console__tools' },
                            h('button', { class: 'chip chip--server', type: 'button', title: `Requests go to ${state.server.url}`, onClick: () => toggleServerMenu(true) },
                                h('span', { class: `env-dot env-dot--${envTone(state.server)}`, 'aria-hidden': 'true' }), state.server.name),
                            expandButton)),
                    h('span', { class: 'console__url' }, methodPill(entry.method), h('code', {}, entry.path))),
                h('div', { class: 'console__body' },
                    h('div', { class: 'console__inputs' },
                        authRow,
                        paramGroups,
                        bodyGroup,
                        h('div', { class: 'console__actions' }, sendButton, altButton, cancelButton, sendHint)),
                    output)),
            samplesCard);

        /**
         * Leave the larger view on Escape.
         */
        const onEscape = (event) => event.key === 'Escape' && !document.querySelector('.overlay') && ui.serverMenu.hidden && setExpanded(false);

        /**
         * Switch between the normal and the larger view.
         */
        function setExpanded(expanded) {
            panel.classList.toggle('is-expanded', expanded);
            document.body.classList.toggle('console-expanded', expanded);
            expandButton.setAttribute('aria-label', expanded ? 'Exit larger view' : 'Open larger view');
            expandButton.setAttribute('aria-pressed', String(expanded));
            expandButton.title = expanded ? 'Exit larger view (Esc)' : 'Open larger view';
            fill(expandButton, icon(expanded ? 'collapse' : 'expand'));
            // The code samples sit under the response in the larger view, and below the console otherwise.
            (expanded ? output : panel).append(samplesCard);
            document[expanded ? 'addEventListener' : 'removeEventListener']('keydown', onEscape);
            storage.set('api-docs.console-expanded', expanded);
        }

        setExpanded(Boolean(storage.get('api-docs.console-expanded')));

        // Called before the next page renders: tidy up without forgetting the user's preference.
        ui.disposeConsole = () => {
            document.body.classList.remove('console-expanded');
            document.removeEventListener('keydown', onEscape);
        };

        panel.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                send();
            }
        });

        return panel;
    }

    /**
     * Show a response: status, timing, body and headers.
     */
    function renderResult(container, outcome, entry) {
        const { response, blob, text, elapsed } = outcome;
        const tone = response.ok ? 'ok' : response.status >= 500 ? 'error' : 'warn';
        const type = response.headers.get('content-type') || '';
        const json = text !== null ? safeJson(text) : undefined;
        const previewable = /^(application\/pdf|image\/)/.test(type);

        let bodyView;
        if (json !== undefined) {
            bodyView = jsonBlock(json);
        } else if (text !== null) {
            bodyView = text ? codeBlock(text.slice(0, 200000), 'text') : h('p', { class: 'muted' }, 'Empty response body.');
        } else {
            const [label, ext] = fileType(type);
            bodyView = h('div', { class: 'file-card' },
                h('span', { class: `file-card__icon file-card__icon--${ext}` }, ext.toUpperCase()),
                h('span', { class: 'file-card__text' },
                    h('strong', {}, responseFilename(outcome, entry)),
                    h('span', { class: 'muted' }, `${label} file · ${formatBytes(blob.size)}`)),
                h('div', { class: 'file-card__actions' },
                    previewable ? h('button', { class: 'btn', type: 'button', onClick: () => openResponse(outcome) }, 'Open') : null,
                    h('button', { class: 'btn btn--primary', type: 'button', onClick: () => saveResponse(outcome, entry) }, icon('download'), 'Save')));
        }

        const headerList = h('dl', { class: 'header-list' }, [...response.headers].map(([name, value]) => [h('dt', {}, name), h('dd', {}, value)]));
        const view = tabs([
            { label: 'Body', render: () => bodyView },
            { label: `Headers (${[...response.headers].length})`, render: () => headerList },
        ]);

        fill(container,
            h('div', { class: 'console__meta' },
                h('span', { class: `status status--${tone}` }, `${response.status} ${response.statusText || STATUS_TEXT[response.status] || ''}`),
                h('span', { class: 'muted' }, `${elapsed} ms`),
                h('span', { class: 'muted' }, formatBytes(blob.size)),
                h('button', { class: 'link-btn console__save', type: 'button', title: 'Save response to file', onClick: () => saveResponse(outcome, entry) },
                    icon('download'), 'Save response')),
            view.el);
    }

    const FILE_TYPES = {
        'application/pdf': ['PDF', 'pdf'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['Excel', 'xlsx'],
        'application/vnd.ms-excel': ['Excel', 'xls'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document': ['Word', 'docx'],
        'text/csv': ['CSV', 'csv'],
        'application/zip': ['ZIP', 'zip'],
        'application/json': ['JSON', 'json'],
        'text/html': ['HTML', 'html'],
        'text/plain': ['Text', 'txt'],
        'image/png': ['PNG', 'png'],
        'image/jpeg': ['JPEG', 'jpg'],
    };

    /**
     * Get the label and file extension of a media type.
     */
    const fileType = (mime = '') => FILE_TYPES[mime.split(';')[0].trim().toLowerCase()] || ['Binary', 'bin'];

    /**
     * Get the media type of the file an endpoint returns, if any.
     */
    function fileResponseOf(op) {
        const content = resolve(op.responses?.['200']).content || {};

        return Object.keys(content).find((type) => type !== 'application/json') || null;
    }

    /**
     * Get the file name for a saved response: the server's, or "<endpoint>-<time>.<ext>".
     */
    function responseFilename({ response }, entry) {
        const disposition = response.headers.get('content-disposition') || '';
        const encoded = /filename\*\s*=\s*[\w-]+''([^;]+)/i.exec(disposition)?.[1];
        let name = encoded ? decodeURIComponent(encoded.trim().replace(/^"|"$/g, '')) : /filename\s*=\s*"?([^";]+)"?/i.exec(disposition)?.[1]?.trim();

        if (!name) {
            const stamp = new Date().toISOString().slice(0, 19).replace('T', '_').replace(/:/g, '-');
            name = `${slugify(entry.op.summary || entry.path) || 'response'}-${stamp}.${fileType(response.headers.get('content-type') || '')[1]}`;
        }

        return name.replace(/[\\/:*?"<>|]+/g, '-');
    }

    /**
     * Save a response to a file.
     */
    function saveResponse(outcome, entry) {
        const url = URL.createObjectURL(outcome.blob);
        const filename = responseFilename(outcome, entry);
        const anchor = h('a', { href: url, download: filename, hidden: true });

        document.body.append(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
        toast(`Saved ${filename}`, 'success');
    }

    /**
     * Open a response (PDF or image) in a new tab.
     */
    function openResponse({ blob }) {
        const url = URL.createObjectURL(blob);
        window.open(url, '_blank', 'noopener');
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    }

    /**
     * Quote a value for a shell command.
     */
    const shellQuote = (value) => `'${String(value).replace(/'/g, `'\\''`)}'`;
    /**
     * Indent every line after the first.
     */
    const indent = (text, spaces) => text.split('\n').map((line, i) => (i ? ' '.repeat(spaces) : '') + line).join('\n');

    /**
     * Build the cURL sample.
     */
    function curlSample(request, security, file = null) {
        const lines = [`curl -X ${request.method} ${shellQuote(request.url)}`];

        Object.entries(request.headers).forEach(([name, value]) => lines.push(`-H ${shellQuote(`${name}: ${value}`)}`));
        if (security.kind === 'bearer') {
            lines.push('-H "Authorization: Bearer $TOKEN"');
        }
        if (request.formEntries) {
            request.formEntries.forEach(([name, value, file]) => lines.push(`-F ${shellQuote(file ? `${name}=@${value?.name || 'file'}` : `${name}=${value}`)}`));
        } else if (request.payload !== undefined) {
            lines.push(`--data ${shellQuote(JSON.stringify(request.payload, null, 2))}`);
        }
        if (file) {
            lines.push(`-o ${shellQuote(file)}`);
        }

        return lines.join(' \\\n  ');
    }

    /**
     * Build the JavaScript fetch sample.
     */
    function fetchSample(request, security, file = null) {
        const headers = Object.entries(request.headers).map(([name, value]) => `    ${/^[A-Za-z_$][\w$]*$/.test(name) ? name : `'${name}'`}: ${JSON.stringify(value)},`);
        if (security.kind === 'bearer') {
            headers.push('    Authorization: `Bearer ${token}`,');
        }

        let prefix = '';
        let body = '';
        if (request.formEntries) {
            prefix = `const form = new FormData();\n${request.formEntries.map(([name, value, file]) =>
                `form.append(${JSON.stringify(name)}, ${file ? 'fileInput.files[0]' : JSON.stringify(value)});`).join('\n')}\n\n`;
            body = '  body: form,\n';
        } else if (request.payload !== undefined) {
            body = `  body: JSON.stringify(${indent(JSON.stringify(request.payload, null, 2), 2)}),\n`;
        }

        return `${prefix}const response = await fetch(${JSON.stringify(request.url)}, {\n  method: '${request.method}',\n  headers: {\n${headers.join('\n')}\n  },\n${body}});\n\n${file
            ? `// Save the file in the browser\nconst url = URL.createObjectURL(await response.blob());\nObject.assign(document.createElement('a'), { href: url, download: '${file}' }).click();`
            : 'const data = await response.json();'}`;
    }

    /**
     * Format a value as a PHP literal.
     */
    function phpValue(value, depth = 1) {
        const pad = '    '.repeat(depth);

        if (value === null || value === undefined) {
            return 'null';
        }
        if (typeof value === 'boolean') {
            return value ? 'true' : 'false';
        }
        if (typeof value === 'number') {
            return String(value);
        }
        if (typeof value === 'string') {
            return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
        }

        const entries = Array.isArray(value) ? value.map((item) => [null, item]) : Object.entries(value);
        if (!entries.length) {
            return '[]';
        }

        const items = entries.map(([key, item]) => `${pad}    ${key === null ? '' : `${phpValue(key)} => `}${phpValue(item, depth + 1)},`);

        return `[\n${items.join('\n')}\n${pad}]`;
    }

    /**
     * Build the Laravel HTTP client sample.
     */
    function phpSample(request, security, file = null) {
        const chain = ['Http::acceptJson()'];
        if (security.kind === 'bearer') {
            chain.push('->withToken($token)');
        }

        const extraHeaders = Object.fromEntries(Object.entries(request.headers).filter(([name]) => !['Accept', 'Content-Type'].includes(name)));
        if (Object.keys(extraHeaders).length) {
            chain.push(`->withHeaders(${phpValue(extraHeaders, 1)})`);
        }

        let data = request.payload;
        if (request.formEntries) {
            request.formEntries.filter(([, , file]) => file).forEach(([name, value]) =>
                chain.push(`->attach('${name}', fopen('/path/to/${value?.name || 'file'}', 'r'))`));
            data = Object.fromEntries(request.formEntries.filter(([, , file]) => !file).map(([name, value]) => [name, value]));
        }

        const method = request.method.toLowerCase();
        const args = [`'${request.url}'`];
        if (data !== undefined && method !== 'get') {
            args.push(phpValue(data, 1));
        }
        if (file) {
            chain.push(`->sink(storage_path('app/${file}'))`);
        }
        chain.push(`->${method}(${args.join(', ')})`);

        return `use Illuminate\\Support\\Facades\\Http;\n\n$response = ${chain.join('\n    ')};${file ? '' : '\n\n$data = $response->json();'}`;
    }

    /**
     * Open the endpoint search (Ctrl K).
     */
    function openPalette() {
        if (!state.spec) {
            return;
        }

        const input = h('input', { class: 'palette__input', type: 'search', autocomplete: 'off', spellcheck: 'false', placeholder: `Search the ${state.portal.title} by name, path or section`, 'aria-label': 'Search endpoints' });
        const list = h('ul', { class: 'palette__list', role: 'listbox' });
        let results = [];
        let cursor = 0;

        /**
         * Open the selected search result.
         */
        const go = (entry) => {
            dialog.close();
            window.location.hash = link(state.portal, 'op', entry.id);
        };

        /**
         * Render the search results.
         */
        const draw = () => {
            fill(list, results.length
                ? results.map((entry, index) => h('li', { class: 'palette__item', role: 'option', 'aria-selected': String(index === cursor), onClick: () => go(entry) },
                    methodPill(entry.method),
                    h('span', { class: 'palette__text' },
                        h('span', { class: 'palette__title' }, entry.op.summary || entry.path),
                        h('code', { class: 'palette__path' }, entry.path)),
                    h('span', { class: 'palette__tag' }, state.tags.get(entry.tag)?.label || entry.tag)))
                : h('li', { class: 'palette__empty' }, 'No endpoints match.'));
            list.children[cursor]?.scrollIntoView({ block: 'nearest' });
        };

        /**
         * Search the endpoints for the typed text.
         */
        const run = () => {
            const terms = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
            results = (terms.length ? state.ops.filter((entry) => terms.every((term) => entry.haystack.includes(term))) : state.ops).slice(0, 60);
            cursor = 0;
            draw();
        };

        input.addEventListener('input', debounce(run, 60));
        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                cursor = (cursor + (event.key === 'ArrowDown' ? 1 : -1) + results.length) % Math.max(results.length, 1);
                draw();
            } else if (event.key === 'Enter' && results[cursor]) {
                go(results[cursor]);
            }
        });

        const dialog = modal(h('div', { class: 'palette' },
            h('div', { class: 'palette__bar' }, icon('search'), input, h('kbd', {}, 'Esc')),
            list,
            h('div', { class: 'palette__foot' }, h('span', {}, h('kbd', {}, '↑'), h('kbd', {}, '↓'), ' to move'), h('span', {}, h('kbd', {}, '↵'), ' to open'))), { label: 'Search endpoints' });
        dialog.dialog.classList.add('dialog--palette');
        run();
        input.focus();
    }

    /**
     * Open the authentication dialog.
     */
    function openAuthorize() {
        const listEl = h('div', { class: 'auth-list' });

        listEl.refresh = () => fill(listEl, config.portals.map((portal) => {
            const session = sessions.get(portal);
            const login = loginOperation(portal);

            if (session) {
                return h('div', { class: 'auth-card is-signed-in' },
                    h('div', { class: 'auth-card__head' }, h('span', { class: 'dot', 'aria-hidden': 'true' }), h('strong', {}, portal.title)),
                    h('p', { class: 'muted' }, `Signed in as ${session.name}. The token is attached to every ${portal.title} request.`),
                    h('div', { class: 'auth-card__actions' },
                        h('button', { class: 'btn', type: 'button', onClick: () => signOut(portal) }, icon('logout'), 'Sign out')));
            }

            const tokenInput = h('input', { class: 'input', type: 'password', autocomplete: 'off', placeholder: 'Paste a bearer token', 'aria-label': `${portal.title} token` });
            /**
             * Save the pasted token for the portal.
             */
            const save = () => {
                const token = tokenInput.value.trim().replace(/^Bearer\s+/i, '');
                if (token) {
                    sessions.set(portal, { token, name: 'Pasted token' });
                    toast(`Token saved for the ${portal.title}.`, 'success');
                    renderSessionState();
                }
            };
            tokenInput.addEventListener('keydown', (event) => event.key === 'Enter' && save());

            return h('div', { class: 'auth-card' },
                h('div', { class: 'auth-card__head' }, icon('lock'), h('strong', {}, portal.title)),
                h('p', { class: 'muted' }, 'Run the Login endpoint and the token is picked up automatically, or paste one.'),
                h('div', { class: 'auth-card__actions' },
                    tokenInput,
                    h('button', { class: 'btn', type: 'button', onClick: save }, 'Save'),
                    login ? h('a', { class: 'btn btn--primary', href: link(portal, 'op', login.id), onClick: () => dialog.close() }, 'Go to Login') : null));
        }));

        listEl.refresh();
        ui.authDialog = listEl;

        const dialog = modal(h('div', { class: 'auth-dialog' },
            h('div', { class: 'dialog__head' },
                h('div', {}, h('h2', {}, 'Authentication'), h('p', { class: 'muted dialog__sub' }, `Tokens for ${state.server.name} · ${state.server.url}`)),
                h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Close', onClick: () => dialog.close() }, icon('close'))),
            listEl), { label: 'Authentication', onClose: () => (ui.authDialog = null) });
    }

    /**
     * Read the portal, page type and id from the URL hash.
     */
    function parseHash() {
        const [slug = '', kind = '', ...rest] = window.location.hash.replace(/^#\/?/, '').split('/');

        return { slug: decodeURIComponent(slug), kind, id: decodeURIComponent(rest.join('/')) };
    }

    /**
     * Render the page for the current URL hash.
     */
    async function route() {
        const token = ++routeToken;
        const target = parseHash();
        const portal = portalBySlug(target.slug) || portalBySlug(storage.get('api-docs.portal')) || config.portals[0];

        if (state.portal !== portal || !state.spec) {
            Object.assign(state, { portal, spec: null });
            storage.set('api-docs.portal', portal.slug);
            renderSwitcher();
            renderAuthButton();
            showLoading(portal);

            try {
                const spec = await loadSpec(portal);
                if (token !== routeToken) {
                    return;
                }
                Object.assign(state, { spec }, index(spec));
                renderSidebar();
            } catch (error) {
                if (token === routeToken) {
                    delete specs[portal.slug];
                    showError(error);
                }

                return;
            }
        }

        document.body.classList.remove('nav-open');
        ui.disposeConsole?.();
        ui.disposeConsole = null;

        if (target.kind === 'op' && state.byId.has(target.id)) {
            renderOperation(state.byId.get(target.id));
        } else if (target.kind === 'tag' && state.tags.has(target.id)) {
            renderTag(state.tags.get(target.id));
        } else {
            renderIntro(target.kind === 'intro' ? target.id : null);
        }

        markActive(target);
    }

    state.server = initialServer();
    buildShell();
    renderServerButton();
    applyTheme();
    prefersDark.addEventListener('change', applyTheme);
    window.addEventListener('hashchange', route);
    document.addEventListener('keydown', (event) => {
        const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName) || event.target.isContentEditable;
        if ((event.key === 'k' && (event.metaKey || event.ctrlKey)) || (event.key === '/' && !typing)) {
            event.preventDefault();
            if (!document.querySelector('.dialog--palette')) {
                openPalette();
            }
        }
    });
    window.addEventListener('storage', (event) => event.key?.startsWith('api-docs.session.') && renderSessionState());

    route();
    watchForRebuilds();
})();
