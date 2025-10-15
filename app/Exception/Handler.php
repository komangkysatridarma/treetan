<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $exception)
    {
        // Jika request adalah API (minta JSON response)
        if ($request->is('api/*') || $request->expectsJson()) {
            return $this->handleApiException($request, $exception);
        }

        return parent::render($request, $exception);
    }

    /**
     * Handle API exceptions
     */
    private function handleApiException($request, Throwable $exception)
    {
        $statusCode = 500;
        $message = 'Internal Server Error';
        $errors = null;

        // Validation Exception
        if ($exception instanceof ValidationException) {
            $statusCode = 422;
            $message = 'Validation Error';
            $errors = $exception->errors();
        }
        // Model Not Found
        elseif ($exception instanceof ModelNotFoundException) {
            $statusCode = 404;
            $message = 'Resource not found';
        }
        // Route Not Found
        elseif ($exception instanceof NotFoundHttpException) {
            $statusCode = 404;
            $message = 'Endpoint not found';
        }
        // Method Not Allowed
        elseif ($exception instanceof MethodNotAllowedHttpException) {
            $statusCode = 405;
            $message = 'Method not allowed';
        }
        // Authentication Exception
        elseif ($exception instanceof AuthenticationException) {
            $statusCode = 401;
            $message = 'Unauthenticated';
        }
        // Database Query Exception
        elseif ($exception instanceof QueryException) {
            $statusCode = 500;
            $message = 'Database error';
            
            // Tambahkan detail error jika dalam mode debug
            if (config('app.debug')) {
                $errors = [
                    'sql_error' => $exception->getMessage(),
                    'sql_code' => $exception->getCode()
                ];
            }
        }
        // General Exception
        else {
            $statusCode = method_exists($exception, 'getStatusCode') 
                ? $exception->getStatusCode() 
                : 500;
            $message = $exception->getMessage() ?: 'Internal Server Error';
        }

        $response = [
            'success' => false,
            'message' => $message,
        ];

        // Tambahkan errors jika ada
        if ($errors) {
            $response['errors'] = $errors;
        }

        // Tambahkan detail debug jika APP_DEBUG=true
        if (config('app.debug')) {
            $response['debug'] = [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => explode("\n", $exception->getTraceAsString()),
            ];
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Convert an authentication exception into a response.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please login first.'
            ], 401);
        }

        return redirect()->guest(route('login'));
    }
}