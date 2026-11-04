<?php
// CLI-only release operation: two default labels on the existing home webinar.
// Snapshot output is captured to a private rollback file, never printed to the terminal.
const CTA_APPLY = false;
const CTA_EXPECTED_HASH = '__CTA_EXPECTED_HASH__';
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\MarketingLanding;
use App\Support\LandingContent;
use Illuminate\Support\Facades\DB;
$ctaRestore = null;
$result=DB::transaction(function () use ($ctaRestore): array {
    $row=MarketingLanding::where('slug',config('jakawi.home_landing_slug'))->lockForUpdate()->firstOrFail();
    if (!$row->isPublic() || ($row->published_configuration['presentation']['template'] ?? null)!=='WEBINAR') throw new RuntimeException('Home must remain a published webinar');
    if (is_array($ctaRestore)) {
        $defaults=LandingContent::webinarDefaults()['registration'];
        if ($row->revision===$ctaRestore['revision']) return ['restored'=>false,'reason'=>'defaults not committed'];
        if ($row->id!==$ctaRestore['id'] || $row->revision!==$ctaRestore['revision']+1
            || ($row->published_configuration['registration']['navigation_label'] ?? null)!==$defaults['navigation_label']
            || ($row->published_configuration['registration']['submit_label'] ?? null)!==$defaults['submit_label']) throw new RuntimeException('New editorial edits detected: restore labels manually');
        $row->configuration=$ctaRestore['configuration'];$row->published_configuration=$ctaRestore['published_configuration'];$row->revision=$ctaRestore['revision'];$row->save();
        return ['restored'=>true];
    }
    $before=['id'=>$row->id,'configuration'=>$row->configuration,'published_configuration'=>$row->published_configuration,'revision'=>$row->revision];
    $hash=hash('sha256',json_encode($before,JSON_THROW_ON_ERROR));
    if (!CTA_APPLY) return ['hash'=>$hash,'before'=>$before];
    if (!hash_equals(CTA_EXPECTED_HASH,$hash)) throw new RuntimeException('Editorial revision changed: no defaults applied');
    $published=$row->published_configuration;$draft=$row->configuration;
    if (!empty($published['registration']['navigation_label']) || ($published['registration']['submit_label'] ?? null)!=='REGISTRARME AHORA') throw new RuntimeException('Published CTA changed: review the intended defaults');
    $defaults=array_intersect_key(LandingContent::webinarDefaults()['registration'],array_flip(['navigation_label','submit_label']));
    LandingContent::validate(['registration'=>$defaults],true);
    $draftMatches=empty($draft['registration']['navigation_label']) && ($draft['registration']['submit_label'] ?? null)===($published['registration']['submit_label'] ?? null);
    $published['registration']=[...$published['registration'],...$defaults];
    if ($draftMatches) $draft['registration']=[...$draft['registration'],...$defaults];
    // All unrelated draft/published content stays byte-for-byte equivalent as PHP values.
    foreach (['configuration'=>$draft,'published_configuration'=>$published] as $key=>$value) {
        foreach (array_keys($defaults) as $field) {
            if (array_key_exists($field,$before[$key]['registration'])) $value['registration'][$field]=$before[$key]['registration'][$field];
            else unset($value['registration'][$field]);
        }
        if ($value!=$before[$key]) throw new RuntimeException('Unexpected content change');
    }
    $row->configuration=$draft;$row->published_configuration=$published;$row->revision++;$row->save();
    return ['landing_id'=>$row->id,'defaults'=>$defaults,'draft_labels_updated'=>$draftMatches,'unrelated_content_unchanged'=>true,'revision'=>$row->revision];
});
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;
