<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Services\AffiliateShortLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
final class PartnerAffiliateController extends Controller {
 public function index(): \Inertia\Response {
  return Inertia::render('admin/AffiliatePartners', ['partners'=>DB::table('affiliate_partners')->orderBy('id')->get()->map(function($p) {
   $p->access=DB::table('affiliate_partner_access')->where('partner',$p->id)->join('users','users.id','=','affiliate_partner_access.user_id')->get(['users.uuid','users.name']); return $p;
  })]);
 }
 public function store(Request $r): \Illuminate\Http\RedirectResponse {
  $d=$r->validate(['partner_id'=>'required|string|max:190','name'=>'required|string|max:190','com_url'=>'required|url|max:2048','status'=>'required|in:enabled,disabled']);
  abort_unless(app(AffiliateShortLinks::class)->validDestination($d['com_url']) && preg_match('~^/[a-z0-9-]+/?$~D',parse_url($d['com_url'],PHP_URL_PATH) ?? ''),422);
  DB::transaction(function() use($d): void {
   $p=DB::table('affiliate_partners')->where('source','jakawi.com')->where('partner_id',$d['partner_id'])->lockForUpdate()->first();
   if ($p) DB::table('affiliate_partners')->where('id',$p->id)->update([...$d,'updated_at'=>now()]);
   else DB::table('affiliate_partners')->insert([...$d,'source'=>'jakawi.com','code'=>Str::random(24),'created_at'=>now(),'updated_at'=>now()]);
  });
  if ($d['status'] === 'enabled') app(\App\Services\PartnerAcquisition::class)->replay();
  return back()->with('status','Partner actualizado. Sin reglas económicas nuevas.');
 }
 public function access(Request $r,int $partner): \Illuminate\Http\RedirectResponse {
  abort(410, 'Los permisos de representantes se administran exclusivamente en JAKAWI.COM.');
 }
 public function link(string $code,string $target): \Illuminate\Http\RedirectResponse {
  $p=DB::table('affiliate_partners')->where('code',$code)->where('status','enabled')->first(); abort_unless($p,404);
  $slug=trim(parse_url($p->com_url,PHP_URL_PATH),'/');
  $url=$target === 'com' ? $p->com_url : 'https://jakawi.com/p/'.$slug.'/club/'.($target === 'checkout'?'checkout':'carta');
  return redirect()->away($url)->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');
 }
 public static function panels(User $user): array {
  $authorized=app(\App\Services\PartnerEconomicAccess::class)->partners(request(),$user);
  if (!$authorized) return [];
  return DB::table('affiliate_partners as p')->where('p.source','jakawi.com')->where('p.status','enabled')->whereIn('p.partner_id',$authorized)->get(['p.*'])->map(function($p): array {
   $people=DB::table('acquisition_people as people')->where('beneficiary_type','PARTNER')->where('beneficiary_id',$p->partner_id)
    ->whereExists(fn($q)=>$q->selectRaw('1')->from('acquisition_aliases as a')->whereColumn('a.person_id','people.id'));
   $leads=(clone $people)->count();
   $active=(clone $people)->whereExists(fn($q)=>$q->selectRaw('1')->from('acquisition_activations as act')->whereColumn('act.person_id','people.id'))->count();
   $commissions=DB::table('commission_ledger')->where('beneficiary_type','PARTNER')->where('beneficiary_id',$p->partner_id)->where('status','<>','discarded')->groupBy('currency','status','review_required')->selectRaw('currency,status,review_required,count(*) as entries,sum(amount)::text as amount')->get();
   return ['partner_id'=>$p->partner_id,'name'=>$p->name,'status'=>$p->status,'leads'=>$leads,'activated'=>$active,'rate'=>$leads?round(100*$active/$leads,1):0,'commissions'=>$commissions,'links'=>collect(['carta','checkout','com'])->mapWithKeys(fn($t)=>[$t=>url('/a/'.$p->code.'/'.$t)])->all()];
  })->all();
 }
}
