<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LandingContent
{
    public const SCHEMA = 'jakawi-club-landing-v1';
    public const TEMPLATES = ['LEAD_VSL', 'WEBINAR', 'WAITLIST'];
    public const EDITORIAL = ['seo', 'hero', 'benefits', 'registration', 'faq', 'final'];

    /** The local LEAD_VSL template; editorial data never includes operational settings. */
    public static function defaults(): array
    {
        return [
            'presentation' => ['template' => 'LEAD_VSL', 'show_video' => true, 'show_faq' => true],
            'seo' => ['title' => 'Tu ciudad tiene mucho más para vivir', 'description' => 'Conoce el comienzo de JAKAWI en Cochabamba. Una nueva forma de descubrir tu ciudad. Vive más. Gasta menos.'],
            'hero' => ['eyebrow' => 'COCHABAMBA / PRELANZAMIENTO', 'title' => 'Tu ciudad tiene', 'emphasis' => 'mucho más', 'ending' => 'para vivir.', 'description' => 'Estamos preparando JAKAWI, una nueva forma de descubrir experiencias, lugares y oportunidades para disfrutar más de Cochabamba.', 'cta' => 'QUIERO SER PARTE'],
            'benefits' => ['eyebrow' => 'EL COMIENZO ES CONTIGO', 'title' => 'Las mejores historias empiezan siendo parte.', 'description' => 'Esto es lo que podrás hacer al sumarte, cuando abramos los registros.', 'items' => [
                ['title' => 'Estar desde el comienzo', 'description' => 'Conoce JAKAWI desde sus primeras etapas y descubre lo que estamos preparando para tu ciudad.'],
                ['title' => 'Saber cuándo encontrarnos', 'description' => 'Encuentra aquí la información de los próximos encuentros cuando sus fechas estén confirmadas.'],
                ['title' => 'Una comunidad por descubrir', 'description' => 'Participa en la comunidad cuando esté disponible. Un espacio para acompañar juntos el comienzo de JAKAWI.'],
                ['title' => 'Invitar a alguien más', 'description' => 'Una vez activada tu cuenta, tendrás un enlace personal para compartir tu invitación.'],
            ]],
            'registration' => ['eyebrow' => 'TU PRÓXIMO PASO', 'title' => 'Las buenas cosas se comparten.', 'description' => 'Una cuenta. Una invitación personal. Y una ciudad con mucho por descubrir juntos.'],
            'faq' => ['eyebrow' => 'TODO CLARO, DESDE EL INICIO', 'title' => 'Antes de dar el paso.', 'items' => [
                ['question' => '¿Qué es JAKAWI?', 'answer' => 'JAKAWI es una comunidad en preparación que empieza en Cochabamba. Este espacio permite conocer el lanzamiento, crear tu cuenta cuando abramos los registros e invitar a otras personas.'],
                ['question' => '¿Registrarse tiene costo?', 'answer' => 'El registro y la membresía de esta etapa son gratuitos. No hay pagos, compras ni comisiones disponibles en esta etapa.'],
                ['question' => '¿Qué pasa después de registrarme?', 'answer' => 'Cuando los registros estén abiertos, podrás completar este formulario. Si tu cuenta es nueva, recibirás un correo para activarla y establecer tu contraseña. Después podrás ingresar a tu cuenta. Por ahora, los registros están cerrados.'],
                ['question' => '¿Cómo funcionan las invitaciones?', 'answer' => 'Al activar tu cuenta tendrás un enlace personal para compartir. Si alguien se registra con una invitación válida, se conserva su primer referente. En tu cuenta podrás ver el número de personas que invitaste, sin sus datos personales.'],
                ['question' => '¿Qué información recibiré?', 'answer' => 'El correo se utiliza para activar la cuenta y recuperar el acceso. Aquí publicaremos las novedades del lanzamiento, los próximos encuentros y el acceso a la comunidad cuando estén confirmados.'],
            ]],
            'final' => ['eyebrow' => 'TU CIUDAD. TU PRÓXIMO CAPÍTULO.', 'title' => 'Hay mucho por vivir. Empecemos juntos.', 'description' => 'Sé parte del comienzo de JAKAWI en Cochabamba.', 'cta' => 'QUIERO SER PARTE'],
            'video' => ['youtube_url' => LaunchSettings::youtubeUrl(config('jakawi.video_url')), 'poster' => LaunchSettings::videoPoster(config('jakawi.video_poster')), 'aspect_ratio' => '3:4', 'source_aspect_ratio' => '9:16'],
        ];
    }

    public static function envelope(array $content): array
    {
        return ['schema' => self::SCHEMA, 'template' => $content['presentation']['template'] ?? 'LEAD_VSL', 'content' => array_intersect_key($content, array_flip(self::EDITORIAL))];
    }

    /** Legacy snapshots gain presentation defaults in memory, without database writes. */
    public static function withPresentation(array $content): array
    {
        return ['presentation' => ['template' => 'LEAD_VSL', 'show_video' => true, 'show_faq' => true], ...$content];
    }

    public static function import(string $json, array $base): array
    {
        try {
            $document = json_decode($json, true, 24, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['editorial_json' => 'JSON inválido o demasiado profundo.']);
        }
        Validator::make(['document' => $document], [
            'document' => ['required', 'array:schema,template,content'],
            'document.schema' => ['required', Rule::in([self::SCHEMA])],
            'document.template' => ['sometimes', 'required', Rule::in(self::TEMPLATES)],
            'document.content' => ['required', 'array:'.implode(',', self::EDITORIAL)],
        ])->validate();
        // Check supplied keys/types before merging; unknown keys are never silently discarded.
        self::validate($document['content'], true);
        $merged = self::merge(self::withPresentation($base), $document['content']);
        if (isset($document['template'])) {
            $merged['presentation']['template'] = $document['template'];
        }
        return self::validate($merged);
    }

    private static function merge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            $base[$key] = is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null)
                ? self::merge($base[$key], $value) : $value;
        }
        return $base;
    }

    public static function validate(array $content, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';
        $text = [$presence, 'required', 'string', 'max:1200', 'not_regex:/[<>]/u'];
        if (! $partial) {
            $content = self::withPresentation($content);
        }
        $rules = ['content' => ['required', 'array:seo,hero,benefits,registration,faq,final,video,presentation']];
        $rules['content.presentation'] = [$presence, 'array:template,show_video,show_faq'];
        $rules['content.presentation.template'] = [$presence, Rule::in(self::TEMPLATES)];
        foreach (['show_video', 'show_faq'] as $key) {
            $rules['content.presentation.'.$key] = [$presence, 'boolean:strict'];
        }
        $sections = [
            'seo' => ['title', 'description'], 'hero' => ['eyebrow', 'title', 'emphasis', 'ending', 'description', 'cta'],
            'benefits' => ['eyebrow', 'title', 'description', 'items'], 'registration' => ['eyebrow', 'title', 'description'],
            'faq' => ['eyebrow', 'title', 'items'], 'final' => ['eyebrow', 'title', 'description', 'cta'],
            'video' => ['youtube_url', 'poster', 'aspect_ratio', 'source_aspect_ratio'],
        ];
        foreach ($sections as $section => $keys) {
            $rules['content.'.$section] = [$presence, 'array:'.implode(',', $keys)];
            foreach ($keys as $key) {
                if ($key !== 'items' && $section !== 'video') {
                    $rules['content.'.$section.'.'.$key] = $text;
                }
            }
        }
        foreach (['benefits' => ['title', 'description'], 'faq' => ['question', 'answer']] as $section => $keys) {
            $rules['content.'.$section.'.items'] = [$presence, 'array', 'list', 'min:1', 'max:8'];
            $rules['content.'.$section.'.items.*'] = ['required', 'array:'.implode(',', $keys)];
            foreach ($keys as $key) {
                $rules['content.'.$section.'.items.*.'.$key] = ['required', 'string', 'max:1200', 'not_regex:/[<>]/u'];
            }
        }
        foreach (['youtube_url', 'poster'] as $key) {
            $rules['content.video.'.$key] = [$partial ? 'sometimes' : 'present', 'nullable', 'string', 'max:500'];
        }
        $rules['content.video.aspect_ratio'] = [$presence, Rule::in(['3:4', '16:9'])];
        $rules['content.video.source_aspect_ratio'] = [$presence, Rule::in(['9:16', '3:4', '16:9'])];
        Validator::make(['content' => $content], $rules)->after(function ($validator) use ($content): void {
            foreach (['youtube_url', 'poster'] as $key) {
                $value = $content['video'][$key] ?? null;
                if ($value !== null && ($key === 'youtube_url' ? LaunchSettings::youtubeUrl($value) : LaunchSettings::videoPoster($value)) === null) {
                    $validator->errors()->add('content.video.'.$key, $key === 'poster' ? 'Selecciona un poster local existente en /marketing/.' : 'Usa una URL HTTPS válida de YouTube.');
                }
            }
        })->validate();
        if (isset($content['video']['youtube_url'])) {
            $content['video']['youtube_url'] = LaunchSettings::youtubeUrl($content['video']['youtube_url']);
        }
        return $content;
    }
}
