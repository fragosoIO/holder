# Holder

Holder is a self-hosted control plane for a company of [Pi](https://pi.dev) agents. You set a mission, hire agents, give them goals and tasks, and a worker wakes Pi to do the work.

Pi is the only agent runtime. Provider, model, and API keys stay with Pi. Holder starts Pi and keeps the company record: the mission, the org chart, the goals, and the tasks.

A company has a mission, agents, goals, projects, and tasks. Hiring an agent records a name, a title, a job, a manager, and the Pi model to use. Assigning a task queues a wakeup. The heartbeat worker claims that wakeup, starts Pi in the company workspace, and puts `holder` on `PATH` so the agent can comment, ask a question, change status, or hand the task to someone who reports to it. A task on a GitHub project runs in a checkout of that repo, on its own branch.

Onboarding creates the company, a chief of staff, and one opening task. Routines, skills, connectors, an audit log, spend tracking, approvals, and company export are still empty screens.

## Requirements

- Docker, for PostgreSQL and the API
- Node.js 22 or newer, for the Vue UI and for Pi
- The `pi` command (`npm install -g @earendil-works/pi-coding-agent`) when a worker on the host should use the host binary. The API image already installs Pi 0.87.1
- `git` and the GitHub CLI `gh`, already in the API image, when a project points at a GitHub repository

The API image installs PHP's `pdo_pgsql` extension. A worker on this machine needs that extension too (`sudo pacman -S php-pgsql` on this OS).

## Run

```bash
cp api/.env.example api/.env
docker compose up --build
```

On startup the API applies migrations and creates the bootstrap owner when that email is missing: `owner@holder.local` / `owner`, company "Holder", mission "Run the company." Compose mounts `~/.pi` into the API container, so a Pi login on the host is the login the worker uses.

Holder joins the external Docker network `proxy`. Traefik routes these hosts to the UI, on HTTPS:

- https://holder.localhost
- https://holder.test
- `https://holder.<dashed-ip>.traefik.me`

`holder.localhost` has to be on the local mkcert certificate (`./mkcert-local.sh holder.localhost` in the Traefik project) or the browser will warn.

The API stays on the internal network and is also published at http://127.0.0.1:8080. Postgres is at 127.0.0.1:5432. Inside Compose, the API reaches Postgres at `postgres` and agents call the API at `http://api:8080`. A process on the host still uses the `127.0.0.1` values in `api/.env`.

To run the UI on the host, stop the `ui` service and use `npm run dev` in `ui/`. That dev server proxies `/api` to http://127.0.0.1:8080.

## Sign in

`HOLDER_MODE` defaults to `local`. A request from a loopback or private address, whose host is `localhost`, `127.0.0.1`, `*.localhost`, or `*.test`, is signed in as the bootstrap owner. That covers https://holder.localhost and https://holder.test.

## Give an agent work

The first time a company is loaded, Holder creates a workspace under the data directory (`api/runtime/holder-data` unless `HOLDER_DATA_DIR` is set). Agents run there. A GitHub project is checked out under `repos/` inside that directory.

Compose leaves the heartbeat worker stopped. After you assign a task, run one wakeup:

```bash
docker compose run --rm --entrypoint php api ./yii heartbeat:work --once
```

Leave the worker running:

```bash
docker compose run --rm --entrypoint php api ./yii heartbeat:work
```

The worker claims one pending wakeup at a time. It skips a paused agent and a blocked task. Pi receives the company mission, the goal chain, the task, its comments, and the agents who report to the assignee. The task page streams the run.

The agent reports with `bin/holder`:

```bash
holder comment --task ID --body "what you did"
holder status --task ID --status in_progress
holder status --task ID --status blocked --blocked-by OTHER_TASK_ID
holder questions --task ID --body '{"questions":[...]}'
holder assign --task ID --agent AGENT_ID
```

`assign` reaches only a direct report, and it creates a subtask. When every named blocker is `done`, Holder sets the blocked task back to `todo` and wakes the assignee. A cancelled blocker stays unresolved. Statuses are `todo`, `in_progress`, `blocked`, `in_review`, `done`, and `cancelled`.

`HOLDER_RUN_TIMEOUT` (default 600) is how long Pi may stay silent before the run stops. `HOLDER_RUN_LIMIT` (default 3600) is the longest a run may last.

## GitHub

Save a token in company settings. It is stored encrypted with `HOLDER_SECRETS_KEY`. Creating a project with a repository URL clones that repo. On a wakeup for a task in the project, the worker prepares a branch. The prompt tells the agent to commit, push, open a pull request with `gh pr create`, and paste the URL into a comment.

## Run Pi from the host

Install `php-pgsql`, and install `git` and `gh` if agents should push to GitHub. Stop the Compose API so the host process can bind port 8080, then:

```bash
php api/yii serve
php api/yii heartbeat:work
```

Postgres can stay in Docker. `api/.env` already points at `127.0.0.1`.

## Configuration

Copy `api/.env.example`. Compose overrides `HOLDER_DB_HOST` to `postgres` and `HOLDER_API_URL` to `http://api:8080`.

| Variable | Default | Role |
| --- | --- | --- |
| `HOLDER_MODE` | `local` | `local` signs in the bootstrap owner for trusted local hosts. `authenticated` requires a password. |
| `HOLDER_DB_HOST` | `127.0.0.1` | Postgres host |
| `HOLDER_DB_PORT` | `5432` | Postgres port |
| `HOLDER_DB_NAME` | `holder` | Database name |
| `HOLDER_DB_USER` | `holder` | Database user |
| `HOLDER_DB_PASSWORD` | `holder` | Database password |
| `HOLDER_SECRETS_KEY` | `dev-only-change-me` | Encryption key for the stored GitHub token |
| `HOLDER_API_URL` | `http://127.0.0.1:8080` | URL agents use to call back |
| `HOLDER_RUN_TIMEOUT` | `600` | Seconds of silence before a Pi run stops |
| `HOLDER_RUN_LIMIT` | `3600` | Longest a Pi run may last, in seconds |
| `HOLDER_DATA_DIR` | `api/runtime/holder-data` | Workspaces, repo checkouts, and session files |
| `HOLDER_BIN` | `bin/holder` | Agent CLI |
| `HOLDER_GIT` | `git` | Git binary |
| `HOLDER_GH` | `gh` | GitHub CLI binary |

Create the first owner yourself with:

```bash
php api/yii holder:bootstrap --email owner@holder.local --password owner --name Owner --company Holder --mission "Run the company."
```

The command leaves an existing user with that email in place. The API container runs it on startup.

## Tests

The heartbeat test uses `api/tests/fixtures/fake-pi.php`. The fixture speaks a small slice of Pi's RPC protocol and comments through `bin/holder`.

```bash
docker compose run --rm --entrypoint php api vendor/bin/codecept run
```

The test server uses port 8081, so it can run while the API on 8080 is up.
