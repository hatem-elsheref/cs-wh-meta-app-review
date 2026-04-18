<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyExternalApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.external_whatsapp.api_key');

        if (! is_string($configured) || $configured === '') {
            return response()->json([
                'error' => 'External WhatsApp API is not configured',
                'code' => 'EXTERNAL_API_DISABLED',
            ], 503);
        }

        $provided = $request->header('X-API-Key');
        if (! is_string($provided) || $provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json([
                'error' => 'Invalid or missing API key',
                'code' => 'INVALID_API_KEY',
            ], 401);
        }

        return $next($request);
    }
}
