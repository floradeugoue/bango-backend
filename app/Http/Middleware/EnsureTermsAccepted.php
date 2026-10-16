<?php

namespace App\Http\Middleware;

use App\Services\Legal\LegalDocumentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTermsAccepted
{
    /**
     * Routes ou motifs exemptés du blocage des CGU.
     *
     * @var array<int, string>
     */
    protected array $exemptRoutes = [
        'api/auth/*',
        'api/legal/*',
        'api/otp/*',
        'api/geo/*',
        'up',
    ];

    public function __construct(
        protected LegalDocumentService $legalDocumentService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Si l'utilisateur n'est pas connecté, le middleware de conformité ne s'applique pas
        if ($user === null) {
            return $next($request);
        }

        // Vérification des exemptions
        foreach ($this->exemptRoutes as $exemptPattern) {
            if ($request->is($exemptPattern)) {
                return $next($request);
            }
        }

        // Vérification de l'acceptation de la version obligatoire des CGU
        if (! $this->legalDocumentService->hasUserAcceptedMandatoryTerms($user)) {
            $mandatoryVersion = $this->legalDocumentService->getMandatoryTermsVersion();

            return response()->json([
                'message' => 'Vous devez accepter la version obligatoire des conditions générales d\'utilisation pour continuer.',
                'error' => 'TERMS_ACCEPTANCE_REQUIRED',
                'required_version' => $mandatoryVersion,
                'accepted_version' => $user->accepted_terms_version,
                'document_slug' => LegalDocumentService::TERMS_SLUG,
            ], 403);
        }

        return $next($request);
    }
}
