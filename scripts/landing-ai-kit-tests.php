<?php

// Runs in the existing disposable schema; uses actual HTTP admin middleware and downloads.
$kitBefore = App\Models\MarketingLanding::orderBy('id')->get()->toArray();
$kitDocuments = App\Http\Controllers\LandingAiKitController::DOCUMENTS;
foreach ($kitDocuments as $kitKey => $kitName) {
    $kitDownload = http('GET', '/admin/landings/crear-con-ia/'.$kitKey);
    check($kitDownload->getStatusCode() === 200 && $kitDownload instanceof Symfony\Component\HttpFoundation\BinaryFileResponse, 'kit descarga individual '.$kitKey);
    $kitBytes = file_get_contents($kitDownload->getFile()->getPathname());
    check($kitBytes === file_get_contents('/reference/landings/'.$kitName)
        && mb_check_encoding($kitBytes, 'UTF-8'), 'kit integridad exacta y UTF-8 '.$kitKey);
    check(str_contains($kitDownload->headers->get('Content-Disposition'), $kitName)
        && str_contains($kitDownload->headers->get('Cache-Control'), 'no-store')
        && str_contains(strtolower($kitDownload->headers->get('Content-Type')), 'charset=utf-8'), 'kit attachment privado y codificación '.$kitKey);
    if ($kitKey === 'example') {
        $kitImport = http('POST', '/admin/landings/import', ['editorial_json' => $kitBytes]);
        check($kitImport->getStatusCode() === 200 && payload($kitImport)['configuration']['presentation']['template'] === 'WEBINAR', 'kit ejemplo descargado importable como landing nueva');
    }
}
$kitDownload = http('GET', '/admin/landings/crear-con-ia/kit');
if (class_exists(ZipArchive::class)) {
    check($kitDownload->getStatusCode() === 200 && $kitDownload instanceof Symfony\Component\HttpFoundation\StreamedResponse, 'kit ZIP disponible sin instalar dependencias');
    $kitTemps = glob(sys_get_temp_dir().'/jakawi-copywriter-*');
    ob_start();
    $kitDownload->sendContent();
    $kitArchive = ob_get_clean();
    check(glob(sys_get_temp_dir().'/jakawi-copywriter-*') === $kitTemps, 'kit ZIP elimina temporal tras streaming');
    $kitTemporary = tempnam(sys_get_temp_dir(), 'kit-test-');
    try {
        file_put_contents($kitTemporary, $kitArchive);
        $kitZip = new ZipArchive;
        check($kitZip->open($kitTemporary, ZipArchive::CHECKCONS) === true && $kitZip->numFiles === 3, 'kit ZIP válido contiene exactamente tres documentos');
        foreach ($kitDocuments as $kitName) {
            check($kitZip->getFromName($kitName) === file_get_contents('/reference/landings/'.$kitName), 'kit ZIP conserva bytes oficiales '.$kitName);
        }
        $kitZip->close();
    } finally {
        unlink($kitTemporary);
    }
} else {
    check($kitDownload->getStatusCode() === 503, 'kit sin extensión ZIP conserva descargas individuales');
}
$kitFallback = new class extends App\Http\Controllers\LandingAiKitController {
    public static function zipAvailable(): bool { return false; }
};
try {
    $kitFallback->download('kit');
    check(false, 'kit fallback debe rechazar ZIP');
} catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
    check($error->getStatusCode() === 503 && str_contains($error->getMessage(), 'tres archivos individuales'), 'kit fallback sin ZIP explica descargas individuales');
}
foreach (['unknown', '..', 'app.env'] as $kitUnsafe) {
    check(http('GET', '/admin/landings/crear-con-ia/'.$kitUnsafe)->getStatusCode() === 404, 'kit allowlist rechaza '.$kitUnsafe);
}
foreach (['email_verified_at', 'activated_at'] as $kitGuard) {
    $kitSaved = $admin->{$kitGuard};
    $admin->{$kitGuard} = null; $admin->save();
    foreach (array_keys($kitDocuments) as $kitKey) {
        check(http('GET', '/admin/landings/crear-con-ia/'.$kitKey)->getStatusCode() === 403, 'kit requiere cuenta verificada y activa '.$kitGuard.'/'.$kitKey);
    }
    check(http('GET', '/admin/landings/crear-con-ia/kit')->getStatusCode() === 403, 'kit ZIP requiere cuenta verificada y activa '.$kitGuard);
    $admin->{$kitGuard} = $kitSaved; $admin->save();
}
foreach (['/admin/landings', '/admin/landings/crear', '/admin/landings/'.$home->id.'/editar'] as $kitPage) {
    check(payload(http('GET', $kitPage, [], true))['props']['ai_kit_zip_available'] === class_exists(ZipArchive::class), 'kit disponibilidad en listado y editor '.$kitPage);
}
check(App\Models\MarketingLanding::orderBy('id')->get()->toArray() === $kitBefore, 'kit no modifica borradores ni snapshots');
