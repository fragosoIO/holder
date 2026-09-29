# Connect a project to a GitHub repository

## Purpose

A company stores one GitHub token. A project can point at one GitHub repository. Holder clones that repository, and each task in the project gets its own git worktree and branch. The agent commits, pushes, and opens a pull request. Tasks with no repository keep using the company folder.

## Locked decisions

- The agent opens the pull request. Holder does not call the GitHub API.
- The token belongs to the company. The repository URL belongs to the project.
- Two tasks in the same project edit separate worktrees.
- The repository URL is chosen when the project is created.

## Company token

Add nullable `companies.github_token text` in a new migration. One SQL statement. Existing company rows stay valid.

The column stores a sodium secretbox payload, base64 of a 24-byte random nonce followed by the ciphertext from `sodium_crypto_secretbox`. The key is the raw SHA-256 of `HOLDER_SECRETS_KEY` (32 bytes). A null column means no token.

`PUT /api/v1/companies/{companyId}/github-token` with `{ "token": "..." }` sets or clears it.

- Owner or admin only, the same manage check as renaming the company. A member, a viewer, or an agent run receives 403 `forbidden`.
- Missing `token` returns 422 `missing_field`.
- Trim the value. Empty after trim stores null.
- A non-empty value is encrypted and stored.

`PATCH /api/v1/companies/{companyId}` still updates only name and mission. It does not read or change the token.

Every company JSON object includes `githubConnected`. It is true only when `github_token` is non-null. The builder keeps ignoring the ciphertext column, so the token never appears in a response. `SELECT c.*` may load the column; the resource must not copy it.

## Project repository

Add these columns in the same migration, one statement each:

- `projects.repo_url text NOT NULL DEFAULT ''`
- `projects.default_branch text NOT NULL DEFAULT ''`

`POST /api/v1/companies/{companyId}/projects` accepts optional `repoUrl`. Creating a project still uses the existing write check: owner, admin, or member.

A blank `repoUrl` keeps today's behavior. `workspacePath` is the path the caller sent. Onboarding keeps creating the Onboarding project on the company folder and does not clone.

A non-blank `repoUrl` must match `https://github.com/<owner>/<repo>` after these rules:

- Host is exactly `github.com`. Reject userinfo, a port, a query, a fragment, and any extra path segment.
- Strip one trailing slash.
- If the repo segment ends in `.git`, strip that suffix once.
- Owner and repo are 1–39 and 1–100 characters of `[A-Za-z0-9._-]`, and neither is `.` or `..`.

Anything else returns 422 `invalid_repo_url`. Store the canonical URL `https://github.com/<owner>/<repo>` with the original segment case and no `.git`.

Saving a valid URL when `githubConnected` is false returns 422 `github_token_missing` and does not clone.

When the URL and token are present, clone before inserting the row:

1. Refuse with 422 `git_unavailable` when `git --version` cannot be run or exits non-zero.
2. Clone into `{dataDir}/repos/{projectId}`. The new id is chosen first so the path is stable.
3. Git uses `bin/holder-git-askpass` for that process only. The remote stored in the clone is the canonical HTTPS URL, with no token in it.
4. Read the default branch from `git symbolic-ref --short refs/remotes/origin/HEAD`, then drop the `origin/` prefix.
5. Insert the project with `repoUrl`, `defaultBranch`, and `workspacePath` set to the clone directory.

Do not hold a database transaction open across the clone. If clone, branch detection, or insert fails, delete `{dataDir}/repos/{projectId}` and insert nothing. A git failure returns 422 `github_clone_failed`. The message is git's stderr with the token and any URL userinfo removed.

There is no project update route. A connected URL stays the one stored at create.

Every project JSON object includes `repoUrl` and `defaultBranch`, including list, create, and the onboarding bundle. Empty strings mean the project has no repository.

## Ask-pass

`bin/holder-git-askpass` is an executable shell script beside the `holder` binary. It prints `x-access-token` when the prompt contains `Username` in any case, and otherwise prints `$GH_TOKEN`. The script contains no secret.

Holder invokes git with `GIT_ASKPASS` set to that script's absolute path, `GIT_TERMINAL_PROMPT=0`, and `GH_TOKEN` set to the decrypted company token. Those variables exist only on that git process unless the run section below also sets them for Pi.

## Worktrees

A heartbeat whose task has a project with a non-empty `repo_url` runs in a worktree. Any other task, including Onboarding and a task with no project, runs in the company folder from `CompanyWorkspace`, as it does today.

Paths and names:

- Clone: `{dataDir}/repos/{projectId}`
- Worktree: `{dataDir}/worktrees/{taskId}`
- Branch: `holder/{taskId}`
- Lock file: `{clone}/.holder-git.lock`

A subtask copies the parent's `project_id` and gets its own worktree and branch.

Under a blocking `flock` on the lock file:

1. If the company token is missing, stop. The run fails and Pi does not start. `holder.error` message is `github_token_missing`.
2. If the clone directory is missing, clone the stored canonical URL into `{dataDir}/repos/{projectId}` again. Do not insert another project row. On success, set `default_branch` from `origin/HEAD` the same way create does. A failure fails the run. `holder.error` message is `git_unavailable`, or `github_clone_failed: ` plus the redacted stderr.
3. `git fetch origin`. A failure fails the run with `github_clone_failed: ` plus the redacted stderr. Fetch does not reset an existing worktree.
4. If `git worktree list --porcelain` already lists this worktree path, reuse it, including uncommitted files.
5. Otherwise, if local branch `holder/{taskId}` exists, `git worktree add` that branch at the worktree path.
6. Otherwise, if `origin/holder/{taskId}` exists, add a worktree that creates the local branch from that remote branch.
7. Otherwise, add a worktree that creates `holder/{taskId}` from `origin/{defaultBranch}`.

A worktree command failure fails the run. `holder.error` message is `github_clone_failed: ` plus the redacted stderr. Pi does not start, and the run does not fall back to the company folder.

When a saved status is `done` or `cancelled` and the previous status was different, commit that status first. After the transaction, run `git worktree remove --force` for that task's worktree and `git branch -D` for `holder/{taskId}`. Ignore failures of either command. Do not delete the remote branch. The same cleanup runs for a board update and for an agent status change. Saving `done` again while the task is already `done` does not run cleanup. Moving `done` to `cancelled` does.

A later heartbeat after the task leaves `done` or `cancelled` follows the steps above, so a surviving `origin/holder/{taskId}` is checked out again.

## Run environment and prompt

`git` and `gh` are configurable binary names, defaulting to `git` and `gh` on `PATH`. Tests point them at stub programs.

The image built from `docker/php/Dockerfile` installs Debian `git` and the GitHub CLI from GitHub's official apt repository. A host worker needs both on its `PATH`.

For a repository run, before starting Pi, run `<gh> --version`. If that fails, the run fails and Pi does not start. `holder.error` message is `gh_unavailable`.

Pi's working directory is the worktree. Its environment adds:

- `GH_TOKEN` and `GITHUB_TOKEN`, both the company token
- `GIT_ASKPASS` as the absolute path of `bin/holder-git-askpass`
- `GIT_TERMINAL_PROMPT=0`

A company-folder run removes `GH_TOKEN`, `GITHUB_TOKEN`, and `GIT_ASKPASS` from the environment it inherits from the worker. The token is not written into the prompt, the run row, or run events.

For a repository run whose wake reason is not `review`, the prompt adds:

```text
Repository: {repoUrl}
You are on branch holder/{taskId}, branched from {defaultBranch}.
Commit your work on this branch. Push it with: git push -u origin holder/{taskId}
Open a pull request with gh pr create. Use {defaultBranch} as the base, holder/{taskId} as the head, and the task title as the pull request title. Write the body from the work you did.
Put the pull request URL in a holder comment.
Do not push {defaultBranch}. Do not force-push.
If gh pr view already shows a pull request for this branch, push new commits and leave that pull request in place.
Do not print GH_TOKEN or GITHUB_TOKEN.
```

For a `review` wake on a repository task, Pi still starts in this task's own worktree, not the child's. Keep the current review instructions. Add one line per done child task, `holder/{childId}`. Tell the reviewer to read the pull request URL in the comments and not to open a new pull request. Also tell them not to print the tokens. The child ids are the done tasks whose `parent_id` is this task. Do not add the `gh pr create` instructions on a review wake.

## UI

Company JSON already loaded by the board supplies `githubConnected`.

Settings (`/company/settings`) keeps name, mission, and the workspace line. Below that, a GitHub block shows "GitHub token saved" or "No GitHub token". A password field starts empty. Its Save button sends the PUT above. Clear is shown only when a token is saved and sends `{ "token": "" }`. The name and mission Save button does not send a token. After a token save, reload the company.

Projects (`/projects`) replaces the empty state. The page lists each project by name. A repository project shows the canonical URL as a link and the default branch. Any other project shows "Company folder". The create form has name and an optional GitHub URL. When the URL is non-empty and `githubConnected` is false, Save stays disabled and the form links to `/company/settings`. The submit button reads "Cloning…" while a request with a URL is in flight, and "Saving…" otherwise. A failed create shows the API error.

`useWorkspace` also loads `GET /api/v1/companies/{companyId}/projects`. New task gains a Project select that defaults to no project. The option label is the project name, plus the repo URL when it has one. Choosing a project sends `projectId`.

The task sidebar shows the project name. When that project has a `repoUrl`, it also shows the repo link and `holder/{taskId}`. The pull request stays in the comments.

## Tests

No test contacts GitHub. A fake `git` creates the destination directory, prints `origin/main` for the symbolic-ref, records worktree commands, and returns a configured failure when asked.

API tests:

- An owner saves a token. The following company JSON has `githubConnected: true` and does not contain the token text. The database value is not the raw token.
- A member receives 403 on the token route.
- An empty token clears `githubConnected`.
- A missing `token` field returns 422 `missing_field` and leaves a saved token in place.
- Create with a repository URL and no token returns 422 `github_token_missing`.
- Create with a rejected URL returns 422 `invalid_repo_url`.
- Create with a successful fake git inserts `repoUrl`, `defaultBranch` `main`, and `workspacePath` under `{dataDir}/repos/{projectId}`.
- Create with a failing fake git returns 422 `github_clone_failed`, inserts nothing, and leaves no clone directory.
- Create without a URL still inserts a project and does not run git.
- Name and mission PATCH leaves the token in place.

Heartbeat tests, using the existing fake Pi:

- A repository task starts in the worktree. The child environment includes `GH_TOKEN`. The stored prompt contains the branch and `gh pr create`, and does not contain the token.
- A second wake for that task does not add the worktree again.
- A task with no repository starts in the company folder. A `GH_TOKEN` on the worker is absent from that child environment.
- A missing `gh` fails the run before Pi starts, with `holder.error` message `gh_unavailable`.
- Moving the task to done invokes worktree removal. A failing removal still leaves the task done.

Prompt unit tests cover the repository instructions, the review wake listing the child branch and not telling the reviewer to open a pull request, and the line not to print the token. A non-repository prompt is unchanged.

UI typecheck is `./node_modules/.bin/vue-tsc -b` from `ui/`. In the browser, exercise saving and clearing the token, creating a project with and without a token, and the branch line on a task whose project has a repository.

## Out of scope

- Changing or clearing a project's repository URL after create
- Holder opening the pull request through the GitHub API
- Forks, SSH remotes, and hosts other than `github.com`
- A GitHub App or a connector screen
- More than one repository on a project
- Deleting a project or its clone from the UI
