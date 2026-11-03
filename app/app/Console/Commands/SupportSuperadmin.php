<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class SupportSuperadmin extends Command
{
    protected $signature = 'support:superadmin {subject : UUID COM autorizado por el propietario} {--revoke}';
    protected $description = 'Concede o revoca exclusivamente soporte CLUB por identidad global y UUID local explícitos';

    public function handle(): int
    {
        $subject = strtolower($this->argument('subject'));
        if (!Str::isUuid($subject)) { $this->error('UUID inválido.'); return self::FAILURE; }
        $user = User::where('global_subject_id', $subject)->first();
        if (!$this->option('revoke') && (!$user || !$user->activated_at || !$user->hasVerifiedEmail())) {
            $this->error('Se requiere una cuenta CLUB existente, activa, verificada y vinculada a ese sub.');
            return self::FAILURE;
        }
        $path = config('support.grants_path');
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $lock = fopen($path.'.lock', 'cb');
        if (!$lock || !flock($lock, LOCK_EX)) return self::FAILURE;
        try {
            $grants = is_file($path) ? json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR) : [];
            if ($this->option('revoke')) unset($grants[$subject]);
            else $grants[$subject] = $user->uuid;
            $temporary = $path.'.'.bin2hex(random_bytes(6));
            file_put_contents($temporary, json_encode($grants, JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($temporary, 0600);
            rename($temporary, $path);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        $this->info('Autorización de soporte actualizada. Sin cambios de identidad ni permisos económicos.');
        return self::SUCCESS;
    }
}
