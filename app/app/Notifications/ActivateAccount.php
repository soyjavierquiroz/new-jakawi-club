<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActivateAccount extends Notification
{
    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.url'), '/').'/activar/'.$this->token.'?'.http_build_query(['email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('Activa tu cuenta JAKAWI')
            ->greeting('¡Bienvenido a JAKAWI!')
            ->line('Verifica tu correo y elige una contraseña para activar tu cuenta.')
            ->action('Activar mi cuenta', $url)
            ->line('Este enlace es de un solo uso y vence en 60 minutos.')
            ->line('Si no solicitaste esta cuenta, puedes ignorar este mensaje.');
    }
}
