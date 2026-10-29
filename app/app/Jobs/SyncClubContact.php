<?php
namespace App\Jobs;

use App\Services\Crm\ClubBridgeClient;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncClubContact implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    public int $tries = 12;
    public int $timeout = 12;
    public array $backoff = [60,120,300,900,3600,10800];

    public function __construct(public readonly array $payload) {}

    public function handle(ClubBridgeClient $client): void
    {
        $result = $client->send($this->payload);
        if (isset($result['contact_id'])) return;
        $error = new \RuntimeException('CLUB CRM delivery HTTP '.($result['http'] ?? 0));
        if ($result['retry'] ?? true) throw $error;
        $this->fail($error);
    }
}
