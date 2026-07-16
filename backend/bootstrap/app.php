<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenTelemetry\API\Trace\Span;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            [$status, $message] = match (true) {
                $e instanceof ValidationException => [422, collect($e->errors())->flatten()->first()],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [404, '対象が見つかりません'],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage() ?: 'リクエストを処理できません'],
                default => [500, '内部エラーが発生しました'],
            };

            return response()->json([
                'message' => $message,
                'trace_id' => Span::getCurrent()->getContext()->getTraceId(),
            ], $status);
        });
    })->create();
