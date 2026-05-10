@extends('layouts.app')

@section('title', 'Add Expense')

@section('content')
<h1>Add Expense</h1>
<p style="margin-top:-0.35rem; margin-bottom:1rem; color:var(--muted); font-size:0.9rem;">
    Add a spending record with category, amount and date.
</p>

<div class="card" id="manual-expense-box">
    <form method="POST" action="{{ route('expenses.store') }}">
        @csrf
        <div class="form-group">
            <label for="category_id">Category</label>
            <select id="category_id" name="category_id" required>
                <option value="">Select</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat['id'] }}" {{ old('category_id') == $cat['id'] ? 'selected' : '' }}>{{ $cat['name'] }}</option>
                @endforeach
            </select>
            @error('category_id') <div class="text-danger">{{ $message }}</div> @enderror
        </div>
        <div class="form-group">
            <label for="amount">Amount</label>
            <div style="display:flex; align-items:stretch; gap:0.35rem;">
                <div style="display:flex; align-items:center; justify-content:center; padding:0 0.65rem; background:var(--surface2); border-radius:8px; border:1px solid var(--border2); font-size:0.9rem; color:var(--txt);">
                    {{ $currencySymbol }}
                </div>
                <input
                    type="number"
                    id="amount"
                    name="amount"
                    step="0.01"
                    min="0.01"
                    value="{{ old('amount') }}"
                    required
                >
            </div>
            @error('amount') <div class="text-danger">{{ $message }}</div> @enderror
        </div>
        <div class="form-group">
            <label for="expense_date">Expense Date</label>
            <input type="date" id="expense_date" name="expense_date"
                   max="{{ date('Y-m-d') }}"
                   value="{{ old('expense_date', date('Y-m-d')) }}" required>
            @error('expense_date') <div class="text-danger">{{ $message }}</div> @enderror
        </div>
        <div class="form-group">
            <label for="description">Description (optional)</label>
            <textarea id="description" name="description" rows="3">{{ old('description') }}</textarea>
            @error('description') <div class="text-danger">{{ $message }}</div> @enderror
        </div>
        <hr style="margin:1.05rem 0 1rem; border:0; border-top:1px solid var(--border2);">
        <div style="margin-bottom:0.85rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:0.75rem; margin-bottom:0.65rem; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:0.65rem; flex-wrap:wrap;">
                    <button type="submit" form="fixed-expense-form" class="btn btn-secondary">Add this month's fixed expenses</button>
                    <label style="display:flex; align-items:center; gap:0.45rem; cursor:default; margin:0;">
                        <input type="checkbox" id="is_fixed_expense" value="1" disabled {{ !empty($autoFixedChecked) ? 'checked' : '' }}>
                        <span style="font-size:0.86rem; color:var(--muted);">Added for this month</span>
                    </label>
                </div>
            </div>
            <div style="margin:0; color:var(--muted); font-size:0.85rem;">
                Fixed templates are managed from <a href="{{ route('profile.show') }}" style="color:var(--acc); font-weight:600; text-decoration:underline;">Settings</a>.
            </div>
        </div>
        <div style="display:flex; gap:0.5rem; margin-top:0.5rem;">
            <button type="submit" class="btn btn-primary">
                Save Expense
            </button>
            <a href="{{ route('expenses.index') }}" class="btn btn-secondary">
                Cancel
            </a>
        </div>
    </form>
    <form method="POST" action="{{ route('expenses.fixed-monthly.store') }}" style="margin:0;" id="fixed-expense-form">
        @csrf
    </form>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const fixedCheckbox = document.getElementById('is_fixed_expense');
    const fixedForm = document.getElementById('fixed-expense-form');
    if (!fixedCheckbox) return;

    if (fixedForm) {
        fixedForm.addEventListener('submit', () => {
            fixedCheckbox.checked = true;
        });
    }
})();
</script>
@endpush
