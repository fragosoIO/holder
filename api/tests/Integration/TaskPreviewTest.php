<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\CompanyWorkspace;
use App\Domain\Github\GitClient;
use App\Domain\Github\RepoCheckout;
use App\Domain\Github\TokenCipher;
use App\Domain\HolderConfig;
use App\Domain\Identity\IdentityService;
use App\Domain\Ids;
use App\Domain\Org\OrgService;
use App\Domain\Org\PiProbe;
use App\Domain\Work\PreviewService;
use App\Domain\Work\PreviewToken;
use App\Domain\Work\WorkService;
use App\Infrastructure\Db;
use App\Shared\Env;
use Codeception\Test\Unit;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Pgsql\Connection;
use Yiisoft\Db\Pgsql\Driver;
use Yiisoft\Db\Pgsql\Dsn;

final class TaskPreviewTest extends Unit
{
    private ?string $companyId = null;

    private ?string $userId = null;

    private string $dataDir = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql is required.');
        }
        $this->dataDir = sys_get_temp_dir() . '/holder-preview-' . bin2hex(random_bytes(4));
        mkdir($this->dataDir);
    }

    protected function tearDown(): void
    {
        if ($this->companyId !== null && extension_loaded('pdo_pgsql')) {
            $this->connection()->createCommand(
                'DELETE FROM companies WHERE id = :id',
                [':id' => $this->companyId],
            )->execute();
        }
        if ($this->userId !== null && extension_loaded('pdo_pgsql')) {
            $this->connection()->createCommand(
                'DELETE FROM users WHERE id = :id',
                [':id' => $this->userId],
            )->execute();
        }
        $this->removeTree($this->dataDir);
        parent::tearDown();
    }

    public function testAGitHubTaskPreviewsItsWorktreeAndAFolderTaskPreviewsTheWorkspace(): void
    {
        [$preview, $userId, $checkout] = $this->preview();
        $taskId = $this->task($this->project());

        $missing = $preview->describe($userId, (string) $this->companyId, $taskId);
        $this->assertSame('no_checkout', $missing['state']);
        $this->assertSame('', $missing['revision']);
        $this->assertNull($missing['url']);
        $this->assertSame(0, $missing['expiresAt']);

        $worktree = $checkout->worktreeDirectory($taskId);
        mkdir($worktree, 0775, true);
        file_put_contents($worktree . '/index.html', '<h1>Berry</h1>');
        file_put_contents($worktree . '/styles.css', 'h1{}');

        $ready = $preview->describe($userId, (string) $this->companyId, $taskId);
        $this->assertSame('ready', $ready['state']);
        $this->assertNotSame('', $ready['revision']);
        $issued = (new PreviewToken('test-key'))->issue((string) $this->companyId, $taskId);
        $opened = $preview->open((string) $this->companyId, $taskId, $issued['token'], 'styles.css');
        $this->assertSame(realpath($worktree . '/styles.css'), $opened['path'] ?? null);

        $this->removeTree($worktree);
        $gone = $preview->describe($userId, (string) $this->companyId, $taskId);
        $this->assertSame('no_checkout', $gone['state']);

        $plain = $this->task(null);
        $empty = $preview->describe($userId, (string) $this->companyId, $plain);
        $this->assertSame('no_page', $empty['state']);
        $workspace = $this->dataDir . '/workspaces/' . $this->companyId;
        file_put_contents($workspace . '/index.html', '<h1>Folder</h1>');
        $page = $preview->describe($userId, (string) $this->companyId, $plain);
        $this->assertSame('ready', $page['state']);
    }

    public function testFinishingAGitHubTaskRemovesThePreview(): void
    {
        [$preview, $userId, $checkout] = $this->preview();
        $projectId = $this->project();
        $taskId = $this->task($projectId);
        $origin = $this->dataDir . '/origin';
        mkdir($origin);
        $this->git($origin, ['init', '-b', 'main']);
        file_put_contents($origin . '/README.md', 'origin');
        $this->git($origin, ['add', 'README.md']);
        $this->git($origin, ['-c', 'user.email=test@holder.local', '-c', 'user.name=Test', 'commit', '-m', 'init']);
        $checkout->cloneRepository($projectId, $origin, 'local-token');
        $prepared = $checkout->prepare($projectId, $taskId, $origin, 'main', 'local-token');
        file_put_contents($prepared['worktree'] . '/index.html', '<h1>Branch</h1>');
        $this->assertSame('ready', $preview->describe($userId, (string) $this->companyId, $taskId)['state']);

        $this->work()->updateTask($userId, (string) $this->companyId, $taskId, ['status' => 'done']);

        $this->assertSame('no_checkout', $preview->describe($userId, (string) $this->companyId, $taskId)['state']);
        $this->assertDirectoryDoesNotExist($prepared['worktree']);
    }

    /**
     * @return array{0: PreviewService, 1: string, 2: RepoCheckout}
     */
    private function preview(): array
    {
        $config = $this->config();
        $db = new Db($this->connection());
        $identity = new IdentityService($db, new CompanyWorkspace($config), new TokenCipher($config));
        $checkout = new RepoCheckout($config, new GitClient($config));
        $preview = new PreviewService($db, $identity, $config, new CompanyWorkspace($config), $checkout);
        $this->companyId = Ids::uuid();
        $this->userId = Ids::uuid();
        $db->exec(
            'INSERT INTO companies (id, name, mission) VALUES (:id, :name, :mission)',
            ['id' => $this->companyId, 'name' => 'Preview Co', 'mission' => ''],
        );
        $db->exec(
            'INSERT INTO users (id, name, email) VALUES (:id, :name, :email)',
            ['id' => $this->userId, 'name' => 'Preview Owner', 'email' => 'preview-' . $this->userId . '@holder.test'],
        );
        $db->exec(
            'INSERT INTO memberships (company_id, user_id, role) VALUES (:company_id, :user_id, :role)',
            ['company_id' => $this->companyId, 'user_id' => $this->userId, 'role' => 'owner'],
        );

        return [$preview, $this->userId, $checkout];
    }

    private function work(): WorkService
    {
        $config = $this->config();
        $db = new Db($this->connection());
        $identity = new IdentityService($db, new CompanyWorkspace($config), new TokenCipher($config));
        $checkout = new RepoCheckout($config, new GitClient($config));

        return new WorkService(
            $db,
            $identity,
            new OrgService($db, $identity, new PiProbe(), new CompanyWorkspace($config)),
            $checkout,
            new TokenCipher($config),
        );
    }

    private function project(): string
    {
        $id = Ids::uuid();
        (new Db($this->connection()))->exec(
            'INSERT INTO projects (id, company_id, name, workspace_path, repo_url, default_branch)
             VALUES (:id, :company_id, :name, :workspace_path, :repo_url, :default_branch)',
            [
                'id' => $id,
                'company_id' => $this->companyId,
                'name' => 'Site',
                'workspace_path' => '',
                'repo_url' => 'https://github.com/acme/site',
                'default_branch' => 'main',
            ],
        );

        return $id;
    }

    private function task(?string $projectId): string
    {
        $id = Ids::uuid();
        (new Db($this->connection()))->exec(
            'INSERT INTO tasks (id, company_id, project_id, title, description, status)
             VALUES (:id, :company_id, :project_id, :title, :description, :status)',
            [
                'id' => $id,
                'company_id' => $this->companyId,
                'project_id' => $projectId,
                'title' => 'Build the page',
                'description' => '',
                'status' => 'in_progress',
            ],
        );

        return $id;
    }

    private function config(): HolderConfig
    {
        return new HolderConfig(
            mode: 'authenticated',
            secretsKey: 'test-key',
            apiUrl: 'http://127.0.0.1:8081',
            dataDir: $this->dataDir,
            binPath: '/repo/bin/holder',
            runTimeoutSeconds: 60,
            runLimitSeconds: 120,
        );
    }

    private function connection(): ConnectionInterface
    {
        return new Connection(
            new Driver(
                new Dsn(
                    host: Env::get('HOLDER_DB_HOST', '127.0.0.1'),
                    databaseName: Env::get('HOLDER_DB_NAME', 'holder'),
                    port: Env::get('HOLDER_DB_PORT', '5432'),
                ),
                Env::get('HOLDER_DB_USER', 'holder'),
                Env::get('HOLDER_DB_PASSWORD', 'holder'),
            ),
            new SchemaCache(new ArrayCache()),
        );
    }

    /**
     * @param list<string> $args
     */
    private function git(string $directory, array $args): void
    {
        $command = ['git', '-C', $directory, ...$args];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertSame(0, $exit, $stdout . $stderr);
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || $path === '/') {
            return;
        }
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
