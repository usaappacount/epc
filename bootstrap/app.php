<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // الثقة ببروكسي Render لإرسال البيانات عبر HTTPS بدون تحذيرات
        $middleware->trustProxies(at: '*');

        // استثناء رابط الويب هوك من حماية الـ CSRF لمنع خطأ 419 مع GreatWhats
        $middleware->validateCsrfTokens(except: [
            'api/webhook/receiver',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
