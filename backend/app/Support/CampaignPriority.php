<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;

/**
 * Niveaux de priorité métier (§12.1) appliqués à la priorité numérique des campagnes (1 à 99, la plus haute l'emporte) :
 * contenus permanents < informations générales < campagnes institutionnelles < communications officielles prioritaires
 * < messages institutionnels urgents. Le niveau « urgent » est soumis au pouvoir étendu « urgence » à partir du seuil
 * réglable `campaigns.urgent_from`.
 */
class CampaignPriority
{
    /** @var list<array{code: string, label: string, from: int, to: int}> */
    public const LEVELS = [
        ['code' => 'permanent', 'label' => 'Contenus permanents', 'from' => 1, 'to' => 19],
        ['code' => 'general', 'label' => 'Informations générales', 'from' => 20, 'to' => 39],
        ['code' => 'institutional', 'label' => 'Campagnes institutionnelles', 'from' => 40, 'to' => 59],
        ['code' => 'official', 'label' => 'Communications officielles prioritaires', 'from' => 60, 'to' => 79],
        ['code' => 'urgent', 'label' => 'Messages institutionnels urgents', 'from' => 80, 'to' => 99],
    ];

    /**
     * @return array{code: string, label: string, from: int, to: int}
     */
    public static function levelOf(int $priority): array
    {
        foreach (self::LEVELS as $level) {
            if ($priority >= $level['from'] && $priority <= $level['to']) {
                return $level;
            }
        }

        return self::LEVELS[0];
    }

    public static function urgentFrom(): int
    {
        return (int) Setting::effective('campaigns.urgent_from');
    }

    /**
     * Refus (journalisé) d'une priorité urgente sans le pouvoir « urgence ».
     */
    public static function assertAllowed(int $priority, User $user): void
    {
        if ($priority >= self::urgentFrom() && ! app(Access::class)->hasPower($user, 'urgence')) {
            app(Audit::class)->log('access.denied', null, 'Priorité urgente sans pouvoir « urgence »', after: ['priority' => $priority], outcome: 'denied', actor: $user);
            throw new BusinessException('PRIORITY_RESTRICTED', 'Une priorité de '.self::urgentFrom().' ou plus (messages institutionnels urgents) exige le pouvoir « urgence ».', 403, ['field' => 'priority']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function meta(User $user): array
    {
        return ['levels' => self::LEVELS, 'urgent_from' => self::urgentFrom(), 'can_urgent' => app(Access::class)->hasPower($user, 'urgence')];
    }
}
