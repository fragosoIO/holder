# GitHub repository connection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a company store one GitHub token, point a project at one `https://github.com/owner/repo` URL, clone it, and run each task in its own worktree so the agent can push `holder/<task id>` and open a pull request.

**Architecture:** A pure URL parser and a sodium cipher sit under the HTTP handlers. `GitClient` shells out to `git` and `gh` with an ask-pass script, never putting the token in a URL. Creating a project clones before inserting the row. A heartbeat for a task on that project fetches, adds or reuses a worktree, and starts Pi there with `GH_TOKEN` set. Marking the task done or cancelled removes the local worktree after the status commit.

**Tech Stack:** PHP 8.5 Yii3 API, PostgreSQL, sodium secretbox, Vue 3 + TypeScript, Codeception, `git` and the GitHub CLI.

**Spec:** `docs/superpowers/specs/2026-09-29-github-repo-design.md`

## Global Constraints

- Accepted repo URL after normalization: `https://github.com/<owner>/<repo>` with an optional trailing slash and one optional `.git` suffix. Host is exactly `github.com`. Reject userinfo, a port, a query, a fragment, and any extra path segment.
- Owner is 1–39 characters and repo is 1–100 characters of `[A-Za-z0-9._-]`. Neither is `.` or `..`.
- Store the canonical URL `https://github.com/<owner>/<repo>` with the original segment case and no `.git`.
- Token ciphertext is base64 of a 24-byte random nonce followed by `sodium_crypto_secretbox` output. The key is the raw SHA-256 of `HOLDER_SECRETS_KEY`.
- Company JSON always includes `githubConnected`, true only when `github_token` is non-null. The token never appears in a response, a prompt, a run row, or a run event.
- `PUT /api/v1/companies/{companyId}/github-token` is owner or admin only. Missing `token` is 422 `missing_field`. Trimmed empty stores null.
- A repository project is refused with 422 `github_token_missing` when the company has no token, 422 `invalid_repo_url` for a bad URL, 422 `git_unavailable` when `git --version` fails, and 422 `github_clone_failed` when clone or branch detection fails. Delete the clone directory and insert no row.
- Clone path is `{dataDir}/repos/{projectId}`. Worktree path is `{dataDir}/worktrees/{taskId}`. Branch is `holder/{taskId}`.
- The lock file is `{dataDir}/repos/{projectId}.lock`, beside the clone. `git clone` refuses a destination that already exists, so the lock cannot live inside the clone directory the spec named.
- Ask-pass is `bin/holder-git-askpass`. It prints `x-access-token` when the prompt contains `Username` in any case, and otherwise prints `$GH_TOKEN`.
- A repository run sets `GH_TOKEN`, `GITHUB_TOKEN`, `GIT_ASKPASS`, and `GIT_TERMINAL_PROMPT=0`. A company-folder run removes `GH_TOKEN`, `GITHUB_TOKEN`, and `GIT_ASKPASS` from the inherited environment.
- `holder.error` messages are exactly `github_token_missing`, `git_unavailable`, or `gh_unavailable`, or `github_clone_failed: ` plus redacted stderr. Pi does not start after any of those.
- Cleanup runs after the status transaction when the new status is `done` or `cancelled` and the previous status was different. Ignore cleanup failures. Do not delete the remote branch.
- Tests must not contact GitHub. `HOLDER_GIT` and `HOLDER_GH` select the binaries. Default names are `git` and `gh`.
- Migrations are one SQL statement per `execute()` call. Do not edit an already applied migration.
- UI typecheck is `./node_modules/.bin/vue-tsc -b` from `ui/`.
- Test command, from the repo root: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run <suite> <test>`. Add `-e HOLDER_GIT=/repo/api/tests/fixtures/fake-git -e HOLDER_GH=/repo/api/tests/fixtures/fake-gh` for every Api run from Task 5 onward.

## File structure

- Create `api/src/Domain/Github/GithubUrl.php` — accept or reject a URL and return the canonical form.
- Create `api/src/Domain/Github/TokenCipher.php` — seal and open the company token.
- Create `api/src/Domain/Github/GitClient.php` — run `git` and `gh`, attach ask-pass, redact secrets.
- Create `api/src/Domain/Github/RepoCheckout.php` — clone, fetch, worktree, and local removal.
- Create `bin/holder-git-askpass` — secret-free ask-pass script.
- Create `api/tests/fixtures/fake-git` and `api/tests/fixtures/fake-gh` — suite stubs.
- Create `api/src/Migration/M20260929120000AddGithub.php` — token and repo columns.
- Modify `api/src/Domain/Identity/IdentityService.php` — save the token and add `githubConnected`.
- Modify `api/src/Api/CompanyEndpoints.php` and `api/config/common/routes.php` — the PUT route.
- Modify `api/src/Domain/Work/WorkService.php` — clone on create, repo fields on project JSON, worktree cleanup.
- Modify `api/src/Domain/Onboarding/OnboardingService.php` — project JSON gains the new fields.
- Modify `api/src/Domain/Heartbeat/PromptBuilder.php` and `HeartbeatWorker.php` — repo prompt, cwd, and env.
- Modify `docker/php/Dockerfile` — install `git` and `gh`.
- Modify the Vue settings, projects, new-task, and task screens.

---

### Task 1: Parse a GitHub repository URL

**Files:**
- Create: `api/src/Domain/Github/GithubUrl.php`
- Test: `api/tests/Unit/GithubUrlTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `App\Domain\Github\GithubUrl::canonicalize(string $url): string` throws `HolderException` `invalid_repo_url` (422). Returns `https://github.com/<owner>/<repo>`.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Unit/GithubUrlTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Github\GithubUrl;
use App\Domain\HolderException;
use Codeception\Test\Unit;

final class GithubUrlTest extends Unit
{
    public function testAcceptsOwnerAndRepo(): void
    {
        $this->assertSame(
            'https://github.com/Acme/Widget',
            GithubUrl::canonicalize('https://github.com/Acme/Widget'),
        );
    }

    public function testStripsGitSuffixAndOneTrailingSlash(): void
    {
        $this->assertSame(
            'https://github.com/Acme/Widget',
            GithubUrl::canonicalize('https://github.com/Acme/Widget.git/'),
        );
    }

    /** @dataProvider rejectedUrls */
    public function testRejects(string $url): void
    {
        $this->expectException(HolderException::class);
        try {
            GithubUrl::canonicalize($url);
        } catch (HolderException $error) {
            $this->assertSame('invalid_repo_url', $error->errorCode);
            $this->assertSame(422, $error->status);
            throw $error;
        }
    }

    /** @return list<array{string}> */
    public function rejectedUrls(): array
    {
        return [
            ['git@github.com:Acme/Widget.git'],
            ['https://user:token@github.com/Acme/Widget'],
            ['https://github.com:443/Acme/Widget'],
            ['https://www.github.com/Acme/Widget'],
            ['https://github.com/Acme/Widget?ref=1'],
            ['https://github.com/Acme/Widget/tree/main'],
            ['https://github.com/Acme'],
            ['https://github.com/. /Widget'],
            ['https://github.com/Acme/..'],
            [''],
        ];
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Unit GithubUrlTest`

Expected: FAIL because `App\Domain\Github\GithubUrl` is not found.

- [ ] **Step 3: Write the parser**

Create `api/src/Domain/Github/GithubUrl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Github;

use App\Domain\HolderException;

final class GithubUrl
{
    public static function canonicalize(string $url): string
    {
        $parts = parse_url(trim($url));
        $scheme = is_array($parts) ? (string) ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $hasExtra = is_array($parts) && (
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])
        );
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        if (
            $hasExtra
            || strtolower($scheme) !== 'https'
            || strtolower($host) !== 'github.com'
            || count($segments) !== 2
        ) {
            throw new HolderException('invalid_repo_url', 'Repository URL must be https://github.com/owner/repo.', 422);
        }
        $repo = $segments[1];
        if (str_ends_with($repo, '.git')) {
            $repo = substr($repo, 0, -4);
        }
        if (!self::segment($segments[0], 39) || !self::segment($repo, 100)) {
            throw new HolderException('invalid_repo_url', 'Repository URL must be https://github.com/owner/repo.', 422);
        }

        return 'https://github.com/' . $segments[0] . '/' . $repo;
    }

    private static function segment(string $value, int $max): bool
    {
        return $value !== '.'
            && $value !== '..'
            && strlen($value) >= 1
            && strlen($value) <= $max
            && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Unit GithubUrlTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add api/src/Domain/Github/GithubUrl.php api/tests/Unit/GithubUrlTest.php
git commit -m "$(cat <<'EOF'
feat: parse a GitHub repository URL

EOF
)"
```

---

### Task 2: Encrypt the company token

**Files:**
- Create: `api/src/Domain/Github/TokenCipher.php`
- Test: `api/tests/Unit/TokenCipherTest.php`

**Interfaces:**
- Consumes: `HolderConfig::$secretsKey`
- Produces: `App\Domain\Github\TokenCipher` with `seal(string $token): string` and `open(string $payload): string`. `seal` returns base64. `open` reverses it. A tampered payload throws `RuntimeException`.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Unit/TokenCipherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Github\TokenCipher;
use App\Domain\HolderConfig;
use Codeception\Test\Unit;

final class TokenCipherTest extends Unit
{
    public function testRoundTripDoesNotStoreTheToken(): void
    {
        $cipher = new TokenCipher($this->config('test-secret'));
        $sealed = $cipher->seal('ghp_example');

        $this->assertStringNotContainsString('ghp_example', $sealed);
        $this->assertSame('ghp_example', $cipher->open($sealed));
    }

    public function testADifferentKeyCannotOpenThePayload(): void
    {
        $sealed = (new TokenCipher($this->config('one')))->seal('ghp_example');

        $this->expectException(\RuntimeException::class);
        (new TokenCipher($this->config('two')))->open($sealed);
    }

    private function config(string $key): HolderConfig
    {
        return new HolderConfig(
            mode: 'local',
            secretsKey: $key,
            apiUrl: 'http://127.0.0.1',
            dataDir: sys_get_temp_dir(),
            binPath: '/bin/holder',
            runTimeoutSeconds: 1,
            runLimitSeconds: 3600,
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Unit TokenCipherTest`

Expected: FAIL because `TokenCipher` is not found.

- [ ] **Step 3: Write the cipher**

Create `api/src/Domain/Github/TokenCipher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Github;

use App\Domain\HolderConfig;
use RuntimeException;

final class TokenCipher
{
    public function __construct(
        private readonly HolderConfig $config,
    ) {}

    public function seal(string $token): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($token, $nonce, $this->key());

        return base64_encode($nonce . $cipher);
    }

    public function open(string $payload): string
    {
        $raw = base64_decode($payload, true);
        $nonceBytes = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($raw === false || strlen($raw) <= $nonceBytes) {
            throw new RuntimeException('GitHub token payload is unreadable.');
        }
        $opened = sodium_crypto_secretbox_open(
            substr($raw, $nonceBytes),
            substr($raw, 0, $nonceBytes),
            $this->key(),
        );
        if ($opened === false) {
            throw new RuntimeException('GitHub token payload is unreadable.');
        }

        return $opened;
    }

    private function key(): string
    {
        return hash('sha256', $this->config->secretsKey, true);
    }
}
```

If the test fails with `sodium_crypto_secretbox` undefined, add `sodium` to the `docker-php-ext-install` line in `docker/php/Dockerfile` and rebuild the API image before rerunning.

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Unit TokenCipherTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add api/src/Domain/Github/TokenCipher.php api/tests/Unit/TokenCipherTest.php docker/php/Dockerfile
git commit -m "$(cat <<'EOF'
feat: encrypt a company GitHub token

EOF
)"
```

---

### Task 3: Save the company token

**Files:**
- Create: `api/src/Migration/M20260929120000AddGithub.php`
- Modify: `api/src/Domain/Identity/IdentityService.php`
- Modify: `api/src/Api/CompanyEndpoints.php`
- Modify: `api/config/common/routes.php`
- Test: `api/tests/Api/GithubTokenCest.php`

**Interfaces:**
- Consumes: `TokenCipher::seal` and `TokenCipher::open`
- Produces: `IdentityService::setGithubToken(string $userId, string $companyId, string $token): array` and company JSON field `githubConnected: bool`. Route `PUT /api/v1/companies/{companyId}/github-token`.

- [ ] **Step 1: Write the failing API test**

Create `api/tests/Api/GithubTokenCest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use App\Shared\Env;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertStringNotContainsString;

final readonly class GithubTokenCest
{
    public function theOwnerCanSaveAndClearAToken(ApiTester $I): void
    {
        [$token, $companyId] = $this->owner($I);

        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', ['token' => 'ghp_example']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['githubConnected' => true]]);
        assertStringNotContainsString('ghp_example', $I->grabResponse());

        $I->sendGET('/api/v1/companies/' . $companyId);
        $I->seeResponseContainsJson(['data' => ['githubConnected' => true]]);
        assertStringNotContainsString('ghp_example', $I->grabResponse());

        $I->sendPATCH('/api/v1/companies/' . $companyId, ['name' => 'Acme', 'mission' => 'Still shipping']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['githubConnected' => true, 'mission' => 'Still shipping']]);

        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', []);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error_data' => ['code' => 'missing_field']]);
        $stored = $this->githubTokenColumn($companyId);
        assertNotSame('ghp_example', $stored);
        assertStringNotContainsString('ghp_example', (string) $stored);
        $I->sendGET('/api/v1/companies/' . $companyId);
        $I->seeResponseContainsJson(['data' => ['githubConnected' => true]]);

        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', ['token' => '   ']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['githubConnected' => false]]);
        assertFalse($I->grabDataFromResponseByJsonPath('$.data.githubConnected')[0]);
    }

    public function aMemberCannotSaveAToken(ApiTester $I): void
    {
        [$owner, $companyId] = $this->owner($I);
        $email = 'member-' . uniqid() . '@holder.test';
        $I->sendPOST('/api/v1/companies/' . $companyId . '/invites', ['email' => $email, 'role' => 'member']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $invite = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/v1/invites/' . $invite . '/accept', ['name' => 'Member', 'password' => 'member-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->haveHttpHeader('Authorization', 'Bearer ' . $I->grabDataFromResponseByJsonPath('$.data.token')[0]);

        $I->sendPUT('/api/v1/companies/' . $companyId . '/github-token', ['token' => 'ghp_example']);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        unset($owner);
    }

    /** @return array{string, string} */
    private function owner(ApiTester $I): array
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/session', ['email' => 'owner@holder.test', 'password' => 'secret-pass']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $token = $I->grabDataFromResponseByJsonPath('$.data.token')[0];
        $companyId = $I->grabDataFromResponseByJsonPath('$.data.companies[0].id')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);

        return [$token, $companyId];
    }

    private function githubTokenColumn(string $companyId): ?string
    {
        $pdo = new \PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                Env::get('HOLDER_DB_HOST', '127.0.0.1'),
                Env::get('HOLDER_DB_PORT', '5432'),
                Env::get('HOLDER_DB_NAME', 'holder'),
            ),
            Env::get('HOLDER_DB_USER', 'holder'),
            Env::get('HOLDER_DB_PASSWORD', 'holder'),
        );
        $statement = $pdo->prepare('SELECT github_token FROM companies WHERE id = :id');
        $statement->execute(['id' => $companyId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }
}
```

`HolderExceptionMiddleware` renders the code at `error_data.code`. That is the assertion key above.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Api GithubTokenCest`

Expected: FAIL with 404 or a missing route for the PUT.

- [ ] **Step 3: Add the migration, service method, and route**

Create `api/src/Migration/M20260929120000AddGithub.php` with three separate `execute()` calls in `up` and three in `down`:

```php
$b->execute('ALTER TABLE companies ADD COLUMN github_token text');
$b->execute("ALTER TABLE projects ADD COLUMN repo_url text NOT NULL DEFAULT ''");
$b->execute("ALTER TABLE projects ADD COLUMN default_branch text NOT NULL DEFAULT ''");
```

Down drops `projects.default_branch`, `projects.repo_url`, then `companies.github_token`, each in its own `execute()`.

In `IdentityService::companyResource`, add `'githubConnected' => is_string($row['github_token'] ?? null) && $row['github_token'] !== ''`. Do not copy `github_token`.

Add `private readonly TokenCipher $tokens` to the `IdentityService` constructor. Yii autowires it. No test constructs `IdentityService` directly.

```php
public function setGithubToken(string $userId, string $companyId, string $token): array
{
    $membership = $this->requireMembership($userId, $companyId);
    $this->assertCanManage((string) $membership['role']);
    $token = trim($token);
    $stored = $token === '' ? null : $this->tokens->seal($token);
    $this->db->exec(
        'UPDATE companies SET github_token = :github_token WHERE id = :id',
        ['github_token' => $stored, 'id' => $companyId],
    );

    return $this->requireCompany($userId, $companyId);
}
```

Add `CompanyEndpoints::saveGithubToken`. When `array_key_exists('token', $body)` is false, throw `HolderException('missing_field', 'Token is required.', 422)`. Otherwise call `setGithubToken` with `(string) $body['token']`.

Register the route directly after the company PATCH route:

```php
Route::put('/companies/{companyId}/github-token')->action([CompanyEndpoints::class, 'saveGithubToken'])->name('companies/github-token'),
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Api GithubTokenCest`

Expected: PASS. Also run `Unit CompanyWorkspaceTest` and `Api OnboardingCest` because company JSON grew a field. Those tests use `seeResponseContainsJson` on subsets, so they should still pass.

- [ ] **Step 5: Commit**

```bash
git add api/src/Migration/M20260929120000AddGithub.php api/src/Domain/Identity/IdentityService.php api/src/Api/CompanyEndpoints.php api/config/common/routes.php api/tests/Api/GithubTokenCest.php
git commit -m "$(cat <<'EOF'
feat: save one GitHub token on the company

EOF
)"
```

---

### Task 4: Run git through ask-pass

**Files:**
- Create: `bin/holder-git-askpass`
- Create: `api/src/Domain/Github/GitClient.php`
- Create: `api/tests/fixtures/fake-git`
- Create: `api/tests/fixtures/fake-gh`
- Test: `api/tests/Unit/GitClientTest.php`

**Interfaces:**
- Consumes: `HolderConfig::$binPath` and `$dataDir`
- Produces: `GitClient::__construct(HolderConfig $config, ?string $gitBinary = null, ?string $ghBinary = null)`. Methods: `assertGit(): void`, `assertGh(): void`, `run(array $args, string $token): GitResult`. `GitResult` is a readonly class with `int $exit`, `string $stdout`, `string $stderr`. `run` throws `HolderException` `git_unavailable` when the binary cannot start. `redact(string $text, string $token): string` is public. Binaries come from the constructor, else `HOLDER_GIT` / `HOLDER_GH`, else `git` / `gh`.

- [ ] **Step 1: Write the failing test**

Create the fixtures and `api/tests/Unit/GitClientTest.php`.

`api/tests/fixtures/fake-git`:

```sh
#!/bin/sh
log="${HOLDER_FAKE_GIT_LOG:-/tmp/holder-fake-git.log}"
failfile="${HOLDER_FAKE_GIT_FAIL:-/tmp/holder-fake-git.fail}"
printf '%s\n' "$*" >> "$log"
mode=""
if [ -f "$failfile" ]; then
  mode=$(cat "$failfile")
fi
if [ "${1:-}" = "-C" ]; then
  shift 2
fi
cmd="${1:-}"
if [ "$cmd" = "--version" ]; then
  echo "git version 2.fake"
  exit 0
fi
if [ "$cmd" = "clone" ]; then
  if [ "$mode" = "clone" ]; then
    echo "fatal: repository not found" >&2
    if [ -n "${GH_TOKEN:-}" ]; then
      echo "token ${GH_TOKEN}" >&2
    fi
    exit 1
  fi
  for dest do :; done
  mkdir -p "$dest"
  exit 0
fi
if [ "$cmd" = "symbolic-ref" ]; then
  echo "origin/main"
  exit 0
fi
if [ "$cmd" = "fetch" ]; then
  if [ "$mode" = "fetch" ]; then
    echo "fatal: fetch failed" >&2
    exit 1
  fi
  exit 0
fi
if [ "$cmd" = "rev-parse" ]; then
  for ref do :; done
  if [ "$mode" = "local" ] && printf '%s' "$ref" | grep -q "refs/heads/"; then
    exit 0
  fi
  if [ "$mode" = "remote" ] && printf '%s' "$ref" | grep -q "refs/remotes/"; then
    exit 0
  fi
  exit 1
fi
if [ "$cmd" = "worktree" ]; then
  sub="${2:-}"
  if [ "$sub" = "list" ]; then
    grep "worktree add" "$log" | while IFS= read -r line; do
      path=""
      for word in $line; do
        case "$word" in
          /*) path=$word ;;
        esac
      done
      if [ -n "$path" ]; then
        printf 'worktree %s\n' "$path"
      fi
    done
    exit 0
  fi
  if [ "$sub" = "add" ]; then
    path="."
    for arg do
      case "$arg" in
        /*) path=$arg ;;
      esac
    done
    mkdir -p "$path"
    exit 0
  fi
  if [ "$sub" = "remove" ]; then
    if [ "$mode" = "remove" ]; then
      echo "fatal: worktree remove failed" >&2
      exit 1
    fi
    for dest do :; done
    rm -rf "$dest"
    exit 0
  fi
fi
if [ "$cmd" = "branch" ]; then
  exit 0
fi
echo "unexpected git command: $*" >&2
exit 1
```

`fake-gh`:

```sh
#!/bin/sh
if [ "${HOLDER_FAKE_GH_MISSING:-}" = "1" ]; then
  echo "gh: command not found" >&2
  exit 127
fi
echo "gh version 2.fake"
exit 0
```

`chmod +x` both fixture scripts. The git binary argument is the absolute fixture path, so this test does not depend on `HOLDER_GIT`.

`GitClientTest` uses a temp dir as `dataDir` and `binPath` set to `{temp}/bin/holder` after copying `bin/holder-git-askpass` into `{temp}/bin`. Pass `dirname(__DIR__) . '/fixtures/fake-git'` as the git binary. Assert:

- `assertGit()` does not throw.
- `new GitClient($config, '/no/such/git', null)->assertGit()` throws `HolderException` with message `git_unavailable`.
- `run(['clone', '--origin', 'origin', 'https://github.com/Acme/Widget', $dest], 'ghp_secret')` creates `$dest`. The log contains the URL and does not contain `ghp_secret`.
- After writing `clone` to the fail file, `run(...)` throws `HolderException` with `errorCode` `github_clone_failed` and message `github_clone_failed: fatal: repository not found`. The message does not contain `ghp_secret`.
- With `HOLDER_FAKE_GH_MISSING=1` and the gh binary set to `fake-gh`, `assertGh()` throws `HolderException` with message `gh_unavailable`. Restore the env var in `finally`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Unit GitClientTest`

Expected: FAIL because `GitClient` is not found.

- [ ] **Step 3: Write ask-pass and GitClient**

`bin/holder-git-askpass`:

```sh
#!/bin/sh
case "${1:-}" in
  *[Uu]sername*) printf '%s\n' "x-access-token" ;;
  *) printf '%s\n' "${GH_TOKEN:-}" ;;
esac
```

`chmod +x bin/holder-git-askpass`.

`GitClient::run` uses `proc_open` with an argument array, not a shell. The environment is the current environment plus `GH_TOKEN`, `GIT_ASKPASS` = `dirname($config->binPath) . '/holder-git-askpass'`, and `GIT_TERMINAL_PROMPT=0`. Capture stdout and stderr. On a start failure, throw `HolderException('git_unavailable', 'git_unavailable', 422)`.

`assertGit` runs `[$git, '--version']` and throws `git_unavailable` when exit is not 0.

`assertGh` runs `[$gh, '--version']` and throws `HolderException('gh_unavailable', 'gh_unavailable', 422)` when exit is not 0.

When a git command exits non-zero, throw `HolderException('github_clone_failed', 'github_clone_failed: ' . $this->oneLine($this->redact($stderr, $token)), 422)`.

`redact` replaces the token with `[redacted]` and replaces `https://<userinfo>@` with `https://`. `oneLine` keeps the first line and trims it.

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Unit GitClientTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add bin/holder-git-askpass api/src/Domain/Github/GitClient.php api/tests/fixtures/fake-git api/tests/fixtures/fake-gh api/tests/Unit/GitClientTest.php
git commit -m "$(cat <<'EOF'
feat: run git with an ask-pass script

EOF
)"
```

---

### Task 5: Clone a repository when a project is created

**Files:**
- Create: `api/src/Domain/Github/RepoCheckout.php`
- Modify: `api/src/Domain/Work/WorkService.php` (`createProject`, `listProjects`)
- Modify: `api/src/Domain/Onboarding/OnboardingService.php` (`bundle`)
- Test: `api/tests/Api/GithubProjectCest.php`
**Interfaces:**
- Consumes: `GithubUrl::canonicalize`, `TokenCipher::open`, `GitClient`
- Produces: `RepoCheckout::directory(string $projectId): string` returns `{dataDir}/repos/{projectId}`. `RepoCheckout::cloneRepository(string $projectId, string $canonicalUrl, string $token): string` returns the default branch and leaves the clone at that directory. Project JSON gains `repoUrl` and `defaultBranch`. `fake-git` is the script from Task 4.

- [ ] **Step 1: Write the failing API test**

Create `api/tests/Api/GithubProjectCest.php` with the owner helper from `GithubTokenCest`. Tests:

- No token and body `{"name":"Api","repoUrl":"https://github.com/Acme/Widget.git"}` returns 422 `github_token_missing`. GET projects does not contain `Api`.
- Token saved, body `{"name":"Bad","repoUrl":"git@github.com:Acme/Widget.git"}` returns 422 `invalid_repo_url`.
- Write `clone` into `/tmp/holder-fake-git.fail`, save the token, POST the valid URL, expect 422 `github_clone_failed`, and assert the response does not contain the token. Delete the fail file in `finally`. GET projects does not contain that project name.
- Delete the fail file. POST `{"name":"Widget","repoUrl":"https://github.com/Acme/Widget.git/"}`. Expect 200, `repoUrl` `https://github.com/Acme/Widget`, `defaultBranch` `main`, and `workspacePath` ending in `/repos/{id}`. `assertDirectoryExists` on that path.
- POST `{"name":"Local","workspacePath":"/tmp/local-only"}` without `repoUrl`. Expect `repoUrl` `""`, `defaultBranch` `""`, and `workspacePath` `/tmp/local-only`.

Run this suite with `HOLDER_GIT=/repo/api/tests/fixtures/fake-git` and `HOLDER_GH=/repo/api/tests/fixtures/fake-gh`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test -e HOLDER_GIT=/repo/api/tests/fixtures/fake-git -e HOLDER_GH=/repo/api/tests/fixtures/fake-gh --entrypoint php api vendor/bin/codecept run Api GithubProjectCest`

Expected: FAIL because the project response has no `repoUrl` or clone is not attempted.

- [ ] **Step 3: Clone before insert**

`RepoCheckout::cloneRepository` does not take the lock. `prepare` and `remove` take it, and `prepare` calls `cloneRepository` while that lock is already held. A second `flock` on the same file in this process would wait forever.

1. `assertGit()`.
2. `$dest = dataDir/repos/{projectId}`. If it exists, delete it recursively.
3. `run(['clone', '--origin', 'origin', $canonicalUrl, $dest], $token)`.
4. Add `GitClient::capture(array $args, string $token): GitResult`, which returns stdout, stderr, and the exit code instead of throwing. `$result = capture(['-C', $dest, 'symbolic-ref', '--short', 'refs/remotes/origin/HEAD'], $token)`. On non-zero, delete `$dest` and throw `github_clone_failed` with the redacted first stderr line.
5. Return the branch name after stripping a leading `origin/`.

On any exception after the directory was created, delete `$dest` and rethrow.

`WorkService::createProject` gains `RepoCheckout` and `TokenCipher` via the constructor. Yii autowires both. When `repoUrl` is blank, keep the current insert. When it is not:

1. Choose `$id` with `Ids::uuid()` before the clone.
2. `$canonical = GithubUrl::canonicalize($repoUrl)`.
3. Load `github_token` for the company. Empty or null throws `github_token_missing` before clone.
4. `$branch = $this->checkout->cloneRepository($id, $canonical, $this->cipher->open($stored))`. Name the `TokenCipher` property `$cipher`.
5. Insert with `workspace_path` = `{dataDir}/repos/{id}`, `repo_url` = `$canonical`, and `default_branch` = `$branch`. `cloneRepository` must also return that path, or `createProject` builds the same path from `HolderConfig::$dataDir`. Use one helper, `RepoCheckout::directory(string $projectId): string`, for both.

`listProjects`, `createProject`'s return array, and `OnboardingService::bundle` include `repoUrl` and `defaultBranch`. Read the new columns. Existing rows default to empty strings.

- [ ] **Step 4: Run the test to verify it passes**

Run the same `GithubProjectCest` command.

Expected: PASS. Then run `Api OnboardingCest` with the same `HOLDER_GIT` and `HOLDER_GH` values. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/src/Domain/Github/RepoCheckout.php api/src/Domain/Github/GitClient.php api/src/Domain/Work/WorkService.php api/src/Domain/Onboarding/OnboardingService.php api/tests/Api/GithubProjectCest.php api/tests/fixtures/fake-git
git commit -m "$(cat <<'EOF'
feat: clone a GitHub repository onto a project

EOF
)"
```

---

### Task 6: Prepare and remove a task worktree

**Files:**
- Modify: `api/src/Domain/Github/RepoCheckout.php`
- Test: `api/tests/Unit/RepoCheckoutTest.php`

**Interfaces:**
- Consumes: `GitClient::run`, `GitClient::capture`, `GitClient::assertGit`
- Produces: `RepoCheckout::prepare(string $projectId, string $taskId, string $repoUrl, string $defaultBranch, string $token): array{worktree: string, defaultBranch: string}`. `RepoCheckout::remove(string $projectId, string $taskId): void`. `prepare` throws `HolderException` whose message is `github_token_missing` when `$token` is `''`. When the clone directory is missing, `prepare` clones again and returns the branch read from `origin/HEAD`. Otherwise it returns the `$defaultBranch` argument unchanged.

- [ ] **Step 1: Write the failing test**

`RepoCheckoutTest` points `GitClient` at `fake-git`. Use a temp `dataDir`. Truncate the log and fail file in `setUp`.

- `prepare` with an empty token throws message `github_token_missing` and does not write the log.
- `prepare('project', 'task', 'https://github.com/Acme/Widget', 'main', 'ghp_secret')` returns `worktree` `{dataDir}/worktrees/task` and `defaultBranch` `main`. The directory exists, and the log contains `worktree add -b holder/task` and `origin/main`. Delete the clone directory and call `prepare` again. The returned `defaultBranch` is still `main`, and the log contains `clone`.
- A second `prepare` for the same ids does not append another `worktree add`.
- Fail file `local`: the log contains `worktree add ` and the path, and does not contain `-b holder/`.
- Fail file `remote`: the log contains `worktree add -b holder/task` and `origin/holder/task`.
- `remove` appends `worktree remove --force` and `branch -D holder/task`.
- Fail file `remove`: `remove` does not throw.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Unit RepoCheckoutTest`

Expected: FAIL because `prepare` is not defined.

- [ ] **Step 3: Implement prepare and remove**

`prepare` and `remove` open `{dataDir}/repos/{projectId}.lock` with `flock` `LOCK_EX` and hold it until they return. `prepare` may call `cloneRepository` while holding that lock. `cloneRepository` must not lock again.

`prepare`:

1. Throw `HolderException('github_token_missing', 'github_token_missing', 422)` when `$token === ''`.
2. If the clone directory is missing, call the same clone steps as `cloneRepository` for `$repoUrl`.
3. `run(['-C', $clone, 'fetch', 'origin'], $token)`.
4. If `capture(['-C', $clone, 'worktree', 'list', '--porcelain'], $token)->stdout` contains `worktree {worktree}`, return that path.
5. If `capture(['-C', $clone, 'rev-parse', '--verify', '--quiet', 'refs/heads/holder/'.$taskId], $token)->exit === 0`, `run(['-C', $clone, 'worktree', 'add', $worktree, 'holder/'.$taskId], $token)`.
6. Else if the remote ref `refs/remotes/origin/holder/{taskId}` verifies, `run(['-C', $clone, 'worktree', 'add', '-b', 'holder/'.$taskId, $worktree, 'origin/holder/'.$taskId], $token)`.
7. Else `run(['-C', $clone, 'worktree', 'add', '-b', 'holder/'.$taskId, $worktree, 'origin/'.$defaultBranch], $token)`.
8. Return `['worktree' => $worktree, 'defaultBranch' => $branchUsed]`. `$branchUsed` is the branch just read from `origin/HEAD` when this call had to clone, and the `$defaultBranch` argument otherwise.

`remove` runs `worktree remove --force` and `branch -D`. Swallow `HolderException` from either command.

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Unit RepoCheckoutTest`

Expected: PASS. Re-run `Unit GitClientTest` and `Api GithubProjectCest` with the fake-git env. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/src/Domain/Github/RepoCheckout.php api/tests/Unit/RepoCheckoutTest.php
git commit -m "$(cat <<'EOF'
feat: give each task its own git worktree

EOF
)"
```

---

### Task 7: Tell the agent which branch to push

**Files:**
- Modify: `api/src/Domain/Heartbeat/PromptBuilder.php`
- Test: `api/tests/Unit/PromptBuilderTest.php`

**Interfaces:**
- Consumes: the existing `build` arguments
- Produces: optional last argument `?array $repository = null`. Shape when present: `repoUrl`, `branch`, `defaultBranch`, and `reviewBranches` as `list<string>`. Existing callers stay valid.

- [ ] **Step 1: Write the failing tests**

Add three methods to `PromptBuilderTest`. Copy the company, task, and agent arrays from `testPromptCarriesMissionGoalAndTask`.

`testRepositoryPromptTellsTheAgentToOpenAPullRequest` passes this repository:

```php
[
    'repoUrl' => 'https://github.com/Acme/Widget',
    'branch' => 'holder/task-1',
    'defaultBranch' => 'main',
    'reviewBranches' => [],
]
```

Assert the prompt contains `Repository: https://github.com/Acme/Widget`, `holder/task-1`, `git push -u origin holder/task-1`, `gh pr create`, `main` as the base, `Do not push main`, `Do not force-push`, `gh pr view`, and `Do not print GH_TOKEN or GITHUB_TOKEN`. Assert it does not contain `ghp_`.

`testReviewPromptNamesChildBranches` uses wake reason `review` and `reviewBranches` `['holder/child-1']`. Assert it contains `holder/child-1`, `Do not open a new pull request`, and `Do not print GH_TOKEN`. Assert it does not contain `gh pr create`.

`testACompanyFolderPromptIsUnchanged` calls `build` with no repository argument and asserts `assertStringNotContainsString('gh pr create', $prompt)`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Unit PromptBuilderTest`

Expected: FAIL because the new strings are absent. Existing methods still pass.

- [ ] **Step 3: Append the repository lines**

Add `?array $repository = null` at the end of `build`. Just before `return implode`, when `$repository` is an array and `$wakeReason === 'review'`, append:

```text
Finished subtask branches:
- {each reviewBranches entry}
Read the pull request URL in the comments. Do not open a new pull request.
Do not print GH_TOKEN or GITHUB_TOKEN.
```

When `$repository` is an array and the wake reason is anything else, append:

```text
Repository: {repoUrl}
You are on branch {branch}, branched from {defaultBranch}.
Commit your work on this branch. Push it with: git push -u origin {branch}
Open a pull request with gh pr create. Use {defaultBranch} as the base, {branch} as the head, and the task title as the pull request title. Write the body from the work you did.
Put the pull request URL in a holder comment.
Do not push {defaultBranch}. Do not force-push.
If gh pr view already shows a pull request for this branch, push new commits and leave that pull request in place.
Do not print GH_TOKEN or GITHUB_TOKEN.
```

Use the task title already in `$task['title']`. Do not accept a token in `$repository`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test --entrypoint php api vendor/bin/codecept run Unit PromptBuilderTest`

Expected: PASS, including the existing prompt tests.

- [ ] **Step 5: Commit**

```bash
git add api/src/Domain/Heartbeat/PromptBuilder.php api/tests/Unit/PromptBuilderTest.php
git commit -m "$(cat <<'EOF'
feat: tell a repository run which branch to push

EOF
)"
```

---

### Task 8: Run a repository task in its worktree

**Files:**
- Modify: `api/src/Domain/Heartbeat/HeartbeatWorker.php`
- Create: `api/tests/fixtures/record-pi`
- Test: `api/tests/Api/GithubHeartbeatCest.php`

**Interfaces:**
- Consumes: `RepoCheckout::prepare`, `GitClient::assertGh`, `PromptBuilder::build` repository argument, `TokenCipher::open`
- Produces: repository heartbeats start Pi with cwd `{dataDir}/worktrees/{taskId}` and `GH_TOKEN` set to the company token. The stored prompt contains the branch and not the token. Subtasks already copy `project_id` in `WorkService::assign`. Leave that insert as it is. `prepare` uses the child task id, so the child gets its own worktree.

- [ ] **Step 1: Write the failing API test**

`record-pi` is executable:

```sh
#!/bin/sh
pwd > "${FAKE_PI_CWD_FILE:-/tmp/holder-pi-cwd}"
env > "${FAKE_PI_ENV_FILE:-/tmp/holder-pi-env}"
exec php "$(dirname "$0")/fake-pi.php" "$@"
```

`GithubHeartbeatCest` signs in as the owner, saves token `ghp_example`, creates the project `https://github.com/Acme/Widget`, hires an agent whose `piBinary` is `/repo/api/tests/fixtures/record-pi`, and creates a task with `projectId` set to that project and that assignee. Truncate `/tmp/holder-fake-git.log`. `putenv('GH_TOKEN=leaked-from-worker')` around the worker, then `php ./yii heartbeat:work --once`.

Assert the task's run prompt contains `holder/{taskId}` and `gh pr create`, and does not contain `ghp_example`. Assert `/tmp/holder-pi-cwd` is `{dataDir}/worktrees/{taskId}` from the project response's sibling path: replace `/repos/{projectId}` with `/worktrees/{taskId}`. Assert `/tmp/holder-pi-env` contains `GH_TOKEN=ghp_example` and does not contain `leaked-from-worker`.

Run `heartbeat:work --once` again. Assert the log file contains `worktree add` once.

Create a second task with no `projectId`, assign it, and run the worker once. Assert that run's cwd ends with `/workspaces/{companyId}` and its env file does not contain `GH_TOKEN` or `leaked-from-worker`.

A third test sets `putenv('HOLDER_FAKE_GH_MISSING=1')` before the worker for a repository task. The run status is `failed`, an event payload message is `gh_unavailable`, and `/tmp/holder-pi-cwd` was not rewritten by this run. Clear the env var in `finally`.

Use the fake-git and fake-gh env on the codecept command. The worker inherits them.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm -e HOLDER_DB_NAME=holder_test -e HOLDER_GIT=/repo/api/tests/fixtures/fake-git -e HOLDER_GH=/repo/api/tests/fixtures/fake-gh --entrypoint php api vendor/bin/codecept run Api GithubHeartbeatCest`

Expected: FAIL because Pi still starts in the company workspace and the prompt has no `gh pr create`.

- [ ] **Step 3: Wire the heartbeat**

Inject `RepoCheckout` and `TokenCipher` into `HeartbeatWorker`.

After the company row is loaded, if the task's `project_id` is non-null, load that project. When `repo_url` is non-empty, build:

```php
$repository = [
    'repoUrl' => (string) $project['repo_url'],
    'branch' => 'holder/' . $task['id'],
    'defaultBranch' => (string) $project['default_branch'],
    'reviewBranches' => $this->reviewBranches($companyId, (string) $task['id'], (string) $wakeup['reason']),
];
```

`reviewBranches` returns `[]` unless the reason is `review`. Otherwise it returns `holder/{id}` for each done task whose `parent_id` is this task, ordered by `created_at`.

Pass `$repository` into `build`. Insert the run row as today. Then, before `childEnv`:

```php
try {
    if ($repository !== null) {
        $token = $this->openGithubToken((string) $company['github_token']);
        $prepared = $this->checkout->prepare(
            (string) $project['id'],
            (string) $task['id'],
            (string) $project['repo_url'],
            (string) $project['default_branch'],
            $token,
        );
        $workspace = $prepared['worktree'];
        if ($prepared['defaultBranch'] !== (string) $project['default_branch']) {
            $this->db->exec(
                'UPDATE projects SET default_branch = :default_branch WHERE id = :id',
                ['default_branch' => $prepared['defaultBranch'], 'id' => $project['id']],
            );
        }
        $this->git->assertGh();
    }
} catch (Throwable $error) {
    $this->record($runId, $companyId, 'holder.error', ['message' => (new PiRunOutcome())->summarize($error->getMessage())]);
    $this->finishRun($runId, 'failed', null);
    return;
}
```

`openGithubToken` returns `''` when the column is null. `prepare` then throws `github_token_missing`. Add `GitClient` to the worker constructor as `$this->git`.

Change `childEnv` to accept `?string $githubToken`. After copying the parent environment, unset `GH_TOKEN`, `GITHUB_TOKEN`, and `GIT_ASKPASS`. When `$githubToken` is a non-empty string, set `GH_TOKEN` and `GITHUB_TOKEN` to it, `GIT_ASKPASS` to `dirname($this->config->binPath) . '/holder-git-askpass'`, and `GIT_TERMINAL_PROMPT` to `0`. Pass null for a company-folder run.

`pi->start` already receives `$workspace`.

- [ ] **Step 4: Run the test to verify it passes**

Run the `GithubHeartbeatCest` command from Step 2.

Expected: PASS. Then run `Api HeartbeatCest` with the same fake-git env. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/src/Domain/Heartbeat/HeartbeatWorker.php api/tests/fixtures/record-pi api/tests/Api/GithubHeartbeatCest.php
git commit -m "$(cat <<'EOF'
feat: run repository tasks in a git worktree

EOF
)"
```

---

### Task 9: Remove the worktree when the task is finished

**Files:**
- Modify: `api/src/Domain/Work/WorkService.php` (`updateTask`, `agentStatus`)
- Test: `api/tests/Api/GithubHeartbeatCest.php`

**Interfaces:**
- Consumes: `RepoCheckout::remove(string $projectId, string $taskId): void`
- Produces: a transition into `done` or `cancelled` from a different status calls `remove` after the transaction. A failing remove still leaves the task in the requested status.

- [ ] **Step 1: Write the failing test**

Add `finishingARepositoryTaskRemovesTheWorktree` to `GithubHeartbeatCest`. Create a connected project and a task on it. Do not wait for a heartbeat. PATCH the task to `done`. Assert `/tmp/holder-fake-git.log` contains `worktree remove --force` and `branch -D holder/{taskId}`, and the task status is `done`.

Add `aFailedRemovalStillMarksTheTaskDone`. Write `remove` into `/tmp/holder-fake-git.fail` before the PATCH. Assert status `done` and response code 200. Delete the fail file in `finally`.

- [ ] **Step 2: Run the test to verify it fails**

Run the `GithubHeartbeatCest` command from Task 8.

Expected: FAIL because the log has no `worktree remove`.

- [ ] **Step 3: Call remove after the status transaction**

`WorkService` already receives `RepoCheckout` from Task 5. After the transaction in both `updateTask` and `agentStatus`:

```php
$this->releaseWorktree($companyId, $taskId, $previousStatus, $status);
```

```php
private function releaseWorktree(string $companyId, string $taskId, string $previous, string $status): void
{
    if (($status !== 'done' && $status !== 'cancelled') || $previous === $status) {
        return;
    }
    $task = $this->requireTaskRow($companyId, $taskId);
    if ($task['project_id'] === null) {
        return;
    }
    $project = $this->db->one(
        'SELECT repo_url FROM projects WHERE id = :id AND company_id = :company_id',
        ['id' => $task['project_id'], 'company_id' => $companyId],
    );
    if ($project === null || (string) $project['repo_url'] === '') {
        return;
    }
    $this->checkout->remove((string) $task['project_id'], $taskId);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run `Api GithubHeartbeatCest` and `Api BlockerCest` with the fake-git env.

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add api/src/Domain/Work/WorkService.php api/tests/Api/GithubHeartbeatCest.php
git commit -m "$(cat <<'EOF'
feat: remove a task worktree when the task finishes

EOF
)"
```

---

### Task 10: Install git and gh in the API image

**Files:**
- Modify: `docker/php/Dockerfile`

**Interfaces:**
- Consumes: nothing from the PHP classes
- Produces: the `api` image has `git` and `gh` on `PATH`.

- [ ] **Step 1: Extend the existing apt install**

Replace the `apt-get install` line so the same `RUN` also installs `git`, `ca-certificates`, and `curl`, adds GitHub's official apt source, installs `gh`, and still deletes `/var/lib/apt/lists/*`. Keep `libpq-dev` and `docker-php-ext-install pdo_pgsql`.

```dockerfile
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev git ca-certificates curl \
    && docker-php-ext-install pdo_pgsql \
    && curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg -o /usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" > /etc/apt/sources.list.d/github-cli.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends gh \
    && rm -rf /var/lib/apt/lists/*
```

- [ ] **Step 2: Build and check the binaries**

Run:

```bash
docker compose build api
docker compose run --rm --entrypoint git api --version
docker compose run --rm --entrypoint gh api --version
```

Expected: both commands print a version and exit 0.

- [ ] **Step 3: Commit**

```bash
git add docker/php/Dockerfile
git commit -m "$(cat <<'EOF'
feat: install git and the GitHub CLI in the API image

EOF
)"
```

---

### Task 11: Settings, projects, and the task sidebar

**Files:**
- Modify: `ui/src/types.ts`
- Modify: `ui/src/views/SettingsView.vue`
- Create: `ui/src/views/ProjectsView.vue`
- Modify: `ui/src/router.ts`
- Modify: `ui/src/composables/workspace.ts`
- Modify: `ui/src/components/NewTaskDialog.vue`
- Modify: `ui/src/views/TaskView.vue`

**Interfaces:**
- Consumes: `githubConnected` on `Company`, `repoUrl` and `defaultBranch` on project JSON, `projectId` on `Task`
- Produces: the screens described in the spec's UI section

- [ ] **Step 1: Add the types**

On `Company`, add `githubConnected: boolean`. Add:

```ts
export type Project = {
  id: string
  companyId: string
  name: string
  workspacePath: string
  repoUrl: string
  defaultBranch: string
}
```

- [ ] **Step 2: Add the GitHub block to settings**

In `SettingsView.vue`, keep the name, mission, workspace line, and their Save button. Under the workspace line, add a block that shows `GitHub token saved` when `company.githubConnected` is true and `No GitHub token` otherwise. A password input uses `v-model="githubToken"` and starts as `''`. Its Save handler PUTs `{ token: githubToken }` to `/api/v1/companies/${company.id}/github-token`, then calls `board.load()` and clears the field. Show Clear only when `githubConnected` is true. Clear PUTs `{ token: '' }` and reloads. Do not include `githubToken` in the name and mission PATCH. Show the request error in the existing alert paragraph.

- [ ] **Step 3: Replace the projects empty state**

`ProjectsView.vue` loads `GET /api/v1/companies/${company.id}/projects` on mount and when the company id changes. Render each project name. When `repoUrl` is non-empty, show it as `<a :href="project.repoUrl" target="_blank" rel="noopener">` plus the default branch. Otherwise show `Company folder`.

The create form has `name` and `repoUrl`. When `repoUrl` is non-empty and `company.githubConnected` is false, disable the submit button and link to `/company/settings` with the text `Add a GitHub token in Settings first.` Submit POSTs `{ name, repoUrl }` when the URL is non-empty, otherwise `{ name, workspacePath: '' }`. The button label is `Cloning…` while saving with a URL, and `Saving…` while saving without one. On success, clear the fields and reload the list. On failure, show `failureMessage`.

Point `/projects` at `ProjectsView` in `router.ts`. Leave the other empty routes alone.

- [ ] **Step 4: Let a new task pick a project**

`useWorkspace` also fetches `Project[]` from the projects route and returns `projects`. `NewTaskDialog` adds a Project select bound to `projectId`, default `''`, with an option `No project`. Each other option's label is `project.name`, and when `repoUrl` is non-empty, `project.name + ' · ' + project.repoUrl`. Include `projectId` in the POST body only when it is non-empty.

In `TaskView.vue`, find the project with `projects.value.find((item) => item.id === task.projectId)`. In the sidebar, under the goal line, show `Project · {name}` when a project exists. When that project has a `repoUrl`, show the link and a mono line `holder/{task.id}`.

- [ ] **Step 5: Typecheck**

Run: `cd ui && ./node_modules/.bin/vue-tsc -b`

Expected: exit 0.

- [ ] **Step 6: Check the screens in the browser**

Open the running UI. On Settings, save a token and confirm the status line changes to `GitHub token saved` and the password field is empty afterward. Clear it and confirm `No GitHub token`. On Projects, with no token, type a GitHub URL and confirm Save is disabled and the Settings link is present. Save a token, create a project with `https://github.com/cli/cli`, and confirm the list shows the canonical link and a branch. Create a task on that project and confirm the sidebar shows the project name, the repo link, and `holder/` plus the task id. Create a task with no project and confirm the sidebar does not show a branch. Check the projects page and the task page at a narrow viewport and at a desktop width.

If `https://holder.localhost` is not up, start it with `docker compose up` and use that host. A failed real clone shows the API error on the form; fix the request or the token and try once more. Do not claim the screen works without completing these actions.

- [ ] **Step 7: Commit**

```bash
git add ui/src/types.ts ui/src/views/SettingsView.vue ui/src/views/ProjectsView.vue ui/src/router.ts ui/src/composables/workspace.ts ui/src/components/NewTaskDialog.vue ui/src/views/TaskView.vue
git commit -m "$(cat <<'EOF'
feat: connect a project to a GitHub repository from the UI

EOF
)"
```
