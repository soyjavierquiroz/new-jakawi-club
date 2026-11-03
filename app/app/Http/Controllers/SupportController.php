<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Support\SupportAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class SupportController extends Controller
{
    public function index(Request $request, SupportAccess $access): Response|\Inertia\Response
    {
        $access->requireActor($request);
        $context = $request->session()->get('support_view');
        if ($context && empty($context['expired'])) return redirect('/admin/soporte/cuenta');
        $data = $request->validate(['sub' => 'nullable|uuid', 'q' => 'nullable|string|max:120']);
        $accounts = [];
        if ($access->recent($request) && !$context) {
            if (!empty($data['sub'])) {
                $access->audit($request, 'lookup', ['subject' => $data['sub']]);
                $accounts = User::where('global_subject_id', $data['sub'])->limit(1)->get(['uuid', 'name', 'global_subject_id'])->all();
            } elseif (!empty($data['q'])) {
                $access->audit($request, 'search');
                $accounts = User::where('name', 'ilike', '%'.$data['q'].'%')->orderBy('id')->limit(20)->get(['uuid', 'name', 'global_subject_id'])->all();
            }
        }
        return Inertia::render('admin/Support', ['recent' => $access->recent($request), 'expired' => (bool)($context['expired'] ?? false),
            'query' => $data, 'accounts' => $accounts, 'searched' => !empty($data['sub']) || !empty($data['q']), 'view' => null]);
    }

    public function reauthenticate(Request $request, SupportAccess $access): Response|\Inertia\Response
    {
        $actor = $access->requireActor($request);
        abort_if($request->session()->has('support_view'), 403);
        $request->session()->forget('support_reauth');
        $return = $request->validate(['sub' => 'nullable|uuid']);
        $client = new \App\Services\Identity\JakawiOidc;
        $client->addAuthParam(['prompt' => 'login']);
        $url = $client->begin();
        $request->session()->regenerate(true);
        $request->session()->put('jakawi_oidc', \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
            'expires_at' => time() + 300, 'values' => $client->transaction,
            'support_return_sub' => $return['sub'] ?? null, 'support_actor' => $actor->uuid, 'support_subject' => $actor->global_subject_id,
        ], JSON_THROW_ON_ERROR)));
        return Inertia::location($url);
    }

    public function start(Request $request, SupportAccess $access): Response|\Inertia\Response
    {
        $actor = $access->requireActor($request);
        abort_unless($access->recent($request) && !$request->session()->has('support_view'), 403, 'Reautentica tu cuenta antes de iniciar soporte.');
        $data = $request->validate(['sub' => 'nullable|uuid|required_without:account', 'account' => 'nullable|uuid|required_without:sub',
            'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\x00-\x1F\x7F]/']]);
        $target = isset($data['account']) ? User::where('uuid', $data['account'])->firstOrFail() : User::where('global_subject_id', $data['sub'])->first();
        if (isset($data['account'], $data['sub'])) abort_unless($target->global_subject_id === $data['sub'], 422);
        $context = ['id' => (string) Str::uuid(), 'actor' => $actor->uuid, 'subject' => $target?->global_subject_id ?? ($data['sub'] ?? null),
            'target_uuid' => $target?->uuid, 'reason' => $data['reason'], 'expires_at' => time() + 900];
        $access->audit($request, 'started', $context);
        $request->session()->regenerate(true);
        $request->session()->put('support_view', $context);
        $request->session()->forget('support_reauth');
        return redirect('/admin/soporte/cuenta');
    }

    public function show(Request $request, SupportAccess $access): Response|\Inertia\Response
    {
        $access->requireActor($request);
        $context = $request->session()->get('support_view');
        abort_unless(is_array($context) && $context['expires_at'] > time(), 403);
        $access->audit($request, $request->is('*/afiliados') ? 'view_affiliates' : 'view_account');
        $target = $context['target_uuid'] ? User::where('uuid', $context['target_uuid'])->first() : null;
        abort_if($target && $target->global_subject_id !== $context['subject'], 409);
        $view = ['target' => $target?->only(['name', 'uuid', 'global_subject_id', 'email', 'phone', 'city']), 'subject' => $context['subject'],
            'expires_at' => $context['expires_at'], 'reason' => $context['reason'], 'tab' => $request->is('*/afiliados') ? 'affiliates' : 'account'];
        if ($target) $view += app(\App\Services\Support\SupportProjection::class)->forUser($target);
        abort_unless($context['expires_at'] > time(), 403, 'El modo soporte venció.');
        return Inertia::render('admin/Support', ['view' => $view, 'recent' => false, 'expired' => false]);
    }

    public function stop(Request $request, SupportAccess $access): Response|\Inertia\Response
    {
        // Exit remains possible after revocation/expiry, and never changes the authenticated user.
        if ($request->session()->has('support_view')) $access->audit($request, 'stopped');
        $request->session()->forget(['support_view', 'support_reauth']);
        $request->session()->regenerate(true);
        return redirect('/dashboard');
    }
}
