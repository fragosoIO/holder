# Task page project preview

## Purpose

On a task page, the same screen shows the website the agent is writing. The person can comment, answer questions, and watch the run while the page is visible. The preview serves files Holder already has on disk. It does not start a dev server.

## Locked decisions

- A GitHub task previews `{dataDir}/worktrees/{taskId}` while that directory exists. Holder already removes it, best effort, when the task becomes `done` or `cancelled`. The preview does not create or delete that directory.
- Any other task previews the company workspace, `{dataDir}/workspaces/{companyId}`. A folder project and a task with no project both run there, so they share one site.
- The site root is the first directory that contains `index.html`: the preview directory, then `site/`, then `public/`, then `dist/`.
- The iframe is sandboxed without the parent origin. A page in the preview cannot use the Holder session.
- The file URL carries an HMAC token. The preview page loads CSS, JavaScript, and images with that token, and without the session cookie.
- The task page polls every 2 seconds and reloads the iframe when the served files change. Run text does not reload the preview.
- A dev server, a published URL, a screenshot, directory listing, and a preview on any page other than the task are out of scope.

## Describe

`GET /api/v1/companies/{companyId}/tasks/{taskId}/preview` is named `tasks/preview`. It uses the same session check as `GET /api/v1/companies/{companyId}/tasks/{taskId}`: `requireUser`, then `requireMembership`. A missing session is 401 `unauthenticated`. A caller who is not a member of the company is 404. A missing task is 404. A viewer may call it.

The JSON, inside the usual `data` object, is:

```json
{
  "state": "ready",
  "revision": "…",
  "url": "/api/v1/companies/…/tasks/…/preview/{token}/index.html",
  "expiresAt": 1780000000
}
```

`state` is `ready`, `no_page`, or `no_checkout`. `url` is a path on the current host, or `null`. `expiresAt` is a unix timestamp in seconds, or `0`. `revision` is a hex string, or `""`.

Directory choice, first match wins:

1. The task has a project whose `repo_url` is non-empty. The directory is `{dataDir}/worktrees/{taskId}`. `RepoCheckout::worktreeDirectory` returns that path and does not create it. When `realpath` of that directory is missing, or is not a directory under `realpath({dataDir}) . '/worktrees'`, the state is `no_checkout`.
2. Otherwise `CompanyWorkspace::ensure` supplies the company workspace. A missing project row and an empty `repo_url` both take this branch.

`no_checkout` returns `url: null`, `revision: ""`, `expiresAt: 0`. It does not fall through to the company workspace.

Inside the chosen directory, the site root is the first existing file, and the root is that file's parent:

1. `index.html`
2. `site/index.html`
3. `public/index.html`
4. `dist/index.html`

The candidate counts only when `is_file` is true and `realpath` stays inside `realpath` of the chosen directory. A symlink that leaves the directory does not count. When none of the four exist, the state is `no_page` with the same empty `url`, `revision`, and `expiresAt` as `no_checkout`.

When a site root exists, the state is `ready`. `url` is the preview file path for `index.html` with a new token. `expiresAt` is that token's expiry. `revision` is the digest below.

`PreviewService::describe(string $userId, string $companyId, string $taskId)` performs this check and returns that array. `PreviewService::open(string $companyId, string $taskId, string $token, string $path)` performs the file check below and returns the absolute file path and content type, or `null` when the response is 404.

## Revision

`revision` is the hex SHA-256 of one record per servable file. Records are sorted by relative path. Each record is `path + "\n" + mtime + "\n" + size + "\n"`. `path` uses `/` and is relative to the site root. `mtime` is `filemtime` and `size` is `filesize`, both as decimal integers.

The walk does not follow symlinks. It skips a directory named `.git` or `node_modules`, and any directory or file whose name starts with `.`. It skips a file whose extension is not in the servable list. Extension comparison is case-insensitive.

A rewrite that keeps the same mtime and size keeps the same revision. The poll interval is 2 seconds, so a same-second edit can wait for the next timestamp.

## File route

`GET /api/v1/companies/{companyId}/tasks/{taskId}/preview/{token}/{path:.+}` is named `tasks/preview-file`. `{path:.+}` includes slashes, so `images/a.png` is one parameter.

The action does not call `requireUser`. `ActorMiddleware` may attach the local owner, and the action ignores the actor. The HMAC is the only credential. The response is raw bytes, not the JSON success envelope. A refusal is 404 with `Content-Type: text/plain; charset=utf-8` and body `Not found.` Every refusal uses that same body.

The token is `{expiry}.{hmac}`. `expiry` is a decimal unix timestamp. `hmac` is hex `hash_hmac('sha256', companyId + "\n" + taskId + "\n" + expiry, HOLDER_SECRETS_KEY)`. The key is the raw secrets string. `PreviewToken::issue` sets expiry to `time() + 43200`. `PreviewToken::verify` accepts a token only when `expiry` is all digits, `time() < expiry`, and `hash_equals` matches the HMAC recomputed from the route's company id, the route's task id, and that expiry.

Before any filesystem use, the company id and the task id from the route must match `^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`. Paths are built from those ids. A task id that is not in the database is 404.

The file action resolves the directory and the site root with the same rules as describe. `no_checkout` and `no_page` are 404.

`path` is decoded with `rawurldecode` once. Split on `/`. Reject a `\` , a null byte, an empty segment, a segment `.` or `..`, a segment named `.git` or `node_modules`, and a segment that starts with `.`. Join the remaining segments. `PreviewSite::file` returns the `realpath` of that file only when it is a regular file inside the site root. Symlinks that leave the root are 404. A directory is 404.

The extension, after the last dot and lowercased, must be one of: `html`, `htm`, `css`, `js`, `mjs`, `json`, `svg`, `png`, `jpg`, `jpeg`, `gif`, `webp`, `ico`, `woff`, `woff2`, `ttf`, `txt`, `map`, `webmanifest`. Anything else is 404, including `md`, `php`, and `env`.

Content types:

| Extension | Content-Type |
| --- | --- |
| `html`, `htm` | `text/html; charset=utf-8` |
| `css` | `text/css; charset=utf-8` |
| `js`, `mjs` | `text/javascript; charset=utf-8` |
| `json`, `map` | `application/json` |
| `svg` | `image/svg+xml` |
| `png` | `image/png` |
| `jpg`, `jpeg` | `image/jpeg` |
| `gif` | `image/gif` |
| `webp` | `image/webp` |
| `ico` | `image/x-icon` |
| `woff` | `font/woff` |
| `woff2` | `font/woff2` |
| `ttf` | `font/ttf` |
| `txt` | `text/plain; charset=utf-8` |
| `webmanifest` | `application/manifest+json` |

Every file response also sends `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, and `Content-Security-Policy: frame-ancestors 'self'`. The query string is ignored, so `?r=` does not change which file is read.

A token is bound to one company and one task. It grants no other API route. It stays valid for 12 hours after issue, including after the member who loaded the task page is removed. That window is accepted.

## The task page

`ProjectPreview.vue` takes `companyId` and `taskId`. `TaskView.vue` renders it between the thread and the properties aside once the task has loaded.

From the `lg` breakpoint up, the task grid is `lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_16rem]` with `gap-6`. The row stretches. Column one is the thread: title, description, questions, comments, and runs. Column two is the preview. Column three is the existing properties aside, unchanged, with `lg:self-start` so it stays at the top of the row. Below `lg`, the same three blocks stack in that order.

The preview card is sticky at `lg` inside the stretched cell, so it stays in view while the thread scrolls. Its height is `calc(100dvh - 6.75rem)`: the 60px shell header plus the 1.5rem main padding on top and bottom. Below `lg` the card is `70vh` and is not sticky.

The component polls describe every 2 seconds. The card header shows `Preview` and a Refresh button. The body:

- Before the first response, `Loading preview…`.
- `ready`: a sandboxed iframe, `title="Project preview"`, white background, filling the body. `sandbox` is `allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox`. It does not include `allow-same-origin` or `allow-top-navigation`.
- `no_page`: `No page yet. The preview looks for index.html in the project folder, site/, public/, or dist/.`
- `no_checkout`: `This branch is not checked out.`

The iframe `src` is the returned `url` plus `?r={revision}`. A poll that returns the same revision leaves `src` alone, even when the response carries a new token. Refresh fetches immediately and sets `src` again when the state is `ready`, adding `&n={count}` so the frame reloads when the revision did not change. When `expiresAt - now < 3600`, the next poll replaces `src` with the new `url` even if the revision is unchanged. That happens once per token, not on every poll.

A failed poll after a preview is showing leaves the iframe in place and shows `Preview paused` in the card header until a poll succeeds. A failed first poll shows the API error in the card and no iframe. Unmount clears the timer and drops a late response. Changing task id starts a new poll and drops the previous iframe.

Relative links inside the site, such as `styles.css` and `bananas.html`, resolve under the token prefix. A root-absolute path such as `/styles.css` addresses the Holder host, not the site root. Pages written for this preview use relative URLs.

The properties aside still shows the project name and the repository link.

## Tests

`api/tests/Unit/PreviewSiteTest.php` covers site-root order (root beats `site/`, then `public/`, then `dist/`), a symlink `index.html` that leaves the directory, and `file` refusing `..`, a symlink that leaves the root, `.git`, `node_modules`, a dotfile, a directory, and `notes.md`. It also covers the revision digest for two files with fixed mtimes and sizes, and a dot directory being excluded.

`api/tests/Unit/PreviewTokenTest.php` covers issue and verify, a token used with another task id, a token past its expiry, and a changed secrets key.

`api/tests/Integration/TaskPreviewTest.php` follows the connection pattern in `WakeupClaimTest`. It inserts a user, a membership, a company, a project with a non-empty `repo_url`, and a task. With no worktree directory, `PreviewService::describe` returns `no_checkout`. After `index.html` and `styles.css` are written at the worktree path, describe returns `ready` and `PreviewService::open`, with a token from `PreviewToken::issue`, returns the absolute path of `styles.css`. Deleting that directory returns `no_checkout` again. A task with no project uses the company workspace from `CompanyWorkspace::ensure` and returns `no_page` until `index.html` is added. The test skips when `pdo_pgsql` is missing.

A second integration test uses a local git repository the way `RepoCheckoutTest` does, not the network. It prepares the task worktree, writes `index.html`, sets the task to `done` through `WorkService::updateTask`, and asserts describe returns `no_checkout`.

`api/tests/Api/TaskPreviewCest.php` uses the HTTP API the way `FloorCest` does:

- No session on the describe route receives 401 `unauthenticated`.
- A member of another company receives 404.
- A viewer of the company receives 200.
- A task whose company workspace has no `index.html` is `no_page`.
- After `index.html` and `styles.css` are written into the workspace path from the company payload, describe is `ready`. `GET` of `url` and of the `styles.css` path, with no `Authorization` header and no session cookie, returns the file bytes and the content types above.
- The token from one task, placed in another task's file URL, receives 404 `Not found.`.
- `../` outside the site root, `.git/HEAD`, and `notes.md` each receive that same 404.

The UI has no component test runner. `vue-tsc` covers `ProjectPreview.vue`. The task page is checked in the browser: a workspace `index.html` is visible beside the thread at a wide viewport and stacked at a narrow one, Refresh reloads it, `no_page` and `no_checkout` show their sentences, a failed later poll leaves the iframe up with `Preview paused`, and a comment still sends.

## Files

- `api/src/Domain/Work/PreviewSite.php`
- `api/src/Domain/Work/PreviewToken.php`
- `api/src/Domain/Work/PreviewService.php`
- `api/src/Domain/Github/RepoCheckout.php` gains public `worktreeDirectory`
- `api/src/Api/PreviewEndpoints.php`
- `api/config/common/routes.php` gains `tasks/preview` and `tasks/preview-file`
- `api/tests/Unit/PreviewSiteTest.php`
- `api/tests/Unit/PreviewTokenTest.php`
- `api/tests/Integration/TaskPreviewTest.php`
- `api/tests/Api/TaskPreviewCest.php`
- `ui/src/components/ProjectPreview.vue`
- `ui/src/views/TaskView.vue`

The container autowires the new classes the same way it autowires `FloorEndpoints`. No DI config change.
