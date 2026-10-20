<?php

use App\Exceptions\Content\ResourceGoneException;
use App\Exceptions\Content\ResourceLockedException;
use App\Exceptions\Content\ResourceNotFoundException;
use App\Exceptions\Upload\TusOffsetConflictException;
use App\Exceptions\Upload\TusUploadException;
use App\Http\Middleware\CheckAdminRole;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureAdminSession;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Services\System\IncidentTrackerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [
            'bango_admin_session',
            'bango_admin_csrf',
        ]);

        $middleware->alias([
            'admin' => CheckAdminRole::class,
            'admin.session' => EnsureAdminSession::class,
            'admin.permission' => EnsureAdminPermission::class,
            'subscribed' => EnsureActiveSubscription::class,
            'terms.accepted' => EnsureTermsAccepted::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('admin/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            $isAdmin = $request->is('admin/*')
                || $request->is('api/admin/*')
                || $request->header('X-Requested-With') === 'bango-admin';

            if (! $isAdmin) {
                return null;
            }

            $fields = [];
            foreach ($e->errors() as $field => $messages) {
                $fields[$field] = $messages[0] ?? 'invalid';
            }

            return response()->json([
                'code' => 'validation',
                'message' => $e->getMessage(),
                'fields' => (object) $fields,
                'meta' => (object) [],
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (ResourceNotFoundException $e, Request $request) {
            return $e->render($request);
        });

        $exceptions->render(function (ResourceGoneException $e, Request $request) {
            return $e->render($request);
        });

        $exceptions->render(function (ResourceLockedException $e, Request $request) {
            return $e->render($request);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // Préservation stricte des exceptions 4xx et de contrôle d'accès
            if ($e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
                || $e instanceof AccessDeniedHttpException
                || $e instanceof NotFoundHttpException
                || $e instanceof ModelNotFoundException
                || $e instanceof ResourceNotFoundException
                || $e instanceof ResourceGoneException
                || $e instanceof ResourceLockedException
                || $e instanceof TusOffsetConflictException
                || $e instanceof TusUploadException
            ) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                if ($status < 500) {
                    return null;
                }
            }

            $statusCode = ($e instanceof HttpExceptionInterface) ? $e->getStatusCode() : 500;

            return app(IncidentTrackerService::class)->renderIncidentResponse($e, $request, null, $statusCode);
        });
    })->create();
