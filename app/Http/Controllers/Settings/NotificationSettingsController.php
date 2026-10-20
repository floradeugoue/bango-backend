<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    public const DEFAULT_SETTINGS = [
        'push_enabled' => true,
        'email_enabled' => true,
        'sms_enabled' => false,
        'likes' => true,
        'comments' => true,
        'new_followers' => true,
        'direct_messages' => true,
        'mentions' => true,
        'stories' => true,
        'listings' => true,
        'visits' => true,
        'marketing' => false,
    ];

    /**
     * Get notification settings for the authenticated user (ST-17).
     *
     * GET /settings/notifications
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $saved = $user->privacy_settings['notifications'] ?? [];
        $settings = array_merge(self::DEFAULT_SETTINGS, $saved);

        return response()->json(array_merge($settings, [
            'success' => true,
            'data' => $settings,
        ]));
    }

    /**
     * Update notification settings for the authenticated user (ST-17).
     *
     * PUT /settings/notifications
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_enabled' => ['sometimes', 'boolean'],
            'email_enabled' => ['sometimes', 'boolean'],
            'sms_enabled' => ['sometimes', 'boolean'],
            'likes' => ['sometimes', 'boolean'],
            'comments' => ['sometimes', 'boolean'],
            'new_followers' => ['sometimes', 'boolean'],
            'direct_messages' => ['sometimes', 'boolean'],
            'mentions' => ['sometimes', 'boolean'],
            'stories' => ['sometimes', 'boolean'],
            'listings' => ['sometimes', 'boolean'],
            'visits' => ['sometimes', 'boolean'],
            'marketing' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $privacySettings = $user->privacy_settings ?? [];
        $current = $privacySettings['notifications'] ?? self::DEFAULT_SETTINGS;
        $updated = array_merge($current, $validated);

        $privacySettings['notifications'] = $updated;
        $user->privacy_settings = $privacySettings;
        $user->save();

        return response()->json(array_merge($updated, [
            'success' => true,
            'data' => $updated,
            'message' => 'Préférences de notification mises à jour.',
        ]));
    }
}
