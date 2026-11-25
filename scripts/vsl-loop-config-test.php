<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Support\LandingContent;
foreach ([true, false] as $loop) {
    $result = LandingContent::validate(['video' => ['loop' => $loop]], true);
    if ($result['video']['loop'] !== $loop) throw new RuntimeException('Explicit loop was lost');
}
if (LandingContent::withPresentation(['video' => ['loop' => false]])['video']['loop'] !== false) throw new RuntimeException('Manual completion was lost');
if (LandingContent::confirmationVideo()['loop'] !== false) throw new RuntimeException('Confirmation completion changed');
try {
    LandingContent::validate(['video' => ['loop' => 'false']], true);
    throw new RuntimeException('Invalid type accepted');
} catch (Illuminate\Validation\ValidationException) {}
echo "PASS: loop validation and normalization; confirmation completion preserved\n";
