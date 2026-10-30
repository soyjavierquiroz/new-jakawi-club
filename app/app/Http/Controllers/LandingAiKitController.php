<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class LandingAiKitController extends Controller
{
    public const DOCUMENTS = [
        'prompt' => 'JAKAWI-COPYWRITER-PROMPT.md',
        'example' => 'JAKAWI-WEBINAR-V3-EXAMPLE.json',
        'schema' => 'JAKAWI-LANDING-V3-SCHEMA.md',
    ];

    public static function zipAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function download(string $document): BinaryFileResponse|StreamedResponse
    {
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($document !== 'kit') {
            abort_unless(isset(self::DOCUMENTS[$document]), 404);
            $name = self::DOCUMENTS[$document];
            $type = $document === 'example' ? 'application/json' : 'text/markdown';

            return response()->download($this->path($name), $name, [...$headers, 'Content-Type' => $type.'; charset=UTF-8']);
        }

        abort_unless(static::zipAvailable(), 503, 'ZIP no disponible. Descarga los tres archivos individuales.');
        $paths = array_map(fn (string $name): string => $this->path($name), self::DOCUMENTS);

        return response()->streamDownload(function () use ($paths): void {
            $temporary = tempnam(sys_get_temp_dir(), 'jakawi-copywriter-');
            if ($temporary === false) {
                throw new \RuntimeException('No se pudo preparar el kit ZIP.');
            }
            $zip = new ZipArchive;
            $opened = false;
            try {
                if ($zip->open($temporary, ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('No se pudo crear el kit ZIP.');
                }
                $opened = true;
                foreach ($paths as $path) {
                    if (! $zip->addFile($path, basename($path))) {
                        throw new \RuntimeException('No se pudo incluir un documento oficial.');
                    }
                }
                $closed = $zip->close();
                $opened = false;
                if (! $closed) {
                    throw new \RuntimeException('No se pudo completar el kit ZIP.');
                }
                readfile($temporary);
            } finally {
                if ($opened) {
                    $zip->close();
                }
                unlink($temporary);
            }
        }, 'JAKAWI-COPYWRITING-KIT-V3.zip', [...$headers, 'Content-Type' => 'application/zip']);
    }

    private function path(string $name): string
    {
        // Fixed allowlist only; the versioned files are mounted outside the public directory.
        $path = base_path('../docs/landings/'.$name);
        abort_unless(is_file($path) && is_readable($path), 503, 'Documento oficial no disponible.');

        return $path;
    }
}
