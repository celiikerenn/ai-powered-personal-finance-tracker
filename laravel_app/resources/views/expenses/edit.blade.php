@extends('layouts.app')

@section('title', 'Edit Expense')

@section('content')
<h1>Edit Expense</h1>
<div class="card">
    <form method="POST" action="{{ route('expenses.update', $expense['expense_id']) }}">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label for="category_id">Category</label>
            <select id="category_id" name="category_id" class="select-control select-enhanced" required>
                <option value="">Select</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat['category_id'] }}"
                        {{ old('category_id', $expense['category_id']) == $cat['category_id'] ? 'selected' : '' }}>
                        {{ $cat['name'] }}
                    </option>
                @endforeach
            </select>
            @error('category_id') <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label for="amount">Amount ({{ $currencySymbol }})</label>
            <input type="number" id="amount" name="amount" step="0.01" min="0.01"
                   value="{{ old('amount', $expense['amount']) }}" required>
            @error('amount') <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label for="expense_date">Expense Date</label>
            @include('partials.date-input', [
                'id' => 'expense_date',
                'name' => 'expense_date',
                'value' => old('expense_date', $expense['expense_date']),
                'max' => date('Y-m-d'),
                'required' => true,
            ])
            @error('expense_date') <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label for="description">Description (optional)</label>
            <textarea id="description" name="description" rows="3">{{ old('description', $expense['description'] ?? '') }}</textarea>
            @error('description') <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        @if(!empty($expense['receipt_image_path']))
            <div class="form-group">
                <label>Receipt image</label>
                <div style="border:1px solid var(--border2); border-radius:12px; padding:0.75rem; background:var(--surface2); max-width:320px;">
                    <img
                        src="{{ asset('storage/'.$expense['receipt_image_path']) }}"
                        alt="Receipt for this expense"
                        style="max-width:100%; max-height:280px; border-radius:8px; display:block;"
                    >
                </div>
            </div>
        @endif

        <button type="submit" class="btn btn-primary">Update</button>
        <a href="{{ route('expenses.index') }}" class="btn btn-secondary">Back</a>
    </form>
</div>
@endsection

