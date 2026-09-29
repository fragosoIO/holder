<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Api\LocalRequest;
use Codeception\Test\Unit;

final class LocalRequestTest extends Unit
{
    public function testLoopbackIsTheLocalOwnerOnAnyHost(): void
    {
        $this->assertTrue(LocalRequest::trusted('127.0.0.1', 'holder.zixio.de'));
        $this->assertTrue(LocalRequest::trusted('::1', 'example.com'));
        $this->assertTrue(LocalRequest::trusted('::ffff:127.0.0.1', 'holder.zixio.de'));
    }

    public function testAPrivateClientOnALocalHostnameIsTheLocalOwner(): void
    {
        $this->assertTrue(LocalRequest::trusted('172.18.0.4', 'holder.localhost'));
        $this->assertTrue(LocalRequest::trusted('10.0.0.8', 'localhost'));
        $this->assertTrue(LocalRequest::trusted('192.168.1.1', '127.0.0.1'));
        $this->assertTrue(LocalRequest::trusted('172.18.0.4', 'holder.test'));
    }

    public function testAPrivateClientOnAPublicHostnameIsNotTheLocalOwner(): void
    {
        $this->assertFalse(LocalRequest::trusted('172.18.0.4', 'holder.zixio.de'));
        $this->assertFalse(LocalRequest::trusted('172.18.0.4', 'holder.192-168-1-128.traefik.me'));
        $this->assertFalse(LocalRequest::trusted('8.8.8.8', 'holder.localhost'));
    }
}
