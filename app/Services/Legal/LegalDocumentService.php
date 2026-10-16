<?php

namespace App\Services\Legal;

use App\Models\Legal\LegalDocument;
use App\Models\Legal\TermsAcceptance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LegalDocumentService
{
    public const DEFAULT_LOCALE = 'fr';

    public const TERMS_SLUG = 'terms-of-service';

    /**
     * Récupère la version active d'un document légal publié avec gestion du fallback de langue.
     */
    public function getPublishedDocument(string $slug, ?string $locale = null, ?string $version = null): ?LegalDocument
    {
        $normalizedSlug = strtolower(trim($slug));
        $targetLocale = $locale ? strtolower(trim($locale)) : self::DEFAULT_LOCALE;

        $query = LegalDocument::query()
            ->published()
            ->where('slug', $normalizedSlug);

        if ($version !== null && $version !== '') {
            $query->where('version', $version);
        }

        // 1. Recherche dans la langue demandée
        $document = (clone $query)
            ->where('locale', $targetLocale)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        if ($document !== null) {
            return $document;
        }

        // 2. Fallback vers la langue par défaut ('fr') si différente
        if ($targetLocale !== self::DEFAULT_LOCALE) {
            return (clone $query)
                ->where('locale', self::DEFAULT_LOCALE)
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    /**
     * Détermine la version obligatoire actuelle des CGU côté serveur.
     */
    public function getMandatoryTermsVersion(): string
    {
        $doc = LegalDocument::query()
            ->published()
            ->where('slug', self::TERMS_SLUG)
            ->where('is_mandatory', true)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        if ($doc !== null) {
            return $doc->version;
        }

        // Fallback sur la dernière version publiée des CGU
        $latest = LegalDocument::query()
            ->published()
            ->where('slug', self::TERMS_SLUG)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        return $latest?->version ?? '1.0.0';
    }

    /**
     * Enregistre l'acceptation des CGU par un utilisateur de manière atomique et idempotente.
     */
    public function recordAcceptance(
        User $user,
        string $documentSlug = self::TERMS_SLUG,
        ?string $version = null,
        ?string $locale = self::DEFAULT_LOCALE,
        ?string $ip = null,
        ?string $userAgent = null
    ): TermsAcceptance {
        $effectiveVersion = $version ?: $this->getMandatoryTermsVersion();
        $targetLocale = $locale ?: self::DEFAULT_LOCALE;

        return DB::transaction(function () use ($user, $documentSlug, $effectiveVersion, $targetLocale, $ip, $userAgent) {
            // Recherche du document légal correspondant s'il existe
            $document = LegalDocument::query()
                ->where('slug', $documentSlug)
                ->where('version', $effectiveVersion)
                ->where('locale', $targetLocale)
                ->first();

            // Vérification de l'idempotence : acceptation déjà enregistrée pour cette version
            $existing = TermsAcceptance::query()
                ->where('user_id', $user->id)
                ->where('document_slug', $documentSlug)
                ->where('version', $effectiveVersion)
                ->first();

            if ($existing !== null) {
                // Met à jour les horodatages de session si nécessaire
                $existing->update([
                    'ip_address' => $ip ?? $existing->ip_address,
                    'user_agent' => $userAgent ?? $existing->user_agent,
                ]);

                // S'assurer que le modèle User est synchronisé
                if ($user->accepted_terms_version !== $effectiveVersion) {
                    $user->update([
                        'terms_accepted_at' => $existing->accepted_at,
                        'accepted_terms_version' => $effectiveVersion,
                    ]);
                }

                return $existing;
            }

            $acceptance = TermsAcceptance::create([
                'user_id' => $user->id,
                'legal_document_id' => $document?->id,
                'version' => $effectiveVersion,
                'document_slug' => $documentSlug,
                'locale' => $targetLocale,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'accepted_at' => now(),
            ]);

            $user->update([
                'terms_accepted_at' => $acceptance->accepted_at,
                'accepted_terms_version' => $effectiveVersion,
            ]);

            return $acceptance;
        });
    }

    /**
     * Vérifie si l'utilisateur a accepté la version obligatoire courante des CGU.
     */
    public function hasUserAcceptedMandatoryTerms(User $user): bool
    {
        $mandatoryVersion = $this->getMandatoryTermsVersion();

        if ($user->accepted_terms_version === $mandatoryVersion && $user->terms_accepted_at !== null) {
            return true;
        }

        return TermsAcceptance::query()
            ->where('user_id', $user->id)
            ->where('document_slug', self::TERMS_SLUG)
            ->where('version', $mandatoryVersion)
            ->exists();
    }
}
