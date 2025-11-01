<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function toResponse($request)
    {
        // If a ?redirect query parameter exists, use it
        if ($request->has('redirect')) {
            return redirect($request->input('redirect'));
        }

        // Otherwise, redirect to intended page or dashboard by default
        return redirect()->intended(route('dashboard'));
    }
}