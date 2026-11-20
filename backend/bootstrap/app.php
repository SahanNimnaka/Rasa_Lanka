<?php

use App\Http\Controllers\Api\V1\Controller as ApiController;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*'));

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiController::error(
                'UNAUTHENTICATED',
                'Authentication required.',
                401
            );
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiController::error(
                'FORBIDDEN',
                'You are not authorized to perform this action.',
                403
            );
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiController::error(
                'FORBIDDEN',
                'You are not authorized to perform this action.',
                403
            );
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiController::error(
                'NOT_FOUND',
                'The requested resource was not found.',
                404
            );
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiController::error(
                'NOT_FOUND',
                'The requested API resource was not found.',
                404
            );
        });

        $exceptions->render(function (HttpException $exception, Request $request) {
            if (! $request->is('api/*') || $exception->getStatusCode() !== 419) {
                return null;
            }

            return ApiController::error(
                'CSRF_TOKEN_MISMATCH',
                'The CSRF token is missing or invalid.',
                419
            );
        });

        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiController::error(
                'CSRF_TOKEN_MISMATCH',
                'The CSRF token is missing or invalid.',
                419
            );
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiController::validationFailed($exception);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiController::error(
                'TOO_MANY_REQUESTS',
                'Too many requests. Please try again later.',
                429
            );
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $message = app()->environment('production')
                ? 'An unexpected server error occurred.'
                : $exception->getMessage();

            return ApiController::error(
                'INTERNAL_SERVER_ERROR',
                $message,
                500
            );
        });
    })->create();
