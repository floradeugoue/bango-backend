<?php

namespace App\Http\Controllers\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Support\AdminTemplateRequest;
use App\Models\Support\SupportResponseTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSupportTemplateController extends Controller
{
    /**
     * 4.10. Liste des modèles de réponse pour le support.
     *
     * GET /api/admin/support/templates
     */
    public function index(Request $request): JsonResponse
    {
        $query = SupportResponseTemplate::with('creator:id,name,email')
            ->orderBy('category')
            ->orderBy('title');

        if ($request->has('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->has('locale')) {
            $query->where('locale', $request->query('locale'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $templates = $query->get();

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    /**
     * 4.10 bis. Création d'un modèle de réponse type.
     *
     * POST /api/admin/support/templates
     */
    public function store(AdminTemplateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['created_by'] = $request->user()->id;

        $template = SupportResponseTemplate::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Modèle de réponse créé avec succès.',
            'data' => $template->load('creator:id,name,email'),
        ], 201);
    }

    /**
     * 4.10 ter. Modification d'un modèle de réponse.
     *
     * PUT /api/admin/support/templates/{id}
     */
    public function update(AdminTemplateRequest $request, int|string $id): JsonResponse
    {
        $template = SupportResponseTemplate::findOrFail($id);
        $template->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Modèle de réponse mis à jour avec succès.',
            'data' => $template->fresh('creator:id,name,email'),
        ]);
    }
}
