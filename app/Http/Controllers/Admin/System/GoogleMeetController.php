<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Services\Google\GoogleMeet;
use App\Services\Google\GoogleMeetException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * System > Google Meet: save the Google Cloud client, connect the academy's Google account once,
 * then live classes can get a Google Meet link with one click ("Generate" in the class form).
 */
class GoogleMeetController extends Controller
{
    public function __construct(private GoogleMeet $meet) {}

    public function index(): View
    {
        return view('admin.system.google-meet', [
            'client' => $this->meet->client(),
            'account' => $this->meet->account()?->load('connectedBy:id,name'),
            'redirectUri' => $this->meet->redirectUri(),
        ]);
    }

    /** The Google Cloud OAuth client (Client ID + secret). An empty secret keeps the saved one. */
    public function saveClient(Request $request): RedirectResponse
    {
        $hasSecret = $this->meet->configured();
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:255', 'regex:/\.apps\.googleusercontent\.com$/'],
            'client_secret' => [$hasSecret ? 'nullable' : 'required', 'string', 'max:255'],
        ], [
            'client_id.regex' => 'A Google Client ID ends with .apps.googleusercontent.com.',
        ]);

        $disconnected = $this->meet->saveClient(trim($data['client_id']), trim((string) ($data['client_secret'] ?? '')) ?: null, $request->user());

        return redirect()->route('admin.google-meet.index')->with('success', $disconnected
            ? 'Google client saved. It is a new Client ID, so the Google account was disconnected: connect it again.'
            : 'Google client saved.');
    }

    /** To Google's sign-in page. */
    public function connect(Request $request): RedirectResponse
    {
        if (! $this->meet->configured()) {
            return back()->with('error', 'Save the Google Client ID and secret first.');
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($this->meet->authUrl($state));
    }

    /** Google sends the admin back here after the sign-in. */
    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('google_oauth_state');
        $back = redirect()->route('admin.google-meet.index');

        if ($request->filled('error')) {
            return $back->with('error', $request->query('error') === 'access_denied'
                ? 'Google sign-in was cancelled.'
                : 'Google sign-in failed: ' . $request->query('error') . '.');
        }
        if (! $expected || ! hash_equals($expected, (string) $request->query('state')) || ! $request->filled('code')) {
            return $back->with('error', 'The Google sign-in link was not valid or has expired. Please try again.');
        }

        try {
            $account = $this->meet->connect((string) $request->query('code'), $request->user());
        } catch (GoogleMeetException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('success', "Google account {$account->email} connected. Live classes can now get a Google Meet link with one click.");
    }

    public function disconnect(): RedirectResponse
    {
        $this->meet->disconnect();

        return redirect()->route('admin.google-meet.index')->with('success', 'Google account disconnected.');
    }

    /** POST from the class form ("Generate"): a new Google Meet link as JSON. */
    public function link(): JsonResponse
    {
        try {
            return response()->json(['meeting_url' => $this->meet->createMeetingLink()]);
        } catch (GoogleMeetException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
