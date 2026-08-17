<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Health check
|--------------------------------------------------------------------------
|
| Exposes application health without revealing any configuration or
| secrets. Used by install.sh / update.sh to verify a deployment before
| reporting success. Returns 200 when the database is reachable.
|
*/
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        return response()->json([
            'status' => 'error',
            'services' => ['database' => 'down'],
            'time' => now()->toIso8601String(),
        ], 503);
    }

    return response()->json([
        'status' => 'ok',
        'services' => ['database' => 'up'],
        'time' => now()->toIso8601String(),
    ]);
});
