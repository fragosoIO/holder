<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Work\PreviewToken;
use Codeception\Test\Unit;

final class PreviewTokenTest extends Unit
{
    public function testIssueVerifiesForTheSameTask(): void
    {
        $tokens = new PreviewToken('test-key');
        $issued = $tokens->issue('company', 'task', 1_000);

        $this->assertSame(1_000 + 43_200, $issued['expiresAt']);
        $this->assertTrue($tokens->verify($issued['token'], 'company', 'task', 1_000));
    }

    public function testATokenForAnotherTaskIsRejected(): void
    {
        $tokens = new PreviewToken('test-key');
        $issued = $tokens->issue('company', 'task', 1_000);

        $this->assertFalse($tokens->verify($issued['token'], 'company', 'other-task', 1_000));
    }

    public function testATokenPastItsExpiryIsRejected(): void
    {
        $tokens = new PreviewToken('test-key');
        $issued = $tokens->issue('company', 'task', 1_000);

        $this->assertFalse($tokens->verify($issued['token'], 'company', 'task', $issued['expiresAt']));
    }

    public function testAChangedSecretsKeyIsRejected(): void
    {
        $issued = (new PreviewToken('test-key'))->issue('company', 'task', 1_000);

        $this->assertFalse((new PreviewToken('other-key'))->verify($issued['token'], 'company', 'task', 1_000));
    }
}
