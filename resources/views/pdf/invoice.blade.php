<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #1e293b;
            font-size: 12px;
            margin: 0;
            padding: 0;
        }
        .page { padding: 32px 40px; }
        .header { border-bottom: 3px solid #4f46e5; padding-bottom: 16px; margin-bottom: 24px; }
        .brand { font-size: 22px; font-weight: 700; color: #4f46e5; }
        .brand small { display: block; font-size: 10px; font-weight: 400; color: #64748b; }
        .meta { float: right; text-align: right; width: 260px; font-size: 11px; }
        .meta strong { display: block; font-size: 13px; color: #0f172a; }
        .meta div { margin-top: 2px; }
        .meta .label { color: #64748b; }
        h1 { margin: 0 0 4px; font-size: 16px; color: #0f172a; }
        .status { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; }
        .status.generated { background: #e0e7ff; color: #3730a3; }
        .status.paid { background: #d1fae5; color: #065f46; }
        .status.draft, .status.overdue { background: #f1f5f9; color: #475569; }
        .parties { width: 100%; margin-bottom: 24px; }
        .parties td { vertical-align: top; width: 50%; }
        .box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
        .box .heading { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; margin-bottom: 6px; }
        .box .name { font-weight: 700; font-size: 13px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.items th { background: #f1f5f9; text-transform: uppercase; font-size: 9px; letter-spacing: .06em; color: #64748b; text-align: left; padding: 8px 10px; }
        table.items th.num, table.items td.num { text-align: right; }
        table.items td { border-bottom: 1px solid #e2e8f0; padding: 8px 10px; }
        .totals { float: right; width: 240px; margin-bottom: 32px; }
        .totals .row { padding: 5px 0; display: block; }
        .totals .row span { float: right; }
        .totals .grand { border-top: 2px solid #4f46e5; margin-top: 6px; padding-top: 8px; font-size: 15px; font-weight: 700; color: #0f172a; }
        .payments { clear: both; margin-bottom: 24px; }
        .payments h3, .balance h3 { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #4f46e5; margin: 0 0 8px; }
        table.pay { width: 100%; border-collapse: collapse; }
        table.pay th { text-align: left; font-size: 9px; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e2e8f0; padding: 6px 8px; }
        table.pay td { border-bottom: 1px solid #f1f5f9; padding: 6px 8px; }
        .balance { width: 240px; margin-bottom: 8px; }
        .balance .amount { font-size: 20px; font-weight: 700; color: #0f172a; }
        .footer { border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 10px; color: #64748b; text-align: center; }
        .footer .clinic-phone { color: #4f46e5; font-weight: 700; }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="brand">
                {{ $clinic['name'] }}
                <small>{{ $clinic['address'] }}</small>
            </div>
            <div class="meta">
                <strong>INVOICE {{ $invoice->invoice_number }}</strong>
                <div><span class="label">Issued:</span> {{ $invoice->issued_at?->format('F j, Y') ?: $invoice->created_at?->format('F j, Y') }}</div>
                <div>
                    <span class="label">Status:</span>
                    <span class="status {{ $invoice->status }}">{{ ucfirst($invoice->status) }}</span>
                </div>
            </div>
            <div style="clear: both;"></div>
        </div>

        <table class="parties">
            <tr>
                <td>
                    <div class="box">
                        <div class="heading">Billed To</div>
                        <div class="name">{{ $invoice->patient_name ?? 'Walk-in Patient' }}</div>
                    </div>
                </td>
                <td>
                    <div class="box">
                        <div class="heading">Contact</div>
                        <div>{{ $clinic['phone'] }}</div>
                        <div>{{ $clinic['email'] }}</div>
                    </div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="num">Qty</th>
                    <th class="num">Unit Price</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoice->items as $item)
                    <tr>
                        <td>{{ $item->description }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}</td>
                        <td class="num">${{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="num">${{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No line items recorded.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="totals">
            <span class="row">Subtotal <span>${{ number_format((float) $invoice->subtotal, 2) }}</span></span>
            <span class="row">Tax ({{ number_format($clinic['tax_rate'], 0) }}%) <span>${{ number_format((float) $invoice->tax, 2) }}</span></span>
            <span class="row grand">Total <span>${{ number_format((float) $invoice->total, 2) }}</span></span>
        </div>

        <div class="payments">
            <h3>Payment Summary</h3>
            @if ($invoice->payments->isNotEmpty())
                <table class="pay">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->payments as $payment)
                            <tr>
                                <td>{{ $payment->paid_at?->format('M j, Y') ?: $payment->created_at?->format('M j, Y') }}</td>
                                <td>{{ ucfirst($payment->method) }}</td>
                                <td class="num">${{ number_format((float) $payment->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p>No payments received yet.</p>
            @endif
        </div>

        <div class="balance">
            <h3>Amount Paid / Balance Due</h3>
            <div>Paid: <strong>${{ number_format((float) $invoice->amount_paid, 2) }}</strong></div>
            <div class="amount">${{ number_format((float) $invoice->balance_due, 2) }} due</div>
        </div>

        <div class="footer">
            Thank you for choosing {{ $clinic['name'] }}.
            Questions about this invoice? Call <span class="clinic-phone">{{ $clinic['phone'] }}</span>.
        </div>
    </div>
</body>
</html>