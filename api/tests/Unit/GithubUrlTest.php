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
