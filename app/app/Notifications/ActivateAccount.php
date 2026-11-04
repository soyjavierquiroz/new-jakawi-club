<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActivateAccount extends Notification
{
    public function __construct(public readonly string $token, public readonly bool $membership = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.url'), '/').'/activar/'.$this->token.'?'.http_build_query(['email' => $notifiable->email]);

        if ($this->membership) $url .= '&continue=membership';
        return (new MailMessage)
            ->from(config('mail.from.address'), 'JAKAWI')
            ->subject('¡Bienvenido a JAKAWI! Activa tu cuenta')
            ->view('mail.activate-account', ['activationUrl' => $url]);
    }
}
