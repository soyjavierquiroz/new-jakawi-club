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
        return Inertia::render('admin/Landings', ['landings' => MarketingLanding::query()->orderByDesc('updated_at')->get(['id', 'title', 'slug', 'status', 'campaign', 'revision', 'published_at']), 'home_slug' => config('jakawi.home_landing_slug')]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/LandingEditor', ['landing' => null, 'defaults' => LandingContent::defaults(), 'webinar_defaults' => LandingContent::webinarDefaults(), 'home_slug' => config('jakawi.home_landing_slug'), 'registration_open' => LaunchSettings::registrationOpen(), 'whatsapp_group_available' => RegistrationNextStep::whatsappGroup() !== null, 'confirmation_video_default_url' => LaunchSettings::defaultVideoUrl(config('jakawi.confirmation_video_url'))]);
    }

    public function edit(MarketingLanding $landing): Response
    {
        return Inertia::render('admin/LandingEditor', [
            'landing' => [...$landing->toArray(), 'configuration' => LandingContent::withPresentation($landing->configuration), 'published_configuration' => $landing->published_configuration ? LandingContent::withPresentation($landing->published_configuration) : null], 'defaults' => null, 'webinar_defaults' => LandingContent::webinarDefaults(), 'home_slug' => config('jakawi.home_landing_slug'), 'registration_open' => LaunchSettings::registrationOpen(), 'whatsapp_group_available' => RegistrationNextStep::whatsappGroup() !== null,
            'metrics' => DB::table('marketing_landing_event_totals')->where('landing_id', $landing->id)
                ->select('event', 'campaign')->selectRaw('SUM(total) AS total')->groupBy('event', 'campaign')->get(),
            'confirmation_video_default_url' => LaunchSettings::defaultVideoUrl(config('jakawi.confirmation_video_url')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->editorData($request);
        $landing = MarketingLanding::create([...$data, 'status' => 'draft']);
        return redirect()->route('landings.edit', $landing)->with('status', 'Borrador creado. Puedes previsualizarlo antes de publicar.');
    }

    public function update(Request $request, MarketingLanding $landing): RedirectResponse
    {
        DB::transaction(function () use ($request, $landing): void {
            $locked = $this->lock($request, $landing);
            $data = $this->editorData($request, $locked);
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
        $data = $request->validate(['editorial_json' => ['required', 'string', 'max:65536'], 'configuration' => ['sometimes', 'required', 'array']]);
        $base = LandingContent::validate($data['configuration'] ?? $landing?->configuration ?? LandingContent::defaults());
        return response()->json(['configuration' => LandingContent::import($data['editorial_json'], $base)]);
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
            $locked->published_configuration = LandingContent::validate($locked->configuration);
            $locked->published_campaign = $locked->campaign;
            $locked->published_at = now();
            $locked->status = 'published';
            $locked->revision++;
            $locked->save();
        });
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
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $locked = MarketingLanding::query()->lockForUpdate()->findOrFail($landing->id);
        if ($locked->revision !== (int) $data['revision']) {
            throw ValidationException::withMessages(['revision' => 'El borrador cambió en otra ventana. Recarga antes de continuar.']);
        }
        return $locked;
    }

    private function editorData(Request $request, ?MarketingLanding $landing = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('marketing_landings', 'slug')->ignore($landing?->id)],
            'campaign' => ['nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'configuration' => ['required', 'array'],
        ]);
        if (! LandingSlug::allowed($data['slug'])) {
            throw ValidationException::withMessages(['slug' => 'Este slug está reservado para una ruta del sitio.']);
        }
        if (($landing?->published_at !== null || $landing?->isHome()) && $data['slug'] !== $landing->slug) {
            throw ValidationException::withMessages(['slug' => 'El slug es permanente después de publicar para conservar enlaces y analítica.']);
        }
        $data['configuration'] = LandingContent::validate($data['configuration']);
        $data['template'] = $data['configuration']['presentation']['template'];
        return $data;
    }
}
