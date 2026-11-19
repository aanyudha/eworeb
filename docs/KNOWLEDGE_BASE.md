# EWO Knowledge Center

The root of EWO is now public. `/eworeb/`, `/eworeb/kb`, and `/eworeb/kb/{slug}` use a React entry built by Vite. The existing staff login, `/eworeb/admin`, and `/eworeb/workspace` remain separate. The public pages show the latest published public guides as featured solutions, with the four initial categories. An empty catalog displays an empty state.

## Local build

From the project root:

```powershell
npm.cmd --prefix frontend ci
npm.cmd --prefix frontend run lint
npm.cmd --prefix frontend run build
```

CI4 reads two Vite entry points from the manifest in `public/workspace-assets`. Apache should expose only `public/`. Links and requests work under the configured `/eworeb` mount. `localhost` and `127.0.0.1` are allowed host aliases for local development. The Knowledge Base responses do not include the debug toolbar. Developer toolbar collection and access are restricted to loopback. [Vite backend integration](https://vite.dev/guide/backend-integration)

## Public API

- `GET /api/v1/kb/categories`
- `GET /api/v1/kb/articles?q=...&category=...&page=1&limit=12`
- `GET /api/v1/kb/articles/{slug}`

Search matches title, summary and keywords, with a 120-character query limit, page up to 1000, and 24 items maximum per page. Only public, published articles count or appear in search, article details and related links. Invalid filters return 422. Missing articles, drafts and internal articles return 404 on detail routes. The front end renders article content as plain text.

## Staff Knowledge Base

The workspace menu is visible to the authenticated `admin` role. Server routes under `/api/v1/admin/kb` require the existing session, `admin_panel`, and the existing admin role key. No database permission is added. The editor supports drafts, review, publishing, and withdrawal. New drafts default to internal visibility. Publishing requires at least one resolution step. To revise a published article, withdraw it first. Editing and transitions submit `updated_at` to detect concurrent changes.

The admin REST routes are GET/POST `articles`, GET/PUT `articles/{id}`, and POST `articles/{id}/publish` and `articles/{id}/withdraw`. Admin JSON writes use the existing CI4 CSRF cookie and token header. [CI4 Security](https://codeigniter4.github.io/userguide/libraries/security.html)

## Storage and publication

Articles are stored in `writable/knowledgebase/articles.json`. This is separate from Work Orders and is outside the document root. Only server-generated filenames are written. Writers lock a shared lock file, validate the document and replace it atomically from a temporary file in the same directory. The ID, state, and timestamps are server-managed. The repository validates slug, text, cardinality, categories, visibility and related slugs. No example content is published in live storage.

Run `tests/integration/auth-http.cjs` with `EWO_TEST_BROWSER` set to a Chrome executable. The runner stores articles and SQLite fixture data in a private temporary directory. Set `EWO_TEST_SUBFOLDER=1` to verify paths under `/eworeb`.

For the proposed MySQL schema and JSON transition, see [Knowledge Base MySQL design](KNOWLEDGE_BASE_MYSQL.md). The running application uses articles.json.
