<?php
namespace App\Services\Crm;

use Illuminate\Support\Facades\Http;

final class ClubBridgeClient
{
    public function send(array $payload): array
    {
        $file = config('crm.secret_file');
        $secret = is_string($file) && is_readable($file) ? trim(file_get_contents($file)) : '';
        if (strlen($secret)<32) throw new \RuntimeException('CLUB CRM credentials unavailable');
        $url = config('crm.url');
        if ($url !== 'https://crm.jakawi.com/wp-json/jakawi-fluentcrm/v1/club/events') throw new \RuntimeException('CLUB CRM endpoint invalid');
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $time = (string)time();
        try {
            $response = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects'=>false])
                ->withHeaders(['X-Jakawi-Timestamp'=>$time, 'X-Jakawi-Event-Id'=>$payload['event_id'],
                    'X-Jakawi-Signature'=>hash_hmac('sha256',$time."\n".$payload['event_id']."\n".$raw,$secret)])
                ->withBody($raw,'application/json')->post($url);
        } catch (\Throwable) { throw new \RuntimeException('CLUB CRM transport unavailable'); }
        $id = $response->json('provider_contact_id');
        if ($response->status()===200 && $response->json('result_code')==='OK' && is_int($id) && $id>0) {
            return ['contact_id'=>$id, 'idempotent'=>$response->json('idempotent')===true];
        }
        // Provider bodies and exceptions may contain private data; never propagate them.
        return ['http'=>$response->status(), 'retry'=>in_array($response->status(),[200,408,429],true) || $response->status()>=500];
    }
}
