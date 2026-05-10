<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel -> FastAPI iletişimi.
 * Tüm iş mantığı ve veritabanı FastAPI'de; Laravel sadece arayüz ve oturum yönetimi yapar.
 */
class FastApiService
{
    /** FastAPI base URL (backend farklı portta çalışır, örn. 8001) */
    protected string $baseUrl;

    /** İstek timeout (saniye) */
    protected int $timeout = 10;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.fastapi.url', 'http://127.0.0.1:8001'), '/');
    }

    /**
     * Yeni harcama oluşturur.
     *
     * @param int $userId Oturumdaki kullanıcı id (auth()->id())
     * @param array $data amount, category_id, description, expense_date
     * @return array FastAPI JSON cevabı
     */
    public function createExpense(int $userId, array $data): array
    {
        $payload = [
            'user_id'      => $userId,
            'category_id'  => (int) $data['category_id'],
            'amount'       => (float) $data['amount'],
            'description'  => $data['description'] ?? null,
            'expense_date' => $data['expense_date'],
        ];

        $response = Http::timeout($this->timeout)
            ->post("{$this->baseUrl}/api/expenses", $payload);

        $this->logIfError($response, 'createExpense');
        $response->throw();

        return $response->json();
    }

    /**
     * Kullanıcının harcama listesini getirir.
     *
     * @param int $userId
     * @param int $skip
     * @param int $limit
     * @return array ['expenses' => [...], 'total' => int]
     */
    public function listExpenses(int $userId, int $skip = 0, int $limit = 50): array
    {
        $response = Http::timeout($this->timeout)
            ->get("{$this->baseUrl}/api/expenses", [
                'user_id' => $userId,
                'skip'    => $skip,
                'limit'   => $limit,
            ]);

        $this->logIfError($response, 'listExpenses');
        $response->throw();

        return $response->json();
    }

    /**
     * Tek harcama getirir (edit formunu doldurmak için).
     */
    public function getExpense(int $userId, int $expenseId): array
    {
        $response = Http::timeout($this->timeout)
            ->get("{$this->baseUrl}/api/expenses/{$expenseId}", [
                'user_id' => $userId,
            ]);

        $this->logIfError($response, 'getExpense');
        $response->throw();

        return $response->json();
    }

    /**
     * Harcama günceller.
     */
    public function updateExpense(int $userId, int $expenseId, array $data): array
    {
        $payload = [
            'category_id'  => isset($data['category_id']) ? (int) $data['category_id'] : null,
            'amount'       => isset($data['amount']) ? (float) $data['amount'] : null,
            'description'  => $data['description'] ?? null,
            'expense_date' => $data['expense_date'] ?? null,
        ];

        // null alanları gönderme (FastAPI optional alanlar)
        $payload = array_filter($payload, fn ($v) => $v !== null);

        $url = "{$this->baseUrl}/api/expenses/{$expenseId}?user_id={$userId}";
        $response = Http::timeout($this->timeout)->put($url, $payload);
        $this->logIfError($response, 'updateExpense');
        $response->throw();

        return $response->json();
    }

    /**
     * Harcama siler.
     */
    public function deleteExpense(int $userId, int $expenseId): array
    {
        $url = "{$this->baseUrl}/api/expenses/{$expenseId}?user_id={$userId}";
        $response = Http::timeout($this->timeout)->delete($url);

        $this->logIfError($response, 'deleteExpense');
        $response->throw();

        return $response->json();
    }

    /**
     * Aylık toplam harcama.
     *
     * @param int $userId
     * @param int $year
     * @param int $month
     * @return array total_amount, expense_count, year, month
     */
    public function getMonthlyTotal(int $userId, int $year, int $month): array
    {
        $response = Http::timeout($this->timeout)
            ->get("{$this->baseUrl}/api/expenses/monthly-total", [
                'user_id' => $userId,
                'year'    => $year,
                'month'   => $month,
            ]);

        $this->logIfError($response, 'getMonthlyTotal');
        $response->throw();

        return $response->json();
    }

    /**
     * Kayıt - FastAPI'de kullanıcı oluşturulur.
     *
     * @return array ['id', 'name', 'email', 'role']
     */
    public function register(array $data): array
    {
        $response = Http::timeout($this->timeout)
            ->post("{$this->baseUrl}/api/auth/register", [
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => $data['password'],
                'role'     => $data['role'] ?? 'user',
            ]);

        $this->logIfError($response, 'register');
        $response->throw();

        return $response->json();
    }

    /**
     * Giriş - FastAPI şifre doğrular, kullanıcı bilgisi döner.
     *
     * @return array ['id', 'name', 'email', 'role']
     */
    public function login(array $credentials): array
    {
        $response = Http::timeout($this->timeout)
            ->post("{$this->baseUrl}/api/auth/login", [
                'email'    => $credentials['email'],
                'password' => $credentials['password'],
            ]);

        $this->logIfError($response, 'login');
        $response->throw();

        return $response->json();
    }

    /**
     * Kullanıcının aylık bütçesini günceller.
     *
     * FastAPI tarafında users tablosundaki monthly_budget alanını update eden
     * bir endpoint'e istek atar.
     */
    public function updateUserBudget(int $userId, float $monthlyBudget): array
    {
        $response = Http::timeout($this->timeout)
            ->post("{$this->baseUrl}/api/auth/update-budget", [
                'user_id'        => $userId,
                'monthly_budget' => $monthlyBudget,
            ]);

        $this->logIfError($response, 'updateUserBudget');
        $response->throw();

        return $response->json();
    }

    /**
     * Kategori listesi (dropdown için).
     *
     * @return array [['id' => 1, 'name' => 'Food'], ...]
     */
    public function listCategories(): array
    {
        $response = Http::timeout($this->timeout)
            ->get("{$this->baseUrl}/api/categories");

        $this->logIfError($response, 'listCategories');
        $response->throw();

        return $response->json();
    }

    /**
     * Kullanıcının şifresini değiştirir.
     *
     * FastAPI /api/auth/change-password endpoint'ine istek atar.
     */
    public function changePassword(array $payload): array
    {
        $response = Http::timeout($this->timeout)
            ->post("{$this->baseUrl}/api/auth/change-password", $payload);

        $this->logIfError($response, 'changePassword');
        $response->throw();

        return $response->json();
    }

    private function logIfError($response, string $method): void
    {
        if ($response->failed()) {
            Log::warning('FastAPI request failed', [
                'method'   => $method,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
        }
    }
}
