<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateUserPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class UserPasswordController extends Controller
{
    public function update(UpdateUserPasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => __('messages.auth.unauthenticated')], 401);
        }

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            return response()->json(['message' => __('messages.auth.password_mismatch')], 422);
        }

        $user->forceFill([
            'password' => Hash::make($request->validated('new_password')),
        ])->save();

        return response()->json([
            'message' => __('messages.user.password_updated'),
        ]);
    }
}
