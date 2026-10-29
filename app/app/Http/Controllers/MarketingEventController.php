<?php

namespace App\Http\Controllers;

use App\Services\MarketingAnalytics;
use App\Models\MarketingLanding;
use App\Support\LaunchSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class MarketingEventController extends Controller
{
    public function __invoke(Request $request, MarketingAnalytics $analytics): Response
    {
        $data = $request->validate([
            'event' => ['required', Rule::in(['landing_visit', 'cta_click', 'video_start'])],
            'placement' => ['required', Rule::in(['landing', 'hero', 'benefits', 'final', 'video'])],
            'landing_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $allowed = [
            'landing_visit' => ['landing'],
            'cta_click' => ['hero', 'benefits', 'final'],
            'video_start' => ['video'],
        ];
        abort_unless(in_array($data['placement'], $allowed[$data['event']], true), 422);
        $landing = isset($data['landing_id']) ? MarketingLanding::find($data['landing_id']) : null;
        abort_if(isset($data['landing_id']) && ! $landing?->isPublic(), 422);
        $video = $landing ? ($landing->published_configuration['video']['youtube_url'] ?? null) : config('jakawi.video_url');
        abort_if($data['event'] === 'video_start' && LaunchSettings::youtubeUrl($video) === null, 422);
        $analytics->recordVisitEvent($request, $data['event'], $data['placement'], $landing);

        return response()->noContent();
    }
}
