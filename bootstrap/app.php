<?php

use App\Exceptions\StaleWriteConflictException;
use App\Handlers\Alert\AlertNotificationHandler;
use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\EnsureApiEnvelope;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRequestId;
use App\Http\Middleware\RequireReauth;
use App\Http\Middleware\ResolveBranch;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackMetricsMiddleware;
use App\Http\Middleware\TwoFactorChallenge;
use App\Support\ApiEnvelope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->append(CompressResponse::class);
        $middleware->append(TrackMetricsMiddleware::class);

        $middleware->throttleApi();

        $middleware->alias([
            'permission' => EnsurePermission::class,
            '2fa' => TwoFactorChallenge::class,
            'reauth' => RequireReauth::class,
        ]);

        // JazzCash's page-redirection flow POSTs the transaction result to our
        // callback URL as a plain browser form — there is no XSRF token from
        // the gateway. The callback authenticates via HMAC signature instead.
        $middleware->validateCsrfTokens(except: [
            'api/v1/jazzcash/callback',
        ]);

        $middleware->web(replace: [
            SubstituteBindings::class => ResolveBranch::class,
        ]);

        $middleware->api(prepend: [
            EnsureRequestId::class,
        ]);
        $middleware->api(append: [
            EnsureApiEnvelope::class,
        ]);
        $middleware->api(replace: [
            SubstituteBindings::class => ResolveBranch::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Render every API exception path in the same envelope as success
        // responses: `{success:false, message, data, meta:{request_id}}`,
        // plus the top-level `errors` mapping for 422s (kept for
        // assertJsonValidationErrors / SPA form display). The closure returns
        // null for web requests so Laravel's default rendering still applies.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            // A stale write is a distinct outcome from a validation failure: the
            // request was well-formed, but the row moved on. 409 with the
            // current version in `data` lets the client offer "reload" rather
            // than showing a validation error the user cannot act on.
            if ($e instanceof StaleWriteConflictException) {
                if (! $request->is('api/*') && ! $request->expectsJson()) {
                    return null;
                }

                $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();

                return ApiEnvelope::error(
                    $e->getMessage(),
                    409,
                    data: $e->context(),
                    requestId: $requestId,
                    request: $request,
                );
            }

            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $status = match (true) {
                $e instanceof ValidationException => 422,
                $e instanceof AuthenticationException => 401,
                $e instanceof AuthorizationException => 403,
                $e instanceof TokenMismatchException => 419,
                $e instanceof MethodNotAllowedHttpException => 405,
                $e instanceof NotFoundHttpException, $e instanceof ModelNotFoundException => 404,
                $e instanceof ThrottleRequestsException => 429,
                $e instanceof HttpException => $e->getStatusCode(),
                default => 500,
            };

            $message = match (true) {
                $e instanceof ValidationException => $e->getMessage() ?: 'The given data was invalid.',
                $e instanceof AuthenticationException => 'Unauthenticated.',
                $e instanceof AuthorizationException => 'This action is unauthorized.',
                $e instanceof TokenMismatchException => 'Your session has expired. Please try again.',
                $e instanceof MethodNotAllowedHttpException => 'Method not allowed.',
                $e instanceof NotFoundHttpException, $e instanceof ModelNotFoundException => 'Resource not found.',
                $e instanceof ThrottleRequestsException => 'Too many requests. Please slow down and try again.',
                $e instanceof HttpException => $e->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error.'),
                default => 'An unexpected server error occurred.',
            };

            $errors = $e instanceof ValidationException ? $e->errors() : [];
            $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();

            return ApiEnvelope::error(
                $message,
                $status,
                errors: $errors,
                requestId: $requestId,
                request: $request,
            );
        });

        $exceptions->report(function (Throwable $e): void {
            app(AlertNotificationHandler::class)
                ->handle($e, request());
        });
    })->create();
