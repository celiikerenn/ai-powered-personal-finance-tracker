<?php

namespace App\Http\Controllers;

use App\Services\FastApiService;
use App\Support\PageInsights;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Harcama ekleme ve listeleme - Tüm veri FastAPI'den.
 */
class ExpenseController extends Controller
{
    private function defaultFixedTemplates(): array
    {
        return [];
    }

    private function parseFilterDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<int>
     */
    private function parseCategoryFilterIds(Request $request): array
    {
        $raw = $request->query('category_id', []);
        if (!is_array($raw)) {
            $raw = ($raw !== null && $raw !== '') ? [$raw] : [];
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($v) => (int) $v, $raw),
            static fn (int $id) => $id > 0
        )));

        return $ids;
    }

    public function __construct(
        protected FastApiService $api
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $userId = $request->session()->get('user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        $categories = [];
        try {
            $categories = $this->api->listCategories();
        } catch (\Throwable $e) {
            // API down ise boş form
        }

        $autoChecked = (bool) $request->session()->get('fixed_auto_checked', false);
        $request->session()->forget('fixed_auto_checked');

        return view('expenses.create', [
            'categories' => $categories,
            'autoFixedChecked' => $autoChecked,
            'aiInsights' => PageInsights::forCreateExpense(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        $request->validate([
            'category_id'  => 'required|integer',
            'amount'       => 'required|numeric|min:0.01',
            'description'  => 'nullable|string|max:2000',
            'expense_date' => 'required|date|before_or_equal:today',
        ]);

        try {
            $this->api->createExpense($userId, $request->only(
                'category_id', 'amount', 'description', 'expense_date'
            ));
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $body = $e->response->json();
            $message = $body['detail'] ?? 'Failed to create expense.';
            return back()->withErrors(['amount' => $message])->withInput();
        }

        return redirect()->route('expenses.index')->with('success', 'Expense created.');
    }

    public function storeMonthlyFixedExpenses(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        $fixedTemplates = $request->session()->get('fixed_expense_templates', $this->defaultFixedTemplates());
        if (empty($fixedTemplates)) {
            return redirect()->route('expenses.create')
                ->withErrors(['amount' => 'No fixed expense template found. Configure templates in Profile first.']);
        }

        try {
            $categories = $this->api->listCategories();
            $categoryIdByName = [];
            foreach ($categories as $cat) {
                $name = strtolower(trim((string) ($cat['name'] ?? '')));
                if ($name !== '' && isset($cat['category_id'])) {
                    $categoryIdByName[$name] = (int) $cat['category_id'];
                }
            }

            $apiData = $this->api->listExpenses($userId, 0, 200);
            $allExpenses = $apiData['expenses'] ?? [];
            $currentMonth = now()->format('Y-m');
            $existingKeys = [];
            foreach ($allExpenses as $expense) {
                $expenseDate = (string) ($expense['expense_date'] ?? '');
                if ($expenseDate === '' || !str_starts_with($expenseDate, $currentMonth)) {
                    continue;
                }
                $desc = strtolower(trim((string) ($expense['description'] ?? '')));
                $amount = number_format((float) ($expense['amount'] ?? 0), 2, '.', '');
                $existingKeys[$desc . '|' . $amount] = true;
            }

            $createdCount = 0;
            foreach ($fixedTemplates as $tpl) {
                $catName = strtolower($tpl['category']);
                $categoryId = $categoryIdByName[$catName] ?? null;
                if ($categoryId === null) {
                    continue;
                }

                $amount = (float) $tpl['amount'];
                $description = (string) $tpl['description'];
                $dedupeKey = strtolower(trim($description)) . '|' . number_format($amount, 2, '.', '');
                if (isset($existingKeys[$dedupeKey])) {
                    continue;
                }

                $this->api->createExpense($userId, [
                    'category_id'  => $categoryId,
                    'amount'       => $amount,
                    'description'  => $description,
                    'expense_date' => now()->toDateString(),
                ]);

                $existingKeys[$dedupeKey] = true;
                $createdCount++;
            }
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $body = $e->response->json();
            $message = $body['detail'] ?? 'Failed to auto-add fixed expenses.';
            return back()->withErrors(['amount' => $message])->withInput();
        } catch (\Throwable $e) {
            return back()->withErrors(['amount' => 'Failed to auto-add fixed expenses.'])->withInput();
        }

        if ($createdCount === 0) {
            $request->session()->put('fixed_auto_checked', true);
            return redirect()->route('expenses.create')->with('success', 'No new fixed expenses added (already exists for this month).');
        }
        $request->session()->put('fixed_auto_checked', true);
        return redirect()->route('expenses.create')->with('success', "Added {$createdCount} fixed expense(s) for this month.");
    }


    public function index(Request $request): View|RedirectResponse
    {
        $userId = $request->session()->get('user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        $page    = max((int) $request->query('page', 1), 1);
        $perPage = 10;
        $categories = [];
        $filters = [
            'date_from'    => '',
            'date_to'      => '',
            'category_ids' => [],
        ];
        $months = [];
        $selectedMonth = null;
        $data = [
            'expenses'      => [],
            'total'         => 0,
            'page'          => $page,
            'perPage'       => $perPage,
            'totalPages'    => 1,
            'categories'    => [],
            'months'        => [],
            'selectedMonth' => null,
            'filters'       => $filters,
        ];
        try {
            $categories = $this->api->listCategories();

            $apiData = $this->api->listExpenses($userId, 0, 200);
            $allExpenses = $apiData['expenses'] ?? [];

            $byMonth = [];
            foreach ($allExpenses as $expense) {
                if (empty($expense['expense_date'])) {
                    continue;
                }
                $monthKey = \Carbon\Carbon::parse($expense['expense_date'])->format('Y-m');
                $byMonth[$monthKey] = true;
            }
            $months = array_keys($byMonth);
            $currentMonthKey = now()->format('Y-m');
            if (!in_array($currentMonthKey, $months, true)) {
                $months[] = $currentMonthKey;
            }
            sort($months);

            $defaultMonth = $currentMonthKey;

            $categoryIds = $this->parseCategoryFilterIds($request);

            $hasDateFilters = $request->has('date_from') || $request->has('date_to');
            $filtersActive = $hasDateFilters;

            $selectedMonth = null;
            if (!$hasDateFilters) {
                $selectedMonth = $request->query('month');
                if (empty($selectedMonth) || !preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
                    $selectedMonth = $currentMonthKey;
                } elseif (!in_array($selectedMonth, $months, true)) {
                    $months[] = $selectedMonth;
                    sort($months);
                }
            }

            $dateFrom = $hasDateFilters ? $this->parseFilterDate($request->query('date_from')) : null;
            $dateTo = $hasDateFilters ? $this->parseFilterDate($request->query('date_to')) : null;

            if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
                [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            }

            $filtered = [];
            foreach ($allExpenses as $expense) {
                if (empty($expense['expense_date'])) {
                    continue;
                }
                $expenseDate = \Carbon\Carbon::parse($expense['expense_date'])->format('Y-m-d');
                $monthKey = \Carbon\Carbon::parse($expense['expense_date'])->format('Y-m');

                if (!$hasDateFilters && $selectedMonth !== null && $monthKey !== $selectedMonth) {
                    continue;
                }
                if ($dateFrom !== null && $expenseDate < $dateFrom) {
                    continue;
                }
                if ($dateTo !== null && $expenseDate > $dateTo) {
                    continue;
                }
                if (!empty($categoryIds) && !in_array((int) ($expense['category_id'] ?? 0), $categoryIds, true)) {
                    continue;
                }
                $filtered[] = $expense;
            }

            $total      = count($filtered);
            $totalPages = max((int) ceil($total / $perPage), 1);
            $page       = min($page, $totalPages);
            $offset     = ($page - 1) * $perPage;
            $pageItems  = array_slice($filtered, $offset, $perPage);

            $data = [
                'expenses'       => $pageItems,
                'total'          => $total,
                'page'           => $page,
                'perPage'        => $perPage,
                'totalPages'     => $totalPages,
                'categories'     => $categories,
                'months'         => $months,
                'selectedMonth'  => $selectedMonth,
                'defaultMonth'   => $defaultMonth,
                'filtersActive'  => $filtersActive,
                'filters'        => [
                    'date_from'    => $request->query('date_from') ?? '',
                    'date_to'      => $request->query('date_to') ?? '',
                    'category_ids' => $categoryIds,
                ],
            ];
        } catch (\Throwable $e) {
            // API hata verirse boş liste
        }

        return view('expenses.index', $data);
    }

    public function edit(Request $request, int $expenseId): View|RedirectResponse
    {
        $userId = $request->session()->get('user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        try {
            $expense = $this->api->getExpense($userId, $expenseId);
            $categories = $this->api->listCategories();
        } catch (\Illuminate\Http\Client\RequestException $e) {
            return redirect()->route('expenses.index')->withErrors([
                'email' => 'Harcama bulunamadı veya API hatası.',
            ]);
        } catch (\Throwable $e) {
            return redirect()->route('expenses.index')->withErrors([
                'email' => 'Harcama yüklenirken hata oluştu.',
            ]);
        }

        return view('expenses.edit', [
            'expense' => $expense,
            'categories' => $categories,
        ]);
    }

    public function update(Request $request, int $expenseId): RedirectResponse
    {
        $userId = $request->session()->get('user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        $request->validate([
            'category_id'  => 'required|integer',
            'amount'       => 'required|numeric|min:0.01',
            'description'  => 'nullable|string|max:2000',
            'expense_date' => 'required|date|before_or_equal:today',
        ]);

        try {
            $this->api->updateExpense($userId, $expenseId, $request->only(
                'category_id', 'amount', 'description', 'expense_date'
            ));
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $body = $e->response->json();
            $message = $body['detail'] ?? 'Failed to update expense.';
            return back()->withErrors(['amount' => $message])->withInput();
        }

        return redirect()->route('expenses.index')->with('success', 'Expense updated.');
    }

    public function destroy(Request $request, int $expenseId): RedirectResponse
    {
        $userId = $request->session()->get('user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        try {
            try {
                $expense = $this->api->getExpense($userId, $expenseId);
                if (!empty($expense['receipt_image_path'])) {
                    \Illuminate\Support\Facades\Storage::disk('public')
                        ->delete($expense['receipt_image_path']);
                }
            } catch (\Throwable) {
                // continue delete even if file lookup fails
            }

            $this->api->deleteExpense($userId, $expenseId);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $body = $e->response->json();
            $message = $body['detail'] ?? 'Failed to delete expense.';
            return redirect()->route('expenses.index')->withErrors(['email' => $message]);
        }

        return redirect()->route('expenses.index')->with('success', 'Expense deleted.');
    }
}
