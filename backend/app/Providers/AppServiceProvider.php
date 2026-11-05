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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Access::class);
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(120)->by('device:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('integration', fn (Request $request) => Limit::perMinute(60)->by('integration:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->getKey() ?: $request->ip()));

        ResetPassword::createUrlUsing(fn (User $user, string $token) => rtrim((string) config('app.frontend_url'), '/').'/compte/verification?token='.$token.'&email='.urlencode($user->email));

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
