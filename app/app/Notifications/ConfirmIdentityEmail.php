<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ConfirmIdentityEmail extends Notification
{
    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Confirma tu correo para acceder a JAKAWI.COM')
            ->greeting('Hola')
            ->line('Solicitaste activar tu acceso a JAKAWI.COM desde tu cuenta CLUB.')
            ->action('Confirmar mi correo', rtrim(config('app.url'), '/').'/cuenta/jakawi/confirmar-email/'.$this->token)
            ->line('Inicia sesión en CLUB con esta misma cuenta para confirmar. El enlace vence en 15 minutos y es de un solo uso.')
            ->line('Tu contraseña y tus permisos se conservan. Si no solicitaste este acceso, ignora este correo.');
    }
}
