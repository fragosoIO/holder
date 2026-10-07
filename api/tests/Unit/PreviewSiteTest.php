<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Work\PreviewSite;
use Codeception\Test\Unit;

final class PreviewSiteTest extends Unit
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holder-preview-site-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testTheFolderIndexBeatsSitePublicAndDist(): void
    {
        $this->page('index.html', 'root');
        $this->page('site/index.html', 'site');
        $this->page('public/index.html', 'public');
        $this->page('dist/index.html', 'dist');

        $this->assertSame($this->root, (new PreviewSite())->root($this->root));
    }

    public function testSiteBeatsPublicAndDist(): void
    {
        $this->page('site/index.html', 'site');
        $this->page('public/index.html', 'public');
        $this->page('dist/index.html', 'dist');

        $this->assertSame($this->root . '/site', (new PreviewSite())->root($this->root));
    }

    public function testPublicBeatsDist(): void
    {
        $this->page('public/index.html', 'public');
        $this->page('dist/index.html', 'dist');

        $this->assertSame($this->root . '/public', (new PreviewSite())->root($this->root));
    }

    public function testDistIsTheLastPlace(): void
    {
        $this->page('dist/index.html', 'dist');

        $this->assertSame($this->root . '/dist', (new PreviewSite())->root($this->root));
    }

    public function testASymlinkIndexThatLeavesTheDirectoryDoesNotCount(): void
    {
        $outside = sys_get_temp_dir() . '/holder-preview-outside-' . bin2hex(random_bytes(4));
        mkdir($outside);
        file_put_contents($outside . '/index.html', 'out');
        symlink($outside . '/index.html', $this->root . '/index.html');

        try {
            $this->assertNull((new PreviewSite())->root($this->root));
        } finally {
            $this->removeTree($outside);
        }
    }

    public function testFileRefusesAParentSegment(): void
    {
        $this->page('index.html', 'home');
        file_put_contents(dirname($this->root) . '/secret.txt', 'secret');

        $this->assertNull((new PreviewSite())->file($this->root, '../secret.txt'));
    }

    public function testFileRefusesASymlinkThatLeavesTheRoot(): void
    {
        $this->page('index.html', 'home');
        $outside = sys_get_temp_dir() . '/holder-preview-linked-' . bin2hex(random_bytes(4)) . '.html';
        file_put_contents($outside, 'out');
        symlink($outside, $this->root . '/linked.html');

        try {
            $this->assertNull((new PreviewSite())->file($this->root, 'linked.html'));
        } finally {
            unlink($outside);
        }
    }

    public function testFileRefusesGitNodeModulesADotfileADirectoryAndMarkdown(): void
    {
        $site = new PreviewSite();
        $this->page('index.html', 'home');
        $this->page('.git/HEAD', 'ref: refs/heads/main');
        $this->page('node_modules/index.html', 'modules');
        $this->page('.env', 'TOKEN=1');
        mkdir($this->root . '/assets');
        $this->page('notes.md', '# notes');

        $this->assertNull($site->file($this->root, '.git/HEAD'));
        $this->assertNull($site->file($this->root, 'node_modules/index.html'));
        $this->assertNull($site->file($this->root, '.env'));
        $this->assertNull($site->file($this->root, 'assets'));
        $this->assertNull($site->file($this->root, 'notes.md'));
    }

    public function testRevisionIsTheDigestOfServableFilesAndSkipsADotDirectory(): void
    {
        $this->page('index.html', 'home');
        $this->page('a.css', 'body{}');
        $this->page('.hidden/secret.html', 'nope');
        touch($this->root . '/a.css', 100);
        touch($this->root . '/index.html', 200);

        $revision = (new PreviewSite())->revision($this->root);

        $css = "a.css\n100\n" . filesize($this->root . '/a.css') . "\n";
        $html = "index.html\n200\n" . filesize($this->root . '/index.html') . "\n";
        $this->assertSame(hash('sha256', $css . $html), $revision);
    }

    private function page(string $path, string $body): void
    {
        $full = $this->root . '/' . $path;
        $directory = dirname($full);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($full, $body);
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
