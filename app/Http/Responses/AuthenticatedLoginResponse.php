<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatedLoginResponse implements LoginResponse
{
    /**
     * Create an HTTP response after a successful login.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $user = $request->user();

        if ($user instanceof User && $user->is_platform_owner) {
            return redirect()->route('owner.dashboard');
        }

        return redirect()->intended(config('fortify.home'));
    }
}
