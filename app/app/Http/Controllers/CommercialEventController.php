<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CommercialEventController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        try {
            $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->json(['error' => 'invalid_json'], 400);
        }
        if (! $object instanceof \stdClass) {
            return response()->json(['error' => 'invalid_json_object'], 400);
        }
        // Canonicalize object keys only; exact original bytes remain stored separately.
        $canonicalize = function (mixed $value) use (&$canonicalize): mixed {
            if ($value instanceof \stdClass) {
                $fields = get_object_vars($value);
                ksort($fields, SORT_STRING);
                return (object) array_map($canonicalize, $fields);
            }
            return is_array($value) ? array_map($canonicalize, $value) : $value;
        };
        $digest = hash('sha256', json_encode($canonicalize($object), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if ((($data['event_type'] ?? null) === 'membership.activated' && ($data['origin'] ?? null) === 'CLUB')
            || ($data['event_type'] ?? null) === 'membership.identity_linked') {
            return app(\App\Services\ClubPurchaseEvents::class)->receive($request, $data, $digest);
        }
        $membership = ($data['event_type'] ?? null) === 'membership.activated';
        $allowed = ['schema_version', 'event_id', 'event_type', 'occurred_at', 'com_reference', 'partner', 'origin', 'referral', 'global_subject_id', 'conversion_status'];
        if ($membership) $allowed = ['schema_version', 'event_id', 'event_type', 'occurred_at', 'com_reference', 'global_subject_id', 'membership_reference', 'payment_reference'];
        if (array_diff(array_keys($data), $allowed)) {
            throw ValidationException::withMessages(['payload' => 'Unknown fields are not accepted in v1.']);
        }
        if (($data['schema_version'] ?? null) !== 1) {
            throw ValidationException::withMessages(['schema_version' => 'Only integer schema_version 1 is supported.']);
        }
        foreach (['com_reference', 'partner', 'origin', 'referral'] as $field) {
            if (isset($object->$field) && ! $object->$field instanceof \stdClass) {
                throw ValidationException::withMessages([$field => 'Expected a JSON object or null.']);
            }
        }
        if (isset($data['referral']) && empty($data['referral']['com_referral_id']) && empty($data['referral']['club_referral_code'])) {
            throw ValidationException::withMessages(['referral' => 'Supply an original COM referral ID or an existing CLUB referral code, or omit referral.']);
        }
        $identifier = ['string', 'max:190', 'regex:/^\S(?:[^\x00-\x1F\x7F]*\S)?$/uD'];
        $data = Validator::make($data, [
            'schema_version' => ['required', 'integer', 'in:1'],
            'event_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
            'event_type' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9._-]*$/D'],
            'occurred_at' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'com_reference' => ['required', 'array:type,id'],
            'com_reference.type' => ['required', ...($membership ? ['in:membership'] : []), 'string', 'max:64', 'regex:/^[a-z][a-z0-9._-]*$/D'],
            'com_reference.id' => ['required', ...$identifier],
            'partner' => ['sometimes', 'nullable', 'array:id,name'],
            'partner.id' => ['required_with:partner', ...$identifier],
            'partner.name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'origin' => ['sometimes', 'nullable', 'array:landing,campaign'],
            'origin.landing' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'origin.campaign' => ['sometimes', 'nullable', ...$identifier],
            'referral' => ['sometimes', 'nullable', 'array:com_referral_id,club_referral_code'],
            'referral.com_referral_id' => ['sometimes', 'nullable', ...$identifier],
            'referral.club_referral_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{12}$/D'],
            'global_subject_id' => [$membership ? 'required' : 'sometimes', ...($membership ? [] : ['nullable']), 'uuid'],
            'membership_reference' => $membership ? ['required', ...$identifier, 'same:com_reference.id'] : ['prohibited'],
            'payment_reference' => $membership ? ['required', ...$identifier] : ['prohibited'],
            'conversion_status' => [$membership ? 'prohibited' : 'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9._-]*$/D'],
        ])->validate();
        if ($membership) $data['conversion_status'] = 'activated';
        $provenance = [
            'raw_body' => $raw, 'raw_body_sha256' => hash('sha256', $raw), 'payload_sha256' => $digest,
            'key_id' => $request->header('X-Jakawi-Com-Key-Id'), 'signed_at' => (int) $request->header('X-Jakawi-Com-Timestamp'),
        ];
        return DB::transaction(function () use ($data, $digest, $provenance): JsonResponse {
            $existing = DB::table('commercial_events')->where('source', 'jakawi.com')->where('event_id', $data['event_id'])->first();
            if ($existing) {
                return $this->receipt($existing, $digest, $provenance, false);
            }
            $code = $data['referral']['club_referral_code'] ?? null;
            $referrer = $code ? User::where('referral_code', $code)->first(['uuid']) : null;
            if ($code && ! $referrer) {
                throw ValidationException::withMessages(['referral.club_referral_code' => 'Unknown CLUB referral code.']);
            }
            $inserted = DB::table('commercial_events')->insertOrIgnore([
                ...$provenance, 'source' => 'jakawi.com', 'event_id' => $data['event_id'], 'schema_version' => $data['schema_version'],
                'event_type' => $data['event_type'], 'occurred_at' => Carbon::parse($data['occurred_at'])->utc(), 'received_at' => now()->utc(),
                'com_reference_type' => $data['com_reference']['type'], 'com_reference_id' => $data['com_reference']['id'],
                'partner_id' => $data['partner']['id'] ?? null, 'partner_name' => $data['partner']['name'] ?? null,
                'landing' => $data['origin']['landing'] ?? null, 'campaign' => $data['origin']['campaign'] ?? null,
                'com_referral_id' => $data['referral']['com_referral_id'] ?? null, 'club_referral_code' => $data['referral']['club_referral_code'] ?? null,
                'referrer_club_uuid' => $referrer?->uuid, 'global_subject_id' => $data['global_subject_id'] ?? null,
                'conversion_status' => $data['conversion_status'],
            ]);
            $event = DB::table('commercial_events')->where('source', 'jakawi.com')->where('event_id', $data['event_id'])->first();
            if ($inserted && $event->event_type !== 'membership.activated') app(\App\Services\Commissions\CommissionEngine::class)->record($event);
            return $this->receipt($event, $digest, $provenance, (bool) $inserted);
        });
    }

    private function receipt(object $event, string $digest, array $provenance, bool $inserted): JsonResponse
    {
        if (! hash_equals($event->payload_sha256, $digest)) {
            DB::table('commercial_event_conflicts')->insertOrIgnore([
                ...$provenance, 'commercial_event_id' => $event->id, 'detected_at' => now()->utc(),
            ]);
            return response()->json(['error' => 'event_id_conflict', 'event_id' => $event->event_id], 409);
        }
        return response()->json(['status' => $inserted ? 'accepted' : 'duplicate', 'event_id' => $event->event_id], $inserted ? 201 : 200);
    }
}
