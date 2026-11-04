<?php

namespace App\Http\Controllers;

use App\Models\MarketingLanding;
use App\Support\LandingContent;
use App\Support\LandingSlug;
use App\Support\LaunchSettings;
use App\Support\RegistrationNextStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LandingAdminController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Landings', ['landings' => MarketingLanding::query()->orderByDesc('updated_at')->get(['id', 'title', 'slug', 'status', 'campaign', 'revision', 'published_at']), 'ai_kit_zip_available' => LandingAiKitController::zipAvailable(), 'home_slug' => config('jakawi.home_landing_slug')]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/LandingEditor', ['landing' => null, 'defaults' => LandingContent::defaults(), 'webinar_defaults' => LandingContent::webinarDefaults(), 'ai_kit_zip_available' => LandingAiKitController::zipAvailable(), 'home_slug' => config('jakawi.home_landing_slug'), 'registration_open' => LaunchSettings::registrationOpen(), 'whatsapp_group_available' => RegistrationNextStep::whatsappGroup() !== null, 'confirmation_video_default_url' => LaunchSettings::defaultVideoUrl(config('jakawi.confirmation_video_url'))]);
    }

    public function edit(MarketingLanding $landing): Response
    {
        return Inertia::render('admin/LandingEditor', [
            'opportunity_image_url' => app(\App\Services\MediaUrl::class)->url($landing->configuration['webinar']['opportunity']['image']['asset_id'] ?? null, 'opportunity'),
            'opportunity_image_metadata' => app(\App\Services\MediaUploadService::class)->metadata($landing->configuration['webinar']['opportunity']['image']['asset_id'] ?? null),
            'commercial_image_url' => app(\App\Services\MediaUrl::class)->url($landing->configuration['commercial']['image'] ?? null, 'thumbnail'),
            'commercial_card_url' => app(\App\Services\MediaUrl::class)->url($landing->configuration['commercial']['image'] ?? null, 'card'),
            'landing' => [...$landing->toArray(), 'configuration' => LandingContent::withPresentation($landing->configuration), 'published_configuration' => $landing->published_configuration ? LandingContent::withPresentation($landing->published_configuration) : null], 'defaults' => null, 'webinar_defaults' => LandingContent::webinarDefaults(), 'ai_kit_zip_available' => LandingAiKitController::zipAvailable(), 'home_slug' => config('jakawi.home_landing_slug'), 'registration_open' => LaunchSettings::registrationOpen(), 'whatsapp_group_available' => RegistrationNextStep::whatsappGroup() !== null,
            'metrics' => DB::table('marketing_landing_event_totals')->where('landing_id', $landing->id)
                ->select('event', 'campaign')->selectRaw('SUM(total) AS total')->groupBy('event', 'campaign')->get(),
            'webinar_metrics' => ($landing->published_configuration['presentation']['template'] ?? null) === 'WEBINAR' ? [
                'totals' => DB::table('marketing_landing_event_totals')->where('landing_id', $landing->id)->whereIn('event', ['webinar_visit', 'webinar_registration'])->select('event', 'placement')->selectRaw('SUM(total) AS total')->groupBy('event', 'placement')->get(),
                'origins' => DB::table('users')->whereRaw("registration_origin->>'landing_slug' = ?", [$landing->slug])->selectRaw("COALESCE(registration_origin->>'channel', 'anterior') AS channel, registration_origin->'utms'->>'utm_source' AS source, registration_origin->'utms'->>'utm_campaign' AS campaign, COUNT(*) AS total")->groupByRaw('1,2,3')->get(),
                'meta_events' => DB::table('ad_events')->where('provider', 'meta')->where('event_name', 'CompleteRegistration')->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->get(),
            ] : null,
            'confirmation_video_default_url' => LaunchSettings::defaultVideoUrl(config('jakawi.confirmation_video_url')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->editorData($request);
        $landing = DB::transaction(function () use ($request, $data): MarketingLanding {
            $landing = MarketingLanding::create([...$data, 'status' => 'draft']);
            $landing->configuration = $this->mediaConfiguration($request, $landing, $data['configuration']);
            $landing->save();
            return $landing;
        });
        return redirect()->route('landings.edit', $landing)->with('status', 'Borrador creado. Puedes previsualizarlo antes de publicar.');
    }

    public function update(Request $request, MarketingLanding $landing): RedirectResponse
    {
        DB::transaction(function () use ($request, $landing): void {
            $locked = $this->lock($request, $landing);
            $data = $this->editorData($request, $locked);
            $data['configuration'] = $this->mediaConfiguration($request, $locked, $data['configuration']);
            $locked->fill($data);
            $locked->revision++;
            $locked->save();
        });
        return back()->with('status', 'Borrador guardado. La versión pública no cambia hasta publicar.');
    }

    public function duplicate(MarketingLanding $landing): RedirectResponse
    {
        $copy = DB::transaction(function () use ($landing): MarketingLanding {
            $source = MarketingLanding::query()->lockForUpdate()->findOrFail($landing->id);
            $copy = $source->replicate(['slug', 'status', 'published_configuration', 'published_campaign', 'published_at', 'revision']);
            $copy->title = mb_substr($source->title, 0, 110).' (copia)';
            do {
                $copy->slug = mb_substr($source->slug, 0, 75).'-copia-'.strtolower(\Illuminate\Support\Str::random(10));
            } while (MarketingLanding::where('slug', $copy->slug)->exists());
            $copy->status = 'draft';
            $copy->revision = 1;
            $copy->save();
            return $copy;
        });
        return redirect()->route('landings.edit', $copy)->with('status', 'Copia creada como borrador independiente.');
    }

    public function import(Request $request, ?MarketingLanding $landing = null): JsonResponse
    {
        $data = $request->validate(['editorial_json' => ['required', 'string', 'max:65536'], 'configuration' => ['sometimes', 'required', 'array']], [
            'editorial_json.required' => 'Selecciona un archivo o pega un documento JSON.',
            'editorial_json.string' => 'editorial_json debe contener el documento como texto JSON.',
            'editorial_json.max' => 'El documento supera 65536 bytes UTF-8 (64 KiB).',
            'configuration.array' => 'configuration debe contener la configuración actual del formulario.',
        ]);
        $base = LandingContent::validate($data['configuration'] ?? $landing?->configuration ?? LandingContent::defaults());
        $configuration = LandingContent::import($data['editorial_json'], $base, $landing === null);
        $asset = $configuration['webinar']['opportunity']['image']['asset_id'] ?? null;
        return response()->json(['configuration' => $configuration,
            'opportunity_image_url' => app(\App\Services\MediaUrl::class)->url($asset, 'opportunity'),
            'opportunity_image_metadata' => app(\App\Services\MediaUploadService::class)->metadata($asset)]);
    }

    public function export(MarketingLanding $landing): JsonResponse
    {
        return response()->json(LandingContent::envelope($landing->configuration), 200, [
            'Content-Disposition' => 'attachment; filename="'.$landing->slug.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function publish(Request $request, MarketingLanding $landing): RedirectResponse
    {
        DB::transaction(function () use ($request, $landing): void {
            $locked = $this->lock($request, $landing);
            if ($locked->published_configuration !== null) {
                $snapshot = json_encode($locked->published_configuration, JSON_THROW_ON_ERROR);
                DB::table('marketing_landing_snapshots')->insertOrIgnore(['landing_id' => $locked->id,
                    'snapshot_hash' => hash('sha256', $snapshot), 'configuration' => $snapshot, 'created_at' => now()]);
            }
            $locked->published_configuration = LandingContent::validate($locked->configuration);
            $locked->published_configuration = [...$locked->published_configuration, 'commercial' => [...$locked->published_configuration['commercial'], 'public_name' => \App\Support\LandingCommercial::text($locked->title, 120)]];
            $locked->published_campaign = $locked->campaign;
            $locked->published_at = now();
            $locked->status = 'published';
            $locked->revision++;
            $locked->save();
        });
        if ($landing->isHome()) {
            app(\App\Services\CommercialCatalog::class)->syncClub();
        }
        return back()->with('status', 'Versión guardada publicada.');
    }

    public function archive(Request $request, MarketingLanding $landing): RedirectResponse
    {
        DB::transaction(function () use ($request, $landing): void {
            $locked = $this->lock($request, $landing);
            if ($locked->isHome()) {
                throw ValidationException::withMessages(['publication' => 'La landing principal no se puede archivar.']);
            }
            $locked->status = 'archived';
            $locked->revision++;
            $locked->save();
        });
        return back()->with('status', 'Landing archivada. Su ruta pública ya no está disponible.');
    }

    private function lock(Request $request, MarketingLanding $landing): MarketingLanding
    {
        DB::statement('LOCK TABLE marketing_landings IN ROW EXCLUSIVE MODE');
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $locked = MarketingLanding::query()->lockForUpdate()->findOrFail($landing->id);
        if ($locked->revision !== (int) $data['revision']) {
            throw ValidationException::withMessages(['revision' => 'El borrador cambió en otra ventana. Recarga antes de continuar.']);
        }
        return $locked;
    }

    private function mediaConfiguration(Request $request, MarketingLanding $landing, array $configuration): array
    {
        if ($request->filled('opportunity_image_upload') && $request->boolean('opportunity_image_remove')) {
            throw ValidationException::withMessages(['opportunity_image_upload' => 'Selecciona subir o quitar la imagen.']);
        }
        if ($request->filled('opportunity_image_upload')) {
            if (!isset($configuration['webinar']['opportunity'])) {
                throw ValidationException::withMessages(['opportunity_image_upload' => 'La sección Oportunidad no está configurada.']);
            }
            try {
                $key = app(\App\Services\MediaUploadService::class)->promote($request->string('opportunity_image_upload')->toString(), $request->user()->id, $landing->id, 'opportunity');
            } catch (ValidationException) {
                throw ValidationException::withMessages(['opportunity_image_upload' => 'La subida caducó, no te pertenece o no está disponible. Selecciona la imagen de nuevo.']);
            }
            $configuration['webinar']['opportunity']['image'] = ['asset_id' => $key, 'alt' => $request->input('opportunity_image_alt') ?? '', 'position' => 'right', 'fit' => 'contain'];
        } elseif ($request->boolean('opportunity_image_remove') && isset($configuration['webinar']['opportunity'])) {
            // Detach only: shared references and publication snapshots remain protected by MediaGc.
            $configuration['webinar']['opportunity']['image'] = null;
        }
        $image = $configuration['commercial']['image'] ?? null;
        if ($request->filled('commercial_image_upload') && $request->boolean('commercial_image_remove')) {
            throw ValidationException::withMessages(['commercial_image_upload' => 'Selecciona subir o quitar la imagen.']);
        }
        if ($request->filled('commercial_image_upload')) {
            $image = app(\App\Services\MediaUploadService::class)->promote($request->string('commercial_image_upload')->toString(), $request->user()->id, $landing->id);
        } elseif ($request->boolean('commercial_image_remove')) {
            $image = null;
        } elseif (app(\App\Services\MediaUploadService::class)->isMediaKey($image)
            && $image !== ($landing->configuration['commercial']['image'] ?? null)
            && !app(\App\Services\MediaReferences::class)->contains($image)) {
            throw ValidationException::withMessages(['commercial_image_upload' => 'Selecciona una imagen mediante el control de subida.']);
        }
        $configuration['commercial']['image'] = $image;
        return $configuration;
    }

    private function editorData(Request $request, ?MarketingLanding $landing = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('marketing_landings', 'slug')->ignore($landing?->id)],
            'campaign' => ['nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'configuration' => ['required', 'array'],
            'opportunity_image_upload' => ['nullable', 'string', 'max:8192'],
            'opportunity_image_remove' => ['sometimes', 'boolean'],
            'opportunity_image_alt' => ['sometimes', 'nullable', 'string', 'max:320', 'not_regex:/[<>]/u'],
            'commercial_image_upload' => ['nullable', 'string', 'max:8192'],
            'commercial_image_remove' => ['sometimes', 'boolean'],
        ]);
        if (! LandingSlug::allowed($data['slug'])) {
            throw ValidationException::withMessages(['slug' => 'Este slug está reservado para una ruta del sitio.']);
        }
        if (($landing?->published_at !== null || $landing?->isHome()) && $data['slug'] !== $landing->slug) {
            throw ValidationException::withMessages(['slug' => 'El slug es permanente después de publicar para conservar enlaces y analítica.']);
        }
        unset($data['commercial_image_upload'], $data['commercial_image_remove'], $data['opportunity_image_upload'], $data['opportunity_image_remove'], $data['opportunity_image_alt']);
        // A detach/replacement must also work if the previous object became unavailable.
        // Only explicit media operations clear it; ordinary text edits retain the reference.
        if (($request->boolean('opportunity_image_remove') || $request->filled('opportunity_image_upload'))
            && isset($data['configuration']['webinar']['opportunity']) && is_array($data['configuration']['webinar']['opportunity'])) {
            $data['configuration']['webinar']['opportunity']['image'] = null;
        }
        $data['configuration'] = LandingContent::validate($data['configuration']);
        $data['template'] = $data['configuration']['presentation']['template'];
        return $data;
    }
}
