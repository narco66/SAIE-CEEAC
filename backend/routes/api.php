<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarSubscriptionController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceApiController;
use App\Http\Controllers\Api\V1\DeviceCommandController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DynamicSourceController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PlayerReleaseController;
use App\Http\Controllers\Api\V1\PlaylistController;
use App\Http\Controllers\Api\V1\QrController;
use App\Http\Controllers\Api\V1\RealtimeController;
use App\Http\Controllers\Api\V1\ReferentialController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\ScreenController;
use App\Http\Controllers\Api\V1\ScreenProfileController;
use App\Http\Controllers\Api\V1\ServiceAccountController;
use App\Http\Controllers\Api\V1\ServiceRequestController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\SupervisionController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VisitController;
use App\Http\Middleware\LogDeviceExchange;
use App\Http\Middleware\VerifyDeviceSignature;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/*
 * API SAIE-CEEAC v1 — contrat documenté dans docs/api/openapi.yaml (API-06).
 */

// Authentification (SEC-01) — limitée en débit.
Route::middleware('throttle:login')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('auth/mfa', [AuthController::class, 'mfa'])->name('auth.mfa');
    Route::post('auth/forgot-password', [AuthController::class, 'forgot'])->name('auth.forgot');
    Route::post('auth/reset-password', [AuthController::class, 'reset'])->name('auth.reset');
});
Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

// Résolution des QR codes : public pour un contenu public, authentification réévaluée sinon (DOC-05).
Route::get('q/{code}', [QrController::class, 'show'])->middleware('throttle:60,1')->name('qr.show');

// Abonnement iCalendar d'une salle par jeton personnel révocable (SAL-11), lisible par un client d'agenda sans session.
Route::get('calendar/{token}.ics', [CalendarSubscriptionController::class, 'feed'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:60,1')
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)->name('calendar.feed');

// API technique des lecteurs (PLY-02 à PLY-07). Toujours sans session ni CSRF : le lecteur s'authentifie par jeton. Il est
// servi sur le même domaine que l'administration (/player/), déclaré « avec état » pour Sanctum : sans cette exclusion, chaque
// appel ouvrirait une session (erreur 500 à l'enregistrement, le lecteur n'étant pas un compte) et exigerait le jeton CSRF.
Route::prefix('device')->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)->group(function (): void {
    Route::post('pairing-codes', [DeviceApiController::class, 'requestCode'])->middleware('throttle:10,1');
    Route::get('pairing-codes/{code}', [DeviceApiController::class, 'pollCode'])->middleware('throttle:30,1');
    Route::get('key', [DeviceApiController::class, 'key']);
    // §10.16 : battement de l'agent local, authentifié par son propre jeton (portée « agent », jamais « device »).
    Route::post('agent-heartbeat', [DeviceApiController::class, 'agentHeartbeat'])->middleware(['auth:sanctum', 'throttle:device']);
    Route::middleware(['auth:sanctum', 'device', 'throttle:device', VerifyDeviceSignature::class, LogDeviceExchange::class])->group(function (): void {
        Route::get('manifest', [DeviceApiController::class, 'manifest']);
        Route::post('agent-token', [DeviceApiController::class, 'agentToken']);
        Route::post('heartbeat', [DeviceApiController::class, 'heartbeat']);
        Route::post('token/rotate', [DeviceApiController::class, 'rotateToken']);
        Route::post('receipts', [DeviceApiController::class, 'receipts']);
        Route::get('assets/{assetVersion}', [DeviceApiController::class, 'asset']);
        Route::post('screenshots/{screenshot}', [DeviceApiController::class, 'uploadScreenshot']);
        Route::post('commands/{command}/ack', [DeviceApiController::class, 'acknowledgeCommand']);
    });
});

// API d'intégration SI (API-07, ACC-03) — comptes de service, désactivée par défaut.
Route::prefix('integration')->middleware(['auth:sanctum', 'throttle:integration'])->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)->group(function (): void {
    Route::get('meetings', [IntegrationController::class, 'meetings']);
    Route::get('screens', [IntegrationController::class, 'screens']);
    Route::get('announcements', [IntegrationController::class, 'announcements']);
});

Route::middleware(['auth:sanctum', 'human', 'throttle:api', 'idempotent'])->group(function (): void {
    Route::get('service-accounts', [ServiceAccountController::class, 'index']);
    Route::post('service-accounts', [ServiceAccountController::class, 'store']);
    Route::post('service-accounts/{account}/rotate', [ServiceAccountController::class, 'rotate']);
    Route::post('service-accounts/{account}/revoke', [ServiceAccountController::class, 'revoke']);
    Route::get('me', [MeController::class, 'show']);
    Route::patch('me', [MeController::class, 'update']);
    Route::post('me/password', [MeController::class, 'password'])->middleware('throttle:password-change');
    Route::get('me/sessions', [MeController::class, 'sessions']);
    Route::delete('me/sessions/{session}', [MeController::class, 'revokeSession']);
    Route::post('me/mfa/setup', [MeController::class, 'mfaSetup']);
    Route::get('me/push', [MeController::class, 'push']);
    Route::post('me/push', [MeController::class, 'subscribePush']);
    Route::delete('me/push', [MeController::class, 'unsubscribePush']);
    Route::post('me/mfa/confirm', [MeController::class, 'mfaConfirm']);

    Route::get('dashboard', [DashboardController::class, 'show']);
    Route::get('tasks', [DashboardController::class, 'tasks']);
    Route::get('search', [DashboardController::class, 'search']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::get('notifications/{notification}/open', [NotificationController::class, 'open']);

    // Référentiels (REF-01, REF-03)
    Route::get('referentials', [ReferentialController::class, 'index']);
    Route::post('referentials/{type}', [ReferentialController::class, 'store']);
    Route::patch('referentials/{type}/{id}', [ReferentialController::class, 'update']);
    Route::get('imports', [ImportController::class, 'index']);
    Route::get('imports/template/{type}', [ImportController::class, 'template']);
    Route::post('imports/simulate', [ImportController::class, 'simulate']);
    Route::post('imports/commit', [ImportController::class, 'commit']);

    // Parc (REF-02, PLY-02, PLY-08, PLY-11)
    Route::get('screens', [ScreenController::class, 'index']);
    Route::post('screens', [ScreenController::class, 'store']);
    Route::post('screens/import', [ScreenController::class, 'import']);
    Route::get('screens/overview', [ScreenController::class, 'overview']);
    Route::get('screens/import/template', [ScreenController::class, 'importTemplate']);
    Route::get('screens/{screen}', [ScreenController::class, 'show']);
    Route::patch('screens/{screen}', [ScreenController::class, 'update']);
    Route::post('screens/{screen}/archive', [ScreenController::class, 'archive']);
    Route::get('screens/{screen}/status-history', [ScreenController::class, 'statusHistory']);
    Route::get('screens/{screen}/history', [ScreenController::class, 'history']);
    Route::get('screen-profiles', [ScreenProfileController::class, 'index']);
    Route::post('screen-profiles', [ScreenProfileController::class, 'store']);
    Route::patch('screen-profiles/{profile}', [ScreenProfileController::class, 'update']);
    Route::post('screens/{screen}/qr', [ScreenController::class, 'qr']);
    Route::post('screens/{screen}/documents', [ScreenController::class, 'attachDocument']);
    Route::delete('screens/{screen}/documents/{document}', [ScreenController::class, 'detachDocument']);
    Route::get('screens/{screen}/preview', [ScreenController::class, 'preview']);
    Route::post('devices/pair', [ScreenController::class, 'pair']);
    Route::post('devices/{device}/reassign', [ScreenController::class, 'reassign']);
    Route::post('devices/{device}/suspend', [ScreenController::class, 'suspend']);
    Route::get('devices/{device}/logs', [ScreenController::class, 'deviceLogs']);
    Route::get('realtime/stream', [RealtimeController::class, 'stream']);
    Route::post('screens/{screen}/commission', [ScreenController::class, 'commission']);
    Route::patch('devices/{device}', [ScreenController::class, 'updateDevice']);
    Route::post('devices/{device}/rotate-credentials', [ScreenController::class, 'rotateCredentials']);
    Route::patch('devices/{device}/release', [PlayerReleaseController::class, 'pin']);
    Route::post('playlists/{playlist}/assign', [PlaylistController::class, 'assign']);
    Route::post('devices/{device}/resume', [ScreenController::class, 'resume']);
    Route::patch('player-releases/{release}', [PlayerReleaseController::class, 'update']);
    Route::get('player-releases/{release}/deployment', [PlayerReleaseController::class, 'deployment']);
    Route::post('devices/{device}/revoke', [ScreenController::class, 'revoke']);
    Route::post('devices/{device}/reload', [ScreenController::class, 'reload']);
    Route::post('devices/{device}/screenshot', [ScreenController::class, 'requestScreenshot']);
    Route::get('devices/{device}/commands', [DeviceCommandController::class, 'index']);
    Route::post('devices/{device}/commands', [DeviceCommandController::class, 'store']);
    Route::post('device-commands/{command}/cancel', [DeviceCommandController::class, 'cancel']);
    Route::get('device-screenshots/{screenshot}', [ScreenController::class, 'screenshot']);
    Route::get('screen-groups', [ScreenController::class, 'groups']);
    Route::post('screen-groups', [ScreenController::class, 'storeGroup']);
    Route::patch('screen-groups/{group}', [ScreenController::class, 'updateGroup']);
    Route::delete('screen-groups/{group}', [ScreenController::class, 'destroyGroup']);

    // Médiathèque et documents (DOC-01 à DOC-08)
    Route::get('assets', [AssetController::class, 'index']);
    Route::post('assets', [AssetController::class, 'store']);
    Route::get('assets/{asset}', [AssetController::class, 'show']);
    Route::patch('assets/{asset}', [AssetController::class, 'update']);
    Route::get('assets/{asset}/status', [AssetController::class, 'status']);
    Route::post('assets/{asset}/versions', [AssetController::class, 'addVersion']);
    Route::post('assets/{asset}/trash', [AssetController::class, 'trash']);
    Route::get('asset-versions/{assetVersion}/file', [AssetController::class, 'file']);
    Route::get('asset-versions/{assetVersion}/thumbnail', [AssetController::class, 'thumbnail']);
    Route::get('asset-versions/{assetVersion}/consultation', [AssetController::class, 'consultation']);

    Route::get('documents', [DocumentController::class, 'index']);
    Route::post('documents', [DocumentController::class, 'store']);
    Route::get('documents/{document}', [DocumentController::class, 'show']);
    Route::patch('documents/{document}', [DocumentController::class, 'update']);
    Route::post('documents/{document}/versions', [DocumentController::class, 'addVersion']);
    Route::post('documents/{document}/publish', [DocumentController::class, 'publish']);
    Route::post('documents/{document}/request-publication', [DocumentController::class, 'requestPublication']);
    Route::post('documents/{document}/unpublish', [DocumentController::class, 'unpublish']);
    Route::post('documents/{document}/trash', [DocumentController::class, 'trash']);
    Route::get('documents/{document}/versions/{version}/download', [DocumentController::class, 'download']);
    Route::get('documents/{document}/reads', [DocumentController::class, 'reads']);
    Route::get('documents/{document}/broadcasts', [DocumentController::class, 'broadcasts']);
    Route::post('documents/{document}/broadcasts', [DocumentController::class, 'broadcast']);

    // Publication (PUB-01 à PUB-11)
    Route::get('templates', [TemplateController::class, 'index']);
    Route::post('templates', [TemplateController::class, 'store']);
    Route::get('templates/{template}', [TemplateController::class, 'show']);
    Route::patch('templates/{template}', [TemplateController::class, 'update']);
    Route::post('templates/{template}/duplicate', [TemplateController::class, 'duplicate']);
    Route::post('templates/{template}/archive', [TemplateController::class, 'archive']);

    Route::get('contents', [ContentController::class, 'index']);
    Route::post('contents', [ContentController::class, 'store']);
    Route::get('contents/{content}', [ContentController::class, 'show']);
    Route::patch('contents/{content}', [ContentController::class, 'update']);
    Route::post('contents/{content}/languages', [ContentController::class, 'languages']);
    Route::post('contents/{content}/submit', [ContentController::class, 'submit']);
    Route::post('contents/{content}/approve', [ContentController::class, 'approve']);
    Route::post('contents/{content}/archive', [ContentController::class, 'archive']);
    Route::post('contents/{content}/duplicate', [ContentController::class, 'duplicate']);

    Route::get('campaigns', [CampaignController::class, 'index']);
    Route::post('campaigns', [CampaignController::class, 'store']);
    Route::post('campaigns/preview', [CampaignController::class, 'preview']);
    Route::get('campaign-priority-levels', [CampaignController::class, 'priorityLevels']);
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show']);
    Route::patch('campaigns/{campaign}', [CampaignController::class, 'update']);
    Route::post('campaigns/{campaign}/publish', [CampaignController::class, 'publish']);
    Route::post('campaigns/{campaign}/suspend', [CampaignController::class, 'suspend']);
    Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume']);
    Route::post('campaigns/{campaign}/archive', [CampaignController::class, 'archive']);
    Route::get('playlists', [PlaylistController::class, 'index']);
    Route::post('playlists', [PlaylistController::class, 'store']);
    Route::get('playlists/{playlist}', [PlaylistController::class, 'show']);
    Route::patch('playlists/{playlist}', [PlaylistController::class, 'update']);
    Route::get('campaigns/{campaign}/delivery', [CampaignController::class, 'delivery']);
    Route::get('program', [CampaignController::class, 'program']);
    Route::get('fallbacks', [CampaignController::class, 'fallbacks']);
    Route::post('fallbacks', [CampaignController::class, 'storeFallback']);
    Route::delete('fallbacks/{fallback}', [CampaignController::class, 'destroyFallback']);

    Route::get('alerts', [AlertController::class, 'index']);
    Route::post('alerts/preview', [AlertController::class, 'preview']);
    Route::post('alerts', [AlertController::class, 'store']);
    Route::get('alerts/{alert}', [AlertController::class, 'show']);
    Route::post('alerts/{alert}/extend', [AlertController::class, 'extend']);
    Route::post('alerts/{alert}/revoke', [AlertController::class, 'revoke']);
    Route::post('alerts/{alert}/approve', [AlertController::class, 'approve']);

    // Salles (SAL-01 à SAL-14)
    Route::get('rooms', [RoomController::class, 'index']);
    Route::post('rooms', [RoomController::class, 'store']);
    Route::get('rooms-calendar', [RoomController::class, 'calendar']);
    Route::get('rooms/{room}', [RoomController::class, 'show']);
    Route::patch('rooms/{room}', [RoomController::class, 'update']);
    Route::get('rooms/{room}/availability', [RoomController::class, 'availability']);
    Route::post('rooms/{room}/blocks', [RoomController::class, 'block']);
    Route::delete('room-blocks/{block}', [RoomController::class, 'unblock']);
    Route::get('mobile-resources', [RoomController::class, 'resources']);
    Route::post('mobile-resources', [RoomController::class, 'storeResource']);

    Route::get('reservations', [ReservationController::class, 'index']);
    Route::post('reservations/check', [ReservationController::class, 'check']);
    Route::post('reservations', [ReservationController::class, 'store']);
    Route::get('reservations/{reservation}', [ReservationController::class, 'show']);
    Route::post('reservations/{reservation}/approve', [ReservationController::class, 'approve']);
    Route::post('reservations/{reservation}/refuse', [ReservationController::class, 'refuse']);
    Route::put('reservations/{reservation}/agenda', [ReservationController::class, 'agenda']);
    Route::post('reservations/{reservation}/priority', [ReservationController::class, 'priority']);
    Route::post('reservations/{reservation}/welcome', [ReservationController::class, 'welcome']);
    Route::patch('occurrences/{occurrence}', [ReservationController::class, 'modify']);
    Route::post('occurrences/{occurrence}/modify-following', [ReservationController::class, 'modifyFollowing']);
    Route::post('occurrences/{occurrence}/cancel', [ReservationController::class, 'cancel']);
    Route::post('occurrences/{occurrence}/no-show', [ReservationController::class, 'noShow']);
    Route::post('occurrences/{occurrence}/check-in', [ReservationController::class, 'checkIn']);
    Route::get('reservations/{reservation}/calendar.ics', [ReservationController::class, 'ics']);
    Route::get('rooms/{room}/calendar.ics', [RoomController::class, 'ics']);
    Route::get('me/calendar-subscriptions', [CalendarSubscriptionController::class, 'index']);
    Route::post('rooms/{room}/calendar-subscriptions', [CalendarSubscriptionController::class, 'store']);
    Route::post('calendar-subscriptions/{subscription}/revoke', [CalendarSubscriptionController::class, 'revoke']);

    Route::get('service-requests', [ServiceRequestController::class, 'index']);
    Route::patch('service-requests/{serviceRequest}', [ServiceRequestController::class, 'update']);
    Route::post('occurrences/{occurrence}/checklist', [ServiceRequestController::class, 'checklist']);

    Route::get('visits', [VisitController::class, 'index']);
    Route::post('visits', [VisitController::class, 'store']);
    Route::get('visits/{visit}', [VisitController::class, 'show']);
    Route::patch('visits/{visit}', [VisitController::class, 'update']);

    // Pilotage (TAB-01, TAB-02, GOV-01)
    Route::get('reports/summary', [ReportController::class, 'summary']);
    Route::get('exports', [ExportController::class, 'index']);
    Route::post('exports', [ExportController::class, 'store'])->middleware('throttle:exports');
    Route::get('exports/{export}', [ExportController::class, 'show']);
    Route::get('exports/{export}/download', [ExportController::class, 'download']);
    Route::post('exports/{export}/cancel', [ExportController::class, 'cancel']);
    Route::post('exports/{export}/retry', [ExportController::class, 'retry']);

    // Administration (HAB, SEC-04)
    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::get('users/{user}', [UserController::class, 'show']);
    Route::patch('users/{user}', [UserController::class, 'update']);
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::post('users/{user}/reactivate', [UserController::class, 'reactivate']);
    Route::post('users/{user}/revoke-sessions', [UserController::class, 'revokeSessions']);
    Route::post('users/{user}/unlock', [UserController::class, 'unlock']);
    Route::post('users/{user}/reset-mfa', [UserController::class, 'resetMfa']);
    Route::get('roles', [RoleController::class, 'index']);
    Route::put('roles/{role}/permissions', [RoleController::class, 'permissions']);
    Route::post('role-assignments', [RoleController::class, 'assign']);
    Route::post('role-assignments/{assignment}/revoke', [RoleController::class, 'revokeAssignment']);
    Route::post('role-extensions', [RoleController::class, 'grantPower']);
    Route::post('role-extensions/{extension}/revoke', [RoleController::class, 'revokePower']);
    Route::get('users/{user}/effective', [RoleController::class, 'effective']);

    Route::get('audit-logs', [AuditController::class, 'index']);
    Route::get('settings', [SettingController::class, 'index']);
    Route::patch('settings', [SettingController::class, 'update']);
    Route::get('player-releases', [PlayerReleaseController::class, 'index']);
    Route::post('player-releases', [PlayerReleaseController::class, 'store']);
    Route::post('player-releases/{release}/withdraw', [PlayerReleaseController::class, 'withdraw']);
    Route::patch('devices/{device}/channel', [PlayerReleaseController::class, 'assignChannel']);
    Route::get('dynamic-sources', [DynamicSourceController::class, 'index']);
    Route::post('dynamic-sources', [DynamicSourceController::class, 'store']);
    Route::patch('dynamic-sources/{source}', [DynamicSourceController::class, 'update']);
    Route::post('dynamic-sources/{source}/test', [DynamicSourceController::class, 'test']);
    Route::get('supervision', [SupervisionController::class, 'show']);
    Route::get('supervision/errors', [SupervisionController::class, 'errors']);
    Route::get('maintenance-plans', [SupervisionController::class, 'plans']);
    Route::post('maintenance-plans', [SupervisionController::class, 'storePlan']);
    Route::patch('maintenance-plans/{plan}', [SupervisionController::class, 'updatePlan']);
    Route::get('incidents', [SupervisionController::class, 'incidents']);
    Route::get('incidents/stats', [SupervisionController::class, 'incidentStats']);
    Route::post('incidents', [SupervisionController::class, 'storeIncident']);
    Route::patch('incidents/{incident}', [SupervisionController::class, 'updateIncident']);
});
