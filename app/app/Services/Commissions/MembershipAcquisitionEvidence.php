<?php

namespace App\Services\Commissions;

use Illuminate\Support\Carbon;

class MembershipAcquisitionEvidence
{
    /**
     * The current authenticated COM contracts provide activation facts only.
     * They cannot establish first-ever acquisition or entitlement at a past instant.
     * Return unknown until COM supplies the contract documented in phase 10E.
     * No inference from email verification, absent events, or browser claims.
     *
     * @return array{first_acquisition: bool, membership_status: string, reference: string}|null
     */
    public function resolve(object $person, object $event, Carbon $at): ?array
    {
        return null;
    }
}
