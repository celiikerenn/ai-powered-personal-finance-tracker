@extends('layouts.app')

@section('title', 'Dashboard')

@push('styles')
<style>
    .budget-usage-head {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 0.75rem;
        margin-bottom: 0.45rem;
        flex-wrap: wrap;
    }
    .budget-usage-head .budget-usage-title {
        font-size: 0.72rem;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.07em;
        font-weight: 600;
    }
    #budget-usage-label.budget-usage-pct {
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--acc);
        letter-spacing: -0.03em;
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }
</style>
@endpush

@section('content')
@php
    $welcomeParts = preg_split('/\s+/u', trim($userName ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $welcomeFirstName = $welcomeParts[0] ?? '';
@endphp
<h1>Dashboard</h1>
<p>Welcome, <strong>{{ $welcomeFirstName !== '' ? $welcomeFirstName : 'User' }}</strong>.</p>

<div class="card" style="display:flex; flex-wrap:wrap; gap:1.5rem; align-items:flex-start;">
    <div style="flex:1 1 260px;">
        @php
            $monthlyBudget = (float) session('monthly_budget', 0);
            $spent = (float)($monthly['total_amount'] ?? 0);
            $usagePercent = $monthlyBudget > 0 ? ($spent / $monthlyBudget * 100) : 0;
            $usageRounded = $monthlyBudget > 0 ? min(200, round($usagePercent)) : 0;
        @endphp

        <h2 style="margin-top: 0; margin-bottom:0.75rem;">
            This Month Summary ({{ $currentYear }}-{{ str_pad($currentMonth, 2, '0', STR_PAD_LEFT) }})
        </h2>
        @if(!empty($monthly))
            <div style="display:flex; flex-wrap:wrap; gap:0.75rem; margin-bottom:1rem;">
                <div style="flex:1 1 0; min-width:150px;">
                    <div style="background:var(--surface); border-radius:16px; border:1px solid var(--border); padding:14px; text-align:center; height:100%; display:flex; flex-direction:column; justify-content:center; box-shadow:none !important; filter:none !important;">
                            <div class="section-label" style="margin-bottom:0.15rem;">
                                Total budget
                            </div>
                            <div class="number-value stat-countup" data-value="{{ $monthlyBudget }}" data-currency="1" style="font-size:24px; font-weight:600;">
                                @if($monthlyBudget > 0)
                                    {{ number_format($monthlyBudget, 2, ',', '.') }} {{ $currencySymbol }}
                                @else
                                    N/A
                                @endif
                            </div>
                            <p style="margin:0.35rem 0 0; font-size:12px; color:var(--muted);">
                                <a href="{{ route('profile.preferences') }}#monthly-budget" style="color:var(--acc); font-weight:600; text-decoration:none;">Configure in Settings</a>
                            </p>
                        </div>
                </div>
                <div style="flex:1 1 0; min-width:150px;">
                    <div style="background:var(--surface); border-radius:16px; border:1px solid var(--border); padding:14px; text-align:center; height:100%; display:flex; flex-direction:column; justify-content:center; box-shadow:none !important; filter:none !important;">
                        <div class="section-label" style="margin-bottom:0.15rem;">
                            Total spent
                        </div>
                        <div class="number-value stat-countup" data-value="{{ $spent }}" data-currency="1" style="font-size:24px; font-weight:600;">
                            {{ number_format($spent, 2, ',', '.') }} {{ $currencySymbol }}
                        </div>
                    </div>
                </div>
                <div style="flex:1 1 0; min-width:150px;">
                    <div style="background:var(--surface); border-radius:16px; border:1px solid var(--border); padding:14px; text-align:center; height:100%; display:flex; flex-direction:column; justify-content:center; box-shadow:none !important; filter:none !important;">
                        <div class="section-label" style="margin-bottom:0.15rem;">
                            Expenses
                        </div>
                        <div class="number-value stat-countup" data-value="{{ (int)($monthly['expense_count'] ?? 0) }}" style="font-size:24px; font-weight:600;">
                            {{ $monthly['expense_count'] ?? 0 }}
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-bottom:0.9rem;">
                @if($monthlyBudget > 0)
                    <div class="budget-usage-head">
                        <span class="budget-usage-title">Budget usage</span>
                        <span class="number-value budget-usage-pct" id="budget-usage-label">0%</span>
                    </div>
                    <div class="budget-bar {{ $usagePercent > 100 ? 'budget-bar--over' : ($usagePercent > 80 ? 'budget-bar--warn' : '') }}" id="budget-bar">
                        <div class="budget-bar-fill" id="budget-bar-fill"
                             data-target="{{ min(100, max(0, $usagePercent)) }}"
                             data-raw-percent="{{ number_format($usagePercent, 4, '.', '') }}"></div>
                    </div>
                    @if($usagePercent > 100)
                        <p style="margin-top:0.45rem; font-size:0.9rem; color:var(--red); font-weight:600;">
                            You have exceeded your monthly budget!
                        </p>
                    @elseif($usagePercent > 80)
                        <p style="margin-top:0.45rem; font-size:0.9rem; color:#fbbf24; font-weight:600;">
                            You have used over 80% of your budget.
                        </p>
                    @endif
                @else
                    <p style="margin-top:0.45rem; font-size:0.9rem; color:var(--muted);">
                        No monthly budget limit is set.
                    </p>
                @endif
            </div>
        @else
            <p>No expenses yet for this month, or the API is not reachable.</p>
        @endif
    </div>
</div>

@if(!empty($recentMonths))
    <div class="card">
        <h2 style="margin-top:0; margin-bottom:0.75rem;">Recent Months</h2>
        <div style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:0.75rem;">
            @foreach($recentMonths as $m)
                <div>
                    <div style="background:var(--surface2); border-radius:0.9rem; padding:0.6rem 0.75rem; border:1px solid var(--border);">
                        <div style="font-weight:600; font-size:0.9rem; margin-bottom:0.25rem; color:var(--txt);">
                            {{ $m['year'] }}-{{ str_pad($m['month'], 2, '0', STR_PAD_LEFT) }}
                        </div>
                        <div style="font-size:0.85rem; color:var(--muted); margin-bottom:0.15rem;">
                            Total spent
                        </div>
                        <div class="number-value" style="font-size:22px; font-weight:600; margin-bottom:0.35rem;">
                            {{ number_format($m['total'], 2, ',', '.') }} {{ $currencySymbol }}
                        </div>
                        <div style="font-size:0.8rem; color:var(--muted);">
                            {{ $m['count'] }} expenses
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        @if(($monthTotalPages ?? 1) > 1)
            @php
                $current = $monthPage ?? 1;
                $last = $monthTotalPages ?? 1;
            @endphp
            <nav class="app-pagination" aria-label="Recent months pagination">
                <p class="app-pagination__info">
                    Page {{ $current }} of {{ $last }} · {{ $monthTotalCount ?? 0 }} months total
                </p>
                <ul class="app-pagination__links">
                    @if($current > 1)
                        <li>
                            <a href="{{ route('dashboard', ['m_page' => $current - 1]) }}"
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
                                <a href="{{ route('dashboard', ['m_page' => $p]) }}"
                                   class="btn btn-secondary"
                                   style="padding:0.25rem 0.6rem; font-size:0.85rem; border-radius:999px;">
                                    {{ $p }}
                                </a>
                            @endif
                        </li>
                    @endfor
                    @if($current < $last)
                        <li>
                            <a href="{{ route('dashboard', ['m_page' => $current + 1]) }}"
                               class="btn btn-secondary"
                               style="padding:0.25rem 0.6rem; font-size:0.85rem; border-radius:999px;">
                                Next ›
                            </a>
                        </li>
                    @endif
                </ul>
            </nav>
        @endif
    </div>
@endif
@endsection
@push('scripts')
<script>
(() => {
    const easeOutQuart = (t) => 1 - Math.pow(1 - t, 4);
    const countNodes = document.querySelectorAll(".stat-countup");
    const cur = document.body.dataset.currencySymbol || "₺";
    if (countNodes.length) {
        const start = performance.now();
        const duration = 900;
        function step(now) {
            const p = Math.min((now - start) / duration, 1);
            const eased = easeOutQuart(p);
            countNodes.forEach((node) => {
                const target = Number(node.dataset.value || 0);
                const value = target * eased;
                if (node.dataset.currency === "1") {
                    node.textContent = `${value.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${cur}`;
                } else {
                    node.textContent = Math.round(value).toLocaleString('tr-TR');
                }
            });
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    const fill = document.getElementById("budget-bar-fill");
    const label = document.getElementById("budget-usage-label");
    if (fill && label) {
        const rawPercent = Number(fill.dataset.rawPercent ?? fill.dataset.target ?? 0);
        const widthPct = Math.max(0, Math.min(100, Number(fill.dataset.target ?? 0)));
        /* Normal: mavi. %80+ uyari: koyu turuncu → amber. %100+ asim: bordo → kirmizi */
        let background;
        let barGlow = "none";
        if (rawPercent > 100) {
            background = "linear-gradient(90deg, #7f1d1d 0%, #b91c1c 35%, #ef4444 70%, #fca5a5 100%)";
            barGlow = "0 0 14px rgba(239, 68, 68, 0.45)";
        } else if (rawPercent > 80) {
            background = "linear-gradient(90deg, #7c2d12 0%, #c2410c 40%, #ea580c 72%, #fbbf24 100%)";
            barGlow = "0 0 12px rgba(234, 88, 12, 0.42)";
        } else {
            background = "linear-gradient(90deg, #1e40af, #93c5fd)";
        }
        fill.style.background = background;
        fill.style.boxShadow = barGlow;
        if (rawPercent > 100) {
            label.style.color = "var(--red)";
            label.style.fontWeight = "700";
        } else if (rawPercent > 80) {
            label.style.color = "#d97706";
            label.style.fontWeight = "700";
        } else {
            label.style.color = "";
            label.style.fontWeight = "";
        }

        requestAnimationFrame(() => { fill.style.width = `${widthPct}%`; });

        const labelTarget = Math.min(400, Math.max(0, rawPercent));
        const start = performance.now();
        const duration = 900;
        function step(now) {
            const p = Math.min((now - start) / duration, 1);
            const eased = easeOutQuart(p);
            const current = labelTarget * eased;
            label.textContent = `${current.toLocaleString('tr-TR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
        if (rawPercent > 80 && window.appToast) {
            window.appToast("Budget usage exceeded 80%.", "warning");
        }
    }

    document.querySelectorAll(".weekly-activity__bar").forEach((bar) => {
        const target = bar.dataset.targetHeight || "0";
        bar.style.height = "0%";
        requestAnimationFrame(() => {
            requestAnimationFrame(() => { bar.style.height = `${target}%`; });
        });
    });
})();
</script>
@endpush


