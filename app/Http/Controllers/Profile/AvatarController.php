<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'], // 5MB max
        ]);

        $user = $request->user();

        if ($user->avatar_url) {
            $oldPath = str_replace(Storage::url(''), '', $user->avatar_url);
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        
        $url = Storage::url($path);
        
        $user->update([
            'avatar_url' => $url
        ]);

        return response()->json([
            'avatarUrl' => $url
        ]);
    }
}
