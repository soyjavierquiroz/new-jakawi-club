<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LandingContent
{
    public const SCHEMA = 'jakawi-club-landing-v2';
    public const LEGACY_SCHEMA = 'jakawi-club-landing-v1';
    public const TEMPLATES = ['LEAD_VSL', 'WEBINAR', 'WAITLIST'];
    public const EDITORIAL = ['seo', 'hero', 'benefits', 'registration', 'faq', 'final', 'after_registration'];

    /** The local LEAD_VSL template; editorial data never includes operational settings. */
    public static function defaults(): array
    {
        return [
            'presentation' => self::presentationDefaults(),
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
            'after_registration' => self::confirmationDefaults(),
            'next_step' => ['action' => 'recover', 'redirect_seconds' => 0],
            'video' => self::videoDefaults(LaunchSettings::defaultVideoUrl(config('jakawi.video_url')), LaunchSettings::videoPoster(config('jakawi.video_poster'))),
            'confirmation_video' => self::videoDefaults(),
            'whatsapp_group' => ['mode' => 'default', 'url' => null],
            'confirmation_whatsapp_group' => ['mode' => 'inherit', 'url' => null],
        ];
    }

    public static function confirmationDefaults(): array
    {
        return ['title' => '¡Felicidades por dar el siguiente paso!', 'message' => 'Recibimos tu solicitud. Si podemos completarla, recibirás un correo con el siguiente paso. Revisa también tu carpeta de spam.', 'instructions' => 'Continúa con el siguiente paso. Puedes solicitar otro enlace de acceso si lo necesitas.', 'cta_label' => 'Continuar'];
    }

    public static function videoDefaults(?string $url = null, ?string $poster = null): array
    {
        return ['youtube_url' => $url, 'poster' => $poster, 'aspect_ratio' => '3:4',
            'source_type' => 'youtube', 'source_aspect_ratio' => '9:16', 'focal_x' => 0.5, 'focal_y' => 0.5];
    }

    private static function presentationDefaults(): array
    {
        return ['template' => 'LEAD_VSL', 'show_video' => true, 'show_faq' => true, 'show_confirmation_video' => true];
    }

    public static function confirmationVideo(array $video = []): array
    {
        $video = [...self::videoDefaults(), ...$video, 'aspect_ratio' => '3:4'];
        $video['youtube_url'] = LaunchSettings::youtubeUrl($video['youtube_url'])
            ?? LaunchSettings::defaultVideoUrl(config('jakawi.confirmation_video_url'));
        $video['poster'] = LaunchSettings::videoPoster($video['poster']);
        return $video;
    }

    public static function envelope(array $content): array
    {
        $content = self::withPresentation($content);
        return ['schema' => self::SCHEMA, 'template' => $content['presentation']['template'] ?? 'LEAD_VSL', 'content' => array_intersect_key($content, array_flip(self::EDITORIAL))];
    }

    /** Legacy snapshots gain presentation defaults in memory, without database writes. */
    public static function withPresentation(array $content): array
    {
        $content = ['after_registration' => self::confirmationDefaults(), 'next_step' => ['action' => 'recover', 'redirect_seconds' => 0],
            'whatsapp_group' => ['mode' => 'default', 'url' => null], 'confirmation_whatsapp_group' => ['mode' => 'inherit', 'url' => null], ...$content];
        if (! isset($content['presentation']) || is_array($content['presentation'])) {
            $content['presentation'] = [...self::presentationDefaults(), ...($content['presentation'] ?? [])];
        }
        foreach (['video', 'confirmation_video'] as $key) {
            $video = $content[$key] ?? [];
            if (! is_array($video)) {
                continue;
            }
            // Add metadata without changing a campaign's published media or visibility.
            // Legacy frames normalize in memory; the visual ratio is no longer editable.
            $content[$key] = [...self::videoDefaults(), ...$video, 'aspect_ratio' => '3:4'];
        }
        return $content;
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
            'document.schema' => ['required', Rule::in([self::SCHEMA, self::LEGACY_SCHEMA])],
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
        $rules = ['content' => ['required', 'array:seo,hero,benefits,registration,faq,final,video,confirmation_video,presentation,after_registration,next_step,whatsapp_group,confirmation_whatsapp_group']];
        $rules['content.presentation'] = [$presence, 'array:template,show_video,show_faq,show_confirmation_video'];
        $rules['content.presentation.template'] = [$presence, Rule::in(self::TEMPLATES)];
        foreach (['show_video', 'show_faq', 'show_confirmation_video'] as $key) {
            $rules['content.presentation.'.$key] = [$presence, 'boolean:strict'];
        }
        foreach (['whatsapp_group' => 'default', 'confirmation_whatsapp_group' => 'inherit'] as $section => $default) {
            $rules['content.'.$section] = [$presence, 'array:mode,url'];
            $rules['content.'.$section.'.mode'] = [$presence, Rule::in([$default, 'custom', 'none'])];
            $rules['content.'.$section.'.url'] = [$partial ? 'sometimes' : 'present', 'nullable', 'string', 'max:500'];
        }
        $sections = [
            'seo' => ['title', 'description'], 'hero' => ['eyebrow', 'title', 'emphasis', 'ending', 'description', 'cta'],
            'benefits' => ['eyebrow', 'title', 'description', 'items'], 'registration' => ['eyebrow', 'title', 'description'],
            'faq' => ['eyebrow', 'title', 'items'], 'final' => ['eyebrow', 'title', 'description', 'cta'],
            'after_registration' => ['title', 'message', 'instructions', 'cta_label'],
        ];
        foreach ($sections as $section => $keys) {
            $rules['content.'.$section] = [$presence, 'array:'.implode(',', $keys)];
            foreach ($keys as $key) {
                if ($key !== 'items') {
                    $rules['content.'.$section.'.'.$key] = $text;
                }
            }
        }
        $rules['content.next_step'] = [$presence, 'array:action,redirect_seconds'];
        $rules['content.next_step.action'] = [$presence, Rule::in(['none', 'recover', 'account', 'home', 'whatsapp'])];
        $rules['content.next_step.redirect_seconds'] = [$presence, 'integer', Rule::in([0, 5, 10, 15, 30, 60])];
        foreach (['title', 'message', 'instructions', 'cta_label'] as $key) {
            $rules['content.after_registration.'.$key][] = 'not_regex:/(?:cuenta\s+(?:est[aá]\s+)?activada|correo\s+(?:est[aá]\s+)?verificado|correo\s+enviado)/iu';
        }
        foreach (['benefits' => ['title', 'description'], 'faq' => ['question', 'answer']] as $section => $keys) {
            $rules['content.'.$section.'.items'] = [$presence, 'array', 'list', 'min:1', 'max:8'];
            $rules['content.'.$section.'.items.*'] = ['required', 'array:'.implode(',', $keys)];
            foreach ($keys as $key) {
                $rules['content.'.$section.'.items.*.'.$key] = ['required', 'string', 'max:1200', 'not_regex:/[<>]/u'];
            }
        }
        foreach (['video', 'confirmation_video'] as $section) {
            $rules['content.'.$section] = [$presence, 'array:youtube_url,poster,aspect_ratio,source_type,source_aspect_ratio,focal_x,focal_y'];
            foreach (['youtube_url', 'poster'] as $key) {
                $rules['content.'.$section.'.'.$key] = [$partial ? 'sometimes' : 'present', 'nullable', 'string', 'max:500'];
            }
            $rules['content.'.$section.'.aspect_ratio'] = [$presence, Rule::in(['3:4'])];
            $rules['content.'.$section.'.source_type'] = [$presence, Rule::in(['youtube'])];
            $rules['content.'.$section.'.source_aspect_ratio'] = [$presence, Rule::in(['9:16', '3:4', '16:9'])];
            foreach (['focal_x', 'focal_y'] as $key) {
                $rules['content.'.$section.'.'.$key] = [$presence, 'numeric:strict', 'between:0,1'];
            }
        }
        Validator::make(['content' => $content], $rules)->after(function ($validator) use ($content): void {
            foreach (['whatsapp_group', 'confirmation_whatsapp_group'] as $section) {
                $url = $content[$section]['url'] ?? null;
                if ((($content[$section]['mode'] ?? '') === 'custom' || $url !== null)
                    && RegistrationNextStep::validGroupUrl($url) === null) {
                    $validator->errors()->add('content.'.$section.'.url', 'Usa una invitación HTTPS de chat.whatsapp.com, sin parámetros ni redirecciones.');
                }
            }
            foreach (['video', 'confirmation_video'] as $section) {
                foreach (['youtube_url', 'poster'] as $key) {
                    $value = $content[$section][$key] ?? null;
                    if ($value !== null && ($key === 'youtube_url' ? LaunchSettings::youtubeUrl($value) : LaunchSettings::videoPoster($value)) === null) {
                        $validator->errors()->add('content.'.$section.'.'.$key, $key === 'poster' ? 'Selecciona un poster local existente en /marketing/.' : 'Usa una URL HTTPS válida de YouTube.');
                    }
                }
            }
        })->validate();
        foreach (['video', 'confirmation_video'] as $section) {
            if (isset($content[$section]['youtube_url'])) {
                $content[$section]['youtube_url'] = LaunchSettings::youtubeUrl($content[$section]['youtube_url']);
            }
        }
        return $content;
    }
}
