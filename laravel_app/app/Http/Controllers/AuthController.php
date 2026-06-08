<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FastApiService;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Giriş ve kayıt - Laravel veritabanına bağlanmaz; FastAPI auth endpoint'lerini kullanır.
 * Başarılı giriş/kayıtta kullanıcı bilgisi session'da tutulur (custom guard yerine manuel).
 */
class AuthController extends Controller
{
    public function __construct(
        protected FastApiService $api
    ) {}

    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        try {
            $user = $this->api->login($request->only('email', 'password'));
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return back()->withErrors([
                'email' => 'Cannot connect to FastAPI. Is the backend running? (port 8001)',
            ])->withInput($request->only('email'));
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $message = $e->response->status() === 401
                ? 'Invalid email or password.'
                : $this->parseFastApiError($e);
            return back()->withErrors(['email' => $message])->withInput($request->only('email'));
        }

        $this->syncLocalUserRow($user);
        $local = User::where('email', $user['email'])->first();
        $currency = $local?->currency !== null && $local->currency !== ''
            ? Currency::normalize($local->currency)
            : 'TRY';

        session([
            'user_id'   => $user['user_id'],
            'user_name' => $user['name'],
            'user_email'=> $user['email'],
            'user_role' => $user['role'],
            'monthly_budget' => (float)($user['monthly_budget'] ?? 0),
            'currency'  => $currency,
            'email_notifications' => (bool) ($user['email_notifications'] ?? true),
        ]);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function showRegisterForm(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        try {
            $user = $this->api->register([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => $request->password,
                'role'     => 'user',
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return back()->withErrors([
                'email' => 'Cannot connect to FastAPI. Is the backend running? (port 8001)',
            ])->withInput($request->only('name', 'email'));
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $message = $this->parseFastApiError($e);
            return back()->withErrors(['email' => $message])->withInput($request->only('name', 'email'));
        }

        $this->syncLocalUserRow($user);
        $local = User::where('email', $user['email'])->first();
        $currency = $local?->currency !== null && $local->currency !== ''
            ? Currency::normalize($local->currency)
            : 'TRY';

        session([
            'user_id'   => $user['user_id'],
            'user_name' => $user['name'],
            'user_email'=> $user['email'],
            'user_role' => $user['role'],
            'monthly_budget' => (float)($user['monthly_budget'] ?? 0),
            'currency'  => $currency,
            'email_notifications' => (bool) ($user['email_notifications'] ?? true),
        ]);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    /**
     * FastAPI hata cevabından okunabilir mesaj çıkarır.
     * detail string olabilir veya 422 için [{"loc":..., "msg":...}] dizisi.
     */
    private function parseFastApiError(\Illuminate\Http\Client\RequestException $e): string
    {
        $body = $e->response->json();
        if ($body === null) {
            return 'Request failed. (HTTP ' . $e->response->status() . ')';
        }

        $detail = $body['detail'] ?? null;
        if ($detail === null) {
            return $body['message'] ?? 'Request failed.';
        }

        if (is_string($detail)) {
            return $detail;
        }

        if (is_array($detail)) {
            $messages = [];
            foreach ($detail as $item) {
                if (is_array($item) && isset($item['msg'])) {
                    $messages[] = $item['msg'];
                } elseif (is_string($item)) {
                    $messages[] = $item;
                }
            }
            if ($messages !== []) {
                return implode(' ', $messages);
            }
        }

        return 'Request failed.';
    }

    /**
     * FastAPI ile aynı e-postaya sahip yerel users satırını garanti eder (para birimi vb. Laravel tarafında).
     *
     * @param  array<string, mixed>  $user
     */
    private function syncLocalUserRow(array $user): void
    {
        $email = $user['email'] ?? null;
        if (!is_string($email) || $email === '') {
            return;
        }

        $existing = User::where('email', $email)->first();
        if ($existing) {
            $existing->update([
                'name' => $user['name'] ?? $existing->name,
            ]);
            return;
        }

        User::create([
            'name' => $user['name'] ?? 'User',
            'email' => $email,
            'password' => Hash::make(Str::random(48)),
            'currency' => 'TRY',
        ]);
    }
}
