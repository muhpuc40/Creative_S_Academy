<?php

namespace App\Http\Controllers\Api;

use App\Actions\RegisterDeviceAction;
use App\Enums\AffiliateStatus;
use App\Enums\MessageChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Api\SendCodeRequest;
use App\Http\Requests\Api\VerifyCodeRequest;
use App\Models\Affiliate;
use App\Models\AffiliateProfile;
use App\Models\AffiliateSetting;
use App\Models\Student;
use App\Models\UserDevice;
use App\Models\UserLoginInfo;
use App\Services\Affiliate\AttributionService;
use App\Services\Auth\LoginChallengeService;
use App\Services\Messaging\MessageService;
use App\Support\Mask;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Login:    1. login     2. send-code     3. verify
 * Sign-up:  1. register  2. send-code     3. verify   (student or affiliate; account is created in step 3)
 */
class AuthController extends Controller
{
    // ------------------------------------------------------- sign-up, step 1
    public function register(RegisterRequest $request, string $type, LoginChallengeService $challenges): JsonResponse
    {
        $challengeId = $challenges->start(
            $type,
            null,
            $request->only(['device_id', 'device_name', 'platform', 'browser']),
            null,
            [
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),   // never stored as plain text
                'visitor_token' => $request->input('visitor_token'),
            ]
        );

        return response()->json([
            'challenge_id' => $challengeId,
            'expires_in' => LoginChallengeService::CHALLENGE_TTL,
            'options' => $challenges->options($request->email, $request->phone),
        ]);
    }

    // ------------------------------------------------------- login, step 1
    public function login(
        LoginRequest $request,
        string $type,
        RegisterDeviceAction $devices,
        LoginChallengeService $challenges
    ): JsonResponse {
        $model = Relation::getMorphedModel($type);
        $field = filter_var($request->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        /** @var \App\Models\Admin|Student|\App\Models\Instructor|null $user */
        $user = $model::where($field, $request->login)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            $this->log($request, $type, $user, 'failed', 'wrong_password');

            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        if (!$user->isActive()) {
            $this->log($request, $type, $user, 'blocked', 'suspended');

            return response()->json(['message' => 'Your account is not active.'], 403);
        }

        // After a 409 the user can send revoke_device_id to replace an old device.
        // It is only removed after the code is verified.
        $revokeId = $request->filled('revoke_device_id')
            ? $user->devices()->active()->whereKey($request->revoke_device_id)->value('id')
            : null;

        try {
            $devices->ensureSlot($user, $request->device_id, $revokeId);
        } catch (HttpResponseException $e) {
            $this->log($request, $type, $user, 'blocked', 'device_limit');
            throw $e;
        }

        // Known device: this device_id already signed in to this user before (active or logged out).
        // The password is enough - skip the OTP step and issue the token now.
        if ($user->devices()->where('device_id', $request->device_id)->exists()) {
            return $this->completeLogin(
                $request, $type, $user,
                $request->only(['device_id', 'device_name', 'platform', 'browser']),
                $revokeId, null, $devices
            );
        }

        $challengeId = $challenges->start(
            $type,
            $user->id,
            $request->only(['device_id', 'device_name', 'platform', 'browser']),
            $revokeId,
            null,
            $user->password
        );

        return response()->json([
            'otp_required' => true,
            'challenge_id' => $challengeId,
            'expires_in' => LoginChallengeService::CHALLENGE_TTL,
            'options' => $challenges->options($user->email, $user->phone),
        ]);
    }

    // ------------------------------------------------------- step 2 (login and sign-up)
    public function sendCode(
        SendCodeRequest $request,
        string $type,
        LoginChallengeService $challenges,
        MessageService $messages
    ): JsonResponse {
        $challenge = $challenges->find($request->challenge_id, $type);
        $contact = $challenge ? $this->contactFor($challenge, $type) : null;

        if (!$contact) {
            return $this->sessionExpired();
        }

        $wait = $challenges->secondsUntilResend($challenge);

        if ($wait > 0) {
            return response()->json([
                'message' => "Please wait {$wait} seconds before requesting a new code.",
                'retry_after' => $wait,
            ], 429);
        }

        $channel = MessageChannel::from($request->channel);
        $to = match ($channel) {
            MessageChannel::Email => $contact['email'],
            MessageChannel::Sms => $contact['phone'],
        };

        if (!$to) {
            return response()->json(['message' => 'This account has no phone number.'], 422);
        }

        // At most 5 codes per email or phone per hour (stops SMS and email spam)
        $limitKey = 'otp-to:' . $to;

        if (RateLimiter::tooManyAttempts($limitKey, 5)) {
            return response()->json([
                'message' => 'Too many codes were requested for this contact. Try again later.',
                'retry_after' => RateLimiter::availableIn($limitKey),
            ], 429);
        }

        $code = $challenges->makeCode();

        try {
            $messages->send(
                $channel,
                $to,
                'login-code',
                ['code' => $code, 'name' => $contact['name'], 'minutes' => intdiv(LoginChallengeService::CODE_TTL, 60)],
                'Your ' . config('app.name') . ' verification code'
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not send the code. Please try again.'], 502);
        }

        RateLimiter::hit($limitKey, 3600);
        $challenges->storeCode($request->challenge_id, $challenge, $channel->value, $code);

        $response = [
            'message' => 'Code sent.',
            'channel' => $channel->value,
            'sent_to' => $channel === MessageChannel::Email ? Mask::email($to) : Mask::phone($to),
            'expires_in' => LoginChallengeService::CODE_TTL,
            'resend_after' => LoginChallengeService::RESEND_AFTER,
        ];

        if (app()->environment('local') && config('messaging.show_login_code')) {
            $response['debug_code'] = $code;
        }

        return response()->json($response);
    }

    // ------------------------------------------------------- step 3 (login and sign-up)
    public function verify(
        VerifyCodeRequest $request,
        string $type,
        LoginChallengeService $challenges,
        RegisterDeviceAction $devices,
        AttributionService $attribution
    ): JsonResponse {
        $challenge = $challenges->find($request->challenge_id, $type);
        if (!$challenge) { return $this->sessionExpired(); }
        return DB::transaction(function () use ($request, $type, $challenges, $devices, $challenge, $attribution) {
            if (!isset($challenge['signup'])) {
                $model = Relation::getMorphedModel($type);
                $model::whereKey($challenge['user_id'])->lockForUpdate()->first();
            }
            // Recheck the password fingerprint after taking the same account lock used by reset.
            return $this->verifyLocked($request, $type, $challenges, $devices, $attribution);
        });
    }

    private function verifyLocked(
        VerifyCodeRequest $request,
        string $type,
        LoginChallengeService $challenges,
        RegisterDeviceAction $devices,
        AttributionService $attribution
    ): JsonResponse {
        $challenge = $challenges->find($request->challenge_id, $type);

        if (!$challenge) {
            return $this->sessionExpired();
        }

        $isSignup = isset($challenge['signup']);

        /** @var \App\Models\Admin|Student|\App\Models\Instructor|Affiliate|null $user */
        $user = $isSignup ? null : $this->findUser($type, $challenge['user_id']);

        if (!$isSignup && (!$user || !$user->isActive())) {
            return $this->sessionExpired();
        }

        $result = $challenges->verify($request->challenge_id, $challenge, $request->code);

        if ($result !== 'ok') {
            $this->log($request, $type, $user, 'failed', "code_{$result}", $challenge['device']['device_id']);

            [$message, $status] = match ($result) {
                'expired' => ['The code has expired. Request a new one.', 422],
                'no_code' => ['Request a code first.', 422],
                'locked' => ['Too many wrong codes. Please start again.', 429],
                default => ['The code is not correct.', 422],
            };

            return response()->json(['message' => $message], $status);
        }

        // Sign-up: the code is correct, now the account is created
        if ($isSignup) {
            $user = match ($type) {
                'affiliate' => $this->createAffiliate($challenge),
                default => $this->createStudent($challenge),
            };

            if (!$user) {
                $challenges->forget($request->challenge_id);

                return response()->json(['message' => 'This email or phone number is already registered.'], 422);
            }

            // Attribution is for students only - an affiliate signing up has no "referring affiliate".
            if ($type !== 'affiliate') {
                $attribution->attributeOnRegister($user, $challenge['signup']['visitor_token'] ?? null);
            }
        }

        $challenges->forget($request->challenge_id);

        return $this->completeLogin($request, $type, $user, $challenge['device'], $challenge['revoke_device_id'], $challenge['channel'], $devices);
    }

    /**
     * Final step shared by both paths (OTP verified, or a known device that skipped OTP):
     * save the device, issue one token for it, log the success.
     */
    private function completeLogin(Request $request, string $type, $user, array $deviceData, ?int $revokeId, ?string $channel, RegisterDeviceAction $devices): JsonResponse
    {
        // Replace the old device the user chose after the 409
        if ($revokeId) {
            $user->devices()->active()->find($revokeId)?->revoke();
        }

        try {
            $device = $devices->register($user, $deviceData, $request);
        } catch (HttpResponseException $e) {
            $this->log($request, $type, $user, 'blocked', 'device_limit', $deviceData['device_id']);
            throw $e;
        }

        // One token per device
        if ($device->token_id) {
            $user->tokens()->whereKey($device->token_id)->delete();
        }

        $token = $user->createToken("{$type}:{$device->device_id}", [$type]);
        $device->update(['token_id' => $token->accessToken->id]);
        $user->last_login_at = now();
        if (!$user->verified_at && $channel) {
            $user->verified_at = now();
            $user->verified_channel = $channel;
        }
        $user->save();

        $this->log($request, $type, $user, 'success', null, $device->device_id);

        return response()->json([
            'otp_required' => false,
            'token' => $token->plainTextToken,
            'user_type' => $type,
            'user' => $user->only(['public_id', 'name', 'email', 'phone', 'avatar']),
            'device_id' => $device->id,
        ]);
    }

    // ------------------------------------------------------- after login
    public function me(Request $request): JsonResponse
    {
        /** @var \App\Models\Admin|Student|\App\Models\Instructor $user */
        $user = $request->user();

        return response()->json([
            'user_type' => $user->userType(),
            'user' => $user->only(['public_id', 'name', 'email', 'phone', 'avatar', 'status', 'last_login_at']),
        ]);
    }

    /**
     * POST logout
     *   no body             -> this device
     *   {"id": 2}           -> that device (id from GET devices)
     *   {"id": "all"}       -> every device
     */
    public function logout(Request $request): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'regex:/^(all|\d+)$/']]);

        /** @var \App\Models\Admin|Student|\App\Models\Instructor $user */
        $user = $request->user();
        $id = $request->input('id');

        if ($id === 'all') {
            $user->devices()->active()->get()->each->revoke();
            $user->tokens()->delete();

            return response()->json(['message' => 'Logged out from all devices.']);
        }

        if ($id !== null) {
            $device = $user->devices()->active()->find($id);
            abort_if(!$device, 404, 'Device not found.');
            $device->revoke();

            return response()->json(['message' => 'Device logged out.']);
        }

        $token = $user->currentAccessToken();
        $device = UserDevice::where('token_id', $token->id)->first();
        $device ? $device->revoke() : $token->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    // ------------------------------------------------------- helpers
    /** Name, email and phone for the code message: from the sign-up data or from the existing user. */
    private function contactFor(array $challenge, string $type): ?array
    {
        if (isset($challenge['signup'])) {
            return $challenge['signup'];
        }

        $user = $this->findUser($type, $challenge['user_id']);

        return ($user && $user->isActive())
            ? ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone]
            : null;
    }

    /** Creates the student after the code is verified. Returns null if the email or phone was taken meanwhile. */
    private function createStudent(array $challenge): ?Student
    {
        $data = $challenge['signup'];

        $taken = Student::withTrashed()
            ->where('email', $data['email'])
            ->orWhere('phone', $data['phone'])
            ->exists();

        if ($taken) {
            return null;
        }

        return Student::forceCreate([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'],   // already hashed
            'status' => 'active',
            // Record the channel actually verified during registration.
            'verified_at' => now(),
            'verified_channel' => $challenge['channel'],
        ]);
    }

    /**
     * Creates the affiliate after the code is verified. Also creates the affiliate_profiles row
     * (status = pending, unless affiliate_settings.auto_approve is on) with a unique ref code.
     * Returns null if the email or phone was taken meanwhile.
     */
    private function createAffiliate(array $challenge): ?Affiliate
    {
        $data = $challenge['signup'];

        $taken = Affiliate::withTrashed()
            ->where('email', $data['email'])
            ->orWhere('phone', $data['phone'])
            ->exists();

        if ($taken) {
            return null;
        }

        $affiliate = Affiliate::forceCreate([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'],   // already hashed
            'status' => 'active',
            // Record the channel actually verified during registration.
            'verified_at' => now(),
            'verified_channel' => $challenge['channel'],
        ]);

        $settings = AffiliateSetting::current();
        // Program off -> always pending, no matter what auto_approve says.
        $autoApprove = $settings->is_active && $settings->auto_approve;

        AffiliateProfile::create([
            'affiliate_id' => $affiliate->id,
            'ref_code' => $this->uniqueRefCode($data['name']),
            'status' => $autoApprove ? AffiliateStatus::Approved : AffiliateStatus::Pending,
            'approved_at' => $autoApprove ? now() : null,
        ]);

        return $affiliate;
    }

    /** First word of the name + a random 3-digit suffix, e.g. "RAHIM482". The affiliate can change it later. */
    private function uniqueRefCode(string $name): string
    {
        $firstWord = trim(explode(' ', trim($name))[0] ?? '');
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $firstWord) ?: '');
        $base = $base !== '' ? substr($base, 0, 10) : 'AFF';

        do {
            $code = $base.random_int(100, 999);
        } while (AffiliateProfile::where('ref_code', $code)->exists());

        return $code;
    }

    /** @return \App\Models\Admin|Student|\App\Models\Instructor|Affiliate|null */
    private function findUser(string $type, int $id)
    {
        $model = Relation::getMorphedModel($type);

        return $model::find($id);
    }

    private function sessionExpired(): JsonResponse
    {
        return response()->json(['message' => 'Session expired. Please start again.'], 422);
    }

    private function log(Request $request, string $type, $user, string $status, ?string $reason = null, ?string $deviceId = null): void
    {
        UserLoginInfo::create([
            'user_type' => $type,
            'user_id' => $user?->id,
            'login_identifier' => $request->input('login') ?? $user?->email,
            'status' => $status,
            'failure_reason' => $reason,
            'device_id' => $deviceId ?? $request->input('device_id'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
