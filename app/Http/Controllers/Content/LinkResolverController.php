<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ResolveLinkRequest;
use App\Http\Resources\Content\ResolvedLinkResource;
use App\Services\Content\LinkResolverService;

class LinkResolverController extends Controller
{
    public function __construct(
        protected LinkResolverService $linkResolverService
    ) {}

    /**
     * Résout un lien universel ou court et renvoie sa cible et son état d'accessibilité.
     */
    public function resolve(ResolveLinkRequest $request): ResolvedLinkResource
    {
        $path = $request->validated('path');
        $currentUser = $request->user('api') ?? $request->user();

        $resolved = $this->linkResolverService->resolve($path, $currentUser);

        return new ResolvedLinkResource($resolved);
    }
}
