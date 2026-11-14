<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProfileDeviceController;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password, and sign out the user's other devices
     * and the mobile app: only this device stays signed in.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('updatePassword', $request->user());

        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);
        ProfileDeviceController::signOutOtherDevices($request);

        $request->user()->notify(new ActionCompleted('password-updated'));

        return back()->with('status', 'password-updated');
    }
}
