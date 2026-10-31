<?php
namespace App\Services;
use App\Models\MembershipPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
final class ClubPurchaseEvents {
    public function receive(Request $request, array $data, string $digest): JsonResponse {
        $activation = ($data['event_type'] ?? '') === 'membership.activated';
        $allowed = ['schema_version', 'event_id', 'event_type', 'occurred_at', 'payment_reference', 'club_reference', 'club_user_uuid', 'global_subject_id', 'membership_reference',
            ...($activation ? ['com_reference', 'origin', 'guest_purchase_id', 'identity_status'] : [])];
        if (array_diff(array_keys($data), $allowed)) throw \Illuminate\Validation\ValidationException::withMessages(['payload' => 'Unknown fields.']);
        $data = Validator::make($data, [
            'schema_version' => 'required|integer|in:1',
            'event_id' => 'required|string|max:128', 'event_type' => 'required|in:membership.activated,membership.identity_linked',
            'occurred_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'payment_reference' => 'required|uuid', 'club_reference' => 'required|uuid',
            'club_user_uuid' => [$activation ? 'present' : 'required', 'nullable', 'uuid'],
            'global_subject_id' => [$activation ? 'present' : 'required', 'nullable', 'uuid'],
            'guest_purchase_id' => 'sometimes|nullable|uuid',
            'membership_reference' => 'required|string|max:190',
            'origin' => $activation ? 'required|in:CLUB' : 'prohibited',
            'identity_status' => $activation ? 'required|in:pending,linked,conflict' : 'prohibited',
            'com_reference' => $activation ? 'required|array:type,id' : 'prohibited',
            'com_reference.type' => $activation ? 'required|in:membership' : 'prohibited',
            'com_reference.id' => $activation ? 'required|same:membership_reference' : 'prohibited',
        ])->validate();
        $expected = 'com:membership:'.$data['payment_reference'].($activation ? ':activated' : ':identity-linked');
        abort_unless($data['event_id'] === $expected && $data['schema_version'] === 1, 422);
        if (($data['identity_status'] ?? '') === 'linked') abort_unless(!empty($data['global_subject_id']) && !empty($data['club_user_uuid']), 422);
        $raw = $request->getContent();
        $provenance = ['raw_body' => $raw, 'raw_body_sha256' => hash('sha256', $raw), 'payload_sha256' => $digest,
            'key_id' => $request->header('X-Jakawi-Com-Key-Id'), 'signed_at' => (int) $request->header('X-Jakawi-Com-Timestamp')];
        return DB::transaction(function () use ($data, $digest, $provenance, $activation): JsonResponse {
            $inserted = DB::table('commercial_events')->insertOrIgnore([...$provenance, 'source' => 'jakawi.com',
                'event_id' => $data['event_id'], 'schema_version' => 1, 'event_type' => $data['event_type'],
                'occurred_at' => Carbon::parse($data['occurred_at'])->utc(), 'received_at' => now()->utc(),
                'com_reference_type' => 'membership', 'com_reference_id' => $data['membership_reference'],
                'global_subject_id' => $data['global_subject_id'], 'conversion_status' => $activation ? 'activated' : 'identity_linked']);
            $event = DB::table('commercial_events')->where('source', 'jakawi.com')->where('event_id', $data['event_id'])->first();
            if (!hash_equals($event->payload_sha256, $digest)) {
                DB::table('commercial_event_conflicts')->insertOrIgnore([...$provenance, 'commercial_event_id' => $event->id, 'detected_at' => now()->utc()]);
                return response()->json(['error' => 'event_id_conflict', 'event_id' => $data['event_id']], 409);
            }
            $purchase = MembershipPurchase::lockForUpdate()->find($data['club_reference']);
            if ($purchase) {
                abort_unless(!$purchase->payment_reference || $purchase->payment_reference === $data['payment_reference'], 409);
                $intent = $purchase->intent;
                // Guest UUID remains original even after a later authenticated claim.
                if ($activation) abort_unless(($data['guest_purchase_id'] ?? null) === $intent['guest_purchase_id'], 409);
                if ($intent['club_user_uuid']) abort_unless($data['club_user_uuid'] === $intent['club_user_uuid'], 409);
                $state = ['payment_reference' => $data['payment_reference'], 'status' => $activation ? 'approved' : $purchase->status,
                    'membership_reference' => $data['membership_reference'], 'activated_at' => $activation ? $data['occurred_at'] : $purchase->activated_at,
                    'identity_status' => $activation ? $data['identity_status'] : 'linked', 'global_subject_id' => $data['global_subject_id']];
                app(PublicMembershipCheckout::class)->apply($purchase, $state);
            }
            // Membership facts never enter the commission engine.
            return response()->json(['event_id' => $data['event_id'], 'status' => $inserted ? 'accepted' : 'duplicate'], $inserted ? 201 : 200);
        });
    }
}
