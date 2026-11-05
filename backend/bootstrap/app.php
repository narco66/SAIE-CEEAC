<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureDevice;
use App\Http\Middleware\EnsureHumanUser;
use App\Http\Middleware\Idempotent;
use App\Http\Middleware\SecurityHeaders;
use App\Support\BusinessException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$error = static function (Request $request, string $code, string $message, int $status, array $errors = [], array $details = []) {
    return response()->json(array_filter([
        'code' => $code,
        'message' => $message,
        'errors' => (object) $errors,
        'details' => $details ?: null,
        'request_id' => $request->attributes->get('request_id'),
    ], fn ($value) => $value !== null), $status);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'human' => EnsureHumanUser::class,
            'device' => EnsureDevice::class,
            'idempotent' => Idempotent::class,
        ]);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($error): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        // Un refus métier (conflit, règle) est une réponse attendue, déjà tracée au journal d'audit : pas une erreur applicative.
        $exceptions->dontReport([BusinessException::class]);

        $exceptions->render(function (Throwable $e, Request $request) use ($error) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => $error($request, 'VALIDATION_FAILED', 'Certaines informations sont invalides.', 422, $e->errors()),
                $e instanceof BusinessException => $error($request, $e->errorCode, $e->getMessage(), $e->status, [], $e->details),
                $e instanceof AuthenticationException => $error($request, 'UNAUTHENTICATED', 'Authentification requise ou session expirée.', 401),
                $e instanceof TokenMismatchException => $error($request, 'CSRF_MISMATCH', 'Session expirée : rechargez la page.', 419),
                // Un refus métier explicite (ex. auto-validation) est expliqué ; un refus sans motif reste générique (le motif va au journal).
                ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) && ! in_array($e->getMessage(), ['', 'This action is unauthorized.'], true) => $error($request, 'FORBIDDEN', $e->getMessage(), 403),
                $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => $error($request, 'FORBIDDEN', 'Vous n’êtes pas autorisé à effectuer cette action.', 403),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $error($request, 'NOT_FOUND', 'Objet absent ou inaccessible.', 404),
                $e instanceof ThrottleRequestsException => $error($request, 'TOO_MANY_REQUESTS', 'Trop de tentatives. Réessayez plus tard.', 429),
                $e instanceof QueryException && $e->getCode() === '23P01' => $error($request, 'BOOKING_CONFLICT', 'Le créneau demandé chevauche une réservation existante.', 409),
                $e instanceof QueryException && $e->getCode() === '23505' => $error($request, 'DUPLICATE', 'Un objet portant ce code existe déjà.', 409),
                $e instanceof HttpExceptionInterface => $error($request, 'HTTP_'.$e->getStatusCode(), $e->getMessage() ?: 'Requête refusée.', $e->getStatusCode()),
                default => config('app.debug') ? null : $error($request, 'SERVER_ERROR', 'Une erreur interne est survenue. Communiquez l’identifiant de diagnostic au support.', 500),
            };
        });
    })->create();
