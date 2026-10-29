<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Normalize;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Question\Question;

class BootstrapLandingAdmin extends Command
{
    protected $signature = 'landings:bootstrap-admin';

    protected $description = 'Crear el primer administrador real mediante una operación interactiva, sin enviar correo';

    public function handle(): int
    {
        if (! $this->input->isInteractive() || ! $this->hasInteractiveTerminal()) {
            $this->error('Ejecuta este comando en una terminal interactiva. No admite credenciales por argumentos, variables ni tuberías.');
            return self::FAILURE;
        }

        $password = $confirmation = null;
        try {
            if (User::where('is_marketing_admin', true)->exists()) {
                $this->error('Ya existe un administrador de landings. Este comando solo prepara el primero.');
                return self::FAILURE;
            }

            $name = Normalize::name($this->ask('Nombre real del administrador'));
            $email = Normalize::email($this->ask('Correo electrónico real del administrador'));
            if (Validator::make(['name' => $name, 'email' => $email], [
                'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
                'email' => ['required', 'email:rfc', 'max:254'],
            ])->fails()) {
                $this->error('Introduce un nombre real de hasta 120 caracteres y un correo válido. No se creó ninguna cuenta.');
                return self::FAILURE;
            }
            if (User::where('email', $email)->exists()) {
                $this->error('Ese correo ya tiene una cuenta. No se modificaron su contraseña ni sus permisos.');
                return self::FAILURE;
            }
            if (! $this->confirm('¿Confirmas que el correo introducido te pertenece y que controlas su acceso?', false)) {
                $this->info('Operación cancelada. No se creó ninguna cuenta.');
                return self::FAILURE;
            }

            $this->info('La contraseña requiere al menos 12 caracteres, letras y números, y admite hasta 72 bytes.');
            // Disable visible fallback: a terminal that cannot hide input must fail closed.
            $password = $this->readPassword('Contraseña');
            $confirmation = $this->readPassword('Repite la contraseña');
            if (Validator::make(['password' => $password, 'password_confirmation' => $confirmation], [
                'password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->numbers()],
            ])->fails() || ! is_string($password) || strlen($password) > 72) {
                $this->error('La contraseña no cumple los requisitos o la confirmación no coincide. No se creó ninguna cuenta.');
                return self::FAILURE;
            }
            if (! $this->confirm('¿Crear esta cuenta con el correo confirmado como primer administrador de landings?', false)) {
                $this->info('Operación cancelada. No se creó ninguna cuenta.');
                return self::FAILURE;
            }

            DB::transaction(function () use ($name, $email, $password): void {
                // Serialize creation with other user writes; never hold a lock while prompting.
                DB::statement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
                if (User::where('is_marketing_admin', true)->exists() || User::where('email', $email)->exists()) {
                    throw new \DomainException('El estado de las cuentas cambió. No se creó ni modificó ninguna cuenta.');
                }
                // Reuse UUID/referral generation and the existing hashed password cast.
                $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
                $user->activated_at = now();
                // Ownership was explicitly confirmed above; this is an offline bootstrap.
                $user->markEmailAsVerified();
                if ($this->callSilently('landings:admin', ['email' => $email]) !== self::SUCCESS) {
                    throw new \DomainException('No se pudo conceder el permiso administrativo. El alta se revirtió.');
                }
            });

            $this->info('Primer administrador creado. Inicia sesión en /login y accede a /admin/landings. El registro público conserva su estado.');
            return self::SUCCESS;
        } catch (\DomainException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        } catch (\Throwable) {
            // SQL/console exceptions may include sensitive values. Never report or log them.
            $this->error('No se pudo confirmar el alta. Revisa el estado de las cuentas antes de reintentar. No se mostraron detalles sensibles.');
            return self::FAILURE;
        } finally {
            unset($password, $confirmation);
        }
    }

    protected function hasInteractiveTerminal(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }

    private function readPassword(string $prompt): mixed
    {
        $question = new Question($prompt);
        $question->setHidden(true)->setHiddenFallback(false)->setTrimmable(false);
        $answer = $this->output->askQuestion($question);
        // The console reader may include Enter; preserve all actual password spaces.
        return is_string($answer) ? rtrim($answer, "\r\n") : $answer;
    }
}
