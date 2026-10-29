<?php

namespace App\Services\Identity;

final class IdentityFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly bool $retryable = true,
        public readonly int $retryAfter = 30,
    ) {
        parent::__construct('JAKAWI identity request could not be completed.');
    }
}
