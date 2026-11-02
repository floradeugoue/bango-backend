<?php

namespace App\Http\Controllers\Translation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Translation\TranslateRequest;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Services\Translation\TranslationService;
use Illuminate\Http\JsonResponse;

class TranslationController extends Controller
{
    public function __construct(
        protected TranslationService $translationService
    ) {}

    /**
     * Translate content of post, reel, post comment, or reel comment.
     * POST /api/translate
     */
    public function translate(TranslateRequest $request): JsonResponse
    {
        $type = (string) $request->validated('type');
        $id = $request->validated('id');
        $target = (string) $request->validated('target');

        $text = match ($type) {
            'post' => (string) (Post::findOrFail($id)->body ?? ''),
            'reel' => (string) (Reel::findOrFail($id)->caption ?? ''),
            'comment' => (string) (PostComment::findOrFail($id)->body ?? ''),
            'reel_comment' => (string) (ReelComment::findOrFail($id)->body ?? ''),
        };

        $result = $this->translationService->translate($text, $target);

        return response()->json([
            'success' => true,
            'text' => $result['text'],
            'source_language' => $result['source_language'],
        ]);
    }
}
