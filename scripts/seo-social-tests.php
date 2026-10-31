<?php

// Included by catalog-tests.php only after private PostgreSQL schema isolation.
$homeLanding = $landing->fresh();
$publishedBefore = $homeLanding->published_configuration;
$seoBefore = App\Support\LandingSeo::forLanding($homeLanding);
foreach (['/', '/'.$homeLanding->slug.'?utm_source=test&club_ref=UNTRUSTED'] as $path) {
    $html = web('GET', $path, [], false)->getContent();
    check(str_contains($html, '<title>'.$seoBefore['name'].'</title>') && str_contains($html, 'href="https://jakawi.club/"')
        && str_contains($html, 'data-inertia="robots" content="index, follow"'), 'initial title canonical robots '.$path);
    foreach (['og:url','og:image:width','og:image:height','og:image:type','twitter:card'] as $key) {
        check(str_contains($html, 'data-inertia="'.$key.'"'), 'initial HTML '.$key.' '.$path);
    }
    check(substr_count($html, 'name="description"')===1 && substr_count($html, '<title>')===1, 'no duplicate title or description '.$path);
}
$otherContent = App\Support\LandingContent::defaults();
$otherContent['commercial'] = ['public_name'=>'Otra landing publicada','image'=>null,'description'=>'Descripción comercial pública'];
$otherContent['video']['poster'] = '/marketing/poster-fixture.png';
$other = App\Models\MarketingLanding::create(['title'=>'TÍTULO PRIVADO','slug'=>'otra-publicada','status'=>'published','configuration'=>$otherContent,
    'published_configuration'=>$otherContent,'published_at'=>now(),'revision'=>1]);
$otherHtml = web('GET','/otra-publicada?utm_source=test',[],false)->getContent();
check(str_contains($otherHtml,'<title>Otra landing publicada</title>') && str_contains($otherHtml,'content="Descripción comercial pública"')
    && str_contains($otherHtml,'href="https://jakawi.club/otra-publicada"') && !str_contains($otherHtml,'TÍTULO PRIVADO'), 'second published landing uses frozen commercial fallbacks and own canonical');
check(App\Support\LandingSeo::forLanding($other)['image_url']===App\Support\LandingCommercial::socialImage([]), 'VSL poster never becomes social image');
$otherDraft=$otherContent;$otherDraft['seo']=['title'=>'SOLO BORRADOR','description'=>'Solo borrador privado'];
$other->configuration=$otherDraft;$other->save();
check(!str_contains(web('GET','/otra-publicada',[],false)->getContent(),'SOLO BORRADOR'), 'SEO draft never leaks into published HTML');
$previewHtml=web('GET','/admin/landings/'.$other->id.'/preview',[],false)->getContent();
check(str_contains($previewHtml,'<title>SOLO BORRADOR</title>') && str_contains($previewHtml,'content="noindex, nofollow"'), 'protected preview uses draft SEO and noindex');
check(str_contains(web('GET','/admin/landings',[],false)->getContent(),'content="noindex, nofollow"'), 'private administration noindex');
check(App\Support\LandingContent::validate($otherContent)['seo']===['title'=>'','description'=>''], 'empty SEO overrides remain valid in editor contract');
$clicksBefore=DB::table('affiliate_link_daily_clicks')->sum('clicks');
$identitiesBefore=DB::table('affiliate_short_links')->orderBy('code')->get()->toJson();
$protectedBefore=protectedSnapshot();
$comOffer=$offers->query()->where('key',$old->offer_key)->first();
DB::table('commercial_offers')->where('key',$old->offer_key)->update(['availability_status'=>'available','publication_status'=>'published','local_paused'=>false,'ends_at'=>null,'catalog_present'=>true]);
foreach ([$old->code,$clubLink->code] as $code) {
    foreach (['WhatsApp/2.25','facebookexternalhit/1.1','Mozilla/5.0'] as $ua) {
        $before=(int)DB::table('affiliate_link_daily_clicks')->sum('clicks');
        $response=kernelRequest(Request::create('https://jakawi.club/r/'.$code.'?club_ref=ATTACKER','GET',[],[],[],['HTTP_USER_AGENT'=>$ua]));
        check($response->getStatusCode()===302,'existing active redirect preserved '.$code.' '.$ua);
        $destination=$response->headers->get('Location');
        check($code===$old->code ? str_contains($destination,'club_ref='.$a->referral_code) && !str_contains($destination,'ATTACKER') : $destination===$a->referralUrl(), 'existing attribution preserved '.$code.' '.$ua);
        check((int)DB::table('affiliate_link_daily_clicks')->sum('clicks')===$before+($ua==='Mozilla/5.0'?1:0), 'one human click zero crawler clicks '.$code.' '.$ua);
    }
}
foreach (['paused','ended','retired'] as $state) {
    DB::table('commercial_offers')->where('key',$old->offer_key)->update(['availability_status'=>$state==='retired'?'available':$state,'catalog_present'=>$state!=='retired']);
    foreach (['WhatsApp/2.25','facebookexternalhit/1.1','Mozilla/5.0'] as $ua) {
        $before=DB::table('affiliate_link_daily_clicks')->sum('clicks');
        $response=kernelRequest(Request::create('https://jakawi.club/r/'.$old->code,'GET',[],[],[],['HTTP_USER_AGENT'=>$ua]));
        $html=$response->getContent();
        check($response->getStatusCode()===200 && !$response->headers->get('Location') && str_contains($html,'property="og:title"')
            && str_contains($html,'content="noindex, nofollow"') && !str_contains($html,$a->referral_code), 'unavailable social HTML no affiliate data '.$state.' '.$ua);
        check(DB::table('affiliate_link_daily_clicks')->sum('clicks')===$before,'unavailable preview counts zero '.$state.' '.$ua);
    }
}
check(DB::table('affiliate_short_links')->orderBy('code')->get()->toJson()===$identitiesBefore && protectedSnapshot()===$protectedBefore, 'SEO and previews preserve permanent links conversions accounts and commissions');
check($homeLanding->fresh()->published_configuration===$publishedBefore, 'SEO reads never mutate published snapshots');
$restore=(array)$comOffer;unset($restore['effective_state']);DB::table('commercial_offers')->where('key',$old->offer_key)->update($restore);
