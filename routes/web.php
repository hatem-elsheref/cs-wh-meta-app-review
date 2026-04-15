<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA (Vite build → public/spa/)
|--------------------------------------------------------------------------
| Static files under /spa/assets/* are served by the web server directly.
| Other /spa/* paths are client routes — return index.html for React Router.
*/

Route::get('/', function () {
    // Let the SPA decide where to go (login vs dashboard) based on stored auth.
    return redirect('/spa', 302);
});

Route::get('/spa/{path?}', function (?string $path = null) {
    $index = public_path('spa/index.html');
    if (! File::isFile($index)) {
        abort(503, 'Frontend build missing. Run: cd Frontend && npm run build:laravel');
    }

    if ($path === null || $path === '') {
        return response()->file($index);
    }

    $safe = str_replace(['..', "\0"], '', $path);
    $candidate = public_path('spa/'.$safe);
    if (File::isFile($candidate)) {
        return response()->file($candidate);
    }

    return response()->file($index);
})->where('path', '.*');
