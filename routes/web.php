<?php

use App\Http\Controllers\Geo\GenderController;
use App\Http\Controllers\Search\PeopleSearchController;
use App\Http\Controllers\Web\PublicAccountDeletionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Route publique des genres (AU-07)
Route::get('/genders', [GenderController::class, 'index'])->name('web.genders');

// Page publique de suppression de compte & conformité Google Play (Suppression des données)
Route::get('/supprimer-mon-compte', [PublicAccountDeletionController::class, 'show'])->defaults('locale', 'fr')->name('web.account.delete.fr');
Route::get('/en/delete-account', [PublicAccountDeletionController::class, 'show'])->defaults('locale', 'en')->name('web.account.delete.en');
Route::post('/supprimer-mon-compte/request-otp', [PublicAccountDeletionController::class, 'requestOtp'])->name('web.account.delete.request-otp');
Route::post('/en/delete-account/request-otp', [PublicAccountDeletionController::class, 'requestOtp'])->defaults('locale', 'en')->name('web.account.delete.request-otp.en');
Route::post('/supprimer-mon-compte', [PublicAccountDeletionController::class, 'deleteAccount'])->name('web.account.delete.submit');
Route::post('/en/delete-account', [PublicAccountDeletionController::class, 'deleteAccount'])->defaults('locale', 'en')->name('web.account.delete.submit.en');
Route::post('/supprimer-mon-compte/partial-data', [PublicAccountDeletionController::class, 'deletePartialData'])->name('web.account.delete.partial');
Route::post('/en/delete-account/partial-data', [PublicAccountDeletionController::class, 'deletePartialData'])->defaults('locale', 'en')->name('web.account.delete.partial.en');

// Suggestions d'habitants et voisins (SQ-01, AU-19) - support direct sans préfixe /api
Route::get('/people/suggestions', [PeopleSearchController::class, 'suggestions'])->middleware('api')->name('web.people.suggestions');
// Recherche de profils - support direct sans préfixe /api
Route::get('/search/people', [PeopleSearchController::class, 'index'])->middleware('api')->name('web.search.people');

// Support direct /admin and /admin/v1 base URL without /api prefix for back-office frontend
Route::prefix('admin')->middleware('api')->group(base_path('routes/admin.php'));
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
