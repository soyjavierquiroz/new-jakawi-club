<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Services\Crm\ClubContact;
use Illuminate\Console\Command;

final class SyncCrmContact extends Command
{
    protected $signature = 'crm:sync-contact {email?} {--registered : Encolar los leads registrados existentes sin modificar sus datos}';
    protected $description = 'Encolar el estado actual de una cuenta existente, sin modificarla ni enviar correo';
    public function handle(ClubContact $contacts): int
    {
        if ($this->option('registered')) {
            if ($this->argument('email')) { $this->error('Usa email o --registered por separado.'); return 1; }
            $count = 0;
            User::whereNotNull('privacy_accepted_at')->whereNotNull('phone')->orderBy('id')->chunkById(100, function ($users) use ($contacts, &$count): void {
                foreach ($users as $user) { $contacts->enqueue($user); $count++; }
            });
            $this->info('Leads existentes encolados: '.$count.'. Sin cambios de cuenta ni correo.');
            return 0;
        }
        if (!$this->argument('email')) { $this->error('Indica una cuenta existente o --registered.'); return 1; }
        $user = User::where('email', \App\Support\Normalize::email($this->argument('email')))->first();
        if (!$user || !$user->phone) { $this->error('Cuenta existente con teléfono requerida.'); return 1; }
        $contacts->enqueue($user);
        $this->info('Contacto existente encolado para CRM. No se modificó la cuenta ni se envió correo.');
        return 0;
    }
}
