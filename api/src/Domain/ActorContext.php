<?php

declare(strict_types=1);

namespace App\Domain;

final class ActorContext
{
    private ?Actor $actor = null;

    public function clear(): void
    {
        $this->actor = null;
    }

    public function set(Actor $actor): void
    {
        $this->actor = $actor;
    }

    public function get(): ?Actor
    {
        return $this->actor;
    }

    public function requireUser(): Actor
    {
        $actor = $this->actor;
        if ($actor === null || !$actor->isUser()) {
            throw new HolderException('unauthenticated', 'Sign in to continue.', 401);
        }

        return $actor;
    }

    public function requireRun(): Actor
    {
        $actor = $this->actor;
        if ($actor === null || !$actor->isRun()) {
            throw new HolderException('unauthenticated', 'A run token is required.', 401);
        }

        return $actor;
    }
}
