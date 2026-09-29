# Holder

Holder is a self-hosted control plane for a company of [Pi](https://pi.dev) agents. You set a mission, hire agents, give them goals and tasks, and a worker wakes Pi to do the work.

This repository is the first slice: identity and companies, an org of Pi agents, goals and tasks, and a heartbeat worker. Budgets, approvals, routines, skills, and company export come later.

Pi is the only agent runtime. Holder does not call a model provider itself. Provider, model, and API keys belong to Pi.

## Requirements

- Docker, for PostgreSQL and the API image
- Node.js 22 or newer, for the Vue UI and for Pi
- The `pi` command (`npm install -g @earendil-works/pi-coding-agent`) when you want a real agent rather than the test double

The API image installs PHP's `pdo_pgsql` extension. The PHP on this machine does not have that extension, so the API and the heartbeat worker run in Docker. A native worker, which can see the host `pi` binary directly, needs `php-pgsql` (`sudo pacman -S php-pgsql` on this OS).

## Run

```bash
cp api/.env.example api/.env
docker compose up --build
```

Holder joins the same external Docker network as the other local apps (`proxy`). Traefik routes these hosts to the UI, on HTTPS:

- https://holder.localhost
- https://holder.test
- https://holder.zixio.de
- https://holder.<dashed-ip>.traefik.me

`holder.localhost` has to be on the local mkcert certificate (`./mkcert-local.sh holder.localhost` in the Traefik project) or the browser will warn. The default install is local mode. On `holder.localhost` and `holder.test` the UI signs in as the bootstrap owner. On `holder.zixio.de` and the `traefik.me` names, sign in as `owner@holder.local` / `owner`. The owner password is `owner` if you switch `HOLDER_MODE` to `authenticated`.

The API stays on the internal network and is also published at http://127.0.0.1:8080. Postgres is at 127.0.0.1:5432. Inside Compose, the API reaches Postgres at `postgres` and agents call the API at `http://api:8080`. A host process still uses the `127.0.0.1` values in `api/.env`.

To run the UI on the host instead of in Compose, stop the `ui` service and use `npm run dev` in `ui/`. That dev server proxies to http://127.0.0.1:8080.

Each company gets a workspace under the Holder data directory when it is loaded. Hiring an agent uses that directory, and Pi runs there. `pi` must be on the image PATH. Create a goal and assign a task. The worker inside `docker compose up` does not start heartbeats by itself. Run one wakeup with:

```bash
docker compose run --rm --entrypoint php api ./yii heartbeat:work --once
```

Leave the worker running with `docker compose run --rm --entrypoint php api ./yii heartbeat:work`.

To use the host `pi` binary, install `php-pgsql`, stop the compose API, and run `php api/yii serve` and `php api/yii heartbeat:work` on the host. Postgres can stay in Docker.

## Tests

The heartbeat test uses `api/tests/fixtures/fake-pi.php`. It speaks a small slice of Pi's RPC protocol and comments through `bin/holder`. No model calls.

```bash
docker compose run --rm --entrypoint php api vendor/bin/codecept run
```

The test server uses port 8081, so it can run while the API on 8080 is up.
