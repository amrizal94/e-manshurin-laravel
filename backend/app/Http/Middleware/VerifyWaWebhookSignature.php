<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifikasi webhook dari WA Gateway (D:\Projects\wa) — HMAC-SHA256 body
 * dengan device api_key sebagai secret, header "X-Webhook-Signature: sha256=<hex>".
 */
class VerifyWaWebhookSignature
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.wa.device_api_key');
        $header = (string) $request->header('X-Webhook-Signature');

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), (string) $secret);

        if (! $secret || ! hash_equals($expected, $header)) {
            abort(401, 'Invalid webhook signature');
        }

        return $next($request);
    }
}
