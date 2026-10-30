<?php

namespace App\Http\Controllers;

use App\Services\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class MediaUploadController extends Controller
{
    public function store(Request $request, MediaUploadService $media): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'max:10240']]);
        if (!config('filesystems.disks.'.config('media.disk').'.key') || !config('filesystems.disks.'.config('media.disk').'.secret') || !config('media.imgproxy_key') || !config('media.imgproxy_salt')) {
            throw ValidationException::withMessages(['image' => 'La subida de imágenes no está configurada. Contacta al administrador.']);
        }
        try {
            // One decoder at a time respects the bounded CLUB FPM container at 24 MP.
            $result = Cache::lock('club:media:normalize', 120)->get(fn (): array => $media->temporary($request->file('image'), $request->user()->id));
            if (!$result) throw ValidationException::withMessages(['image' => 'Estamos procesando otra imagen. Inténtalo de nuevo en unos segundos.']);
            return response()->json($result);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            // SDK messages can contain signed requests; never report them verbatim.
            throw ValidationException::withMessages(['image' => 'No pudimos subir la imagen. Inténtalo de nuevo; la imagen guardada sigue disponible.']);
        }
    }
}
