<?php
namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function pause(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['kind' => 'bad-password'], 403);
        }

        $user->update(['status' => 'paused']);
        $user->tokens()->delete(); // Sign out entirely

        return response()->json(['success' => true]);
    }

    public function delete(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['kind' => 'bad-password'], 403);
        }

        // Soft deletes the user
        $user->delete();
        $user->tokens()->delete();

        return response()->json(['success' => true]);
    }
}
