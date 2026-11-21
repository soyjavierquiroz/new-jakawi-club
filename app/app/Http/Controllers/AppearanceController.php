<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppearanceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['theme_preference' => ['required', Rule::in(['light', 'dark', 'system'])]]);
        $user = $request->user();
        // Reject stale requests from a document belonging to another signed-in account.
        abort_unless((string) $user->uuid === $request->header('X-Appearance-Identity'), 409);
        $user->theme_preference = $data['theme_preference'];
        $user->save();

        return response()->json(['theme_preference' => $user->theme_preference]);
    }
}
