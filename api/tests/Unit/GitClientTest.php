<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Github\GitClient;
use App\Domain\Github\RepoCheckout;
use App\Domain\HolderConfig;
use App\Domain\HolderException;
use Codeception\Test\Unit;

final class GitClientTest extends Unit
{
    private string $root;

    private string $log;

    private string $failFile;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function _before(): void
    {
        $this->root = sys_get_temp_dir() . '/holder-git-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/bin', 0777, true);
        $askpass = dirname(__DIR__, 3) . '/bin/holder-git-askpass';
        if (!copy($askpass, $this->root . '/bin/holder-git-askpass')) {
            $this->fail('Could not copy holder-git-askpass.');
        }
        chmod($this->root . '/bin/holder-git-askpass', 0755);

        $this->log = $this->root . '/git.log';
        $this->failFile = $this->root . '/git.fail';
        foreach (['HOLDER_FAKE_GIT_LOG', 'HOLDER_FAKE_GIT_FAIL', 'HOLDER_FAKE_GH_MISSING'] as $key) {
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

    public function testAssertGitDoesNotThrow(): void
    {
        $this->client()->assertGit();
    }

    public function testAMissingGitBinaryIsUnavailable(): void
    {
        $client = new GitClient($this->config(), '/no/such/git', null);

        try {
            $client->assertGit();
            $this->fail('Expected HolderException was not thrown.');
        } catch (HolderException $error) {
            $this->assertSame('git_unavailable', $error->getMessage());
        }
    }

    public function testCloneCreatesTheDestinationAndOmitsTheTokenFromTheLog(): void
    {
        $dest = $this->root . '/widget';

        $this->client()->run(
            ['clone', '--origin', 'origin', 'https://github.com/Acme/Widget', $dest],
            'ghp_secret',
        );

        $this->assertDirectoryExists($dest);
        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('https://github.com/Acme/Widget', $log);
        $this->assertStringNotContainsString('ghp_secret', $log);
    }

    public function testCloneFailureRedactsTheToken(): void
    {
        file_put_contents($this->failFile, 'clone');
        $dest = $this->root . '/missing';

        try {
            $this->client()->run(
                ['clone', '--origin', 'origin', 'https://github.com/Acme/Widget', $dest],
                'ghp_secret',
            );
            $this->fail('Expected HolderException was not thrown.');
        } catch (HolderException $error) {
            $this->assertSame('github_clone_failed', $error->errorCode);
            $this->assertSame('github_clone_failed: fatal: repository not found', $error->getMessage());
            $this->assertStringNotContainsString('ghp_secret', $error->getMessage());
            $this->assertStringNotContainsString('ghp_secret', $error->getTraceAsString());
        }
    }

    public function testMissingGitDoesNotLeaveTheTokenInTheTrace(): void
    {
        $this->assertTokenStaysOutOfTheTrace(function (RepoCheckout $checkout): void {
            $checkout->cloneRepository('project-id', 'https://github.com/Acme/Widget', 'ghp_secret');
        }, new GitClient($this->config(), '/no/such/git'), 'git_unavailable');
    }

    public function testSymbolicRefFailureDoesNotLeaveTheTokenInTheTrace(): void
    {
        $git = $this->root . '/symbolic-ref-git';
        file_put_contents($git, <<<'SH'
#!/bin/sh
if [ "${1:-}" = "--version" ]; then
  echo "git version 2.fake"
  exit 0
fi
if [ "${1:-}" = "-C" ]; then
  shift 2
fi
cmd="${1:-}"
if [ "$cmd" = "clone" ]; then
  for dest do :; done
  mkdir -p "$dest"
  exit 0
fi
if [ "$cmd" = "symbolic-ref" ]; then
  echo "fatal: symbolic ref failed" >&2
  exit 1
fi
echo "unexpected git command" >&2
exit 1
SH);
        chmod($git, 0755);
        $dest = $this->root . '/repos/project-id';

        $this->assertTokenStaysOutOfTheTrace(function (RepoCheckout $checkout) use ($dest): void {
            try {
                $checkout->cloneRepository('project-id', 'https://github.com/Acme/Widget', 'ghp_secret');
            } finally {
                $this->assertDirectoryDoesNotExist($dest);
            }
        }, new GitClient($this->config(), $git), 'github_clone_failed');
    }

    public function testCaptureReturnsStdoutAndStderrWhenStderrFillsThePipe(): void
    {
        $git = $this->root . '/noisy-git';
        file_put_contents($git, <<<'SH'
#!/bin/sh
php -r 'fwrite(STDERR, str_repeat("x", 70000)); fwrite(STDOUT, "ok");'
SH);
        chmod($git, 0755);
        $client = new GitClient($this->config(), $git);

        $started = microtime(true);
        $result = $client->capture(['status'], 'ghp_secret');

        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertSame(0, $result->exit);
        $this->assertSame('ok', $result->stdout);
        $this->assertSame(str_repeat('x', 70000), $result->stderr);
    }

    public function testRedactStripsTheTokenAndEmbeddedUserinfo(): void
    {
        $redacted = $this->client()->redact(
            "fatal: https://x-access-token:ghp_secret@github.com/Acme/Widget\nghp_secret\n",
            'ghp_secret',
        );

        $this->assertStringNotContainsString('ghp_secret', $redacted);
        $this->assertStringNotContainsString('x-access-token', $redacted);
        $this->assertStringNotContainsString('@github.com', $redacted);
        $this->assertStringContainsString('https://github.com/Acme/Widget', $redacted);
        $this->assertStringContainsString('[redacted]', $redacted);
    }

    public function testMissingGhIsUnavailable(): void
    {
        $previous = getenv('HOLDER_FAKE_GH_MISSING');
        putenv('HOLDER_FAKE_GH_MISSING=1');
        $_ENV['HOLDER_FAKE_GH_MISSING'] = '1';

        try {
            $client = new GitClient($this->config(), null, dirname(__DIR__) . '/fixtures/fake-gh');
            $client->assertGh();
            $this->fail('Expected HolderException was not thrown.');
        } catch (HolderException $error) {
            $this->assertSame('gh_unavailable', $error->getMessage());
        } finally {
            if ($previous === false) {
                putenv('HOLDER_FAKE_GH_MISSING');
                unset($_ENV['HOLDER_FAKE_GH_MISSING']);
            } else {
                putenv('HOLDER_FAKE_GH_MISSING=' . $previous);
                $_ENV['HOLDER_FAKE_GH_MISSING'] = $previous;
            }
        }
    }

    private function assertTokenStaysOutOfTheTrace(\Closure $call, GitClient $git, string $errorCode): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $checkout = new RepoCheckout($this->config(), $git);
            try {
                $call($checkout);
                $this->fail('Expected HolderException was not thrown.');
            } catch (HolderException $error) {
                $this->assertSame($errorCode, $error->errorCode);
                $this->assertStringNotContainsString('ghp_secret', $error->getMessage());
                $this->assertStringNotContainsString('ghp_secret', $error->getTraceAsString());
            }
            $this->assertSame('0', ini_get('zend.exception_ignore_args'));
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '0' : $previous);
        }
    }

    private function client(): GitClient
    {
        return new GitClient($this->config(), dirname(__DIR__) . '/fixtures/fake-git', null);
    }

    private function config(): HolderConfig
    {
        return new HolderConfig(
            mode: 'local',
            secretsKey: 'test',
            apiUrl: 'http://127.0.0.1',
            dataDir: $this->root,
            binPath: $this->root . '/bin/holder',
            runTimeoutSeconds: 1,
            runLimitSeconds: 3600,
        );
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }

    private function removeTree(string $path): void
    {
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
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
