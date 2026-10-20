<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Support direct /admin/v1 base URL without /api prefix for back-office frontend
Route::prefix('admin/v1')->middleware('api')->group(base_path('routes/admin.php'));

// Diffusion publique des sons musicaux (évite 403 / disque privé)
Route::get('/sounds/{filename}', function (string $filename) {
    $candidates = [
        public_path('sounds/'.$filename),
        storage_path('app/public/sounds/'.$filename),
        storage_path('app/public/sounds/catalogue/'.$filename),
    ];

    foreach ($candidates as $path) {
        if (file_exists($path)) {
            return response()->file($path, [
                'Content-Type' => 'audio/mpeg',
                'Accept-Ranges' => 'bytes',
            ]);
        }
    }

    $silentMp3 = base64_decode('//uQxAAAAAAAAAAAAAAAAAAAAAAASW5mbwAAAA8AAAAFAAAACAAICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg//uQxAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAAFAAAACAAICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg');

    return response($silentMp3, 200, [
        'Content-Type' => 'audio/mpeg',
        'Content-Disposition' => 'inline; filename="'.$filename.'"',
        'Accept-Ranges' => 'bytes',
    ]);
})->where('filename', '[A-Za-z0-9_-]+\.mp3')->name('sounds.stream');

Route::get('/storage/sounds/{filename}', function (string $filename) {
    $candidates = [
        public_path('sounds/'.$filename),
        storage_path('app/public/sounds/'.$filename),
        storage_path('app/public/sounds/catalogue/'.$filename),
    ];

    foreach ($candidates as $path) {
        if (file_exists($path)) {
            return response()->file($path, [
                'Content-Type' => 'audio/mpeg',
                'Accept-Ranges' => 'bytes',
            ]);
        }
    }

    $silentMp3 = base64_decode('//uQxAAAAAAAAAAAAAAAAAAAAAAASW5mbwAAAA8AAAAFAAAACAAICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg//uQxAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAAFAAAACAAICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg');

    return response($silentMp3, 200, [
        'Content-Type' => 'audio/mpeg',
        'Content-Disposition' => 'inline; filename="'.$filename.'"',
        'Accept-Ranges' => 'bytes',
    ]);
})->where('filename', '[A-Za-z0-9_-]+\.mp3');
