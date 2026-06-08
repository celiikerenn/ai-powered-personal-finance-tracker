<?php

namespace App\Http\Controllers;

use App\Services\FastApiService;
use App\Support\PageInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReceiptScanController extends Controller
{
    public function __construct(
        protected FastApiService $api
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $userId = $request->session()->get('user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        $categories = [];
        try {
            $categories = $this->api->listCategories();
        } catch (\Throwable $e) {
            // API down
        }

        $scan = $request->session()->get('receipt_scan');

        return view('expenses.receipt-scan', [
            'categories' => $categories,
            'scan' => $scan,
            'receiptPreview' => $request->session()->get('receipt_scan_preview'),
            'aiInsights' => PageInsights::forReceiptScan(is_array($scan) ? $scan : null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        $request->validate([
            'receipt' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        try {
            $categories = $this->api->listCategories();
            $uploaded = $request->file('receipt');
            $result = $this->api->scanReceipt((int) $userId, $uploaded);
            $result['category_id'] = $this->resolveCategoryId($categories, $result['category_name'] ?? null);
            $request->session()->put('receipt_scan', $result);
            $request->session()->put('receipt_scan_preview', $this->previewPayload($uploaded));

            return redirect()
                ->route('expenses.receipt-scan')
                ->with('success', $result['message'] ?? 'Receipt scanned. Please review the fields below.');
        } catch (\Throwable $e) {
            return redirect()
                ->route('expenses.receipt-scan')
                ->withErrors(['receipt' => $e->getMessage()]);
        }
    }

    public function confirm(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        if (!$request->session()->has('receipt_scan')) {
            return redirect()
                ->route('expenses.receipt-scan')
                ->withErrors(['receipt' => 'Scan a receipt first.']);
        }

        $validated = $request->validate([
            'category_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $receiptPath = $this->storeReceiptImage((int) $userId, $request->session()->get('receipt_scan_preview'));
            if ($receiptPath !== null) {
                $validated['receipt_image_path'] = $receiptPath;
            }

            $this->api->createExpense((int) $userId, $validated);

            $scan = $request->session()->get('receipt_scan');
            if (is_array($scan)) {
                try {
                    $this->api->learnReceiptMemory((int) $userId, [
                        'category_id' => (int) $validated['category_id'],
                        'description' => $validated['description'] ?? '',
                        'raw_text'    => $scan['raw_text'] ?? '',
                    ]);
                } catch (\Throwable) {
                    // Non-blocking: expense already saved
                }
            }

            $request->session()->forget(['receipt_scan', 'receipt_scan_preview']);

            return redirect()
                ->route('expenses.receipt-scan')
                ->with('success', 'Expense saved. You can scan another receipt.');
        } catch (\Throwable $e) {
            return redirect()
                ->route('expenses.receipt-scan')
                ->withErrors(['amount' => $e->getMessage()]);
        }
    }

    public function discard(Request $request): RedirectResponse
    {
        $request->session()->forget(['receipt_scan', 'receipt_scan_preview']);

        return redirect()
            ->route('expenses.receipt-scan')
            ->with('success', 'Scan discarded. You can upload another receipt.');
    }

    /**
     * @param  list<array{id: int, name: string}>  $categories
     */
    private function resolveCategoryId(array $categories, ?string $categoryName): ?int
    {
        if ($categoryName === null || $categoryName === '') {
            return null;
        }

        foreach ($categories as $cat) {
            if (strcasecmp((string) ($cat['name'] ?? ''), $categoryName) === 0) {
                return (int) $cat['category_id'];
            }
        }

        return null;
    }

    /**
     * @return array{mime: string, data: string, ext: string}
     */
    private function previewPayload(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        return [
            'mime' => $file->getMimeType() ?: 'image/jpeg',
            'data' => base64_encode((string) file_get_contents($file->getRealPath())),
            'ext' => $ext,
        ];
    }

    /**
     * @param  array{mime?: string, data?: string, ext?: string}|null  $preview
     */
    private function storeReceiptImage(int $userId, ?array $preview): ?string
    {
        if (empty($preview['data'])) {
            return null;
        }

        $binary = base64_decode((string) $preview['data'], true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $ext = in_array($preview['ext'] ?? '', ['jpg', 'jpeg', 'png', 'webp'], true)
            ? ($preview['ext'] === 'jpeg' ? 'jpg' : $preview['ext'])
            : 'jpg';

        $relative = 'receipts/'.$userId.'/'.Str::uuid()->toString().'.'.$ext;
        Storage::disk('public')->put($relative, $binary);

        return $relative;
    }
}
