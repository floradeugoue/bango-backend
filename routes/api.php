<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| PUBLIC
| ├── AUTH
| └── GEO (Lecture seule)
|
| USER (auth:api)
| ├── PROFILE
| ├── OTP
| ├── ONBOARDING
| ├── HOUSING
| └── SETTINGS
|
| ADMIN (auth:api + admin role)
| ├── ROLES
| ├── USERS
| └── GEO (CRUD)
|
*/

// ==========================================
// 🔓 PUBLIC
// ==========================================

Route::prefix('auth')->group(function () {
    Route::post('/signup', \App\Http\Controllers\Auth\SignupController::class);
    Route::post('/signin', \App\Http\Controllers\Auth\SigninController::class);
});

Route::prefix('geo')->group(function () {
    Route::apiResource('countries', \App\Http\Controllers\Geo\CountryController::class)->only(['index', 'show']);
    Route::apiResource('currencies', \App\Http\Controllers\Geo\CurrencyController::class)->only(['index', 'show']);
    Route::apiResource('cities', \App\Http\Controllers\Geo\CityController::class)->only(['index', 'show']);
    Route::apiResource('neighbourhoods', \App\Http\Controllers\Geo\NeighbourhoodController::class)->only(['index', 'show']);
    Route::apiResource('operators', \App\Http\Controllers\Geo\OperatorController::class)->only(['index', 'show']);
});


// ==========================================
// 🔒 USER (Requiert un Token Passport)
// ==========================================
Route::middleware('auth:api')->group(function () {
    
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // --- PROFILE ---
    Route::prefix('profile')->group(function () {
        Route::get('/', [\App\Http\Controllers\Profile\ProfileController::class, 'show']);
        Route::post('/handle-check', \App\Http\Controllers\Profile\HandleCheckController::class);
        Route::put('/identity', [\App\Http\Controllers\Profile\ProfileController::class, 'updateIdentity']);
        Route::put('/birthdate', [\App\Http\Controllers\Profile\ProfileController::class, 'updateBirthdate']);
        Route::put('/gender', [\App\Http\Controllers\Profile\ProfileController::class, 'updateGender']);
        Route::put('/location', [\App\Http\Controllers\Profile\ProfileController::class, 'updateLocation']);
        Route::put('/interests', [\App\Http\Controllers\Profile\ProfileController::class, 'updateInterests']);
        Route::put('/avatar', \App\Http\Controllers\Profile\AvatarController::class);
    });
    
    // --- OTP ---
    Route::prefix('otp')->group(function () {
        Route::post('/request', [\App\Http\Controllers\Otp\OtpController::class, 'requestOtp']);
        Route::post('/verify', [\App\Http\Controllers\Otp\OtpController::class, 'verifyOtp']);
        Route::post('/resend', [\App\Http\Controllers\Otp\OtpController::class, 'resendOtp']);
    });

    // --- ONBOARDING ---
    Route::post('/onboarding/complete', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'completeStep']);

    // --- HOUSING ---
    Route::prefix('housing/search')->group(function () {
        Route::get('/', [\App\Http\Controllers\Housing\HousingSearchController::class, 'show']);
        Route::put('/', [\App\Http\Controllers\Housing\HousingSearchController::class, 'update']);
    });

    // --- SETTINGS ---
    Route::prefix('settings')->group(function () {
        Route::post('/email/request', [\App\Http\Controllers\Profile\EmailController::class, 'requestChange']);
        Route::post('/email/verify', [\App\Http\Controllers\Profile\EmailController::class, 'verifyChange']);
        Route::post('/email/resend', [\App\Http\Controllers\Profile\EmailController::class, 'resendCode']);
        Route::post('/email/cancel', [\App\Http\Controllers\Profile\EmailController::class, 'cancelChange']);
        
        Route::put('/password', [\App\Http\Controllers\Profile\PasswordController::class, 'update']);

        Route::post('/secure', [\App\Http\Controllers\Profile\SecurityController::class, 'secureAccount']);
        Route::get('/sessions', [\App\Http\Controllers\Profile\SecurityController::class, 'getSessions']);
        Route::post('/sessions/{id}/acknowledge', [\App\Http\Controllers\Profile\SecurityController::class, 'acknowledgeSession']);

        Route::post('/account/pause', [\App\Http\Controllers\Profile\AccountController::class, 'pause']);
        Route::delete('/account', [\App\Http\Controllers\Profile\AccountController::class, 'delete']);
    });

    // ==========================================
    // 🛡️ ADMIN (Requiert un Token Passport + Rôle Admin)
    // ==========================================
    Route::middleware('admin')->group(function () {
        
        // --- ROLES & USERS ---
        Route::apiResource('roles', \App\Http\Controllers\Admin\RoleController::class);
        Route::get('roles/{role}/users', [\App\Http\Controllers\Admin\RoleController::class, 'users']);
        Route::get('users/{user}/role', [\App\Http\Controllers\Admin\RoleController::class, 'getUserRole']);
        Route::patch('users/{user}/role', [\App\Http\Controllers\Admin\RoleController::class, 'updateUserRole']);
        
        // --- GEO (CRUD complet pour Admin) ---
        Route::prefix('geo')->group(function () {
            Route::apiResource('countries', \App\Http\Controllers\Geo\CountryController::class)->except(['index', 'show']);
            Route::apiResource('currencies', \App\Http\Controllers\Geo\CurrencyController::class)->except(['index', 'show']);
            Route::apiResource('cities', \App\Http\Controllers\Geo\CityController::class)->except(['index', 'show']);
            Route::apiResource('neighbourhoods', \App\Http\Controllers\Geo\NeighbourhoodController::class)->except(['index', 'show']);
            Route::apiResource('operators', \App\Http\Controllers\Geo\OperatorController::class)->except(['index', 'show']);
        });

    });
});
