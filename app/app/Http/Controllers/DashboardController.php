<?php

namespace App\Http\Controllers;

use App\Support\LaunchSettings;
use App\Support\Normalize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'member' => $user->only('name', 'email', 'phone', 'city'),
            'referral_url' => $user->referralUrl(),
            'invitees_count' => $user->invitees()->count(),
            'launch' => LaunchSettings::publicData(true),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge([
            'name' => Normalize::name($request->input('name')),
            'phone' => Normalize::phone($request->input('phone')),
            'city' => Normalize::name($request->input('city')) ?: null,
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'city' => ['nullable', 'string', 'max:100'],
        ], [
            'name.*' => 'Escribe tu nombre (hasta 120 caracteres).',
            'phone.*' => 'Incluye el prefijo de tu país y un teléfono válido.',
            'city.*' => 'La ciudad admite hasta 100 caracteres.',
        ]);
        $request->user()->update($data);

        return back()->with('status', 'Perfil actualizado.');
    }
}
