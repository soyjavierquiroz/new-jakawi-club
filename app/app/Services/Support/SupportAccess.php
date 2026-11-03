<?php

namespace App\Services\Support;

use App\Models\User;
use Illuminate\Http\Request;

final class SupportAccess
{
    public function authorized(?User $actor): bool
    {
        if (!$actor || !$actor->activated_at || !$actor->hasVerifiedEmail() || !$actor->global_subject_id) return false;
        if (!is_file(config('support.grants_path'))) return false;
        try {
            $grants = json_decode(file_get_contents(config('support.grants_path')), true, 16, JSON_THROW_ON_ERROR);
            return ($grants[$actor->global_subject_id] ?? null) === $actor->uuid;
        } catch (\Throwable) { return false; }
    }

    public function requireActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($this->authorized($actor), 403);
        abort_unless($request->session()->get('jakawi_sso_subject') === $actor->global_subject_id, 403, 'Ingresa con tu identidad real mediante OIDC normal.');
        return $actor;
    }

    public function recent(Request $request): bool
    {
        $proof = $request->session()->get('support_reauth');
        return is_array($proof) && ($proof['actor'] ?? null) === $request->user()?->uuid
            && ($proof['subject'] ?? null) === $request->user()?->global_subject_id
            && is_int($proof['at'] ?? null) && $proof['at'] <= time() && $proof['at'] > time() - 300;
    }

    public function audit(Request $request, string $action, ?array $context = null): void
    {
        $context ??= $request->session()->get('support_view', []);
        $entry = ['at' => gmdate('c'), 'action' => $action, 'actor_uuid' => $request->user()?->uuid,
            'actor_subject' => $request->user()?->global_subject_id,
            'target_subject' => $context['subject'] ?? null, 'target_uuid' => $context['target_uuid'] ?? null,
            'reason' => $context['reason'] ?? null, 'support_id' => $context['id'] ?? null];
        $path = config('support.audit_path');
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $file = fopen($path, 'ab');
        if (!$file) abort(503, 'Auditoría de soporte no disponible.');
        try {
            if (!flock($file, LOCK_EX)) abort(503);
            chmod($path, 0600);
            $line = json_encode($entry, JSON_THROW_ON_ERROR)."\n";
            if (fwrite($file, $line) !== strlen($line) || !fflush($file) || !fsync($file)) abort(503);
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
}
