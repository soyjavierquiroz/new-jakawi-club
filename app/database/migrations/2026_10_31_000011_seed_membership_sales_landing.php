<?php
use App\Support\LandingContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        if (DB::table('marketing_landings')->where('slug', 'membresia')->exists()) return;
        $content = LandingContent::defaults();
        $content['seo'] = ['title' => 'Membresía JAKAWI CLUB · Bs 100', 'description' => 'Activa tu membresía JAKAWI CLUB por Bs 100. Completa tus datos y paga por QR, sin registro previo.'];
        $content['hero'] = ['eyebrow' => 'JAKAWI CLUB', 'title' => 'Activa tu membresía', 'emphasis' => 'JAKAWI', 'ending' => 'CLUB', 'description' => 'Completa tus datos y paga por QR. Puedes realizar toda tu compra aquí, sin crear una cuenta previamente.', 'cta' => 'Activar mi membresía'];
        $content['benefits'] = ['eyebrow' => 'TU COMPRA, PASO A PASO', 'title' => 'Un proceso simple.', 'description' => 'Elige continuar y completa estos pasos para activar tu membresía.', 'items' => [
            ['title' => 'Completa tus datos', 'description' => 'Solo necesitamos tu nombre, correo electrónico y WhatsApp o teléfono.'],
            ['title' => 'Paga por QR', 'description' => 'El importe de la membresía es Bs 100. Descarga el QR o escanéalo desde tu aplicación bancaria.'],
            ['title' => 'Envía tu comprobante', 'description' => 'Sube un archivo JPG, PNG o PDF. La membresía se activa después de confirmar el pago.'],
        ]];
        $content['faq'] = ['eyebrow' => 'ANTES DE CONTINUAR', 'title' => 'Preguntas frecuentes', 'items' => [
            ['question' => '¿Necesito crear una cuenta antes de comprar?', 'answer' => 'No. Puedes completar tus datos y continuar directamente al pago.'],
            ['question' => '¿Cuánto cuesta la membresía?', 'answer' => 'El importe de la membresía es Bs 100.'],
            ['question' => '¿Cuándo se activa mi membresía?', 'answer' => 'Después de verificar tu pago. Puedes consultar el estado de la operación en esta misma página.'],
        ]];
        $content['final'] = ['eyebrow' => 'CONTINÚA CON TU COMPRA', 'title' => 'Activa tu membresía', 'description' => 'Completa tus datos, paga por QR y envía tu comprobante.', 'cta' => 'Activar mi membresía'];
        $content['video'] = LandingContent::videoDefaults(); $content['presentation']['show_video'] = false;
        $content['testimonials'] = [];
        $json = json_encode(LandingContent::validate($content), JSON_THROW_ON_ERROR);
        DB::table('marketing_landings')->insert(['title' => 'Membresía JAKAWI CLUB', 'slug' => 'membresia', 'template' => 'LEAD_VSL', 'configuration' => $json, 'published_configuration' => $json,
            'status' => 'published', 'revision' => 1, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    public function down(): void { /* Preserve editorial content. */ }
};
