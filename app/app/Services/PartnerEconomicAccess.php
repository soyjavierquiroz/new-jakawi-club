<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Fresh COM authority, memoized only on the current HTTP request. Never a session grant. */
final class PartnerEconomicAccess
{
    public function partners(Request $request, User $user): array
    {
        $subject = $user->global_subject_id;
        if (! is_string($subject) || ! Str::isUuid($subject)
            || $request->user()?->id !== $user->id
            || $request->session()->get('jakawi_sso_subject') !== $subject) return [];
        $key = self::class.':'.$subject;
        if ($request->attributes->has($key)) return $request->attributes->get($key);
        $partners = [];
        try {
            $response = app(ClubPurchaseClient::class)->partnerAccess($subject);
            if ($response->status() !== 200 || strlen($response->body()) > 262144) throw new \RuntimeException;
            $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['global_subject_id'] ?? null) !== $subject
                || ! is_array($data['partners'] ?? null) || ! array_is_list($data['partners'])) throw new \RuntimeException;
            $seen = [];
            foreach ($data['partners'] as $partner) {
                if (! is_array($partner) || ! is_int($partner['id'] ?? null) || $partner['id'] < 1
                    || ! is_string($partner['name'] ?? null) || ! is_string($partner['status'] ?? null)
                    || ! in_array($partner['role'] ?? null, ['owner', 'manager', 'staff'], true)
                    || ! is_bool($partner['economic_access'] ?? null) || isset($seen[$partner['id']])) throw new \RuntimeException;
                $seen[$partner['id']] = true;
                if ($partner['status'] === 'published' && $partner['economic_access'] === true
                    && in_array($partner['role'], ['owner', 'manager'], true)) $partners[] = (string) $partner['id'];
            }
        } catch (\Throwable) {
            // Fail closed without exposing signed headers, credentials or provider response bodies.
            $partners = [];
            $request->attributes->set('partner_access_unavailable', true);
        }
        $request->attributes->set($key, $partners);
        return $partners;
    }
}
