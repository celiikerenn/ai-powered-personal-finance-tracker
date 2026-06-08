@extends('layouts.app')

@section('title', 'Expense List')

@push('styles')
<style>
    .expenses-table-wrap {
        background: var(--surface);
        border-radius: 16px;
        border: 1px solid var(--border);
        box-shadow: 0 1px 3px rgba(15,23,42,0.05), 0 8px 24px rgba(15,23,42,0.06);
        overflow: hidden;
    }
    .expenses-table col.col-id { width: 4.5rem; }
    .expenses-table col.col-date { width: 7rem; }
    .expenses-table col.col-cat { width: 9rem; }
    .expenses-table col.col-desc { min-width: 10rem; }
    .expenses-table col.col-amount { width: 8rem; }
    .expenses-table col.col-created { width: 9.5rem; }
    .expenses-table col.col-actions { width: 11.5rem; }
    .expenses-table .cell-id {
        font-size: 12px;
        color: var(--muted);
    }
    .expenses-table thead { background: var(--surface2); border-bottom: 1px solid var(--border2); }
    .expenses-table th {
        padding: 12px 16px;
        font-size: 11px !important;
        color: var(--muted) !important;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .expenses-table tbody tr {
        transition: background-color 0.14s ease;
    }
    .expenses-table tbody tr:hover {
        background: rgba(37, 99, 235, 0.06);
    }
    .expenses-table td {
        padding: 14px 16px;
        font-size: 13px;
        font-weight: 400;
        color: var(--txt);
        border-bottom: 1px solid var(--border);
    }
    .expenses-table tbody tr:last-child td { border-bottom: none; }
    .amount-cell { font-weight: 600; color: var(--txt); }
    .date-cell { font-size: 12px; color: var(--muted); }
    .expenses-table td.expense-actions-cell {
        vertical-align: middle;
    }
    .expense-actions {
        display: grid;
        grid-template-columns: 4.75rem 4.75rem;
        gap: 8px;
        justify-content: end;
        justify-items: stretch;
        width: fit-content;
        margin-left: auto;
    }
    .expense-actions .btn {
        border-radius: 8px;
        padding: 0 8px;
        font-size: 12px;
        font-weight: 600;
        line-height: 1;
        height: 2rem;
        width: 100%;
        min-width: 0;
        box-sizing: border-box;
        box-shadow: none !important;
        transform: none !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        white-space: nowrap;
    }
    .expense-actions .btn-expense-edit {
        background: rgba(245, 158, 11, 0.16);
        color: #b45309;
        border: 1px solid rgba(217, 119, 6, 0.4);
    }
    .expense-actions .btn-expense-edit:hover {
        background: rgba(245, 158, 11, 0.26);
        color: #92400e;
        border-color: rgba(217, 119, 6, 0.65);
    }
    .expense-actions .btn-expense-delete {
        background: rgba(239, 68, 68, 0.1);
        color: #b91c1c;
        border: 1px solid rgba(220, 38, 38, 0.35);
    }
    .expense-actions .btn-expense-delete:hover {
        background: rgba(239, 68, 68, 0.18);
        color: #991b1b;
        border-color: rgba(220, 38, 38, 0.55);
    }
    .row-deleting { opacity:0; transform: translateY(-6px); transition: opacity 0.2s ease, transform 0.2s ease; }

    .expense-delete-modal {
        position: fixed;
        inset: 0;
        z-index: 400;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.25rem;
        visibility: hidden;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }
    .expense-delete-modal.is-open {
        visibility: visible;
        opacity: 1;
        pointer-events: auto;
    }
    .expense-delete-modal__backdrop {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.72);
        backdrop-filter: blur(4px);
    }
    .expense-delete-modal__panel {
        position: relative;
        max-width: 400px;
        width: 100%;
        background: var(--surface);
        border: 1px solid var(--border2);
        border-radius: 16px;
        padding: 1.35rem 1.5rem 1.25rem;
        box-shadow: 0 24px 48px rgba(0, 0, 0, 0.5);
    }
    .expense-delete-modal__panel h3 {
        margin: 0 0 0.5rem;
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--txt);
    }
    .expense-delete-modal__detail {
        margin: 0 0 1.15rem;
        font-size: 0.9rem;
        color: var(--txt2);
        line-height: 1.45;
    }
    .expense-delete-modal__actions {
        display: flex;
        gap: 0.6rem;
        justify-content: flex-end;
        flex-wrap: wrap;
    }
    .expense-delete-modal__actions .btn { min-width: 5.5rem; }
    .expense-delete-modal__actions .delete-confirm {
        background: rgba(220, 38, 38, 0.12);
        color: #b91c1c;
        border: 1px solid rgba(220, 38, 38, 0.35);
    }
    .expense-delete-modal__actions .delete-confirm:hover {
        background: rgba(220, 38, 38, 0.2);
        color: #991b1b;
    }

    .expenses-page-layout {
        display: flex;
        gap: 1rem;
        align-items: flex-start;
    }
    .expenses-filter-sidebar {
        flex: 0 0 232px;
        width: 232px;
        position: sticky;
        top: 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    .expenses-month-picker__note {
        margin: 0.5rem 0 0;
        font-size: 0.78rem;
        color: var(--muted);
        line-height: 1.4;
    }
    .expenses-month-picker select:disabled {
        opacity: 0.65;
        cursor: not-allowed;
    }
    .expenses-filter-sidebar .filter-toolbar {
        margin-bottom: 0;
    }
    .expenses-filter-sidebar .expenses-filters-panel {
        overflow: visible;
        min-height: 22rem;
        display: flex;
        flex-direction: column;
    }
    .expenses-filter-sidebar .expenses-filters-panel .expenses-filter__form {
        display: flex;
        flex-direction: column;
        gap: 0.875rem;
        align-items: stretch;
        flex: 1;
    }
    .expenses-filter__hint {
        margin: 0.35rem 0 0;
        font-size: 0.78rem;
        line-height: 1.35;
        color: var(--muted);
    }
    .expenses-filter-sidebar .ts-wrapper.multi .ts-control {
        min-height: 2.5rem;
    }
    .expenses-filter-sidebar .expenses-filters-panel .expenses-filter__actions {
        flex-direction: column;
        align-items: stretch;
        margin-top: auto;
        padding-top: 0.35rem;
    }
    .expenses-filter-sidebar .expenses-filters-panel .expenses-filter__actions .btn {
        width: 100%;
        justify-content: center;
        text-align: center;
    }
    .expenses-main {
        flex: 1;
        min-width: 0;
    }
    @media (max-width: 960px) {
        .expenses-page-layout {
            flex-direction: column;
        }
        .expenses-filter-sidebar {
            width: 100%;
            flex: none;
            position: static;
        }
        .expenses-filter-sidebar .expenses-filters-panel .expenses-filter__actions {
            flex-direction: row;
            flex-wrap: wrap;
            margin-top: 0.5rem;
        }
        .expenses-filter-sidebar .expenses-filters-panel .expenses-filter__actions .btn {
            width: auto;
            flex: 1 1 auto;
        }
    }
</style>
@endpush

@section('content')
<h1>My Expenses</h1>
<p style="margin-bottom:0.75rem; color:var(--muted); font-size:0.9rem;">
    Review, edit or delete your past expenses. Use this list to keep your spending history up to date.
</p>

<div class="expenses-page-layout">
    <aside class="expenses-filter-sidebar" aria-label="Expense browsing">
        @include('partials.expenses-month-picker', [
            'months' => $months ?? [],
            'selectedMonth' => $selectedMonth ?? null,
            'filtersActive' => $filtersActive ?? false,
            'preserveCategoryIds' => $filters['category_ids'] ?? [],
        ])
        @include('partials.expenses-filter', [
            'categories' => $categories ?? [],
            'defaultMonth' => $defaultMonth ?? null,
            'filters' => $filters ?? [],
            'preserveMonth' => !($filtersActive ?? false) ? ($selectedMonth ?? null) : null,
        ])
    </aside>

    <div class="expenses-main">
<div class="card expenses-table-wrap">
    @if(count($expenses) > 0)
        <table class="expenses-table">
            <colgroup>
                <col class="col-id">
                <col class="col-date">
                <col class="col-cat">
                <col class="col-desc">
                <col class="col-amount">
                <col class="col-created">
                <col class="col-actions">
            </colgroup>
            <thead>
                <tr>
                    <th scope="col" style="text-transform:uppercase; letter-spacing:0.06em; font-size:0.75rem;">ID</th>
                    <th scope="col" style="text-transform:uppercase; letter-spacing:0.06em; font-size:0.75rem;">Date</th>
                    <th scope="col" style="text-transform:uppercase; letter-spacing:0.06em; font-size:0.75rem;">Category</th>
                    <th scope="col" style="text-transform:uppercase; letter-spacing:0.06em; font-size:0.75rem;">Description</th>
                    <th scope="col" class="text-right" style="text-transform:uppercase; letter-spacing:0.06em; font-size:0.75rem;">Amount</th>
                    <th scope="col" style="text-transform:uppercase; letter-spacing:0.06em; font-size:0.75rem;">Created</th>
                    <th scope="col" style="text-transform:uppercase; letter-spacing:0.06em; font-size:0.75rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($expenses as $e)
                    @php
                        $cat = strtolower($e['category_name'] ?? 'other');
                        $badgeClass = 'badge-other';
                        if (str_contains($cat, 'food')) $badgeClass = 'badge-food';
                        elseif (str_contains($cat, 'transport')) $badgeClass = 'badge-transport';
                        elseif (str_contains($cat, 'utilities') || str_contains($cat, 'utility') || str_contains($cat, 'bill')) $badgeClass = 'badge-bills';
                        elseif (str_contains($cat, 'grocery') || str_contains($cat, 'groceries')) $badgeClass = 'badge-shopping';
                        elseif (str_contains($cat, 'health')) $badgeClass = 'badge-health';
                        elseif (str_contains($cat, 'entertainment')) $badgeClass = 'badge-entertainment';
                        elseif (str_contains($cat, 'education')) $badgeClass = 'badge-education';
                        elseif (str_contains($cat, 'clothing')) $badgeClass = 'badge-clothing';
                        elseif (str_contains($cat, 'rent')) $badgeClass = 'badge-rent';
                    @endphp
                    <tr class="expense-row"
                        data-category="{{ strtolower($e['category_name'] ?? '') }}"
                        data-search="{{ strtolower(($e['description'] ?? '') . ' ' . ($e['category_name'] ?? '') . ' ' . ($e['expense_id'] ?? '') . ' ' . number_format($e['amount'], 2, ',', '.') . ' ' . number_format($e['amount'], 2, '.', '') . ' ' . \Carbon\Carbon::parse($e['expense_date'])->format('d.m.Y') . ' ' . \Carbon\Carbon::parse($e['expense_date'])->format('Y-m-d') . ' ' . \Carbon\Carbon::parse($e['created_at'])->format('d.m.Y H:i')) }}">
                        <td class="cell-id">#{{ $e['expense_id'] }}</td>
                        <td class="date-cell">{{ \Carbon\Carbon::parse($e['expense_date'])->format('d.m.Y') }}</td>
                        <td>
                            <span class="badge-category {{ $badgeClass }}">
                                {{ $e['category_name'] }}
                            </span>
                        </td>
                        <td>{{ \Illuminate\Support\Str::limit($e['description'] ?? '-', 40) }}</td>
                        <td class="text-right amount-cell">
                            {{ number_format($e['amount'], 2, ',', '.') }} {{ $currencySymbol }}
                        </td>
                        <td class="date-cell">{{ \Carbon\Carbon::parse($e['created_at'])->format('d.m.Y H:i') }}</td>
                        <td class="expense-actions-cell">
                            <div class="expense-actions">
                                <a class="btn btn-expense-edit" href="{{ route('expenses.edit', $e['expense_id']) }}" title="Edit expense" aria-label="Edit expense">
                                    <span aria-hidden="true">✏</span> Edit
                                </a>
                                <button
                                    type="button"
                                    class="btn btn-expense-delete btn-delete-trigger"
                                    data-expense-line="{{ e(\Illuminate\Support\Str::limit($e['description'] ?? '—', 80)) }} · {{ number_format($e['amount'], 2, ',', '.') }} {{ $currencySymbol }}"
                                    title="Delete expense"
                                    aria-label="Delete expense"
                                ><span aria-hidden="true">🗑</span> Delete</button>
                                <form method="POST" action="{{ route('expenses.destroy', $e['expense_id']) }}" style="display:none;" class="delete-form">
                                    @csrf
                                    @method('DELETE')
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p style="margin-top: 1rem;">
            <strong>{{ count($expenses) }}</strong> of <strong>{{ $total }}</strong> records.
        </p>

        @if(($totalPages ?? 1) > 1)
            @php
                $current = $page ?? 1;
                $last = $totalPages ?? 1;
                $paginationBase = [
                    'month' => ($filtersActive ?? false) ? null : ($selectedMonth ?? null),
                    'date_from' => ($filtersActive ?? false) ? ($filters['date_from'] ?? null) : null,
                    'date_to' => ($filtersActive ?? false) ? ($filters['date_to'] ?? null) : null,
                ];
                if (!empty($filters['category_ids'] ?? [])) {
                    $paginationBase['category_id'] = $filters['category_ids'];
                }
                $paginationQuery = array_filter(
                    $paginationBase,
                    fn ($v) => $v !== null && $v !== '' && $v !== []
                );
            @endphp
            <nav class="app-pagination" aria-label="Expenses pagination">
                <p class="app-pagination__info">
                    Page {{ $current }} of {{ $last }} · {{ $total }} records total
                </p>
                <ul class="app-pagination__links">
                    @if($current > 1)
                        <li>
                            <a href="{{ route('expenses.index', array_merge($paginationQuery, ['page' => $current - 1])) }}"
                               class="btn btn-secondary"
                               style="padding:0.25rem 0.6rem; font-size:0.85rem; border-radius:999px;">
                                ‹ Prev
                            </a>
                        </li>
                    @endif
                    @for($p = 1; $p <= $last; $p++)
                        <li>
                            @if($p === $current)
                                <span class="btn btn-primary"
                                      style="padding:0.25rem 0.6rem; font-size:0.85rem; border-radius:999px;">
                                    {{ $p }}
                                </span>
                            @else
                                <a href="{{ route('expenses.index', array_merge($paginationQuery, ['page' => $p])) }}"
                                   class="btn btn-secondary"
                                   style="padding:0.25rem 0.6rem; font-size:0.85rem; border-radius:999px;">
                                    {{ $p }}
                                </a>
                            @endif
                        </li>
                    @endfor
                    @if($current < $last)
                        <li>
                            <a href="{{ route('expenses.index', array_merge($paginationQuery, ['page' => $current + 1])) }}"
                               class="btn btn-secondary"
                               style="padding:0.25rem 0.6rem; font-size:0.85rem; border-radius:999px;">
                                Next ›
                            </a>
                        </li>
                    @endif
                </ul>
            </nav>
        @endif
    @else
        <div class="empty-state empty-state--wide" role="status">
            <div class="empty-state__icon" aria-hidden="true">📋</div>
            <p class="empty-state__title">No expenses yet</p>
            <p class="empty-state__text">
                @if(!empty($filtersActive))
                    No expenses match the current filters. Try adjusting them or use <strong>Clear</strong>.
                @else
                    No expenses for this month. Try another month from the selector above.
                @endif
            </p>
        </div>
    @endif
</div>
    </div>
</div>

<div id="expense-delete-modal" class="expense-delete-modal" aria-hidden="true" role="dialog" aria-labelledby="expense-delete-title">
    <div class="expense-delete-modal__backdrop" data-delete-modal-close></div>
    <div class="expense-delete-modal__panel">
        <h3 id="expense-delete-title">Delete this expense?</h3>
        <p class="expense-delete-modal__detail" id="expense-delete-modal-detail"></p>
        <div class="expense-delete-modal__actions">
            <button type="button" class="btn btn-secondary" id="expense-delete-modal-cancel">Cancel</button>
            <button type="button" class="btn delete-confirm" id="expense-delete-modal-confirm">Delete</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const modal = document.getElementById("expense-delete-modal");
    const detailEl = document.getElementById("expense-delete-modal-detail");
    const btnCancel = document.getElementById("expense-delete-modal-cancel");
    const btnConfirm = document.getElementById("expense-delete-modal-confirm");
    if (!modal || !detailEl || !btnCancel || !btnConfirm) return;

    let pendingForm = null;
    let pendingRow = null;

    function openModal(line, form, row) {
        pendingForm = form;
        pendingRow = row;
        detailEl.textContent = line || "";
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        btnConfirm.focus();
    }

    function closeModal() {
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        pendingForm = null;
        pendingRow = null;
    }

    document.querySelectorAll(".expense-row").forEach((row) => {
        const trigger = row.querySelector(".btn-delete-trigger");
        const form = row.querySelector(".delete-form");
        if (!trigger || !form) return;
        trigger.addEventListener("click", () => {
            const line = trigger.getAttribute("data-expense-line") || "";
            openModal(line, form, row);
        });
    });

    btnCancel.addEventListener("click", closeModal);
    modal.querySelectorAll("[data-delete-modal-close]").forEach((el) => {
        el.addEventListener("click", closeModal);
    });

    btnConfirm.addEventListener("click", () => {
        if (!pendingForm || !pendingRow) return;
        const formToSend = pendingForm;
        const rowEl = pendingRow;
        rowEl.classList.add("row-deleting");
        closeModal();
        setTimeout(() => formToSend.submit(), 220);
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && modal.classList.contains("is-open")) {
            e.preventDefault();
            closeModal();
        }
    });
})();
</script>
@endpush
