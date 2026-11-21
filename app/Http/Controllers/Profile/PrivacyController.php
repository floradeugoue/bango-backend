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
        'ads_decided_at' => null,
        'showNeighbourhood' => true,
        'showFollowing' => true,
        'birthday_visibility' => 'none',
        'birthdayVisibility' => 'none',
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
            'birthday_visibility' => $user->birthday_visibility ?? 'none',
            'birthdayVisibility' => $user->birthday_visibility ?? 'none',
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
            'showNeighbourhood' => ['sometimes', 'boolean'],
            'showFollowing' => ['sometimes', 'boolean'],
            'birthday_visibility' => ['sometimes', 'in:none,mutuals'],
            'birthdayVisibility' => ['sometimes', 'in:none,mutuals'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $wasPrivate = (bool) $user->is_private;

        $currentSettings = $user->privacy_settings ?? self::DEFAULT_PRIVACY;
        $updatedSettings = array_merge($currentSettings, $validated);

        if ($request->has('ads_personalization')) {
            $updatedSettings['ads_decided_at'] = now()->toIso8601String();
        }

        if ($request->has('privateAccount')) {
            $user->is_private = $request->boolean('privateAccount');
            $updatedSettings['privateAccount'] = $user->is_private;
        }

        $visibility = $request->input('birthday_visibility') ?? $request->input('birthdayVisibility');
        if ($visibility !== null) {
            $user->birthday_visibility = $visibility;
            $updatedSettings['birthday_visibility'] = $visibility;
            $updatedSettings['birthdayVisibility'] = $visibility;
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
