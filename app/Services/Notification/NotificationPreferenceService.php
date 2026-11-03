<?php

namespace App\Services\Notification;

use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Models\User;

class NotificationPreferenceService
{
    /**
     * Récupère les préférences de notification d'un utilisateur fusionnées avec les valeurs par défaut.
     *
     * @return array<string, bool>
     */
    public function getSettings(User $user): array
    {
        $saved = $user->privacy_settings['notifications'] ?? [];

        return array_merge(NotificationSettingsController::DEFAULT_SETTINGS, $saved);
    }

    /**
     * Détermine si la notification relève de la Sécurité ou des Paiements (toujours exemptée).
     * Ces notifications sont TOUJOURS envoyées sur tous les canaux disponibles quel que soit le réglage.
     */
    public function isExempt(string|object $typeOrNotification): bool
    {
        $type = strtolower($this->extractType($typeOrNotification));
        $className = is_object($typeOrNotification) ? strtolower(class_basename($typeOrNotification)) : '';

        // 1. Sécurité : code appareil, appareil inconnu, 2FA, OTP, mot de passe, alertes sécurité
        $securityKeywords = [
            'deviceverification',
            'unknowndevice',
            'security',
            'auth',
            '2fa',
            'otp',
            'password',
            'login',
            'device_verification',
            'unknown_device',
        ];

        foreach ($securityKeywords as $keyword) {
            if (str_contains($type, $keyword) || str_contains($className, $keyword)) {
                return true;
            }
        }

        // 2. Paiements : rappels d'échec de paiement, renouvellement d'abonnement, factures, transactions
        $paymentKeywords = [
            'payment',
            'renewal',
            'receipt',
            'subscription_payment',
            'payout',
            'billing',
            'invoice',
        ];

        foreach ($paymentKeywords as $keyword) {
            if (str_contains($type, $keyword) || str_contains($className, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Résout le sujet (topic) selon les préférences de l'utilisateur :
     * likes, comments, direct_messages, new_followers, mentions, stories, listings, visits, marketing.
     */
    public function resolveTopic(string|object $typeOrNotification): ?string
    {
        $type = strtolower($this->extractType($typeOrNotification));
        $className = is_object($typeOrNotification) ? strtolower(class_basename($typeOrNotification)) : '';

        // Visites (visites immobilières) - prioritaire sur listing
        if (
            str_contains($type, 'visit') ||
            str_contains($className, 'visit')
        ) {
            return 'visits';
        }

        // Likes / J'aime
        if ($type === 'like' || str_contains($className, 'like') || $type === 'post_like' || $type === 'reel_like') {
            return 'likes';
        }

        // Commentaires & réponses
        if (
            in_array($type, ['comment', 'reply', 'comment_like', 'pin_comment'], true) ||
            (str_contains($type, 'comment') && ! str_contains($type, 'story')) ||
            str_contains($className, 'comment')
        ) {
            return 'comments';
        }

        // Messages directs
        if (
            in_array($type, ['new_message', 'new_story_reply', 'added_to_group', 'direct_message', 'message', 'chat'], true) ||
            str_contains($type, 'message') ||
            str_contains($className, 'message') ||
            str_contains($className, 'storyreply') ||
            str_contains($className, 'group')
        ) {
            return 'direct_messages';
        }

        // Nouveaux abonnés
        if (
            in_array($type, ['follow', 'follow_request', 'new_follower', 'follower', 'accepted_follow_request'], true) ||
            str_contains($type, 'follow') ||
            str_contains($className, 'follow')
        ) {
            return 'new_followers';
        }

        // Mentions (@handle)
        if ($type === 'mention' || str_contains($type, 'mention') || str_contains($className, 'mention')) {
            return 'mentions';
        }

        // Stories
        if (
            in_array($type, ['story_question_answer', 'countdown_remind', 'story_question', 'story_reaction'], true) ||
            str_starts_with($type, 'story_') ||
            str_contains($className, 'story')
        ) {
            return 'stories';
        }

        // Annonces immobilières
        if (
            str_contains($type, 'listing') ||
            str_contains($className, 'listing')
        ) {
            return 'listings';
        }

        // Marketing & Promotions
        if (
            in_array($type, ['marketing', 'promo', 'promotion', 'campaign_activated', 'campaign_completed', 'campaign_suspended'], true) ||
            str_starts_with($type, 'campaign_') ||
            str_contains($className, 'campaign') ||
            str_contains($className, 'boost')
        ) {
            return 'marketing';
        }

        return null;
    }

    /**
     * Détermine si l'envoi push est autorisé pour un utilisateur et une notification donnée.
     */
    public function isPushAllowed(User $user, string|object $typeOrNotification): bool
    {
        // Sécurité et paiements : TOUJOURS envoyés
        if ($this->isExempt($typeOrNotification)) {
            return true;
        }

        $settings = $this->getSettings($user);

        // Si le canal push global est désactivé
        if (! ($settings['push_enabled'] ?? true)) {
            return false;
        }

        $topic = $this->resolveTopic($typeOrNotification);
        if ($topic !== null) {
            return (bool) ($settings[$topic] ?? ($topic === 'marketing' ? false : true));
        }

        return true;
    }

    /**
     * Détermine si l'envoi par e-mail est autorisé pour un utilisateur et une notification donnée.
     */
    public function isEmailAllowed(User $user, string|object $typeOrNotification): bool
    {
        // Sécurité et paiements : TOUJOURS envoyés
        if ($this->isExempt($typeOrNotification)) {
            return true;
        }

        $settings = $this->getSettings($user);

        // Si le canal email global est désactivé
        if (! ($settings['email_enabled'] ?? true)) {
            return false;
        }

        $topic = $this->resolveTopic($typeOrNotification);
        if ($topic !== null) {
            return (bool) ($settings[$topic] ?? ($topic === 'marketing' ? false : true));
        }

        return true;
    }

    /**
     * Détermine si l'envoi par SMS est autorisé pour un utilisateur et une notification donnée.
     */
    public function isSmsAllowed(User $user, string|object $typeOrNotification): bool
    {
        // Sécurité et paiements : TOUJOURS envoyés
        if ($this->isExempt($typeOrNotification)) {
            return true;
        }

        $settings = $this->getSettings($user);

        // Si le canal SMS global est désactivé
        if (! ($settings['sms_enabled'] ?? false)) {
            return false;
        }

        $topic = $this->resolveTopic($typeOrNotification);
        if ($topic !== null) {
            return (bool) ($settings[$topic] ?? ($topic === 'marketing' ? false : true));
        }

        return true;
    }

    /**
     * Résout la liste des canaux de notification (database, mail...) pour un notifiable.
     * Le canal 'database' est TOUJOURS inclus pour que la notification reste visible dans la cloche in-app.
     *
     * @return array<int, string>
     */
    public function channelsFor(object $notifiable, object $notification): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User && method_exists($notification, 'toMail')) {
            if ($this->isEmailAllowed($notifiable, $notification)) {
                $channels[] = 'mail';
            }
        }

        return $channels;
    }

    /**
     * Extrait le type ou nom identifiant.
     */
    protected function extractType(string|object $typeOrNotification): string
    {
        if (is_string($typeOrNotification)) {
            return $typeOrNotification;
        }

        if (isset($typeOrNotification->type) && is_string($typeOrNotification->type)) {
            return $typeOrNotification->type;
        }

        return class_basename($typeOrNotification);
    }
}
