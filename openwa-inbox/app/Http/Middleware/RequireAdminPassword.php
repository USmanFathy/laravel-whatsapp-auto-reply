<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** HTTP Basic protection for the configuration page. Disabled (503) until AUTO_REPLY_ADMIN_PASSWORD is set. */
class RequireAdminPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('openwa.admin.password');
        if ($password === '') {
            return response('The configuration page is disabled: set AUTO_REPLY_ADMIN_PASSWORD in .env, then run "php artisan config:clear".', 503);
        }

        $userOk = hash_equals((string) config('openwa.admin.user'), (string) $request->getUser());
        $passOk = hash_equals($password, (string) $request->getPassword());
        if (! ($userOk && $passOk)) {
            return response('Authentication required.', 401, ['WWW-Authenticate' => 'Basic realm="Auto reply", charset="UTF-8"']);
        }

        return $next($request);
    }
}
