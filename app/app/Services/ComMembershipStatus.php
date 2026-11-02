<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/** A live entitlement read. It never creates purchases, links or economic events. */
final class ComMembershipStatus
{
    public function forUser(User $user): ?array
    {
        if (!$user->global_subject_id) return null;
        try {
            $subject = $user->global_subject_id;
            if (!Str::isUuid($subject)) throw new \RuntimeException;
            $response = app(ClubPurchaseClient::class)->membershipStatus($subject);
            if ($response->status() !== 200 || strlen($response->body()) > 16384) throw new \RuntimeException;
            $data = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR);
            $m = $data['membership'] ?? null;
            if (($data['contract'] ?? null) !== 'jakawi-com-membership-status-v1'
                || ($data['global_subject_id'] ?? null) !== $subject || !is_array($m)
                || !in_array($m['type'] ?? null, ['free', 'standard', 'pioneer'], true)
                || !in_array($m['status'] ?? null, ['free', 'active', 'scheduled', 'expired', 'cancelled'], true)
                || !is_bool($m['is_active'] ?? null) || !is_array($m['benefits'] ?? null)) throw new \RuntimeException;
            foreach (['public_catalog', 'club_access', 'exclusive_member_benefits'] as $benefit) {
                if (!is_bool($m['benefits'][$benefit] ?? null)) throw new \RuntimeException;
            }
            foreach (['starts_at', 'effective_ends_at', 'official_launch_date'] as $field) {
                if (!array_key_exists($field, $m) || ($m[$field] !== null && (!is_string($m[$field])
                    || strlen($m[$field]) > 40 || strtotime($m[$field]) === false))) throw new \RuntimeException;
            }
            if (!in_array($m['expiry_policy'] ?? null, [null, 'fixed', 'official_launch_plus_15_calendar_months'], true)) throw new \RuntimeException;
            if ($m['is_active'] !== ($m['status'] === 'active')
                || ($m['type'] === 'free' && ($m['is_active'] || $m['benefits']['exclusive_member_benefits']))
                || (!$m['is_active'] && $m['benefits']['exclusive_member_benefits'])) throw new \RuntimeException;
            return ['available' => true, ...array_intersect_key($m, array_flip([
                'type', 'status', 'is_active', 'starts_at', 'effective_ends_at', 'expiry_policy', 'official_launch_date', 'benefits',
            ]))];
        } catch (\Throwable) {
            // Never fall back to historical activation as a current COM entitlement.
            return ['available' => false, 'is_active' => false];
        }
    }
}
