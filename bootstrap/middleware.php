<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CheckApplicationHealthMiddleware;
use App\Http\Middleware\CheckMigrationStatus;
use App\Http\Middleware\ClearRequestCacheMiddleware;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\InstalledStateMiddleware;
use App\Http\Middleware\KillSessionIfNotInstalledMiddleware;
use App\Http\Middleware\LoadLangMiddleware;
use App\Http\Middleware\NotInstalledStateMiddleware;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\ThrottleMiddelware;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * @var Middleware $middleware
 */
$middleware->redirectGuestsTo( fn() => route( 'ns.login' ) );

/**
 * When the application runs behind a reverse proxy or an HTTPS tunnel
 * (ngrok, Cloudflare Tunnel, load balancer), the X-Forwarded-* headers
 * must be trusted for generated URLs to keep the https scheme.
 * Disabled unless TRUSTED_PROXIES is set (e.g. TRUSTED_PROXIES=*).
 */
if ( ! empty( env( 'TRUSTED_PROXIES' ) ) ) {
    $middleware->trustProxies( at: env( 'TRUSTED_PROXIES' ) === '*' ? '*' : explode( ',', env( 'TRUSTED_PROXIES' ) ) );
}

/**
 * We'll list here all aliased middleware.
 */
$middleware->alias( [
    'ns.not-installed' => NotInstalledStateMiddleware::class,
    'ns.installed' => InstalledStateMiddleware::class,
    'ns.clear-cache' => ClearRequestCacheMiddleware::class,
    'auth' => Authenticate::class,
    'auth.basic' => AuthenticateWithBasicAuth::class,
    'bindings' => SubstituteBindings::class,
    'cache.headers' => SetCacheHeaders::class,
    'can' => Authorize::class,
    'guest' => RedirectIfAuthenticated::class,
    'password.confirm' => RequirePassword::class,
    'signed' => ValidateSignature::class,
    'throttle' => ThrottleRequests::class,
    'verified' => EnsureEmailIsVerified::class,
    'ns.check-migrations' => CheckMigrationStatus::class,
    'ns.check-application-health' => CheckApplicationHealthMiddleware::class,
] );

/**
 * We'll now register middlewaregroups
 */
$middleware->group( 'web', [
    EncryptCookies::class,
    KillSessionIfNotInstalledMiddleware::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    AuthenticateSession::class,
    ShareErrorsFromSession::class,
    VerifyCsrfToken::class,
    LoadLangMiddleware::class,
] );

/**
 * We'll now register the api middleware group
 */
$middleware->group( 'api', [
    EnsureFrontendRequestsAreStateful::class,
    LoadLangMiddleware::class,
    ThrottleMiddelware::class . ':80,1',
] );
