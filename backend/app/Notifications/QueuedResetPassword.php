<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Lien de réinitialisation envoyé en file d'attente (ASVS 5.0 V6.3) : la réponse ne dure pas plus longtemps quand le
 * compte existe, ce qui empêcherait de deviner les comptes au temps de réponse.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(string $token)
    {
        parent::__construct($token);
        $this->onQueue('notifications');
    }
}
