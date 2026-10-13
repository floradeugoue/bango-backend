<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Resources\FollowUserResource;
use App\Models\FollowRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlockController extends Controller
{
    /**
     * List all users blocked by the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return FollowUserResource::collection($user->blockedUsers()->paginate(20));
    }

    /**
     * Block a user (cuts all follows and requests both ways).
     */
    public function block(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var User $target */
        $target = User::findOrFail($id);

        if ($user->id === $target->id) {
            return response()->json(['message' => 'Vous ne pouvez pas vous bloquer vous-même.'], 422);
        }

        // Sever social connections both ways
        $user->following()->detach($target->id);
        $target->following()->detach($user->id);

        // Delete all pending requests both ways
        FollowRequest::where(function ($q) use ($user, $target) {
            $q->where('from_id', $user->id)->where('to_id', $target->id);
        })->orWhere(function ($q) use ($user, $target) {
            $q->where('from_id', $target->id)->where('to_id', $user->id);
        })->delete();

        // Attach block
        $user->blockedUsers()->syncWithoutDetaching([$target->id]);

        return response()->json([
            'success' => true,
            'blocked' => true,
        ]);
    }

    /**
     * Unblock a user.
     */
    public function unblock(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->blockedUsers()->detach($id);

        return response()->json([
            'success' => true,
            'blocked' => false,
        ]);
    }
}
