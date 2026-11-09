<?php

namespace App\Providers;

use App\Models\Device;
use App\Models\DeviceScreenshot;
use App\Models\DynamicSource;
use App\Models\FallbackContent;
use App\Models\PlayerRelease;
use App\Models\ReservationOccurrence;
use App\Models\RoleAssignment;
use App\Models\RoleExtension;
use App\Models\RoomBlock;
use App\Models\ScreenGroup;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Access;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Access::class);
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // Étape du second facteur : la requête ne porte pas d'adresse, la limite vise alors l'utilisateur en attente.
        // Plafond global par adresse en plus de la limite par compte : freine la pulvérisation de mots de passe sur de nombreux comptes.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by((Str::lower((string) $request->input('email'))
                ?: 'mfa:'.($request->hasSession() ? (string) ($request->session()->get('mfa.pending')['user'] ?? '') : '')).'|'.$request->ip()),
            Limit::perMinute((int) config('saie.security.login_per_ip_per_minute', 30))->by('login-ip:'.$request->ip()),
        ]);
        // TECH-04 : migrate:fresh, db:wipe et migrate:reset sont refusés en production (aucune perte de données par erreur).
        DB::prohibitDestructiveCommands($this->app->isProduction());
        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(120)->by('device:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('integration', fn (Request $request) => Limit::perMinute(60)->by('integration:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->getKey() ?: $request->ip()));

        // Jeton dans le fragment (#) : il n'est ni envoyé au serveur ni écrit dans les journaux d'accès (ASVS 5.0 V14.2).
        ResetPassword::createUrlUsing(fn (User $user, string $token) => rtrim((string) config('app.frontend_url'), '/').'/compte/verification#token='.$token.'&email='.urlencode($user->email));
        // ASVS 5.0 V6.2 : 12 caractères au moins, 64 au plus (limite de bcrypt), sans règle de composition.
        PasswordRule::defaults(fn () => PasswordRule::min(12)->max(64));
        // Vérification du mot de passe actuel : freine la recherche du mot de passe depuis une session volée (ASVS 5.0 V6.3).
        // Exports : traitements coûteux, 10 demandes par minute et par personne (ASVS 5.0 V2.4).
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by('exports:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by('password-change:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        Route::model('notification', UserNotification::class);
        Route::model('occurrence', ReservationOccurrence::class);
        Route::model('assignment', RoleAssignment::class);
        Route::model('extension', RoleExtension::class);
        Route::model('group', ScreenGroup::class);
        Route::model('block', RoomBlock::class);
        Route::model('fallback', FallbackContent::class);
        Route::model('screenshot', DeviceScreenshot::class);
        Route::model('device', Device::class);
        Route::model('source', DynamicSource::class);
        Route::model('account', ServiceAccount::class);
        Route::model('release', PlayerRelease::class);

        Route::pattern('notification', '[0-9a-f-]{36}');
        foreach (['release', 'source', 'account', 'occurrence', 'assignment', 'extension', 'group', 'block', 'fallback', 'screenshot', 'device', 'screen', 'asset', 'assetVersion', 'document', 'template', 'content', 'campaign', 'alert', 'room', 'reservation', 'serviceRequest', 'visit', 'export', 'user', 'role', 'incident'] as $parameter) {
            Route::pattern($parameter, '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
        }
    }
}
