<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LandingContent
{
    public const SCHEMA = 'jakawi-club-landing-v3';
    public const V2_SCHEMA = 'jakawi-club-landing-v2';
    public const LEGACY_SCHEMA = 'jakawi-club-landing-v1';
    public const TEMPLATES = ['LEAD_VSL', 'WEBINAR', 'WAITLIST'];
    public const EDITORIAL = ['seo', 'hero', 'benefits', 'registration', 'faq', 'final', 'after_registration', 'webinar'];
    public const EDITORIAL_V3 = [...self::EDITORIAL, 'presentation', 'video', 'confirmation_video', 'whatsapp_group', 'confirmation_whatsapp_group'];
    public const BENEFIT_ICONS = ['play', 'users', 'gift', 'compass', 'calendar', 'star', 'heart', 'arrow-right', 'lightbulb', 'megaphone'];

    public const WEBINAR_SECTIONS = ['hero', 'event', 'benefits', 'opportunity', 'early', 'steps', 'registration', 'faq', 'final'];

    /** The local LEAD_VSL defaults shared by the existing administrative and public flows. */
    public static function defaults(): array
    {
        return [
            'presentation' => self::presentationDefaults(),
            'commercial' => ['image' => null, 'description' => null, 'public_name' => null],
            'seo' => ['title' => '', 'description' => ''],
            'hero' => ['eyebrow' => 'COCHABAMBA / PRELANZAMIENTO', 'title' => 'Tu ciudad tiene', 'emphasis' => 'mucho más', 'ending' => 'para vivir.', 'description' => 'Estamos preparando JAKAWI, una nueva forma de descubrir experiencias, lugares y oportunidades para disfrutar más de Cochabamba.', 'cta' => 'QUIERO SER PARTE'],
            'benefits' => ['eyebrow' => 'EL COMIENZO ES CONTIGO', 'title' => 'Las mejores historias empiezan siendo parte.', 'description' => 'Esto es lo que podrás hacer al sumarte, cuando abramos los registros.', 'items' => [
                ['title' => 'Estar desde el comienzo', 'description' => 'Conoce JAKAWI desde sus primeras etapas y descubre lo que estamos preparando para tu ciudad.'],
                ['title' => 'Saber cuándo encontrarnos', 'description' => 'Encuentra aquí la información de los próximos encuentros cuando sus fechas estén confirmadas.'],
                ['title' => 'Una comunidad por descubrir', 'description' => 'Participa en la comunidad cuando esté disponible. Un espacio para acompañar juntos el comienzo de JAKAWI.'],
                ['title' => 'Invitar a alguien más', 'description' => 'Una vez activada tu cuenta, tendrás un enlace personal para compartir tu invitación.'],
            ]],
            'registration' => [...self::registrationDefaults(), 'eyebrow' => 'TU PRÓXIMO PASO', 'title' => 'Las buenas cosas se comparten.', 'description' => 'Una cuenta. Una invitación personal. Y una ciudad con mucho por descubrir juntos.'],
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
            'confirmation_whatsapp_group' => ['mode' => 'default', 'url' => null],
        ];
    }

    /** Reusable seed only: never applied to existing drafts or published snapshots. */
    public static function webinarDefaults(): array
    {
        $content = self::defaults();
        $content['presentation']['template'] = 'WEBINAR';
        $content['presentation']['webinar'] = [
            'title' => 'Próximo webinar en vivo', 'starts_at' => null,
            'sections' => array_map(fn (string $id): array => ['id' => $id, 'visible' => true], self::WEBINAR_SECTIONS),
        ];
        $content['hero']['description'] = 'Descubre JAKAWI y cómo participar desde el comienzo en nuestro próximo webinar gratuito.';
        $content['hero']['cta'] = $content['final']['cta'] = 'RESERVAR MI LUGAR GRATIS';
        $content['benefits'] = ['eyebrow' => 'QUÉ DESCUBRIRÁS', 'title' => 'Conoce el comienzo de JAKAWI.', 'description' => 'Tres temas para acompañar el prelanzamiento.', 'items' => [
            ['title' => 'Qué es JAKAWI', 'description' => 'Conoce la propuesta que estamos preparando para Cochabamba.'],
            ['title' => 'Cómo ser parte', 'description' => 'Descubre cómo crear tu cuenta gratuita y acompañar el comienzo.'],
            ['title' => 'Qué viene primero', 'description' => 'Conoce los próximos pasos del prelanzamiento.'],
        ]];
        $content['webinar'] = [
            'opportunity' => ['title' => 'Una ciudad con mucho por descubrir.', 'description' => 'A veces vivimos la rutina sin conocer las posibilidades de nuestra propia ciudad. JAKAWI comienza con una invitación a descubrir Cochabamba juntos.'],
            'early' => ['title' => 'Participa desde el comienzo.', 'description' => 'Conoce la propuesta y decide cómo acompañar esta etapa.', 'items' => [
                ['title' => 'Entiende la propuesta', 'description' => 'Escucha de qué se trata JAKAWI en el webinar.'],
                ['title' => 'Conoce los próximos pasos', 'description' => 'Descubre qué estamos preparando para el prelanzamiento.'],
                ['title' => 'Comparte tu invitación', 'description' => 'Al activar tu cuenta tendrás un enlace personal disponible.'],
            ]],
            'steps' => ['title' => 'Cómo funciona', 'items' => [
                ['title' => 'Regístrate gratis', 'description' => 'Completa tu nombre, WhatsApp y correo. Revisa tu email para activar la cuenta.'],
                ['title' => 'Entra a la comunidad', 'description' => 'Después del registro encontrarás el acceso a WhatsApp si el grupo está habilitado.'],
                ['title' => 'Participa en el webinar', 'description' => 'Consulta la fecha confirmada y las indicaciones del encuentro cuando estén disponibles.'],
            ]],
        ];
        $content['registration'] = [...self::registrationDefaults(), 'eyebrow' => 'TU PRÓXIMO PASO', 'title' => 'Reserva tu lugar gratis.', 'description' => 'Regístrate para acompañar el prelanzamiento de JAKAWI.'];
        $content['faq']['items'] = [
            ['question' => '¿Tiene costo?', 'answer' => 'El registro y el webinar de esta etapa son gratuitos.'],
            ['question' => '¿Cuándo es el webinar?', 'answer' => 'La fecha y la hora se mostrarán aquí cuando estén confirmadas. Si aún no aparecen, el encuentro sigue en preparación.'],
            ['question' => '¿Qué pasa después del registro?', 'answer' => 'Verás la página de confirmación y el grupo de WhatsApp si está habilitado. Si tu cuenta es nueva, recibirás las indicaciones de activación por correo. Tu enlace personal estará disponible después de activar la cuenta.'],
        ];
        return $content;
    }

    /** Copy only: field identities, actions and consent remain owned by the real form. */
    public static function registrationDefaults(): array
    {
        return ['form_title' => 'Tu lugar en JAKAWI', 'form_subtitle' => '',
            'name_placeholder' => 'Tu nombre completo', 'phone_placeholder' => 'WhatsApp',
            'email_placeholder' => 'Tu correo electrónico', 'submit_label' => null,
            'submit_microcopy' => '', 'existing_account_label' => '¿Ya tienes cuenta?',
            'existing_account_link_label' => 'Ingresa aquí'];
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
        return ['schema' => self::SCHEMA, 'template' => $content['presentation']['template'], 'content' => array_intersect_key($content, array_flip(self::EDITORIAL_V3))];
    }

    /** Legacy snapshots gain presentation defaults in memory, without database writes. */
    public static function withPresentation(array $content): array
    {
        $content = ['commercial' => ['image' => null, 'description' => null, 'public_name' => null], 'after_registration' => self::confirmationDefaults(), 'next_step' => ['action' => 'recover', 'redirect_seconds' => 0],
            'whatsapp_group' => ['mode' => 'default', 'url' => null], 'confirmation_whatsapp_group' => ['mode' => 'inherit', 'url' => null], ...$content];
        if (($content['after_registration']['title'] ?? null) === 'Gracias por dar el siguiente paso.') {
            $content['after_registration']['title'] = self::confirmationDefaults()['title'];
        }
        if (! isset($content['presentation']) || is_array($content['presentation'])) {
            $content['presentation'] = [...self::presentationDefaults(), ...($content['presentation'] ?? [])];
        }
        if (isset($content['registration']) && is_array($content['registration'])) {
            $content['registration'] = [...self::registrationDefaults(), ...$content['registration']];
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

    public static function import(string $json, array $base, bool $newLanding = false): array
    {
        if (strlen($json) > 65536) {
            throw ValidationException::withMessages(['editorial_json' => 'El documento supera 65536 bytes UTF-8 (64 KiB).']);
        }
        try {
            $document = json_decode($json, true, 24, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['editorial_json' => 'JSON inválido o demasiado profundo.']);
        }
        $v3 = is_array($document) && ($document['schema'] ?? null) === self::SCHEMA;
        self::validator(['document' => $document], [
            'document' => ['required', 'array:schema,template,content'],
            'document.schema' => ['required', Rule::in([self::SCHEMA, self::V2_SCHEMA, self::LEGACY_SCHEMA])],
            'document.template' => ['sometimes', 'required', Rule::in(self::TEMPLATES)],
            'document.content' => ['required', 'array:'.implode(',', $v3 ? self::EDITORIAL_V3 : self::EDITORIAL)],
        ])->validate();
        // Check supplied keys/types before merging; unknown keys are never silently discarded.
        $patch = self::validate($document['content'], true);
        if (isset($document['template'])) {
            if ($v3 && isset($patch['presentation']['template']) && $patch['presentation']['template'] !== $document['template']) {
                throw ValidationException::withMessages(['content.presentation.template' => 'Debe coincidir con template en la raíz del JSON.']);
            }
            $patch['presentation']['template'] = $document['template'];
        }
        if ($v3 && $newLanding) {
            // A new v3 document is self-contained: never inherit campaign media or environment defaults.
            $required = array_diff(self::EDITORIAL_V3, ['webinar']);
            $rules = [];
            foreach ($required as $key) {
                $rules['content.'.$key] = ['required', 'array'];
            }
            foreach (['show_video', 'show_faq', 'show_confirmation_video', 'template'] as $key) {
                $rules['content.presentation.'.$key] = ['required'];
            }
            foreach (['video', 'confirmation_video'] as $key) {
                foreach (array_keys(self::videoDefaults()) as $field) {
                    $rules['content.'.$key.'.'.$field] = [in_array($field, ['youtube_url', 'poster']) ? 'present' : 'required'];
                }
            }
            foreach (['whatsapp_group', 'confirmation_whatsapp_group'] as $key) {
                $rules['content.'.$key.'.mode'] = ['required'];
                $rules['content.'.$key.'.url'] = ['present'];
            }
            if (($patch['presentation']['template'] ?? null) === 'WEBINAR') {
                $rules['content.webinar'] = ['required', 'array'];
                $rules['content.presentation.webinar'] = ['required', 'array'];
            }
            self::validator(['content' => $patch], $rules)->validate();
            return self::validate([...$patch, 'next_step' => ['action' => 'recover', 'redirect_seconds' => 0]]);
        }
        return self::validate(self::merge(self::withPresentation($base), $patch));
    }

    /** Reuse Laravel rules and report unknown properties at their exact JSON path. */
    private static function validator(array $data, array $rules): \Illuminate\Validation\Validator
    {
        return Validator::make($data, $rules, [
            'required' => ':attribute es obligatorio y no puede estar vacío.',
            'required_with' => ':attribute es obligatorio para completar esta sección.',
            'present' => ':attribute debe estar presente; usa null solo si el campo lo permite.',
            'present_with' => ':attribute debe estar presente; usa null si la fecha no está confirmada.',
            'array' => ':attribute debe ser un objeto o una lista con las propiedades permitidas.',
            'list' => ':attribute debe ser una lista JSON consecutiva.',
            'string' => ':attribute debe ser texto.',
            'max' => ['string' => ':attribute no puede superar :max caracteres.', 'array' => ':attribute no puede tener más de :max elementos.'],
            'min' => ['array' => ':attribute debe tener al menos :min elemento.'],
            'size' => ['array' => ':attribute debe contener exactamente :size elementos.'],
            'in' => ':attribute contiene un valor no admitido. Valores permitidos: :values.',
            'boolean' => ':attribute debe ser true o false, sin comillas.',
            'numeric' => ':attribute debe ser un número JSON, sin comillas.',
            'between' => ['numeric' => ':attribute debe estar entre :min y :max.'],
            'distinct' => ':attribute está repetido; cada sección debe aparecer una sola vez.',
            'date_format' => ':attribute debe ser una fecha válida con formato YYYY-MM-DDTHH:mm:ss-04:00.',
            'regex' => ':attribute tiene un formato inválido; la fecha requiere el huso -04:00 de Bolivia.',
            'not_regex' => ':attribute no admite < ni >.',
            'content.registration.*.not_regex' => ':attribute solo admite texto plano, sin HTML ni URLs.',
            'content.after_registration.*.not_regex' => ':attribute no admite < ni > ni afirmar cuenta activada, correo verificado o correo enviado; el servidor confirma esos estados.',
        ])->after(function (\Illuminate\Validation\Validator $validator): void {
            foreach ($validator->failed() as $path => $failures) {
                $allowed = $failures['Array'] ?? [];
                $value = data_get($validator->getData(), $path);
                if ($allowed && is_array($value)) {
                    foreach (array_diff(array_keys($value), $allowed) as $unknown) {
                        $validator->errors()->add($path.'.'.$unknown, 'Propiedad no admitida en este contrato JSON.');
                    }
                }
            }
        });
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
        $rules = ['content' => ['required', 'array:seo,hero,benefits,registration,faq,final,video,confirmation_video,presentation,after_registration,next_step,whatsapp_group,confirmation_whatsapp_group,webinar,commercial']];
        $rules['content.commercial'] = ['sometimes', 'array:image,description,public_name'];
        $rules['content.commercial.image'] = ['sometimes', 'nullable', 'string', 'max:500'];
        $rules['content.commercial.description'] = ['sometimes', 'nullable', 'string', 'max:320', 'not_regex:/[<>]/u'];
        // Captured at publication, never resolved from a mutable draft name.
        $rules['content.commercial.public_name'] = ['sometimes', 'nullable', 'string', 'max:120', 'not_regex:/[<>]/u'];
        $rules['content.presentation'] = [$presence, 'array:template,show_video,show_faq,show_confirmation_video,webinar'];
        $rules['content.presentation.template'] = [$presence, Rule::in(self::TEMPLATES)];
        $rules['content.presentation.webinar'] = ['sometimes', 'required', 'array:title,starts_at,sections'];
        $rules['content.presentation.webinar.title'] = [...($partial ? ['sometimes', 'required'] : ['required_with:content.presentation.webinar']), 'string', 'max:1200', 'not_regex:/[<>]/u'];
        $rules['content.presentation.webinar.starts_at'] = [$partial ? 'sometimes' : 'present_with:content.presentation.webinar', 'nullable', 'date_format:Y-m-d\\TH:i:sP', 'regex:/-04:00$/D'];
        $rules['content.presentation.webinar.sections'] = [$partial ? 'sometimes' : 'required_with:content.presentation.webinar', 'array', 'list', 'size:9'];
        $rules['content.presentation.webinar.sections.*'] = ['required', 'array:id,visible'];
        $rules['content.presentation.webinar.sections.*.id'] = ['required', 'distinct:strict', Rule::in(self::WEBINAR_SECTIONS)];
        $rules['content.presentation.webinar.sections.*.visible'] = ['required', 'boolean:strict'];
        $rules['content.webinar'] = ['sometimes', 'required', 'array:opportunity,early,steps'];
        foreach (['opportunity' => ['title', 'description'], 'early' => ['title', 'description', 'items'], 'steps' => ['title', 'items']] as $section => $keys) {
            $rules['content.webinar.'.$section] = [$partial ? 'sometimes' : 'required_with:content.webinar', 'array:'.implode(',', $keys)];
            foreach ($keys as $key) {
                if ($key !== 'items') {
                    $rules['content.webinar.'.$section.'.'.$key] = [...($partial ? ['sometimes', 'required'] : ['required_with:content.webinar']), 'string', 'max:1200', 'not_regex:/[<>]/u'];
                }
            }
            if (in_array('items', $keys)) {
                $rules['content.webinar.'.$section.'.items'] = [$partial ? 'sometimes' : 'required_with:content.webinar', 'array', 'list', 'size:3'];
                $rules['content.webinar.'.$section.'.items.*'] = ['required', 'array:title,description'];
                foreach (['title', 'description'] as $key) {
                    $rules['content.webinar.'.$section.'.items.*.'.$key] = ['required', 'string', 'max:1200', 'not_regex:/[<>]/u'];
                }
            }
        }
        foreach (['show_video', 'show_faq', 'show_confirmation_video'] as $key) {
            $rules['content.presentation.'.$key] = [$presence, 'boolean:strict'];
        }
        foreach (['whatsapp_group' => 'default', 'confirmation_whatsapp_group' => 'inherit'] as $section => $default) {
            $rules['content.'.$section] = [$presence, 'array:mode,url'];
            $rules['content.'.$section.'.mode'] = [$presence, Rule::in(array_unique([$default, 'default', 'custom', 'none']))];
            $rules['content.'.$section.'.url'] = [$partial ? 'sometimes' : 'present', 'nullable', 'string', 'max:500'];
        }
        $sections = [
            'seo' => ['title', 'description'], 'hero' => ['eyebrow', 'title', 'emphasis', 'ending', 'description', 'cta'],
            'benefits' => ['eyebrow', 'title', 'description', 'items'], 'registration' => ['eyebrow', 'title', 'description', ...array_keys(self::registrationDefaults())],
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
        foreach (['title' => 160, 'description' => 320] as $key => $limit) {
            $rules['content.seo.'.$key] = [$partial ? 'sometimes' : 'present', 'nullable', 'string', 'max:'.$limit, 'not_regex:/[<>]/u'];
        }
        foreach (array_keys(self::registrationDefaults()) as $key) {
            $rules['content.registration.'.$key] = ['sometimes',
                ...(in_array($key, ['submit_label', 'form_subtitle', 'submit_microcopy']) ? ['nullable'] : ['required']),
                'string', 'max:1200', 'not_regex:~[<>]|[a-z][a-z0-9+.-]*://|(?:javascript|data|mailto|tel):|//|www\\.|\\b[a-z0-9-]+\\.[a-z]{2,}(?:\\b|/)~iu'];
        }
        $rules['content.next_step'] = [$presence, 'array:action,redirect_seconds'];
        $rules['content.next_step.action'] = [$presence, Rule::in(['none', 'recover', 'account', 'home', 'whatsapp'])];
        $rules['content.next_step.redirect_seconds'] = [$presence, 'integer', Rule::in([0, 5, 10, 15, 30, 60])];
        foreach (['title', 'message', 'instructions', 'cta_label'] as $key) {
            $rules['content.after_registration.'.$key][] = 'not_regex:/(?:cuenta\s+(?:est[aá]\s+)?activada|correo\s+(?:est[aá]\s+)?verificado|correo\s+enviado)/iu';
        }
        foreach (['benefits' => ['title', 'description'], 'faq' => ['question', 'answer']] as $section => $keys) {
            $rules['content.'.$section.'.items'] = [$presence, 'array', 'list', 'min:1', 'max:8'];
            $rules['content.'.$section.'.items.*'] = ['required', 'array:'.implode(',', $section === 'benefits' ? [...$keys, 'icon'] : $keys)];
            if ($section === 'benefits') {
                $rules['content.benefits.items.*.icon'] = ['sometimes', 'required', 'string', Rule::in(self::BENEFIT_ICONS)];
            }
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
        self::validator(['content' => $content], $rules)->after(function ($validator) use ($content, $partial): void {
            $image = $content['commercial']['image'] ?? null;
            if ($image !== null && !app(\App\Services\MediaUrl::class)->validReference($image)) {
                $validator->errors()->add('content.commercial.image', 'Selecciona una imagen válida desde el control de subida.');
            }
            if (isset($content['presentation']['webinar'])) {
                if (($content['presentation']['webinar']['starts_at'] ?? null) === '') {
                    $validator->errors()->add('content.presentation.webinar.starts_at', 'Usa null si no hay fecha confirmada, o una fecha válida con formato YYYY-MM-DDTHH:mm:ss-04:00.');
                }
                if (! $partial && ! isset($content['webinar'])) {
                    $validator->errors()->add('content.webinar', 'Completa el contenido de la composición WEBINAR.');
                }
                $sections = $content['presentation']['webinar']['sections'] ?? [];
                foreach (is_array($sections) ? $sections : [] as $index => $section) {
                    if (is_array($section) && ($section['id'] ?? null) === 'registration' && ($section['visible'] ?? null) !== true) {
                        $validator->errors()->add('content.presentation.webinar.sections.'.$index.'.visible', 'El formulario de registro debe permanecer visible.');
                    }
                }
            }
            foreach (['whatsapp_group', 'confirmation_whatsapp_group'] as $section) {
                $url = $content[$section]['url'] ?? null;
                if (((! $partial && ($content[$section]['mode'] ?? '') === 'custom') || $url !== null)
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
        if (is_string($content['registration']['submit_label'] ?? null) && trim($content['registration']['submit_label']) === '') {
            $content['registration']['submit_label'] = null;
        }
        foreach (['form_subtitle', 'submit_microcopy'] as $key) {
            if (array_key_exists($key, $content['registration'] ?? []) && $content['registration'][$key] === null) {
                $content['registration'][$key] = '';
            }
        }
        if (isset($content['seo'])) {
            foreach (['title', 'description'] as $key) {
                if (array_key_exists($key, $content['seo']) && $content['seo'][$key] === null) $content['seo'][$key] = '';
            }
        }
        return $content;
    }
}
