<?php
// Explicit release step. Only the home landing's visual metadata changes.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\MarketingLanding;
use Illuminate\Support\Facades\DB;
$mode = getenv('JAKAWI_FLOW_OPERATION') ?: 'snapshot';
if ($mode === 'snapshot') {
    $rows = DB::table('marketing_landings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    foreach ($rows as &$row) foreach (['configuration', 'published_configuration'] as $field) $row[$field] = json_decode($row[$field] ?? 'null', true);
    echo json_encode(['rows'=>$rows, 'registration_open'=>(bool)config('jakawi.registration_open'), 'mail_ready'=>(bool)config('jakawi.transactional_mail_ready')], JSON_THROW_ON_ERROR);
    return;
}
DB::transaction(function () use ($mode): void {
    $landing = MarketingLanding::where('slug', config('jakawi.home_landing_slug'))->lockForUpdate()->sole();
    if (!$landing->isPublic()) throw new RuntimeException('Expected published home');
    $before = $landing->only(['id','configuration','published_configuration','revision']);
    if ($mode === 'rollback') {
        $restore = json_decode(base64_decode(getenv('JAKAWI_FLOW_RESTORE')), true, 512, JSON_THROW_ON_ERROR);
        if ($before != $restore['after']) throw new RuntimeException('Landing edited after release; refusing overwrite');
        $landing->update(['configuration'=>$restore['before']['configuration'], 'published_configuration'=>$restore['before']['published_configuration'], 'revision'=>$landing->revision+1]);
        echo 'Visual metadata rollback PASS';
        return;
    }
    if ($mode !== 'apply') throw new RuntimeException('Invalid operation');
    $layouts = ['hero'=>'spotlight','event'=>'flow','benefits'=>'proof','opportunity'=>'split','early'=>'flow','steps'=>'flow','registration'=>'split','faq'=>'flow','final'=>'spotlight'];
    $project = function (array $content) use ($layouts): array {
        if (($content['presentation']['template'] ?? null) !== 'WEBINAR' || count($content['presentation']['webinar']['sections'] ?? []) !== 9) throw new RuntimeException('Expected current webinar composition');
        foreach ($content['presentation']['webinar']['sections'] as &$section) {
            $section['layout'] = $layouts[$section['id']];
            $section['background'] = $section['id'] === 'final' ? 'dark' : (in_array($section['id'], ['opportunity','early'], true) ? 'soft' : 'light');
        }
        return $content;
    };
    $landing->update(['configuration'=>$project($landing->configuration), 'published_configuration'=>$project($landing->published_configuration), 'revision'=>$landing->revision+1]);
    echo json_encode(['before'=>$before,'after'=>$landing->fresh()->only(array_keys($before))], JSON_THROW_ON_ERROR);
});
