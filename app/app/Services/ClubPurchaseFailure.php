<?php

namespace App\Services;

final class ClubPurchaseFailure extends \RuntimeException
{
    public function __construct(public readonly ?int $status = null)
    {
        parent::__construct('Purchase service unavailable');
    }
}
