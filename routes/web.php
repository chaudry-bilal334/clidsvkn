<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The application is delivered as a React + TypeScript single-page
| application (see DESIGN.md) that talks to the Laravel JSON API under
| /api/v1. The SPA entry (resources/views/spa.blade.php) is served for all
| non-API paths; client-side routing is handled by React Router.
|
*/

Route::pattern('any', '^(?!api|up|broadcasting|build|storage|_debugbar).*$');

Route::get('/{any}', fn () => view('spa'))->where('any', '.*')->name('spa');
