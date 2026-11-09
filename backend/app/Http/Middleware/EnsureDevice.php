<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes techniques des lecteurs : jeton de portée « device » et lecteur non révoqué (PLY-02, R17).
 */
class EnsureDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        if (! $device instanceof Device || ! $device->isActive() || ! $device->tokenCan('device')) {
            throw new AuthenticationException('Lecteur non reconnu ou révoqué.');
        }

        return $next($request);
    }
}
