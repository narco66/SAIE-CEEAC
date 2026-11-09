<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal applicatif en ajout seul (SEC-04). Les secrets sont filtrés avant écriture (SEC-05).
 */
class Audit
{
    /** @var list<string> */
    private const SENSITIVE = ['password', 'token', 'mfa_secret', 'mfa_recovery_codes', 'remember_token', 'issued_token', 'poll_secret_hash', 'secret', 'text_content', 'payload'];

    public function __construct(private Access $access) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        Model|string|null $object = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        ?string $power = null,
        ?string $lang = null,
        string|int|null $version = null,
        string $outcome = 'success',
        User|Device|null $actor = null,
        ?string $objectLabel = null,
    ): void {
        $actor ??= auth()->user();
        $request = app()->runningInConsole() ? null : request();

        AuditLog::query()->create([
            'occurred_at' => now(),
            'actor_id' => $actor instanceof User ? $actor->id : null,
            'actor_type' => $actor instanceof Device ? 'device' : ($actor ? 'user' : 'system'),
            'actor_label' => $actor instanceof Device ? $actor->code : $actor?->name,
            'actor_role' => $actor instanceof User ? mb_substr($this->access->primaryRoleLabel($actor), 0, 60) : null,
            'power' => $power,
            'action' => $action,
            'object_type' => $object instanceof Model ? class_basename($object) : $object,
            'object_id' => $object instanceof Model ? (string) $object->getKey() : null,
            'object_label' => $objectLabel ?? ($object instanceof Model ? ($object->getAttribute('reference') ?? $object->getAttribute('code') ?? $object->getAttribute('name') ?? $object->getAttribute('title')) : null),
            'lang' => $lang,
            'version' => $version !== null ? (string) $version : null,
            'reason' => $reason,
            'outcome' => $outcome,
            'ip' => $request?->ip(),
            'request_id' => $request?->attributes->get('request_id'),
            'before' => $before ? $this->filter($before) : null,
            'after' => $after ? $this->filter($after) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function filter(array $values): array
    {
        $filtered = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                continue;
            }
            $filtered[$key] = is_array($value) ? $this->filter($value) : $value;
        }

        return $filtered;
    }

    /**
     * Secrets à ne jamais journaliser (SEC-05), à tout niveau de profondeur : clés connues, ou nom évoquant un mot de passe,
     * un jeton, un secret ou une clé privée / d'API / d'authentification.
     */
    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        return in_array($key, self::SENSITIVE, true) || in_array($key, ['auth', 'api_key', 'apikey', 'private_key', 'authorization'], true)
            || preg_match('/(password|passwd|secret|token|private_key|api_key|apikey|credential)/', $key) === 1;
    }
}
