<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use App\Support\IdHash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    public function apiGet(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'designation' => $user->designation,
            'address' => $user->address,
            'profile_picture_url' => $user->profile_picture_url,
            'higher_education' => $user->higher_education,
            'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
            'phone_country_code' => $user->phone_country_code,
            'phone_number' => $user->phone_number,
            'completion' => $user->profileCompletion(),
        ]);
    }

    public function apiUpdate(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            'higher_education' => 'required|string|max:255',
            'date_of_birth' => 'nullable|date|before:today',
            'phone_country_code' => 'required|string|max:5',
            'phone_number' => 'required|string|max:20',
            'profile_picture' => ($user->profile_picture ? 'nullable' : 'required') . '|image|max:5120',
        ]);

        $user->fill($request->only(['name', 'address', 'higher_education', 'date_of_birth', 'phone_country_code', 'phone_number']));

        if ($request->hasFile('profile_picture')) {
            if ($user->profile_picture) {
                Storage::disk('local')->delete($user->profile_picture);
            }
            $path = $request->file('profile_picture')->store('profile-pictures/' . $user->id, 'local');
            $user->profile_picture = $path;
        }

        $user->save();

        return response()->json([
            'profile_picture_url' => $user->profile_picture_url,
            'completion' => $user->profileCompletion(),
        ]);
    }

    public function showPicture(Request $request, $token)
    {
        // Only allow JS fetch with custom header — blocks direct URL access and <img src> inspection
        if (!$request->header('X-PF-Token')) abort(403);

        // Verify signed URL
        $expires = (int) $request->query('e');
        $sig = (string) $request->query('s');
        if (!$expires || !$sig || !IdHash::verifySignature($token, $expires, $sig)) abort(403);

        $id = IdHash::decode($token);
        if ($id === null) abort(404);

        $user = \App\Models\User::withoutGlobalScopes()->find($id);
        if (!$user || !$user->profile_picture) abort(404);

        $viewer = auth()->user();
        $isAdmin = $viewer->hasRole('SuperAdmin');
        $sameTenant = $viewer->tenant_id && $viewer->tenant_id === $user->tenant_id;
        if (!$isAdmin && !$sameTenant) abort(403);

        $disk = Storage::disk('local');
        if (!$disk->exists($user->profile_picture)) abort(404);

        $etag = md5($user->profile_picture . filemtime($disk->path($user->profile_picture)));
        if ($request->header('If-None-Match') === '"' . $etag . '"') {
            return response('', 304);
        }

        return $disk->response($user->profile_picture, null, [
            'Cache-Control' => 'private, max-age=600',
            'ETag' => '"' . $etag . '"',
            'Content-Disposition' => 'inline',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function updateBasic(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'notification_email' => 'nullable|email',
        ]);

        $user->name = $request->name;

        if ($user->email !== $request->email) {
            $user->email = $request->email;
            $user->email_verified_at = null;
        }

        $user->notification_email = $request->notification_email;
        $user->save();

        return response()->json(['ok' => true]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

}
