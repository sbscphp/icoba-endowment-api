<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Daily digest — {{ $report['report_date'] }}</title>
    @php
        $ngn = fn ($v) => '₦'.number_format((float) $v, 2);
        $ngn0 = fn ($v) => '₦'.number_format((float) $v, 0);
        $pct = fn ($v) => $v === null ? 'n/a' : number_format((float) $v, 1).'%';
        $summary = $report['summary'];
        $overdue = $report['overdue'];
        $daily = $report['trend']['daily'];
        $monthly = $report['trend']['monthly'];
        $dirClass = fn (string $d) => $d === 'increase' ? 'up' : ($d === 'decrease' ? 'down' : 'flat');
        $dirSign = fn (string $d) => $d === 'increase' ? '▲' : ($d === 'decrease' ? '▼' : '•');
        $dash = fn ($v) => ($v === null || $v === '') ? '—' : $v;

        // Bar chart: built as an SVG document and embedded as an <img> data URI,
        // which dompdf renders reliably (inline <svg> is not supported).
        $chartW = 540; $chartH = 150; $padL = 8; $padB = 26; $padT = 12;
        $maxDaily = max(1.0, (float) max(array_column($daily, 'amount_numeric') ?: [0]));
        $n = max(1, count($daily));
        $slot = ($chartW - $padL * 2) / $n;
        $barW = max(4, $slot * 0.6);
        $plotH = $chartH - $padB - $padT;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$chartW.'" height="'.$chartH.'" viewBox="0 0 '.$chartW.' '.$chartH.'">';
        $svg .= '<rect x="0" y="0" width="'.$chartW.'" height="'.$chartH.'" fill="#ffffff"/>';
        $svg .= '<line x1="'.$padL.'" y1="'.($chartH - $padB).'" x2="'.($chartW - $padL).'" y2="'.($chartH - $padB).'" stroke="#9ca3af" stroke-width="1"/>';
        foreach ($daily as $i => $day) {
            $h = $day['amount_numeric'] > 0 ? max(1.5, ($day['amount_numeric'] / $maxDaily) * $plotH) : 0;
            $x = $padL + $i * $slot + ($slot - $barW) / 2;
            $y = $chartH - $padB - $h;
            $cx = round($x + $barW / 2, 1);
            $fill = $i === count($daily) - 1 ? '#111827' : '#4f46e5';
            if ($h > 0) {
                $svg .= '<rect x="'.round($x, 1).'" y="'.round($y, 1).'" width="'.round($barW, 1).'" height="'.round($h, 1).'" rx="1.5" fill="'.$fill.'"/>';
                $svg .= '<text x="'.$cx.'" y="'.round(max($padT - 2, $y - 3), 1).'" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="6.5" fill="#374151">'.number_format($day['amount_numeric'] / 1000, 0).'k</text>';
            }
            $svg .= '<text x="'.$cx.'" y="'.($chartH - $padB + 10).'" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="6.5" fill="#6b7280">'.\Carbon\Carbon::parse($day['date'])->format('d').'</text>';
            $svg .= '<text x="'.$cx.'" y="'.($chartH - $padB + 19).'" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="6.5" fill="#6b7280">'.\Carbon\Carbon::parse($day['date'])->format('D').'</text>';
        }
        $svg .= '</svg>';
        $chartSrc = 'data:image/svg+xml;base64,'.base64_encode($svg);
    @endphp
    <style>
        * { box-sizing: border-box; }
        @page { margin: 34px 30px 44px 30px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; color: #1f2937; margin: 0; }
        h1 { font-size: 17px; margin: 0; color: #111827; }
        h2 { font-size: 12px; margin: 18px 0 6px; color: #111827; border-bottom: 2px solid #111827; padding-bottom: 3px; }
        h3 { font-size: 9.5px; margin: 12px 0 4px; color: #374151; }
        p { margin: 0 0 6px; line-height: 1.45; }
        .muted { color: #6b7280; }
        .small { font-size: 7.5px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .nowrap { white-space: nowrap; }
        .up { color: #15803d; }
        .down { color: #b91c1c; }
        .flat { color: #6b7280; }
        .bad { color: #b91c1c; font-weight: bold; }
        .tag { display: inline-block; padding: 1px 5px; border-radius: 6px; font-size: 7px; font-weight: bold; }
        .tag-warn { background: #fef3c7; color: #92400e; }
        .tag-info { background: #e0e7ff; color: #3730a3; }
        .tag-muted { background: #f3f4f6; color: #374151; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .logo { height: 28px; }
        .kpis { table-layout: fixed; page-break-inside: avoid; }
        .kpis td { width: 25%; padding: 4px; vertical-align: top; }
        .kpi { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 9px; background: #f9fafb; min-height: 44px; }
        .kpi .label { font-size: 7px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; }
        .kpi .value { font-size: 13px; font-weight: bold; color: #111827; margin-top: 3px; }
        .kpi .sub { font-size: 7px; color: #6b7280; margin-top: 2px; }
        .kpi.alert { background: #fef2f2; border-color: #fecaca; }
        .kpi.alert .value { color: #b91c1c; }
        .grid th, .grid td { border: 1px solid #e5e7eb; padding: 4px 5px; vertical-align: top; }
        .grid th { background: #111827; color: #fff; font-size: 7.5px; text-align: left; }
        .grid tbody tr:nth-child(even) td { background: #f9fafb; }
        .grid tfoot td { font-weight: bold; background: #eef2ff; }
        .donor-block { border: 1px solid #d1d5db; border-radius: 8px; margin: 0 0 8px; page-break-inside: avoid; }
        .donor-head td { padding: 6px 8px; background: #f3f4f6; border-bottom: 1px solid #d1d5db; }
        .donor-head .name { font-size: 10px; font-weight: bold; color: #111827; }
        .donor-head .contact { font-size: 8px; color: #374151; margin-top: 2px; }
        .donor-body { padding: 4px 6px 6px; }
        .donor-body .grid th { background: #4b5563; }
        .callout { border: 1px solid #f59e0b; background: #fffbeb; color: #92400e; border-radius: 8px; padding: 6px 8px; margin: 6px 0; }
        .ok { border: 1px solid #86efac; background: #f0fdf4; color: #166534; border-radius: 8px; padding: 6px 8px; margin: 6px 0; }
        .page-break { page-break-before: always; }
        .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 7px; color: #6b7280; }
        .footer .page:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="footer">
        <table><tr>
            <td>{{ config('app.name') }} · Daily digest for {{ $report['report_date'] }} · Confidential — contains donor contact details</td>
            <td class="right">Page <span class="page"></span></td>
        </tr></table>
    </div>

    {{-- ============================ COVER / SUMMARY ============================ --}}
    <table class="header">
        <tr>
            <td style="width: 55%;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" alt="{{ config('app.name') }}" class="logo">
                @else
                    <div style="font-size: 14px; font-weight: bold;">{{ config('app.name') }}</div>
                @endif
                <div class="muted small" style="margin-top: 4px;">{{ config('endowment.foundation_name') }}</div>
            </td>
            <td class="right">
                <h1>Daily Donations &amp; Pledges Digest</h1>
                <div class="muted">Report for <strong>{{ $report['report_date_label'] }}</strong></div>
                <div class="muted small">Generated {{ $report['generated_at']->format('Y-m-d H:i') }} ({{ $report['timezone'] }}) · All amounts in NGN unless stated</div>
            </td>
        </tr>
    </table>

    <h2>1. Headline summary</h2>
    <table class="kpis">
        <tr>
            <td><div class="kpi">
                <div class="label">Donations yesterday</div>
                <div class="value">{{ $ngn0($summary['donations_yesterday']['amount']) }}</div>
                <div class="sub">{{ $summary['donations_yesterday']['count'] }} donations · {{ $summary['donations_yesterday']['donors'] }} donors</div>
            </div></td>
            <td><div class="kpi">
                <div class="label">Month to date</div>
                <div class="value">{{ $ngn0($summary['donations_mtd']['amount']) }}</div>
                <div class="sub">{{ $summary['donations_mtd']['count'] }} donations · {{ $summary['donations_mtd']['donors'] }} donors</div>
            </div></td>
            <td><div class="kpi">
                <div class="label">Year to date</div>
                <div class="value">{{ $ngn0($summary['donations_ytd']['amount']) }}</div>
                <div class="sub">{{ $summary['donations_ytd']['count'] }} donations</div>
            </div></td>
            <td><div class="kpi">
                <div class="label">All time raised</div>
                <div class="value">{{ $ngn0($summary['donations_all_time']['amount']) }}</div>
                <div class="sub">{{ $summary['donations_all_time']['count'] }} donations</div>
            </div></td>
        </tr>
        <tr>
            <td><div class="kpi {{ (float) $overdue['totals']['amount_ngn'] > 0 ? 'alert' : '' }}">
                <div class="label">Overdue pledges</div>
                <div class="value">{{ $ngn0($overdue['totals']['amount_ngn']) }}</div>
                <div class="sub">{{ $overdue['totals']['donors'] }} donors · {{ $overdue['totals']['pledges'] }} pledges · {{ $overdue['totals']['installments'] }} installments</div>
            </div></td>
            <td><div class="kpi">
                <div class="label">Active pledges</div>
                <div class="value">{{ $summary['active_pledges']['count'] }}</div>
                <div class="sub">{{ $summary['active_pledges']['donors'] }} donors · {{ $ngn0($summary['active_pledges']['committed_ngn']) }} committed</div>
            </div></td>
            <td><div class="kpi">
                <div class="label">Pledge collection rate</div>
                <div class="value">{{ $pct($summary['active_pledges']['collection_rate']) }}</div>
                <div class="sub">{{ $ngn0($summary['active_pledges']['honored_ngn']) }} honored · {{ $ngn0($summary['active_pledges']['pending_ngn']) }} pending</div>
            </div></td>
            <td><div class="kpi">
                <div class="label">Pledge activity yesterday</div>
                <div class="value">{{ $summary['new_pledges_yesterday']['count'] }} new</div>
                <div class="sub">{{ $ngn0($summary['new_pledges_yesterday']['committed_ngn']) }} committed · {{ $summary['pledges_fulfilled_yesterday'] }} fulfilled · {{ $summary['paused_pledges'] }} paused</div>
            </div></td>
        </tr>
    </table>

    @if($summary['awaiting_bank_verification']['count'] > 0)
        <div class="callout">
            <strong>{{ $summary['awaiting_bank_verification']['count'] }} bank transfer(s)</strong> totalling {{ $ngn($summary['awaiting_bank_verification']['amount_ngn']) }} are still awaiting verification
            (oldest since {{ $summary['awaiting_bank_verification']['oldest_at'] }}). These are not counted in the donation figures above until reconciled.
        </div>
    @endif

    {{-- ============================ TREND ============================ --}}
    <h2>2. Donation trend</h2>
    <h3>Daily donations — last {{ count($daily) }} days (NGN)</h3>
    <img src="{{ $chartSrc }}" width="{{ $chartW }}" height="{{ $chartH }}" alt="Daily donations chart" style="display:block;">
    @if((float) max(array_column($daily, 'amount_numeric') ?: [0]) <= 0)
        <p class="muted small">No donations were recorded in this window.</p>
    @endif
    <p class="muted small">Bar labels are in thousands of naira. Dark bar is the report date. Daily average over the window: {{ $ngn($report['trend']['daily_average']) }}@if($report['trend']['peak_day']); best day: {{ $report['trend']['peak_day']['label'] }} ({{ $ngn($report['trend']['peak_day']['amount']) }})@endif.</p>

    <table>
        <tr>
            <td style="width: 58%; padding-right: 8px; vertical-align: top;">
                <h3>Daily detail</h3>
                <table class="grid">
                    <thead><tr><th>Date</th><th class="right">Donations</th><th class="right">Amount (NGN)</th></tr></thead>
                    <tbody>
                        @foreach(array_reverse($daily) as $day)
                            <tr><td>{{ \Carbon\Carbon::parse($day['date'])->format('D, j M Y') }}</td><td class="right">{{ $day['count'] }}</td><td class="right">{{ $ngn($day['amount']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
            <td style="width: 42%; vertical-align: top;">
                <h3>Monthly detail</h3>
                <table class="grid">
                    <thead><tr><th>Month</th><th class="right">Donations</th><th class="right">Amount (NGN)</th></tr></thead>
                    <tbody>
                        @foreach(array_reverse($monthly) as $m)
                            <tr><td>{{ $m['label'] }}@if($m['is_partial']) <span class="muted small">(to date)</span>@endif</td><td class="right">{{ $m['count'] }}</td><td class="right">{{ $ngn($m['amount']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    {{-- ============================ VARIANCE ============================ --}}
    <h2>3. Variance analysis</h2>
    <table class="grid">
        <thead>
            <tr><th>Comparison</th><th>Baseline</th><th class="right">Current</th><th class="right">Baseline</th><th class="right">Difference</th><th class="right">Change</th><th class="right">Count Δ</th></tr>
        </thead>
        <tbody>
            @foreach($report['variance'] as $v)
                <tr>
                    <td>{{ $v['label'] }}</td>
                    <td class="muted">{{ $v['comparison_label'] }}</td>
                    <td class="right">{{ $ngn($v['current']) }}</td>
                    <td class="right">{{ $ngn($v['previous']) }}</td>
                    <td class="right {{ $dirClass($v['direction']) }}">{{ $ngn($v['difference']) }}</td>
                    <td class="right {{ $dirClass($v['direction']) }}">{{ $dirSign($v['direction']) }} {{ $v['percent'] === null ? 'n/a' : number_format(abs($v['percent']), 1).'%' }}</td>
                    <td class="right {{ $v['count_difference'] > 0 ? 'up' : ($v['count_difference'] < 0 ? 'down' : 'flat') }}">{{ $v['count_difference'] > 0 ? '+' : '' }}{{ $v['count_difference'] }} ({{ $v['current_count'] }} vs {{ $v['previous_count'] }})</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted small">"n/a" means the baseline was zero, so a percentage change cannot be computed.</p>

    <h3>Pledge schedule variance by campaign (expected vs. honored)</h3>
    <p class="muted small">"Scheduled to date" is the sum of all installments that fell due on or before the report date across active pledges. "Gap" is how far behind schedule collections are; the collection rate is honored ÷ scheduled.</p>
    <table class="grid">
        <thead>
            <tr><th>Campaign</th><th class="right">Active pledges</th><th class="right">Committed</th><th class="right">Scheduled to date</th><th class="right">Honored</th><th class="right">Gap</th><th class="right">Collection</th><th class="right">Overdue</th></tr>
        </thead>
        <tbody>
            @forelse($report['campaigns'] as $c)
                @if($c['active_pledges'] > 0)
                <tr>
                    <td>{{ $c['name'] }}</td>
                    <td class="right">{{ $c['active_pledges'] }}@if($c['paused_pledges']) <span class="muted small">({{ $c['paused_pledges'] }} paused)</span>@endif</td>
                    <td class="right">{{ $ngn($c['pledged_committed_ngn']) }}</td>
                    <td class="right">{{ $ngn($c['scheduled_to_date_ngn']) }}</td>
                    <td class="right">{{ $ngn($c['pledged_honored_ngn']) }}</td>
                    <td class="right {{ (float) $c['schedule_gap_ngn'] > 0 ? 'down' : 'up' }}">{{ $ngn($c['schedule_gap_ngn']) }}</td>
                    <td class="right">{{ $pct($c['collection_rate']) }}</td>
                    <td class="right {{ (float) $c['overdue_ngn'] > 0 ? 'bad' : '' }}">{{ $ngn($c['overdue_ngn']) }} <span class="muted small">({{ $c['overdue_pledges'] }})</span></td>
                </tr>
                @endif
            @empty
                <tr><td colspan="8" class="muted center">No campaigns with active pledges.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================ CAMPAIGNS ============================ --}}
    <h2>4. Campaign performance</h2>
    <table class="grid">
        <thead>
            <tr><th>Campaign</th><th>Status</th><th class="right">Target</th><th class="right">Raised</th><th class="right">Progress</th><th class="right">To target</th><th class="right">Yesterday</th><th class="right">Month to date</th><th class="right">Pledged outstanding</th><th>Ends</th></tr>
        </thead>
        <tbody>
            @forelse($report['campaigns'] as $c)
                <tr>
                    <td>{{ $c['name'] }}<br><span class="muted small">{{ $c['code'] }}</span></td>
                    <td><span class="tag {{ $c['status'] === 'active' ? 'tag-info' : 'tag-muted' }}">{{ ucfirst($c['status']) }}</span></td>
                    <td class="right">{{ (float) $c['target_ngn'] > 0 ? $ngn0($c['target_ngn']) : '—' }}</td>
                    <td class="right">{{ $ngn0($c['raised_ngn']) }}<br><span class="muted small">{{ $c['donations_count'] }} donations</span></td>
                    <td class="right">{{ $pct($c['progress_percent']) }}</td>
                    <td class="right">{{ $c['remaining_to_target_ngn'] !== null ? $ngn0($c['remaining_to_target_ngn']) : '—' }}</td>
                    <td class="right">{{ $ngn0($c['yesterday_ngn']) }}<br><span class="muted small">{{ $c['yesterday_count'] }}</span></td>
                    <td class="right">{{ $ngn0($c['mtd_ngn']) }}<br><span class="muted small">{{ $c['mtd_count'] }}</span></td>
                    <td class="right">{{ $ngn0($c['pledged_pending_ngn']) }}</td>
                    <td class="nowrap">{{ $dash($c['end_date']) }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="muted center">No active campaigns.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================ BREAKDOWNS ============================ --}}
    <h2>5. Breakdowns (yesterday and month to date)</h2>
    <table>
        <tr>
            <td style="width: 50%; padding-right: 6px; vertical-align: top;">
                <h3>By payment method</h3>
                <table class="grid">
                    <thead><tr><th>Method</th><th class="right">Yesterday</th><th class="right">MTD</th></tr></thead>
                    <tbody>
                        @forelse($report['breakdowns']['payment_method'] as $r)
                            <tr><td>{{ $r['label'] }}</td><td class="right">{{ $ngn0($r['yesterday_amount']) }} <span class="muted small">({{ $r['yesterday_count'] }})</span></td><td class="right">{{ $ngn0($r['mtd_amount']) }} <span class="muted small">({{ $r['mtd_count'] }})</span></td></tr>
                        @empty
                            <tr><td colspan="3" class="muted center">No donations this month.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            <td style="width: 50%; vertical-align: top;">
                <h3>By donor type</h3>
                <table class="grid">
                    <thead><tr><th>Donor type</th><th class="right">Yesterday</th><th class="right">MTD</th></tr></thead>
                    <tbody>
                        @forelse($report['breakdowns']['donor_type'] as $r)
                            <tr><td>{{ $r['label'] }}</td><td class="right">{{ $ngn0($r['yesterday_amount']) }} <span class="muted small">({{ $r['yesterday_count'] }})</span></td><td class="right">{{ $ngn0($r['mtd_amount']) }} <span class="muted small">({{ $r['mtd_count'] }})</span></td></tr>
                        @empty
                            <tr><td colspan="3" class="muted center">No donations this month.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
        </tr>
    </table>
    <h3>By currency</h3>
    <table class="grid">
        <thead><tr><th>Currency</th><th class="right">Yesterday (native)</th><th class="right">Yesterday (NGN)</th><th class="right">MTD (native)</th><th class="right">MTD (NGN)</th></tr></thead>
        <tbody>
            @forelse($report['breakdowns']['currency'] as $r)
                <tr>
                    <td>{{ $r['label'] }}</td>
                    <td class="right">{{ number_format((float) $r['yesterday_native_amount'], 2) }} <span class="muted small">({{ $r['yesterday_count'] }})</span></td>
                    <td class="right">{{ $ngn0($r['yesterday_amount']) }}</td>
                    <td class="right">{{ number_format((float) $r['mtd_native_amount'], 2) }} <span class="muted small">({{ $r['mtd_count'] }})</span></td>
                    <td class="right">{{ $ngn0($r['mtd_amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted center">No donations this month.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================ OVERDUE FOLLOW-UP ============================ --}}
    <h2 class="page-break">6. Overdue pledges — follow-up list</h2>
    <p class="muted small">Overdue means an installment was due on or before {{ $report['report_date'] }} and still has a balance. Partially paid installments are included. Paused pledges are excluded here and listed in section 8. Sorted by overdue amount, largest first. "Anonymous" marks pledges hidden from public displays; contact details are shown here for internal follow-up only.</p>

    @if(empty($overdue['donors']))
        <div class="ok">No overdue pledge installments. Nothing to follow up today.</div>
    @else
        <table>
            <tr>
                <td style="width: 45%; padding-right: 8px; vertical-align: top;">
                    <h3>Aging of overdue balances</h3>
                    <table class="grid">
                        <thead><tr><th>Days overdue</th><th class="right">Installments</th><th class="right">Amount (NGN)</th><th class="right">Share</th></tr></thead>
                        <tbody>
                            @foreach($overdue['aging'] as $a)
                                <tr><td>{{ $a['bucket'] }}</td><td class="right">{{ $a['count'] }}</td><td class="right">{{ $ngn($a['amount']) }}</td><td class="right">{{ $pct($a['share']) }}</td></tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr><td>Total</td><td class="right">{{ $overdue['totals']['installments'] }}</td><td class="right">{{ $ngn($overdue['totals']['amount_ngn']) }}</td><td class="right">100%</td></tr></tfoot>
                    </table>
                </td>
                <td style="width: 55%; vertical-align: top;">
                    <h3>Summary</h3>
                    <table class="grid">
                        <tbody>
                            <tr><td>Donors to contact</td><td class="right"><strong>{{ $overdue['totals']['donors'] }}</strong></td></tr>
                            <tr><td>Pledges with overdue installments</td><td class="right"><strong>{{ $overdue['totals']['pledges'] }}</strong></td></tr>
                            <tr><td>Overdue installments</td><td class="right"><strong>{{ $overdue['totals']['installments'] }}</strong></td></tr>
                            <tr><td>Total overdue</td><td class="right bad">{{ $ngn($overdue['totals']['amount_ngn']) }}</td></tr>
                            <tr><td>Share of all outstanding pledge balances</td><td class="right">{{ $pct($overdue['totals']['share_of_pending']) }}</td></tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>

        @if(count($overdue['donors']) > $maxOverdueDonors)
            <div class="callout">Showing the top {{ $maxOverdueDonors }} of {{ count($overdue['donors']) }} donors by overdue amount. The attached CSV contains every overdue pledge.</div>
        @endif

        <h3>Donors ({{ count($overdue['donors']) }})</h3>
        @foreach(array_slice($overdue['donors'], 0, $maxOverdueDonors) as $index => $group)
            @php $d = $group['donor']; $t = $group['totals']; @endphp
            <div class="donor-block">
                <table class="donor-head">
                    <tr>
                        <td style="width: 60%;">
                            <div class="name">{{ $index + 1 }}. {{ $d['name'] }}
                                @if(!$d['is_registered']) <span class="tag tag-muted">Guest</span>@endif
                                @if($d['is_anonymous']) <span class="tag tag-warn">Anonymous</span>@endif
                            </div>
                            <div class="contact">
                                Email: <strong>{{ $dash($d['email']) }}</strong> &nbsp;·&nbsp; Phone: <strong>{{ $dash($d['phone']) }}</strong>
                                @if($d['donor_type'] || $d['graduation_set'])<br><span class="muted">{{ $dash($d['donor_type']) }}@if($d['graduation_set']) · Set: {{ $d['graduation_set'] }}@endif</span>@endif
                            </div>
                        </td>
                        <td class="right" style="width: 40%;">
                            <div class="bad" style="font-size: 11px;">Overdue {{ $ngn($t['overdue_ngn']) }}</div>
                            <div class="small muted">{{ $t['pledges'] }} pledge(s) · {{ $t['overdue_installments'] }} overdue installment(s) · up to {{ $t['max_days_overdue'] }} days late</div>
                            <div class="small muted">Committed {{ $ngn0($t['committed_ngn']) }} · Honored {{ $ngn0($t['honored_ngn']) }} · Pending {{ $ngn0($t['pending_ngn']) }}</div>
                        </td>
                    </tr>
                </table>
                <div class="donor-body">
                    <table class="grid">
                        <thead>
                            <tr><th>Campaign</th><th>Plan</th><th class="right">Committed</th><th class="right">Honored</th><th class="right">Pending</th><th class="right">Overdue</th><th class="center">Missed</th><th>First missed</th><th class="right">Days late</th><th>Last paid</th><th class="center">Reminders</th></tr>
                        </thead>
                        <tbody>
                            @foreach($group['pledges'] as $p)
                                <tr>
                                    <td>{{ $p['campaign'] }}@if($p['is_anonymous']) <span class="tag tag-warn">Anon</span>@endif</td>
                                    <td class="small">{{ str_replace('_', ' ', $p['payment_plan']) }}<br><span class="muted">{{ $p['installments_paid'] }}/{{ $p['installment_count'] }} paid</span></td>
                                    <td class="right">{{ $ngn($p['committed_ngn']) }}@if($p['currency'] !== 'NGN')<br><span class="muted small">{{ $p['currency'] }} {{ number_format((float) $p['committed'], 2) }}</span>@endif</td>
                                    <td class="right">{{ $ngn($p['honored_ngn']) }}</td>
                                    <td class="right">{{ $ngn($p['pending_ngn']) }}</td>
                                    <td class="right bad">{{ $ngn($p['overdue_ngn']) }}@if($p['currency'] !== 'NGN')<br><span class="muted small">{{ $p['currency'] }} {{ number_format((float) $p['overdue'], 2) }}</span>@endif</td>
                                    <td class="center">{{ $p['overdue_installments'] }}</td>
                                    <td class="nowrap">{{ $p['earliest_due_date'] }}</td>
                                    <td class="right">{{ $p['days_overdue'] }}</td>
                                    <td class="nowrap">{{ $dash($p['last_payment_at']) }}</td>
                                    <td class="center">{{ $p['reminders_sent'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    @endif

    {{-- ============================ UPCOMING ============================ --}}
    <h2>7. Installments due in the next {{ (int) config('reports.daily_digest.upcoming_days', 7) }} days</h2>
    @if(empty($report['upcoming']))
        <p class="muted">No installments fall due in this window.</p>
    @else
        @if(count($report['upcoming']) > $maxListRows)<div class="callout">Showing the first {{ $maxListRows }} of {{ count($report['upcoming']) }} upcoming installments.</div>@endif
        <table class="grid">
            <thead><tr><th>Due</th><th>Donor</th><th>Email</th><th>Phone</th><th>Campaign</th><th class="center">#</th><th class="right">Amount</th><th class="right">NGN</th></tr></thead>
            <tbody>
                @foreach(array_slice($report['upcoming'], 0, $maxListRows) as $u)
                    <tr>
                        <td class="nowrap">{{ $u['due_date'] }}</td>
                        <td>{{ $u['donor']['name'] }}@if($u['donor']['is_anonymous']) <span class="tag tag-warn">Anon</span>@endif</td>
                        <td>{{ $dash($u['donor']['email']) }}</td>
                        <td>{{ $dash($u['donor']['phone']) }}</td>
                        <td>{{ $dash($u['campaign']) }}</td>
                        <td class="center">{{ $u['sequence'] }}</td>
                        <td class="right">{{ $u['currency'] }} {{ number_format((float) $u['amount'], 2) }}</td>
                        <td class="right">{{ $ngn($u['amount_ngn']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- ============================ PAUSED ============================ --}}
    <h2>8. Paused pledges</h2>
    @if(empty($report['paused']))
        <p class="muted">No pledges are paused.</p>
    @else
        <table class="grid">
            <thead><tr><th>Donor</th><th>Email</th><th>Phone</th><th>Campaign</th><th class="right">Committed</th><th class="right">Pending (NGN)</th><th>Paused on</th><th>Resumes</th></tr></thead>
            <tbody>
                @foreach(array_slice($report['paused'], 0, $maxListRows) as $p)
                    <tr>
                        <td>{{ $p['donor']['name'] }}</td>
                        <td>{{ $dash($p['donor']['email']) }}</td>
                        <td>{{ $dash($p['donor']['phone']) }}</td>
                        <td>{{ $dash($p['campaign']) }}</td>
                        <td class="right">{{ $p['currency'] }} {{ number_format((float) $p['committed'], 2) }}</td>
                        <td class="right">{{ $ngn($p['pending_ngn']) }}</td>
                        <td class="nowrap">{{ $dash($p['paused_at']) }}</td>
                        <td class="nowrap">{{ $dash($p['resume_date']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- ============================ NEW / FULFILLED ============================ --}}
    <h2>9. Pledge activity on {{ $report['report_date'] }}</h2>
    <h3>New pledges ({{ count($report['new_pledges']) }})</h3>
    @if(empty($report['new_pledges']))
        <p class="muted">No new pledges.</p>
    @else
        <table class="grid">
            <thead><tr><th>Donor</th><th>Email</th><th>Phone</th><th>Campaign</th><th>Plan</th><th class="right">Committed</th><th class="right">NGN</th></tr></thead>
            <tbody>
                @foreach(array_slice($report['new_pledges'], 0, $maxListRows) as $p)
                    <tr>
                        <td>{{ $p['donor']['name'] }}@if($p['donor']['is_anonymous']) <span class="tag tag-warn">Anon</span>@endif</td>
                        <td>{{ $dash($p['donor']['email']) }}</td>
                        <td>{{ $dash($p['donor']['phone']) }}</td>
                        <td>{{ $dash($p['campaign']) }}</td>
                        <td class="small">{{ str_replace('_', ' ', $p['payment_plan']) }}@if($p['installment_count']) × {{ $p['installment_count'] }}@endif</td>
                        <td class="right">{{ $p['currency'] }} {{ number_format((float) $p['committed'], 2) }}</td>
                        <td class="right">{{ $ngn($p['committed_ngn']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3>Pledges fulfilled ({{ count($report['fulfilled_pledges']) }})</h3>
    @if(empty($report['fulfilled_pledges']))
        <p class="muted">No pledges were completed.</p>
    @else
        <table class="grid">
            <thead><tr><th>Donor</th><th>Email</th><th>Campaign</th><th class="right">Committed</th><th class="right">NGN</th><th>Pledged on</th></tr></thead>
            <tbody>
                @foreach(array_slice($report['fulfilled_pledges'], 0, $maxListRows) as $p)
                    <tr>
                        <td>{{ $p['donor']['name'] }}</td>
                        <td>{{ $dash($p['donor']['email']) }}</td>
                        <td>{{ $dash($p['campaign']) }}</td>
                        <td class="right">{{ $p['currency'] }} {{ number_format((float) $p['committed'], 2) }}</td>
                        <td class="right">{{ $ngn($p['committed_ngn']) }}</td>
                        <td class="nowrap">{{ $dash($p['pledged_on']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="muted small" style="margin-top: 14px;">Notes: donation figures count successful transactions by the date they were created and use the naira value recorded at the time. Pledge figures come from each pledge's installment schedule; foreign-currency pledges are converted at the rate captured when the pledge was made.</p>
</body>
</html>
