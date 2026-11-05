<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

#[Signature('saie:vapid')]
#[Description('Génère une paire de clés VAPID pour les notifications push (NOT-03) à placer dans le .env')]
class GenerateVapidKeys extends Command
{
    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->info('Ajouter au fichier .env (la clé privée ne doit jamais être versionnée) :');
        $this->line('SAIE_VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('SAIE_VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
