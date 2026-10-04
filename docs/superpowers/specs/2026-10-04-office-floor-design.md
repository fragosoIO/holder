# Live office floor

## Purpose

A Floor page shows every agent in the current company who is not terminated, the way the Smallville map in [generative_agents](https://github.com/joonspk-research/generative_agents) shows every persona at once. Each agent is a sprite in a pixel office. While they are at a work spot, the bubble shows the task title plus the latest Pi step. Clicking a sprite opens that task, or the agent when they have no task.

## Locked decisions

- The office is a Phaser 3 scene inside the existing Vue app. One new route, `/floor`.
- Art is original and committed with the UI. Nothing is copied from `joonspk-research/generative_agents`.
- The page polls `GET /api/v1/companies/{companyId}/floor` every 2 seconds. The task page keeps its own event stream.
- A sprite walks only after the first snapshot. The first time an agent appears, they are already at the spot for that snapshot.
- Replay, map editing, unique portraits, sound, and a push stream are out of scope.

## Snapshot

`GET /api/v1/companies/{companyId}/floor` uses the same session check as `GET /api/v1/companies/{companyId}/agents`. A caller who cannot list agents cannot read the floor. The JSON, inside the usual `data` object, is:

```json
{
  "agents": [
    {
      "id": "…",
      "name": "Ada",
      "title": "Engineer",
      "status": "active",
      "place": "work",
      "step": "Editing src/Login.php",
      "task": { "id": "…", "title": "Login page", "status": "in_progress" }
    }
  ]
}
```

`place` is `desk` or `work`. `task` is an object or `null`. `step` is always a string. Agents are ordered by `created_at` ascending, then `id`. Terminated agents are omitted.

The task on an agent is chosen in this order:

1. The assigned task whose `checkout_run_id` points at a run with `status = 'running'` and that run's `agent_id`. If more than one, use the run with the latest `started_at`.
2. Otherwise the assigned task with the latest `updated_at` whose status is not `done` or `cancelled`.
3. Otherwise `null`.

Place and the desk line are decided in this order. The first match wins:

1. Agent status `paused`: `desk`, step `Paused`. The task is still attached.
2. The chosen task is `blocked` or `in_review`: `desk`, step `Waiting on you`.
3. The chosen task has a running checkout run: `work`, and `step` comes from the step lines below.
4. Anything else: `desk`, step `At their desk`.

A paused agent stays at the desk even when a run row is still `running`. A blocked or in-review task sends them back even when the checkout run is still `running`.

## Step lines

Only the chosen running run contributes a step. Among its `tool_execution_start` and `holder.error` events, the one with the greatest id wins. A newer `message_update` does not count.

- `tool_execution_start` uses the payload's `toolName` and `args`.
  - `edit` or `write` with a non-empty string `args.path` becomes `Editing {path}`. The path is copied as stored.
  - `read` with a non-empty string `args.path` becomes `Reading {path}`.
  - `bash` with a non-empty string `args.command` becomes `Running {command}`. Collapse whitespace to single spaces and trim. If the command is longer than 80 characters, keep 79 and append `…`.
  - `bash` with an empty or missing command becomes `Running a command`.
  - Any other non-empty `toolName`, including `edit`, `write`, or `read` without a path, is that name.
  - A payload that is not an object, or a `toolName` that is missing or empty, becomes `Working`.
- `holder.error` becomes `Hit an error`. The error body is not part of the response.

Names match exactly, including case. If the run has no such event, the step is `Starting`. One unreadable event does not fail the snapshot.

`StepLine` is a pure function of one event and returns the string for that event. `FloorPlace` is a pure function of agent status, task status, and whether the checkout run is running. It returns `desk` or `work`, plus the desk step (`Paused`, `Waiting on you`, or `At their desk`). For `work` it returns no step, and `FloorService` uses `StepLine`. `FloorService` loads the rows, picks the task, and calls those two. It checks membership the same way `OrgService::listAgents` does.

The query does not read `message_update` payloads. For each running run it loads the `tool_execution_start` or `holder.error` row with the greatest id.

## The room

Phaser 3 is a UI dependency. `FloorView.vue` creates the game on mount and destroys it on unmount. The scene does not fetch. The view polls, and it passes each snapshot into the scene.

Assets live in `ui/src/assets/floor/` and are original pixel art:

- `office.png` and `office.json`: a Tiled map, 32px tiles, 40 tiles wide and 24 tall.
- `clerk.png` and `clerk.json`: one character atlas. Idle and a four-frame walk for down, up, left, and right. Draw the figure in light tones so a tint reads clearly.

An object layer named `spots` has point objects `desk-1` … `desk-12` and `work-1` … `work-12`. Desk `n` sits at tile `(1 + (n - 1) * 3, 4)`. Work spot `n` sits at tile `(1 + (n - 1) * 3, 18)`. The code reads positions from the object layer.

Seat `i` (zero-based, in snapshot order) uses spot `(i % 12) + 1`. Row `floor(i / 12)` adds `floor(i / 12) * 64` pixels to that spot's y. Two sprites never share a pixel. Camera bounds include the map plus any overflow rows.

Each agent uses the desk and the work spot of the same seat, so the walk is a straight vertical segment in that column. Walk speed is 120 pixels per second. On arrival the sprite idles facing down.

The first snapshot places each agent at their current spot. A later snapshot that changes `place`, or changes which work spot they own, retargets from the sprite's current pixel. An in-progress walk is replaced. Walks do not queue. An agent who leaves the snapshot is removed at once. Switching company clears the sprites and loads the other office.

Arrow keys pan. Dragging the map pans. The wheel zooms from 1 to 2. The camera starts at zoom 1, centered on the map. A drag does not count as a click.

Each sprite is tinted from a stable hue of its id. Under the feet, a label shows initials: the first character of the first word and, when the name has more than one word, the first character of the last word. One word uses its first character. Letters are uppercased. An empty name uses `?`.

The bubble sits above the sprite and shows two lines. Each line is cut at 22 characters, and a cut line ends with `…`. Line one is the task title, or the step when `task` is null. Line two is the step, and it is omitted when there is no task. Hovering a sprite shows a DOM card with the agent name, the full task title or `No task`, and the full step.

A click on a sprite with a task goes to `/issues/{id}`. A click with no task goes to `/agents/{id}`.

Under the canvas, the same snapshot is a list of links. Each row shows the name and the full step, and it goes to the same place as a click. That list is the keyboard path and the narrow-viewport path.

## Failures

- Until the first snapshot, the status text is `Loading the office…`.
- The first request failing shows the API error as an alert and creates no sprites.
- A later poll failing leaves every sprite where it is, lets the current walk finish, and shows `Live updates paused` until a poll succeeds.
- No listed agents shows the room and the banner `You have no agents`, with the same link to `/agents/new` that the dashboard uses.
- The poll stops when the view unmounts, and an in-flight request is aborted.

## Navigation

Add `/floor` to the router with title `Floor`. In the primary nav, put Floor after Dashboard. Use a map icon from the icons already used in the sidebar.

## Tests

`api/tests/Unit/StepLineTest.php` covers the formatting of one event: `edit` and `write` with a path, `read` with a path, a bash command, a bash command longer than 80 characters, a bash with no command, an edit with no path, an empty tool name, a payload that is not an object, and `holder.error`.

`api/tests/Unit/FloorPlaceTest.php` covers the four place rules, including paused-while-running and blocked-while-running.

`api/tests/Api/FloorCest.php` uses the HTTP API the way `CompanyIsolationCest` does:

- No session receives 401 `unauthenticated`.
- A member of another company receives 404.
- An active agent with no task is `desk` / `At their desk` / `task: null`.
- An assigned task that is not running stays `desk` with that task's id and title, and the step `At their desk`.
- A paused agent stays in the list at `desk` with `Paused`.
- A terminated agent is absent.
- A blocked task is `desk` / `Waiting on you`.

`api/tests/Integration/FloorSnapshotTest.php` follows the connection pattern in `WakeupClaimTest`. It inserts a company, a user, a membership, an agent, a task whose `checkout_run_id` points at a running run, and two events on that run: a `tool_execution_start` for `edit` of `src/Login.php`, then a newer `message_update`. `FloorService::snapshot` for that member returns `place` `work` and step `Editing src/Login.php`. The test skips when `pdo_pgsql` is missing.

The scene is checked in the browser: an empty office, an idle agent at a desk, an agent walking to a work spot, the bubble text, click-through to the task, a failed later poll leaving everyone in place, and a narrow viewport where the list still opens the task.

## Files

- `api/src/Domain/Floor/StepLine.php`
- `api/src/Domain/Floor/FloorPlace.php`
- `api/src/Domain/Floor/FloorService.php`
- `api/src/Api/FloorEndpoints.php`
- `api/config/common/routes.php` gains the route, named `floor/show`
- `api/tests/Unit/StepLineTest.php`
- `api/tests/Unit/FloorPlaceTest.php`
- `api/tests/Api/FloorCest.php`
- `api/tests/Integration/FloorSnapshotTest.php`
- `ui/src/views/FloorView.vue`
- `ui/src/floor/OfficeScene.ts`
- `ui/src/assets/floor/office.png`
- `ui/src/assets/floor/office.json`
- `ui/src/assets/floor/clerk.png`
- `ui/src/assets/floor/clerk.json`
- `ui/src/router.ts`
- `ui/src/App.vue`
- `ui/package.json` gains `phaser`
