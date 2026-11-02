<?php

namespace App\Http\Middleware;

use App\Enums\Page\PageRole;
use App\Models\Page\Page;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPageContext
{
    /**
     * Header HTTP envoyé par l'application lorsqu'un contexte Page est actif.
     */
    public const HEADER = 'X-Bango-Page';

    /**
     * Intercepte l'en-tête X-Bango-Page, vérifie l'appartenance et le rôle actif
     * de l'utilisateur sur la Page, et enregistre le contexte dans la requête.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $pageId = $request->header(self::HEADER);

        if (! $pageId) {
            return $next($request);
        }

        $user = $request->user();

        // Si la requête n'est pas authentifiée mais porte l'en-tête
        if (! $user) {
            abort(401, 'Authentification requise pour agir au nom d\'une page.');
        }

        /** @var Page|null $page */
        $page = Page::find($pageId);

        if (! $page) {
            abort(404, 'Page introuvable pour le contexte spécifié.');
        }

        if ($page->isSuspended()) {
            abort(403, 'Cette page professionnelle est suspendue.');
        }

        // Vérification de l'appartenance : Propriétaire ou membre actif accepté
        $memberRole = $page->memberRole($user);

        if (! $memberRole && ! $user->isAdmin()) {
            abort(403, 'Vous n\'êtes pas membre actif de cette page.');
        }

        $role = $memberRole ?? PageRole::Owner;

        // Attachement du contexte à la requête courante
        $request->attributes->set('acting_page', $page);
        $request->attributes->set('active_page', $page);
        $request->attributes->set('active_page_role', $role);

        return $next($request);
    }
}
