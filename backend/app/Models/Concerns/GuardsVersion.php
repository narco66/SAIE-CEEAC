<?php

namespace App\Models\Concerns;

use App\Support\BusinessException;
use Illuminate\Database\Eloquent\Model;

/**
 * API-04 : concurrence optimiste atomique.
 *
 * Le contrôleur enregistre la version lue par le client (Controller::expectVersion) ; à l'écriture, la version est
 * réservée par une mise à jour conditionnelle (WHERE version = version attendue). PostgreSQL sérialise ces mises à jour
 * sur la ligne : si un autre utilisateur a enregistré entre la lecture et l'écriture, aucune ligne n'est touchée et la
 * modification est refusée (409) au lieu d'écraser la sienne, même si le service a relu entre-temps la ligne fraîche.
 */
trait GuardsVersion
{
    public static function bootGuardsVersion(): void
    {
        static::updating(function (Model $model): void {
            $key = self::versionExpectationKey($model);
            $expectations = request()->attributes->get('expected_versions', []);
            if (! array_key_exists($key, $expectations) || ! $model->isDirty('version')) {
                return;
            }
            $expected = $expectations[$key];
            unset($expectations[$key]);
            request()->attributes->set('expected_versions', $expectations);

            $claimed = $model->newQuery()->whereKey($model->getKey())->where('version', $expected)->update(['version' => $model->getAttribute('version')]);
            if ($claimed === 0) {
                $current = (int) $model->newQuery()->whereKey($model->getKey())->value('version');
                throw new BusinessException('VERSION_CONFLICT', 'L’objet a été modifié par un autre utilisateur. Rechargez la version courante.', 409, ['current_version' => $current]);
            }
        });
    }

    /**
     * Version lue par le client, à réserver lors de la prochaine écriture de ce modèle dans la requête.
     */
    public function expectVersionOnSave(int $expected): void
    {
        $expectations = request()->attributes->get('expected_versions', []);
        $expectations[self::versionExpectationKey($this)] = $expected;
        request()->attributes->set('expected_versions', $expectations);
    }

    private static function versionExpectationKey(Model $model): string
    {
        return $model::class.':'.$model->getKey();
    }
}
