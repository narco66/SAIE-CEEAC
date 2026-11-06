<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Contrôles de mise en service (TECH-02, SEC-01, SEC-05, LIV-03) : configuration de production, secrets, antivirus,
 * base de données et absence de données de démonstration. Un contrôle « fail » bloque la mise en service ; « warn » est à examiner.
 */
class ProductionReadiness
{
    /**
     * @return list<array{name: string, status: 'ok'|'warn'|'fail', detail: string}>
     */
    public function checks(): array
    {
        return [
            $this->check('Environnement de production', config('app.env') === 'production', 'APP_ENV='.config('app.env')),
            $this->check('Mode débogage désactivé', ! config('app.debug'), config('app.debug') ? 'APP_DEBUG=true expose les traces d’erreur' : 'APP_DEBUG=false'),
            $this->check('Clé d’application définie', filled(config('app.key')), filled(config('app.key')) ? 'APP_KEY présente' : 'APP_KEY vide : php artisan key:generate'),
            $this->placeholders(),
            $this->check('Adresses en HTTPS', $this->https(config('app.url')) && $this->https(config('app.frontend_url')), 'APP_URL='.config('app.url').' · FRONTEND_URL='.config('app.frontend_url')),
            $this->check('Cookies de session sécurisés', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE doit valoir true derrière TLS'),
            $this->check('Mode démonstration désactivé', ! config('saie.demo_mode'), config('saie.demo_mode') ? 'SAIE_DEMO_MODE=true' : 'SAIE_DEMO_MODE=false'),
            $this->demoData(),
            $this->check('Second facteur imposé', (bool) config('saie.security.mfa_enforced'), 'SAIE_MFA_ENFORCED doit valoir true (SEC-01)'),
            $this->antivirus(),
            ...$this->signingKey(),
            $this->check('Envoi réel des courriels', ! in_array(config('mail.default'), ['log', 'array'], true), 'MAIL_MAILER='.config('mail.default')),
            $this->check('Files de traitement asynchrones', config('queue.default') !== 'sync', 'QUEUE_CONNECTION='.config('queue.default')),
            $this->push(),
            ...$this->database(),
            $this->check('Stockage privé accessible en écriture', $this->writable(storage_path('app/private')), storage_path('app/private')),
            $this->warnUnless('Journalisation sans niveau « debug »', ! in_array('debug', [config('logging.channels.single.level'), config('logging.channels.daily.level')], true), 'LOG_LEVEL=warning ou info recommandé en production'),
        ];
    }

    public function passes(): bool
    {
        return collect($this->checks())->every(fn (array $check): bool => $check['status'] !== 'fail');
    }

    /**
     * @return array{name: string, status: 'ok'|'fail', detail: string}
     */
    private function check(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'status' => $ok ? 'ok' : 'fail', 'detail' => $detail];
    }

    /**
     * @return array{name: string, status: 'ok'|'warn', detail: string}
     */
    private function warnUnless(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'status' => $ok ? 'ok' : 'warn', 'detail' => $detail];
    }

    /**
     * Valeurs du modèle infra/.env.example restées telles quelles (« À_FOURNIR »).
     *
     * @return array{name: string, status: 'ok'|'fail', detail: string}
     */
    private function placeholders(): array
    {
        $keys = [
            'app.url' => 'APP_URL', 'app.frontend_url' => 'FRONTEND_URL', 'database.connections.pgsql.password' => 'DB_PASSWORD', 'sanctum.stateful' => 'SANCTUM_STATEFUL_DOMAINS',
            'mail.mailers.smtp.host' => 'MAIL_HOST', 'mail.mailers.smtp.username' => 'MAIL_USERNAME', 'mail.mailers.smtp.password' => 'MAIL_PASSWORD',
            'saie.device.signing_key_id' => 'SAIE_MANIFEST_KEY_ID',
        ];
        $left = array_values(array_filter($keys, fn (string $variable, string $key): bool => str_contains((string) json_encode(config($key), JSON_UNESCAPED_UNICODE), 'À_FOURNIR'), ARRAY_FILTER_USE_BOTH));

        return $this->check('Aucune valeur de modèle restante', $left === [], $left === [] ? 'Toutes les valeurs « À_FOURNIR » sont renseignées' : 'À renseigner dans infra/.env : '.implode(', ', $left));
    }

    private function https(mixed $url): bool
    {
        return is_string($url) && str_starts_with($url, 'https://');
    }

    /**
     * @return array{name: string, status: 'ok'|'fail', detail: string}
     */
    private function demoData(): array
    {
        try {
            $tables = DB::table('information_schema.columns')->where('table_schema', 'public')->where('column_name', 'is_demo')->pluck('table_name');
            $count = $tables->sum(fn (string $table): int => DB::table($table)->where('is_demo', true)->count());

            return $this->check('Aucune donnée de démonstration', $count === 0, $count === 0 ? 'Aucun enregistrement fictif' : "$count enregistrement(s) fictif(s) : base à réinitialiser sans le jeu de démonstration");
        } catch (Throwable $e) {
            return $this->check('Aucune donnée de démonstration', false, 'Contrôle impossible : '.$e->getMessage());
        }
    }

    /**
     * @return array{name: string, status: 'ok'|'fail', detail: string}
     */
    private function antivirus(): array
    {
        if (config('saie.antivirus.driver') !== 'clamd') {
            return $this->check('Antivirus de production (clamd)', false, 'SAIE_AV_DRIVER='.config('saie.antivirus.driver').' : seul le fichier de test EICAR serait détecté');
        }
        $host = (string) config('saie.antivirus.clamd_host');
        $port = (int) config('saie.antivirus.clamd_port');
        $socket = @fsockopen($host, $port, $errno, $error, 3);
        if (! $socket) {
            return $this->check('Antivirus de production (clamd)', false, "clamd injoignable sur $host:$port ($error)");
        }
        stream_set_timeout($socket, 3);
        fwrite($socket, "zPING\0");
        $reply = trim((string) fgets($socket), "\0\r\n ");
        fclose($socket);

        return $this->check('Antivirus de production (clamd)', $reply === 'PONG', "clamd $host:$port répond « $reply »");
    }

    /**
     * @return list<array{name: string, status: 'ok'|'warn'|'fail', detail: string}>
     */
    private function signingKey(): array
    {
        $path = (string) config('saie.device.signing_key_path');
        $exists = is_file($path) && is_readable($path);
        $checks = [$this->check('Clé de signature des manifestes', $exists, $exists ? $path : "Absente ou illisible : $path (voir docs/deploiement.md)")];
        if ($exists && DIRECTORY_SEPARATOR === '/') {
            $mode = fileperms($path) & 0777;
            $checks[] = $this->check('Clé de signature protégée', ($mode & 0077) === 0, sprintf('Droits %o : seuls le propriétaire doit pouvoir la lire (chmod 600)', $mode));
        }
        $checks[] = $this->warnUnless('Identifiant de clé de production', config('saie.device.signing_key_id') !== 'mk-local', 'SAIE_MANIFEST_KEY_ID='.config('saie.device.signing_key_id'));

        return $checks;
    }

    /**
     * @return array{name: string, status: 'ok'|'warn'|'fail', detail: string}
     */
    private function push(): array
    {
        // Le paramètre est lu en base : une base injoignable ne doit pas faire planter le contrôle (rapport toujours produit).
        try {
            $enabled = (bool) Setting::get('channels.push_enabled', false);
        } catch (Throwable $e) {
            return $this->check('Notifications push', false, 'Contrôle impossible : base injoignable');
        }
        $keys = filled(config('saie.push.public_key')) && filled(config('saie.push.private_key'));
        if (! $enabled) {
            return $this->warnUnless('Notifications push', true, 'Canal désactivé (V39)');
        }

        return $this->check('Notifications push', $keys, $keys ? 'Canal activé, clés VAPID présentes' : 'Canal activé sans clés VAPID : php artisan saie:vapid');
    }

    /**
     * @return list<array{name: string, status: 'ok'|'warn'|'fail', detail: string}>
     */
    private function database(): array
    {
        try {
            $version = (int) DB::scalar("select current_setting('server_version_num')::int");
            $timezone = (string) DB::scalar('show timezone');
            $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))->keys()
                ->diff(app('migrator')->getRepository()->getRan())->count();

            return [
                $this->check('PostgreSQL 18', config('database.default') === 'pgsql' && $version >= 180000, 'Version '.intdiv($version, 10000).' ('.config('database.default').')'),
                $this->check('Session PostgreSQL en UTC', strtoupper($timezone) === 'UTC', "timezone=$timezone"),
                $this->check('Migrations appliquées', $pending === 0, $pending === 0 ? 'Aucune migration en attente' : "$pending migration(s) en attente : php artisan migrate --force"),
                $this->leastPrivilege(),
            ];
        } catch (Throwable $e) {
            return [$this->check('Base de données joignable', false, $e->getMessage())];
        }
    }

    /**
     * SEC-04, TECH-02 : l'application ne se connecte ni en superutilisateur ni en propriétaire des tables,
     * et ne peut ni modifier ni supprimer le journal d'audit.
     *
     * @return array{name: string, status: 'ok'|'fail', detail: string}
     */
    private function leastPrivilege(): array
    {
        ['role' => $role, 'super' => $super, 'owner' => $owner, 'tamper' => $tamper] = $this->privilegeFacts();
        $ok = ! $super && ! $owner && ! $tamper;

        return $this->check('Compte PostgreSQL à moindre privilège', $ok, $ok ? "Compte « $role » sans superutilisateur ni droit d’altération du journal"
            : "Compte « $role » : ".implode(', ', array_filter([$super ? 'superutilisateur' : null, $owner ? 'propriétaire des tables' : null, $tamper ? 'peut modifier ou supprimer le journal' : null])).' — utiliser saie_app (infra/postgres/10-roles.sh)');
    }

    /**
     * Droits effectifs du compte de connexion.
     *
     * @return array{role: string, super: bool, owner: bool, tamper: bool}
     */
    protected function privilegeFacts(): array
    {
        return [
            'role' => (string) DB::scalar('select current_user'),
            'super' => (bool) DB::scalar('select rolsuper from pg_roles where rolname = current_user'),
            'owner' => (bool) DB::scalar("select pg_catalog.pg_get_userbyid(relowner) = current_user from pg_class where relname = 'audit_logs'"),
            'tamper' => (bool) DB::scalar("select has_table_privilege('audit_logs', 'UPDATE') or has_table_privilege('audit_logs', 'DELETE') or has_table_privilege('audit_logs', 'TRUNCATE')"),
        ];
    }

    private function writable(string $directory): bool
    {
        if (! is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        return is_dir($directory) && is_writable($directory);
    }
}
