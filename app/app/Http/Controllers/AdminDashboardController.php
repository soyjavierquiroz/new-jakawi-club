<?php

namespace App\Http\Controllers;

use App\Services\Support\SupportAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class AdminDashboardController extends Controller
{
    public static function navigation(Request $request): array
    {
        $user = $request->user();
        $superadmin = app(SupportAccess::class)->authorized($user)
            && $request->session()->get('jakawi_sso_subject') === $user?->global_subject_id;
        $marketing = $user?->is_marketing_admin === true && $user->activated_at !== null && $user->hasVerifiedEmail();
        if ($request->session()->has('support_view')) {
            return $superadmin ? [['label' => 'Soporte de usuarios', 'href' => '/admin/soporte']] : [];
        }
        $links = $superadmin ? [['label' => 'Resumen', 'href' => '/admin']] : [];
        if ($marketing) {
            foreach ([
                'Partners afiliados' => '/admin/partners-afiliados',
                'Libro de comisiones' => '/admin/comisiones',
                'Reglas de comisiones' => '/admin/reglas-comisiones',
                'Ofertas comerciales' => '/admin/ofertas-comerciales',
                'Conversiones COM' => '/admin/conversiones-com',
                'Landings' => '/admin/landings',
            ] as $label => $href) $links[] = compact('label', 'href');
        }
        if ($superadmin) $links[] = ['label' => 'Soporte de usuarios', 'href' => '/admin/soporte'];
        return $links;
    }

    public function __invoke(Request $request, SupportAccess $access): Response
    {
        // An existing explicit superadmin grant, bound to the real OIDC actor.
        // Marketing alone never grants access to the global overview.
        $access->requireActor($request);
        $approved = DB::table('commission_ledger')->where('status', 'approved')->where('review_required', false);
        $evidence = CommissionAdminController::unissuedEvidence();
        $review = "(a.status = 'invalid' OR a.result->>'first_acquisition' = 'unknown' OR a.result->>'membership_status' = 'UNKNOWN')";
        return Inertia::render('admin/Dashboard', ['summary' => [
            'partners' => DB::table('affiliate_partners')->where('status', 'enabled')->count(),
            'individuals' => DB::table('acquisition_people')->where('beneficiary_type', 'INDIVIDUAL')->distinct()->count('beneficiary_id'),
            'acquisitions' => DB::table('acquisition_activations')->count(),
            'approved' => (clone $approved)->count(),
            'amounts' => (clone $approved)->selectRaw('currency, sum(amount)::text as amount')->groupBy('currency')->orderBy('currency')->get(),
            'evidence' => DB::table('commission_ledger')->whereIn('status', ['registered', 'candidate'])->where('review_required', false)->count()
                + (clone $evidence)->whereRaw("a.status = 'pending' AND coalesce(a.result->>'first_acquisition', '') <> 'unknown' AND coalesce(a.result->>'membership_status', '') <> 'UNKNOWN'")->count(),
            'review' => DB::table('commission_ledger')->where('review_required', true)->where('status', '<>', 'discarded')->count()
                + (clone $evidence)->whereRaw($review)->count(),
        ]]);
    }
}
