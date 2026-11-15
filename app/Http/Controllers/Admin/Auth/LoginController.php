<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Actions\RegisterDeviceAction;
use App\Enums\MessageChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Models\Admin;
use App\Models\UserLoginInfo;
use App\Services\Auth\LoginChallengeService;
use App\Services\Messaging\MessageService;
use App\Support\Mask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Admin panel login (session based), same steps as the API login:
 *
 *   1. password        POST login
 *   2. send the code   POST login/send-code   (admin chooses email or SMS)
 *   3. verify the code POST login/verify      (device is saved, session starts)
 *
 * If the device limit is reached, the admin first picks an old device to sign out
 * (GET/POST login/devices). That device is removed only after the code is verified.
 *
 * The device is identified by a random id that THE SERVER puts in a cookie (sf_device).
 */
class LoginController extends Controller
{
    private const DEVICE_COOKIE = 'sf_device';

    // ------------------------------------------------------- step 1
    public function show(): View
    {
        return view('admin.auth.login');
    }

    public function store(LoginRequest $request, LoginChallengeService $challenges, RegisterDeviceAction $devices): RedirectResponse
    {
        $login = trim($request->input('login'));
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        /** @var Admin|null $admin */
        $admin = Admin::where($field, $login)->first();

        if (!$admin || !Hash::check($request->input('password'), $admin->password)) {
            $this->log($request, $admin, 'failed', 'wrong_password');

            return back()->withErrors(['login' => 'These credentials do not match our records.'])->onlyInput('login');
        }

        if (!$admin->isActive()) {
            $this->log($request, $admin, 'blocked', 'suspended');

            return back()->withErrors(['login' => 'Your account is not active.'])->onlyInput('login');
        }

        if ($admin->deviceLimit() < 1) {
            $this->log($request, $admin, 'blocked', 'device_limit');

            return back()->withErrors(['login' => 'Login is disabled: device policy is missing or zero.'])->onlyInput('login');
        }

        $device = $this->deviceFrom($request);

        // Known device: this browser already signed in to this admin before (active or logged out).
        // The password is enough - skip the OTP step. A new browser or a different admin gets no match.
        if ($admin->devices()->where('device_id', $device['device_id'])->exists()
            && $devices->hasSlot($admin, $device['device_id'])) {
            return $this->completeLogin($request, $admin, $device, $request->boolean('remember'), null, null, $devices);
        }

        $challengeId = $challenges->start('admin', $admin->id, $device, null, null, $admin->password);

        $request->session()->put('admin_login', [
            'challenge_id' => $challengeId,
            'remember' => $request->boolean('remember'),
            'revoke_device_id' => null,
        ]);

        return redirect()->route('admin.login.verify');
    }

    // ------------------------------------------------------- device limit
    public function devices(Request $request, LoginChallengeService $challenges, RegisterDeviceAction $devices)
    {
        [$challenge, $admin] = $this->current($request, $challenges);

        if (!$challenge) {
            return $this->expired($request);
        }

        if ($devices->hasSlot($admin, $challenge['device']['device_id'], $this->revokeId($request, $admin))) {
            return redirect()->route('admin.login.verify');
        }

        return view('admin.auth.devices', [
            'limit' => $admin->deviceLimit(),
            'devices' => $admin->devices()->active()->orderByDesc('last_used_at')->get(),
        ]);
    }

    public function chooseDevice(Request $request, LoginChallengeService $challenges): RedirectResponse
    {
        $request->validate(['device' => ['required', 'integer']]);

        [$challenge, $admin] = $this->current($request, $challenges);

        if (!$challenge) {
            return $this->expired($request);
        }

        $id = $admin->devices()->active()->whereKey($request->input('device'))->value('id');
        abort_if(!$id, 404, 'Device not found.');

        $request->session()->put('admin_login.revoke_device_id', $id);

        return redirect()->route('admin.login.verify');
    }

    // ------------------------------------------------------- step 2 (page + send)
    public function verifyPage(Request $request, LoginChallengeService $challenges, RegisterDeviceAction $devices)
    {
        [$challenge, $admin] = $this->current($request, $challenges);

        if (!$challenge) {
            return $this->expired($request);
        }

        if (!$devices->hasSlot($admin, $challenge['device']['device_id'], $this->revokeId($request, $admin))) {
            return redirect()->route('admin.login.devices');
        }

        $sent = $challenge['code_hash'] && !$request->boolean('change');

        return view('admin.auth.verify', [
            'options' => $challenges->options($admin->email, $admin->phone),
            'sent' => $sent,
            'sentTo' => $sent ? $this->maskFor($challenge['channel'], $admin) : null,
            'channel' => $challenge['channel'],
            'wait' => $challenges->secondsUntilResend($challenge),
            'revokeDevice' => $this->revokeId($request, $admin)
                ? $admin->devices()->find($this->revokeId($request, $admin))
                : null,
        ]);
    }

    public function sendCode(Request $request, LoginChallengeService $challenges, MessageService $messages): RedirectResponse
    {
        $request->validate(['channel' => ['required', 'in:email,sms']]);

        [$challenge, $admin] = $this->current($request, $challenges);

        if (!$challenge) {
            return $this->expired($request);
        }

        $wait = $challenges->secondsUntilResend($challenge);

        if ($wait > 0) {
            return back()->withErrors(['channel' => "Please wait {$wait} seconds before requesting a new code."]);
        }

        $channel = MessageChannel::from($request->input('channel'));
        $to = $channel === MessageChannel::Email ? $admin->email : $admin->phone;

        if (!$to) {
            return back()->withErrors(['channel' => 'This account has no phone number.']);
        }

        // At most 5 codes per email or phone per hour (same limit as the API)
        $limitKey = 'otp-to:' . $to;

        if (RateLimiter::tooManyAttempts($limitKey, 5)) {
            return back()->withErrors(['channel' => 'Too many codes were requested for this contact. Try again later.']);
        }

        $code = $challenges->makeCode();

        try {
            $messages->send(
                $channel,
                $to,
                'login-code',
                ['code' => $code, 'name' => $admin->name, 'minutes' => intdiv(LoginChallengeService::CODE_TTL, 60)],
                'Your ' . config('app.name') . ' verification code'
            );
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['channel' => 'Could not send the code. Please try again.']);
        }

        RateLimiter::hit($limitKey, 3600);
        $challenges->storeCode($request->session()->get('admin_login.challenge_id'), $challenge, $channel->value, $code);

        $response = redirect()->route('admin.login.verify')
            ->with('status', 'Code sent to ' . $this->maskFor($channel->value, $admin) . '.');

        if (app()->environment('local') && config('messaging.show_login_code')) {
            $response->with('debug_code', $code);
        }

        return $response;
    }

    // ------------------------------------------------------- step 3
    public function verify(Request $request, LoginChallengeService $challenges, RegisterDeviceAction $devices): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        [$challenge, $admin] = $this->current($request, $challenges);

        if (!$challenge) {
            return $this->expired($request);
        }

        $challengeId = $request->session()->get('admin_login.challenge_id');
        $device = $challenge['device'];
        $result = $challenges->verify($challengeId, $challenge, $request->input('code'));

        if ($result !== 'ok') {
            $this->log($request, $admin, 'failed', "code_{$result}", $device['device_id']);

            if ($result === 'locked') {
                $request->session()->forget('admin_login');

                return redirect()->route('admin.login')->withErrors(['login' => 'Too many wrong codes. Please sign in again.']);
            }

            return back()->withErrors([
                'code' => match ($result) {
                    'expired' => 'The code has expired. Request a new one.',
                    'no_code' => 'Request a code first.',
                    default => 'The code is not correct.',
                }
            ]);
        }

        // The code is right. Check the device limit once more (a slot may have been taken meanwhile).
        $revokeId = $this->revokeId($request, $admin);

        if (!$devices->hasSlot($admin, $device['device_id'], $revokeId)) {
            $this->log($request, $admin, 'blocked', 'device_limit', $device['device_id']);

            return redirect()->route('admin.login.devices');
        }

        $remember = (bool) $request->session()->get('admin_login.remember');
        $challenges->forget($challengeId);

        return $this->completeLogin($request, $admin, $device, $remember, $revokeId, $challenge['channel'], $devices);
    }

    /**
     * Final step shared by both paths (OTP verified, or a known device that skipped OTP):
     * save the device, sign in, set the device cookie, log the success.
     */
    private function completeLogin(Request $request, Admin $admin, array $device, bool $remember, ?int $revokeId, ?string $channel, RegisterDeviceAction $devices): RedirectResponse
    {
        DB::transaction(function () use ($admin, $channel, $revokeId, $device, $devices, $request) {
            // Sign out the old device the admin chose
            if ($revokeId) {
                $admin->devices()->active()->find($revokeId)?->revoke();
            }

            $devices->register($admin, $device, $request);

            $admin->last_login_at = Carbon::now();
            if (!$admin->verified_at && $channel) {
                $admin->verified_at = Carbon::now();
                $admin->verified_channel = $channel;
            }

            $admin->save();
        });

        Auth::guard('web')->login($admin, $remember);
        $request->session()->regenerate();
        $request->session()->forget('admin_login');
        $this->queueDeviceCookie($device['device_id'], $request);
        $this->log($request, $admin, 'success', null, $device['device_id']);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function cancel(Request $request, LoginChallengeService $challenges): RedirectResponse
    {
        if ($id = $request->session()->get('admin_login.challenge_id')) {
            $challenges->forget($id);
        }

        $request->session()->forget('admin_login');

        return redirect()->route('admin.login');
    }

    // ------------------------------------------------------- logout
    public function destroy(Request $request): RedirectResponse
    {
        // Free this device's slot
        $deviceId = $request->cookie(self::DEVICE_COOKIE);

        if ($deviceId && $admin = $request->user()) {
            $admin->devices()->active()->where('device_id', $deviceId)->first()?->revoke();
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    // ------------------------------------------------------- helpers
    /** @return array{0: ?array, 1: ?Admin} the challenge and its admin */
    private function current(Request $request, LoginChallengeService $challenges): array
    {
        $id = $request->session()->get('admin_login.challenge_id');
        $challenge = $id ? $challenges->find($id, 'admin') : null;

        return $challenge ? [$challenge, Admin::find($challenge['user_id'])] : [null, null];
    }

    private function expired(Request $request): RedirectResponse
    {
        $request->session()->forget('admin_login');

        return redirect()->route('admin.login')->withErrors(['login' => 'Session expired. Please sign in again.']);
    }

    /** The old device the admin chose to sign out (only if it still belongs to this admin). */
    private function revokeId(Request $request, Admin $admin): ?int
    {
        $id = $request->session()->get('admin_login.revoke_device_id');

        return $id ? $admin->devices()->active()->whereKey($id)->value('id') : null;
    }

    private function maskFor(?string $channel, Admin $admin): string
    {
        return $channel === 'sms' ? Mask::phone((string) $admin->phone) : Mask::email($admin->email);
    }

    /** The server gives each browser a random device id (cookie). Reuse it if it is already there. */
    private function deviceFrom(Request $request): array
    {
        $deviceId = $request->cookie(self::DEVICE_COOKIE);

        if (!is_string($deviceId) || strlen($deviceId) !== 40) {
            $deviceId = Str::random(40);
        }

        $this->queueDeviceCookie($deviceId, $request);

        $agent = (string) $request->userAgent();

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown',
        };

        return [
            'device_id' => $deviceId,
            'device_name' => "{$browser} on {$platform}",
            'platform' => $platform,
            'browser' => $browser,
        ];
    }

    private function queueDeviceCookie(string $deviceId, Request $request): void
    {
        // 5 years, HttpOnly (JavaScript cannot read it)
        Cookie::queue(Cookie::make(self::DEVICE_COOKIE, $deviceId, 60 * 24 * 365 * 5, '/', null, $request->isSecure(), true, false, 'lax'));
    }

    private function log(Request $request, ?Admin $admin, string $status, ?string $reason = null, ?string $deviceId = null): void
    {
        UserLoginInfo::create([
            'user_type' => 'admin',
            'user_id' => $admin?->id,
            'login_identifier' => $request->input('login') ?? $admin?->email,
            'status' => $status,
            'failure_reason' => $reason,
            'device_id' => $deviceId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}