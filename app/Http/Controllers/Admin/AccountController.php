<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The signed-in admin's own account: profile, devices (active sessions + login history),
 * and password. Reached from the avatar menu in the topbar, not the sidebar.
 */
class AccountController extends Controller
{
    /* ------------------------------------------------------------- profile */

    public function profile(Request $request): View
    {
        return view('admin.account.profile', ['admin' => $request->user()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $admin = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('admins', 'email')->ignore($admin->id)->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'max:20', Rule::unique('admins', 'phone')->ignore($admin->id)->whereNull('deleted_at')],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        $admin->fill(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone']]);

        if ($request->hasFile('avatar')) {
            $this->deleteAvatar($admin->avatar);
            $admin->avatar = $request->file('avatar')->store('avatars', 'public');
        } elseif ($request->boolean('remove_avatar')) {
            $this->deleteAvatar($admin->avatar);
            $admin->avatar = null;
        }

        $admin->save();

        return back()->with('success', 'Profile updated.');
    }

    /** Remove a stored avatar file (skips external URLs and empty values). */
    private function deleteAvatar(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }

    /* ------------------------------------------------------------- devices */

    public function devices(Request $request): View
    {
        $admin = $request->user();

        return view('admin.account.devices', [
            'current' => (string) $request->cookie('sf_device'),
            'devices' => $admin->devices()->active()->orderByDesc('last_used_at')->get(),
            'history' => $admin->loginInfos()->latest('created_at')->limit(50)->get(),
        ]);
    }

    /** Sign out another device. The current password is required (the current device logs out normally). */
    public function revokeDevice(Request $request): JsonResponse
    {
        $request->validate([
            'device' => ['required', 'integer'],
            'current_password' => ['required', 'current_password:web'],
        ]);

        $device = $request->user()->devices()->active()->whereKey($request->integer('device'))->first();
        abort_if(! $device, 404, 'Device not found.');

        $device->revoke();

        return response()->json(['ok' => true]);
    }

    /* ------------------------------------------------------------ password */

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update(['password' => $request->input('password')]);

        return response()->json(['ok' => true]);
    }
}
