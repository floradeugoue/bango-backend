<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\Profile\FollowRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrivacyController extends Controller
{
    public const DEFAULT_PRIVACY = [
        'privateAccount' => false,
        'seePosts' => 'everyone',
        'commentPosts' => 'followers',
        'messageMe' => 'everyone',
        'tagMe' => 'followers',
        'onlineStatus' => true,
        'readReceipts' => true,
        'ads_personalization' => true,
    ];

    /**
     * Get privacy settings for the authenticated user.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $saved = $user->privacy_settings ?? [];
        $settings = array_merge(self::DEFAULT_PRIVACY, $saved, [
            'privateAccount' => (bool) $user->is_private,
        ]);

        return response()->json($settings);
    }

    /**
     * Update privacy settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'privateAccount' => ['sometimes', 'boolean'],
            'seePosts' => ['sometimes', 'in:everyone,followers,nobody'],
            'commentPosts' => ['sometimes', 'in:everyone,followers,nobody'],
            'messageMe' => ['sometimes', 'in:everyone,followers,nobody'],
            'tagMe' => ['sometimes', 'in:everyone,followers,nobody'],
            'onlineStatus' => ['sometimes', 'boolean'],
            'readReceipts' => ['sometimes', 'boolean'],
            'ads_personalization' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $wasPrivate = (bool) $user->is_private;

        $currentSettings = $user->privacy_settings ?? self::DEFAULT_PRIVACY;
        $updatedSettings = array_merge($currentSettings, $validated);

        if ($request->has('privateAccount')) {
            $user->is_private = $request->boolean('privateAccount');
            $updatedSettings['privateAccount'] = $user->is_private;
        }

        $user->privacy_settings = $updatedSettings;
        $user->save();

        // If user transitioned from private to public, accept all pending follow requests
        if ($wasPrivate && ! $user->is_private) {
            $pending = FollowRequest::where('to_id', $user->id)
                ->where('status', 'pending')
                ->get();

            foreach ($pending as $req) {
                /** @var User|null $sender */
                $sender = User::find($req->from_id);
                if ($sender) {
                    $sender->following()->syncWithoutDetaching([$user->id]);
                }
                $req->delete();
            }
        }

        return response()->json($updatedSettings);
    }
}
