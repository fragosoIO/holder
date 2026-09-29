<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Github\GitClient;
use App\Domain\Github\RepoCheckout;
use App\Domain\HolderConfig;
use App\Domain\HolderException;
use Codeception\Test\Unit;

final class RepoCheckoutTest extends Unit
{
    private string $root;

    private string $log;

    private string $failFile;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function _before(): void
    {
        $this->root = sys_get_temp_dir() . '/holder-repo-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/bin', 0777, true);
        $askpass = dirname(__DIR__, 3) . '/bin/holder-git-askpass';
        if (!copy($askpass, $this->root . '/bin/holder-git-askpass')) {
            $this->fail('Could not copy holder-git-askpass.');
        }
        chmod($this->root . '/bin/holder-git-askpass', 0755);

        $this->log = $this->root . '/git.log';
        $this->failFile = $this->root . '/git.fail';
        file_put_contents($this->log, '');
        file_put_contents($this->failFile, '');

        foreach (['HOLDER_FAKE_GIT_LOG', 'HOLDER_FAKE_GIT_FAIL'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
        $this->setEnv('HOLDER_FAKE_GIT_LOG', $this->log);
        $this->setEnv('HOLDER_FAKE_GIT_FAIL', $this->failFile);
    }

    protected function _after(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        $this->removeTree($this->root);
    }

    public function testPrepareWithAnEmptyTokenThrowsAndDoesNotWriteTheLog(): void
    {
        try {
            $this->checkout()->prepare('project', 'task', 'https://github.com/Acme/Widget', 'main', '');
            $this->fail('Expected HolderException was not thrown.');
        } catch (HolderException $error) {
            $this->assertSame('github_token_missing', $error->getMessage());
            $this->assertSame('github_token_missing', $error->errorCode);
            $this->assertSame(422, $error->status);
        }

        $this->assertSame('', (string) file_get_contents($this->log));
    }

    public function testPrepareCreatesAWorktreeAndReclonesWhenTheCloneIsGone(): void
    {
        $checkout = $this->checkout();
        $worktree = $this->root . '/worktrees/task';

        $created = $checkout->prepare('project', 'task', 'https://github.com/Acme/Widget', 'main', 'ghp_secret');

        $this->assertSame(['worktree' => $worktree, 'defaultBranch' => 'main'], $created);
        $this->assertDirectoryExists($worktree);
        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('worktree add -b holder/task', $log);
        $this->assertStringContainsString('origin/main', $log);

        $before = (string) file_get_contents($this->log);
        $this->removeTree($checkout->directory('project'));
        $again = $checkout->prepare('project', 'task', 'https://github.com/Acme/Widget', 'main', 'ghp_secret');

        $this->assertSame('main', $again['defaultBranch']);
        $this->assertSame($worktree, $again['worktree']);
        $this->assertStringContainsString('clone', substr((string) file_get_contents($this->log), strlen($before)));
    }

    public function testASecondPrepareDoesNotAddTheWorktreeAgain(): void
    {
        $checkout = $this->checkout();
        $checkout->prepare('project', 'task', 'https://github.com/Acme/Widget', 'main', 'ghp_secret');
        $adds = substr_count((string) file_get_contents($this->log), 'worktree add');

        $checkout->prepare('project', 'task', 'https://github.com/Acme/Widget', 'main', 'ghp_secret');

        $this->assertSame($adds, substr_count((string) file_get_contents($this->log), 'worktree add'));
        $this->assertGreaterThan(0, $adds);
    }

    public function testPrepareUsesAnExistingLocalBranch(): void
    {
        file_put_contents($this->failFile, 'local');
        $worktree = $this->root . '/worktrees/task';

        $this->checkout()->prepare('project', 'task', 'https://github.com/Acme/Widget', 'main', 'ghp_secret');

        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('worktree add ', $log);
        $this->assertStringContainsString($worktree, $log);
        $this->assertStringNotContainsString('-b holder/', $log);
    }

    public function testPrepareTracksAnExistingRemoteBranch(): void
    {
        file_put_contents($this->failFile, 'remote');

        $this->checkout()->prepare('project', 'task', 'https://github.com/Acme/Widget', 'main', 'ghp_secret');

        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('worktree add -b holder/task', $log);
        $this->assertStringContainsString('origin/holder/task', $log);
    }

    public function testRemoveDeletesTheWorktreeAndTheBranch(): void
    {
        $this->checkout()->remove('project', 'task');

        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('worktree remove --force', $log);
        $this->assertStringContainsString('branch -D holder/task', $log);
    }

    public function testRemoveSwallowsAWorktreeFailure(): void
    {
        file_put_contents($this->failFile, 'remove');

        $this->checkout()->remove('project', 'task');
    }

    public function testPrepareDoesNotRecordTheTokenInTheTrace(): void
    {
        file_put_contents($this->failFile, 'fetch');
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $this->checkout()->prepare('project', 'task', 'https://github.com/Acme/Widget', 'main', 'ghp_secret');
            $this->fail('Expected HolderException was not thrown.');
        } catch (HolderException $error) {
            $this->assertStringNotContainsString('ghp_secret', $error->getMessage());
            $this->assertStringNotContainsString('ghp_secret', $error->getTraceAsString());
            $this->assertSame('0', ini_get('zend.exception_ignore_args'));
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '0' : $previous);
        }
    }

    private function checkout(): RepoCheckout
    {
        $config = new HolderConfig(
            mode: 'local',
            secretsKey: 'test',
            apiUrl: 'http://127.0.0.1',
            dataDir: $this->root,
            binPath: $this->root . '/bin/holder',
            runTimeoutSeconds: 1,
            runLimitSeconds: 3600,
        );

        return new RepoCheckout($config, new GitClient($config, dirname(__DIR__) . '/fixtures/fake-git', null));
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $item);
        }
        rmdir($path);
    }
}
