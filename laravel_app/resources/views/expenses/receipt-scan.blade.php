@extends('layouts.app')

@section('title', 'Receipt Scan')

@section('content')
<h1>Receipt Scan</h1>
<p style="margin-top:-0.35rem; margin-bottom:0.75rem; color:var(--muted); font-size:0.9rem;">
    Upload a receipt photo. OCR reads amount, date and category — you confirm before saving.
</p>

@include('partials.ai-insights', ['insights' => $aiInsights ?? []])

@php
    $confidence = $scan['confidence'] ?? 'low';
    $confidenceLabel = match($confidence) {
        'high' => 'High confidence',
        'medium' => 'Medium confidence',
        default => 'Low confidence — check all fields',
    };
    $hasScan = !empty($scan);
@endphp

<div class="receipt-scan-layout">
    <div class="receipt-scan-col">
        <div class="card receipt-scan-card">
            <div class="receipt-scan-card__inner">
                <label class="receipt-scan-card__label" for="receipt">Receipt image</label>

                @if($hasScan && !empty($receiptPreview['data']))
                <div class="receipt-scan-form__main">
                    <div id="receipt-preview-wrap" style="margin-top:0.35rem;">
                        <img
                            id="receipt-preview"
                            src="data:{{ $receiptPreview['mime'] ?? 'image/jpeg' }};base64,{{ $receiptPreview['data'] }}"
                            alt="Scanned receipt"
                            style="max-width:100%; max-height:320px; border-radius:12px; border:1px solid var(--border2); display:block;"
                        >
                    </div>
                </div>
                <form method="POST" action="{{ route('expenses.receipt-scan.discard') }}" class="receipt-scan-card__footer">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Scan another receipt</button>
                </form>
                @else
                <form method="POST" action="{{ route('expenses.receipt-scan.store') }}" enctype="multipart/form-data" id="receipt-scan-form" class="receipt-scan-form">
                    @csrf
                    <div class="receipt-scan-form__main">
                    <input
                        type="file"
                        id="receipt"
                        name="receipt"
                        accept="image/jpeg,image/jpg,image/png,image/webp"
                        required
                        class="receipt-scan-file-input"
                    >
                    <input
                        type="file"
                        id="receipt-picker"
                        accept="image/jpeg,image/jpg,image/png,image/webp"
                        class="receipt-scan-file-input"
                    >
                    <div class="receipt-scan-upload-area">
                        <div class="receipt-scan-upload">
                            <div
                                class="receipt-dropzone"
                                id="receipt-dropzone"
                                role="button"
                                tabindex="0"
                                aria-label="Drag and drop or choose an image from your device"
                            >
                                <div class="receipt-dropzone__icon" aria-hidden="true">
                                    <svg class="receipt-dropzone__svg" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M24 8v20M14 18l10-10 10 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M10 32h28" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                </div>
                                <p class="receipt-dropzone__title">Upload image</p>
                                <p class="receipt-dropzone__subtitle">Drag & drop or choose a file</p>
                                <span class="receipt-dropzone__btn">Choose file</span>
                            </div>
                        </div>

                        <p class="receipt-scan-formats">
                            <strong>Supported formats:</strong> JPG, JPEG, PNG, WEBP
                            <span class="receipt-scan-formats__sep">·</span>
                            max 5 MB
                        </p>
                        <p class="receipt-dropzone__filename" id="receipt-filename" hidden></p>

                        @error('receipt') <div class="text-danger receipt-scan-upload-area__error">{{ $message }}</div> @enderror

                        <div id="receipt-preview-wrap" class="receipt-scan-preview" style="display:none;">
                            <p class="receipt-scan-preview__label">Preview</p>
                            <img id="receipt-preview" alt="" class="receipt-scan-preview__img">
                        </div>
                    </div>
                    </div>

                    <div class="receipt-scan-card__footer">
                        <button type="submit" class="btn btn-primary">Scan receipt</button>
                        <button type="button" class="btn btn-secondary" id="receipt-cancel-btn">Cancel</button>
                    </div>

                </form>
                @endif
            </div>
        </div>
    </div>

    <div class="receipt-scan-col">
        <div class="card receipt-scan-card">
            <div class="receipt-scan-card__inner">
            <div class="receipt-scan-card__header">
                <p class="receipt-scan-card__heading">Detected from receipt</p>
                @if($hasScan)
                <span class="receipt-scan-confidence">{{ $confidenceLabel }}</span>
                @endif
            </div>

            @if(!$hasScan)
            <div class="receipt-scan-placeholder" id="receipt-detected-placeholder">
                <div class="receipt-scan-placeholder__fields">
                <div class="form-group">
                    <label for="category_id_placeholder">Category</label>
                    <select id="category_id_placeholder" class="select-control" disabled>
                        <option value="">Select</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat['category_id'] }}">{{ $cat['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="amount_placeholder">Amount</label>
                    <div style="display:flex; align-items:stretch; gap:0.35rem;">
                        <div style="display:flex; align-items:center; justify-content:center; padding:0 0.65rem; background:var(--surface2); border-radius:8px; border:1px solid var(--border2); font-size:0.9rem; color:var(--txt);">
                            {{ $currencySymbol ?? '₺' }}
                        </div>
                        <input type="number" id="amount_placeholder" disabled placeholder="—">
                    </div>
                </div>
                <div class="form-group">
                    <label for="expense_date_placeholder">Expense date</label>
                    <input type="text" id="expense_date_placeholder" class="select-control" disabled placeholder="—" style="width:100%;">
                </div>
                <div class="form-group">
                    <label for="description_placeholder">Description / merchant</label>
                    <textarea id="description_placeholder" rows="2" disabled placeholder="Optional"></textarea>
                </div>
                </div>

                <div class="receipt-scan-card__footer">
                    <button type="button" class="btn btn-primary" disabled>Save expense</button>
                </div>
            </div>
            @else
            <form method="POST" action="{{ route('expenses.receipt-scan.confirm') }}" class="receipt-scan-form">
                @csrf
                <div class="receipt-scan-form__main">
                <div class="form-group">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" class="select-control select-enhanced" required>
                        <option value="">Select</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat['category_id'] }}" {{ (string) old('category_id', $scan['category_id'] ?? '') === (string) $cat['category_id'] ? 'selected' : '' }}>{{ $cat['name'] }}</option>
                        @endforeach
                    </select>
                    @if(!empty($scan['category_source']) && ($scan['category_source'] ?? '') === 'memory')
                        <p style="margin:0.35rem 0 0; font-size:0.78rem; color:var(--muted);">
                            Category suggested from your saved receipt history.
                        </p>
                    @elseif(!empty($scan['category_name']) && empty($scan['category_id']))
                        <p style="margin:0.35rem 0 0; font-size:0.78rem; color:var(--muted);">
                            Detected “{{ $scan['category_name'] }}” but no matching category — please pick one (it will be remembered next time).
                        </p>
                    @endif
                    @error('category_id') <div class="text-danger">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label for="amount">Amount</label>
                    <div style="display:flex; align-items:stretch; gap:0.35rem;">
                        <div style="display:flex; align-items:center; justify-content:center; padding:0 0.65rem; background:var(--surface2); border-radius:8px; border:1px solid var(--border2); font-size:0.9rem; color:var(--txt);">
                            {{ $currencySymbol ?? '₺' }}
                        </div>
                        <input type="number" id="amount" name="amount" step="0.01" min="0.01"
                               value="{{ old('amount', $scan['amount'] ?? '') }}" required>
                    </div>
                    @error('amount') <div class="text-danger">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label for="expense_date">Expense date</label>
                    @include('partials.date-input', [
                        'id' => 'expense_date',
                        'name' => 'expense_date',
                        'value' => old('expense_date', $scan['expense_date'] ?? date('Y-m-d')),
                        'max' => date('Y-m-d'),
                        'required' => true,
                    ])
                    @error('expense_date') <div class="text-danger">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label for="description">Description / merchant</label>
                    <textarea id="description" name="description" rows="2" placeholder="Optional">{{ old('description', $scan['description'] ?? '') }}</textarea>
                    @if(!empty($scan['description']) && !empty($scan['description_source']))
                        <p style="margin:0.35rem 0 0; font-size:0.78rem; color:var(--muted);">
                            Suggested from receipt ({{ $scan['description_source'] === 'ai' ? 'AI' : 'OCR' }}). You can edit before saving.
                        </p>
                    @endif
                    @error('description') <div class="text-danger">{{ $message }}</div> @enderror
                </div>
                </div>

                <div class="receipt-scan-card__footer">
                    <button type="submit" class="btn btn-primary">Save expense</button>
                </div>
            </form>

            @if(!empty($scan['raw_text']))
            <details style="margin-top:1rem; padding:0.75rem 0 0; border-top:1px solid var(--border2);">
                <summary style="cursor:pointer; font-weight:600; color:var(--txt2);">OCR raw text</summary>
                <pre style="margin:0.75rem 0 0; white-space:pre-wrap; font-size:0.78rem; color:var(--muted); max-height:200px; overflow:auto;">{{ $scan['raw_text'] }}</pre>
            </details>
            @endif
            @endif
            </div>
        </div>
    </div>
</div>

@endsection

@push('styles')
<style>
    .receipt-scan-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 1.25rem;
        align-items: stretch;
    }
    .receipt-scan-col {
        min-width: 0;
        display: flex;
        flex-direction: column;
    }
    .receipt-scan-card {
        margin-bottom: 0;
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 100%;
    }
    .receipt-scan-card__inner {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
    .receipt-scan-card__label {
        display: block;
        margin: 0 0 0.5rem;
        font-weight: 600;
        color: var(--txt);
    }
    .receipt-scan-card__header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
        flex-shrink: 0;
    }
    .receipt-scan-card__heading {
        margin: 0;
        font-weight: 600;
        color: var(--txt);
    }
    .receipt-scan-confidence {
        font-size: 0.8rem;
        padding: 0.25rem 0.6rem;
        border-radius: 999px;
        background: var(--surface2);
        color: var(--txt2);
        white-space: nowrap;
    }
    .receipt-scan-form {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
    .receipt-scan-form__main {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
        gap: 0.5rem;
    }
    .receipt-scan-upload-area {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: stretch;
        min-height: 0;
        gap: 0.65rem;
    }
    .receipt-scan-upload {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
    .receipt-scan-placeholder {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
    .receipt-scan-placeholder__fields {
        flex: 1;
    }
    .receipt-scan-card__footer {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: auto;
        padding-top: 1rem;
        flex-shrink: 0;
        width: 100%;
    }
    .receipt-scan-form .receipt-scan-card__footer .btn {
        flex: 1 1 8rem;
        min-width: 0;
        justify-content: center;
    }
    .receipt-scan-file-input {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
    .receipt-dropzone {
        display: flex;
        flex: 1;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        width: 100%;
        min-height: 10rem;
        height: 100%;
        padding: 1.25rem 1rem;
        border-radius: 16px;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
        user-select: none;
        border: 2px dashed rgba(37, 99, 235, 0.28);
        background: linear-gradient(165deg, rgba(37, 99, 235, 0.07) 0%, var(--surface2) 48%, var(--surface) 100%);
    }
    .receipt-dropzone:hover,
    .receipt-dropzone:focus-visible {
        border-color: var(--acc);
        background: linear-gradient(165deg, rgba(37, 99, 235, 0.12) 0%, var(--acc-light) 55%, var(--surface) 100%);
        box-shadow: 0 8px 28px var(--acc-glow);
        outline: none;
    }
    .receipt-dropzone.is-dragover {
        border-color: var(--acc);
        border-style: solid;
        background: var(--acc-light);
        box-shadow: 0 0 0 4px var(--acc-glow);
        transform: scale(1.01);
    }
    .receipt-dropzone.is-has-file {
        border-style: solid;
        border-color: rgba(22, 163, 74, 0.45);
        background: linear-gradient(165deg, rgba(22, 163, 74, 0.08) 0%, var(--surface2) 100%);
    }
    .receipt-dropzone.is-has-file .receipt-dropzone__icon {
        background: rgba(22, 163, 74, 0.12);
        color: #16a34a;
    }
    .receipt-dropzone__icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 3.5rem;
        height: 3.5rem;
        margin-bottom: 0.35rem;
        border-radius: 50%;
        background: var(--acc-light);
        color: var(--acc);
        transition: background 0.2s ease, color 0.2s ease;
    }
    .receipt-dropzone__svg {
        width: 2.35rem;
        height: 2.35rem;
    }
    .receipt-dropzone__title {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        color: var(--txt);
        line-height: 1.35;
    }
    .receipt-dropzone__subtitle {
        margin: 0;
        font-size: 0.85rem;
        font-weight: 500;
        color: var(--txt2);
        line-height: 1.4;
    }
    .receipt-dropzone__btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-top: 0.5rem;
        padding: 0.5rem 1.15rem;
        font-size: 0.88rem;
        font-weight: 600;
        color: #fff;
        background: linear-gradient(135deg, #1d4ed8, var(--acc2));
        border-radius: 999px;
        box-shadow: 0 2px 8px rgba(37, 99, 235, 0.35);
        pointer-events: none;
    }
    .receipt-dropzone__filename {
        margin: 0.5rem 0 0;
        max-width: 100%;
        font-size: 0.82rem;
        font-weight: 600;
        color: #16a34a;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        text-align: center;
    }
    .receipt-scan-formats {
        margin: 0;
        flex-shrink: 0;
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--muted);
        text-align: center;
        line-height: 1.45;
    }
    .receipt-scan-upload-area__error {
        margin: 0;
        text-align: center;
        font-size: 0.88rem;
    }
    .receipt-scan-preview {
        flex-shrink: 0;
    }
    .receipt-scan-preview__label {
        margin: 0 0 0.5rem;
        font-size: 0.85rem;
        color: var(--txt2);
        font-weight: 600;
    }
    .receipt-scan-preview__img {
        display: block;
        width: 100%;
        max-height: 220px;
        object-fit: contain;
        border-radius: 12px;
        border: 1px solid var(--border2);
    }
    .receipt-scan-formats strong {
        color: var(--txt2);
        font-weight: 600;
    }
    .receipt-scan-formats__sep {
        margin: 0 0.2rem;
    }
    .receipt-scan-placeholder input:disabled,
    .receipt-scan-placeholder select:disabled,
    .receipt-scan-placeholder textarea:disabled {
        opacity: 0.72;
        cursor: not-allowed;
        background: var(--surface2);
    }
    .receipt-scan-placeholder .btn-primary:disabled {
        opacity: 0.55;
        cursor: not-allowed;
    }
    @media (max-width: 900px) {
        .receipt-scan-layout {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush

@push('scripts')
<script>
(() => {
    const input = document.getElementById('receipt');
    const picker = document.getElementById('receipt-picker');
    const wrap = document.getElementById('receipt-preview-wrap');
    const img = document.getElementById('receipt-preview');
    const dropzone = document.getElementById('receipt-dropzone');
    const filenameEl = document.getElementById('receipt-filename');

    const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    const ALLOWED_EXT = /\.(jpe?g|png|webp)$/i;
    const MAX_BYTES = 5 * 1024 * 1024;

    if (!input || !picker) return;

    const isAllowedImage = (file) => {
        if (!file) return false;
        if (ALLOWED_TYPES.includes(file.type)) return true;
        return ALLOWED_EXT.test(file.name || '');
    };

    const showPreview = (file) => {
        if (!wrap || !img) return;
        if (!file) {
            wrap.style.display = 'none';
            img.removeAttribute('src');
            return;
        }
        img.src = URL.createObjectURL(file);
        wrap.style.display = 'block';
    };

    const setSourceFile = (file) => {
        const has = Boolean(file);
        dropzone?.classList.toggle('is-has-file', has);
        if (filenameEl) {
            if (has && file) {
                filenameEl.hidden = false;
                filenameEl.textContent = file.name;
            } else {
                filenameEl.hidden = true;
                filenameEl.textContent = '';
            }
        }
    };

    const toast = (msg, type) => {
        if (typeof window.appToast === 'function') {
            window.appToast(msg, type);
        }
    };

    const assignFile = (file) => {
        if (!file) return;
        if (!isAllowedImage(file)) {
            toast('Only JPG, JPEG, PNG and WEBP images are supported.', 'error');
            return;
        }
        if (file.size > MAX_BYTES) {
            toast('Image is too large (max 5 MB).', 'error');
            return;
        }
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        showPreview(file);
        setSourceFile(file);
    };

    const clearFile = () => {
        input.value = '';
        picker.value = '';
        showPreview(null);
        setSourceFile(null);
    };

    picker.addEventListener('change', () => {
        const file = picker.files && picker.files[0];
        if (file) assignFile(file);
    });

    const openPicker = () => picker.click();

    document.getElementById('receipt-cancel-btn')?.addEventListener('click', clearFile);

    dropzone?.addEventListener('click', openPicker);
    dropzone?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openPicker();
        }
    });

    if (dropzone) {
        ['dragenter', 'dragover'].forEach((evt) => {
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                dropzone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach((evt) => {
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                dropzone.classList.remove('is-dragover');
            });
        });
        dropzone.addEventListener('drop', (e) => {
            const file = e.dataTransfer?.files?.[0];
            if (file) assignFile(file);
        });
    }
})();
</script>
@endpush
