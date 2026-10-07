# Platform review

Exercised on 2026-10-05 against the running stack. API health returned `{"status":"success","data":{"ok":true}}`. Session mode was `"mode": "local"`.

Mutations used one company created in this pass, `4ac265b9-7caf-4007-ab2b-6f7f6c9f7f51` (`Audit Pass d5e939`, then renamed). Holder, Walkthrough Org, and Test were only read. A before/after comparison of their tasks logged `unchanged`. Both Pi runs started on the disposable company were cancelled; no run was left `running`.

Statuses are works, broken, unfinished, or blocked. Each observation below is a verbatim excerpt from the exercise log (rendered page text, HTTP body, or bin/holder output).

## Inventory

| Action | Status | Observation |
| --- | --- | --- |
| load /login | works | `"title": "Sign in · Holder"` and the form text `Sign in` / `Email` / `Password`. |
| load /onboarding | works | `Name the organization and its first agent. That chief of staff is hired on Pi and can hire the rest of the team.` POST `/api/v1/onboarding` returned `"name":"Audit Pass d5e939"`. |
| load /dashboard | works | `Pi bills the provider` with `6 open, 1 blocked`. |
| load /floor | works | Rendered `Audit Chief`, `Waiting on you`, `Audit Report`, `Smoking`. |
| load /inbox | works | `Paperclip onboarding` / `Blocked` and `Audit question task` / `In review`. |
| load /search | works | `Search tasks, agents, and goals. Ctrl+K or ⌘K opens this page.` |
| load /issues | works | Task list includes `Audit filter hit`, `Audit wake task`, and `Audit blocker target`. |
| load /issues/:id | works | `Audit comment from the board.` and `No page yet. The preview looks for index.html in the project folder, site/, public/, or dist/.` |
| load /projects | works | `Audit folder` under `Company folder`, plus the `New project` form. |
| load /routines | unfinished | `No routines yet.` |
| load /artifacts | unfinished | `No artifacts yet.` |
| load /goals | works | `Audit goal` and `Disposable goal for the action pass.` |
| load /agents | works | `3 agents`, including `Audit Temp` as `terminated`. |
| load /agents/new | works | `Workspace /repo/api/runtime/holder-data/workspaces/4ac265b9-7caf-4007-ab2b-6f7f6c9f7f51` and a model select. |
| load /agents/:id | works | `Pi 0.87.1` with `Pause`, `Terminate`, and `Save`. |
| load /skills | unfinished | `No skills yet.` |
| load /apps | unfinished | `No connectors yet.` |
| load /activity | unfinished | `No audit events yet.` |
| load /org | works | `Audit Chief`, `Chief of Staff`, `Audit Report`, `Report editor`. |
| load /costs | unfinished | `No spend recorded. Model bills stay with Pi.` |
| load /company/settings | works | Fields showed `"name": "Audit Pass d5e939 renamed"` and `"mission": "Audit mission for the disposable company."` |
| company menu | works | Menu text included `Walkthrough Org`, `Audit Pass d5e939 renamed`, `New organization`, `Org`, `Costs`, `Settings`. |
| company switch | works | `"switchedToHolder": "Holder"` then `"restored": "Audit Pass d5e939 renamed"`. |
| New organization | works | The menu item opened `"url": "/onboarding"` with `Create organization`. |
| theme toggle | works | `"themeBefore": "dark"` then `"stored": "light"`. |
| sign out | blocked | `"signOutButtons": 0` because the session is `"mode": "local"`. The button is rendered only when mode is authenticated. |
| sidebar New task | works | Dialog text: `Title`, `Description`, `Goal`, `No goal`, `Assignee`, `Unassigned`, `Create task`. |
| Search shortcut | works | Ctrl+K opened `/search` with `Search tasks, agents, and goals. Ctrl+K or ⌘K opens this page.` |
| create task | works | `"title":"Audit filter hit","description":"visible in the task filter","status":"todo"` |
| filter tasks | works | Title filter kept `Audit filter hit`. Status `cancelled` showed `No tasks match that filter.` |
| patch status | works | `"title":"Audit filter hit","description":"visible in the task filter","status":"in_progress"` |
| patch assignee | works | `"assigneeAgentId":"c9cd7334-e0c6-43e1-a813-4e0896cc9356","title":"Audit wake task"` |
| add blocker | works | `"blockerIds":["a9e9095c-32d3-415b-b7b7-efdbabd481b4"]` and `"title":"Audit blocker target"` |
| remove blocker | works | `"updatedAt":"2026-10-05 21:47:25.973191+00"` with `"blockerIds":[]` |
| comment | works | `"body":"Audit comment from the board."` |
| answer opening question | works | `What would you like to do?\nInterview me and propose a plan and an agent team to execute it.` and `"openingQuestion":null` |
| answer agent questions | works | `What should the audit note?\nKeep going` |
| cancel run | works | `{"status":"success","data":{"ok":true}}` and the run `"status":"cancelled"` |
| create goal | works | `"title":"Audit goal","description":"Disposable goal for the action pass."` |
| create project | works | `"name":"Audit folder","workspacePath":"","repoUrl":"","defaultBranch":""` |
| save company name and mission | works | `"name":"Audit Pass d5e939 renamed","mission":"Audit mission for the disposable company."` |
| save GitHub token | works | `"githubConnected":true` |
| clear GitHub token | works | `"githubConnected":false` and the settings page `No GitHub token` |
| hire agent | works | `"name":"Audit Report","title":"Report","jobDescription":"Handles one assigned subtask during the audit.","managerId":"c9cd7334-e0c6-43e1-a813-4e0896cc9356","status":"active"` |
| save agent | works | `"title":"Report editor"` |
| pause | works | `"status":"paused"` |
| resume | works | Resume returned `"title":"Report editor"` with `"status":"active"` |
| terminate | works | `"name":"Audit Temp"` and `"status":"terminated"` |
| preview refresh | works | Both fetches returned `{"state":"no_page","revision":"","url":null,"expiresAt":0}` |
| floor snapshot load and its task-or-agent link | works | `"step":"Waiting on you","task":{"id":"a1703b9f-0eea-4c6e-a021-36d7d25fe52c","title":"Paperclip onboarding","status":"blocked"}`. Clicking the clerk opened `https://holder.localhost/issues/a1703b9f-0eea-4c6e-a021-36d7d25fe52c`. |
| search hit | works | `"hit": "GOALS\nAudit goal"` |
| search miss | works | `No matches.` |
| bin/holder comment | works | `"body":"Audit agent comment."` Exit 0. Stderr: `Deprecated: The predefined locally scoped $http_response_header variable is deprecated, call http_get_last_response_headers() instead in /repo/bin/holder on line 75` |
| bin/holder status | works | `"title":"Audit question task","description":"questions are answered here","status":"in_review"` |
| bin/holder questions | works | `"prompt":"What should the audit note?"` The card came back with `"intro":""` while the intro was stored as the comment `Audit question`. |
| bin/holder assign | works | `Assigned a subtask to Audit Report.` Child `"assigneeAgentId":"25eb3cb9-3a68-472a-b2f1-1ef84a3ea3f5"`. |
| invite create | unfinished | API only. POST returned `"email":"audit-d5e939@example.com","role":"member"`. No screen creates an invite. |
| invite accept | unfinished | API only. Accept returned `"name":"Audit Member","email":"audit-d5e939@example.com"` with `"role":"member"`. No screen accepts an invite. |
| assigning a task so a wakeup is queued or the worker refuses | works | `"status":"in_progress","checkoutRunId":"45ce1a44-9ad2-40a9-974d-3a4266f095e6"` and wakeup `assignment` for `b67c484c-dbf3-47e7-83b0-d4ea9d3bce57`. The run was then cancelled. |

## Unfinished features

These are inventory rows whose live result was an empty shell or a capability that exists only on the API.

- **load /routines.** The page body is only `No routines yet.` There is no create control.
- **load /artifacts.** The page body is only `No artifacts yet.`
- **load /skills.** The page body is only `No skills yet.`
- **load /apps.** The page body is only `No connectors yet.` The nav label is Connectors.
- **load /activity.** The page body is only `No audit events yet.` The company rename, task create, comment, pause, and invite from this pass did not show up.
- **load /costs.** The page body is only `No spend recorded. Model bills stay with Pi.` The dashboard tile for the same company reads `Pi bills the provider` and does not show an amount.
- **invite create.** `"email":"audit-d5e939@example.com","role":"member"` succeeded on the invites POST. The UI has no invite control.
- **invite accept.** `"name":"Audit Member","email":"audit-d5e939@example.com"` succeeded on the accept POST. The UI has no accept screen.

## Improvements

Each item names the inventory row it changes.

- **load /routines.** Replace the empty state with a way to save a prompt, an agent, and an interval, and have the heartbeat enqueue that agent when the interval is due.
- **load /artifacts.** List the files agents write under the company workspace (and a task worktree when one exists), with a link from the task that produced the file.
- **load /skills.** Let an operator attach a Pi skill to an agent and show the attached skills on `/agents/:id`, instead of a static empty page.
- **load /apps.** Add a connector row for the GitHub token already stored in settings, or hide Connectors until a second connector exists.
- **load /activity.** Record an audit row for company update, task create, task comment, agent pause/resume/terminate, and invite create, and list those rows on `/activity`.
- **load /costs.** Increment `spentCents` from Pi usage, or remove the Costs page and the dashboard Month spend tile that stays at an em dash (`Pi bills the provider`).
- **invite create.** Add an invite form on /org or /company/settings that POSTs the email and role and shows the pending invite.
- **invite accept.** Add a page that accepts the invite token, name, and password, then opens the new member's session. The API already returns that session.
- **bin/holder comment, bin/holder status, bin/holder questions, bin/holder assign.** On PHP 8.5 every command prints `Deprecated: The predefined locally scoped $http_response_header variable is deprecated, call http_get_last_response_headers() instead in /repo/bin/holder on line 75`. Read the status from `http_get_last_response_headers()` so stdout stays the JSON body.
- **bin/holder questions.** The questions call returned `"intro":""` and put the intro in a separate comment (`Audit question`). Return the intro on the card the board answers, so the card and the comment are not split.
- **sign out.** GET `/api/v1/session` includes the session secret in `token` next to `"mode": "local"`. Keep the secret in the cookie only. The sign-out button correctly stays hidden while mode is `local` (`"signOutButtons": 0`).
