<?php

namespace App\Console\Commands;

use App\Services\ComCatalogSync;
use App\Services\CommercialCatalog;
use Illuminate\Console\Command;

final class SyncCommercialCatalog extends Command
{
    protected $signature = 'catalog:sync';
    protected $description = 'Recorrer el catálogo COM completo y actualizar captación CLUB compatible';

    public function handle(ComCatalogSync $com, CommercialCatalog $catalog): int
    {
        $result = self::SUCCESS;
        try {
            $counts = $com->run();
            $this->info('COM: '.$counts['offers'].' ofertas en '.$counts['pages'].' páginas.');
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());
            $result = self::FAILURE;
        }
        $catalog->syncClub();
        return $result;
    }
}
