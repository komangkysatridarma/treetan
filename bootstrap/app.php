<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*');
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            $statusCode = 500;
            $message = 'Internal Server Error';
            $errors = null;

            if ($e instanceof ValidationException) {
                $statusCode = 422;
                $message = 'Validation Error';
                $errors = $e->errors();
            }
            elseif ($e instanceof ModelNotFoundException) {
                $statusCode = 404;
                $message = 'Resource not found';
            }
            elseif ($e instanceof NotFoundHttpException) {
                $statusCode = 404;
                $message = 'Endpoint not found';
            }
            elseif ($e instanceof MethodNotAllowedHttpException) {
                $statusCode = 405;
                $message = 'Method not allowed';
            }
            elseif ($e instanceof AuthenticationException) {
                $statusCode = 401;
                $message = 'Unauthenticated. Please login first.';
            }
            elseif ($e instanceof HttpException) {
                $statusCode = $e->getStatusCode();
                $message = $e->getMessage() ?: 'HTTP Error';
            }
            elseif ($e instanceof QueryException) {
                $statusCode = 500;
                $message = 'Database error occurred';
                
                if (config('app.debug')) {
                    $errors = [
                        'sql_error' => $e->getMessage(),
                        'sql_code' => $e->getCode()
                    ];
                } else {
                    $message = 'A database error occurred. Please contact support.';
                }
            }
            else {
                $statusCode = method_exists($e, 'getStatusCode') 
                    ? $e->getStatusCode() 
                    : 500;
                
                if (config('app.debug')) {
                    $message = $e->getMessage() ?: 'Internal Server Error';
                } else {
                    $message = 'An error occurred. Please try again later.';
                }
            }

            $response = [
                'success' => false,
                'message' => $message,
            ];

            if ($errors) {
                $response['errors'] = $errors;
            }
            if (config('app.debug')) {
                $response['debug'] = [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 10),
                ];
            }

            return response()->json($response, $statusCode);
        });

        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            if ($e instanceof AuthenticationException && $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please login first.'
                ], 401);
            }

            return $response;
        });
    })->create();