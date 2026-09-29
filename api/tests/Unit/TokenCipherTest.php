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
