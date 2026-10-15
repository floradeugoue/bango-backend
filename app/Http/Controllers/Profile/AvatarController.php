<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvatarController extends Controller
{
    public function __invoke(Request $request, MediaStorageService $storageService): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'], // 5MB max
        ]);

        $user = $request->user();

        if ($user->avatar_url) {
            $storageService->delete($user->avatar_url, 'public');
        }

        $directory = $storageService->pathFor('avatars');
        $path = $request->file('avatar')->store($directory, 'public');

        $url = $storageService->getUrl($path, 'public');

        $user->update([
            'avatar_url' => $url,
        ]);

        return response()->json([
            'avatarUrl' => $url,
        ]);
    }
}
