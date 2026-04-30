<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>{{ $invoice['subject'] }}</title>
</head>

<body style="margin:0; padding:24px 0; font-family:Arial, sans-serif; background:#f3f6fb; color:#1f2937;">

    <div style="max-width:680px; background:#ffffff; margin:auto; border-radius:18px; overflow:hidden; box-shadow:0 18px 45px rgba(15,23,42,0.08);">
        <div style="padding:28px 32px 22px; background:#0f172a; color:#ffffff;">
            <div style="font-size:12px; letter-spacing:0.08em; text-transform:uppercase; opacity:0.75; margin-bottom:10px;">
                Property Listing Invoice
            </div>
            <h2 style="margin:0; font-size:28px; line-height:1.2;">{{ $invoice['subject'] }}</h2>
            <p style="margin:10px 0 0; font-size:14px; line-height:1.6; color:rgba(255,255,255,0.82);">
                This invoice confirms the latest payment activity recorded in your account.
            </p>
        </div>

        <div style="padding:30px 32px;">
            <p style="margin:0 0 18px; font-size:15px; line-height:1.7;">Please find your invoice summary below.</p>

            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:24px; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">
                <tbody>
                    <tr>
                        <td style="padding:14px 16px; background:#f8fafc; color:#475569; font-weight:bold; width:180px;">Invoice Number</td>
                        <td style="padding:14px 16px;">{{ $invoice['invoice_number'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:14px 16px; background:#f8fafc; color:#475569; font-weight:bold; border-top:1px solid #e5e7eb; width:180px;">Property</td>
                        <td style="padding:14px 16px;">{{ $invoice['property'] }}</td>
                    </tr>
                    <tr>
                        <td style="padding:14px 16px; background:#f8fafc; color:#475569; font-weight:bold; border-top:1px solid #e5e7eb;">Tenant</td>
                        <td style="padding:14px 16px; border-top:1px solid #e5e7eb;">{{ $invoice['tenant'] }}</td>
                    </tr>
                    <tr>
                        <td style="padding:14px 16px; background:#f8fafc; color:#475569; font-weight:bold; border-top:1px solid #e5e7eb;">Issue Date</td>
                        <td style="padding:14px 16px; border-top:1px solid #e5e7eb;">{{ $invoice['issue_date'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:14px 16px; background:#f8fafc; color:#475569; font-weight:bold; border-top:1px solid #e5e7eb;">Due Date</td>
                        <td style="padding:14px 16px; border-top:1px solid #e5e7eb;">{{ $invoice['due_date'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:14px 16px; background:#f8fafc; color:#475569; font-weight:bold; border-top:1px solid #e5e7eb;">Payment Reference</td>
                        <td style="padding:14px 16px; border-top:1px solid #e5e7eb;">{{ $invoice['payment_reference'] ?? ($invoice['stripe_invoice_id'] ?? '-') }}</td>
                    </tr>
                </tbody>
            </table>

            <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">
                <thead>
                    <tr style="background:#f0f0f0;">
                        <th align="left">Description</th>
                        <th align="right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(($invoice['items'] ?? []) as $item)
                        <tr>
                            <td>{{ $item['label'] }}</td>
                            <td align="right">{{ \App\Support\Currency::format($item['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>Subtotal</strong></td>
                        <td align="right"><strong>{{ \App\Support\Currency::format($invoice['subtotal'] ?? $invoice['total']) }}</strong></td>
                    </tr>
                    <tr>
                        <td><strong>Total</strong></td>
                        <td align="right"><strong>{{ \App\Support\Currency::format($invoice['total']) }}</strong></td>
                    </tr>
                </tfoot>
            </table>

            <p style="margin:24px 0 0; font-size:12px; line-height:1.7; color:#64748b;">
                This is a system-generated invoice from Property Listing. A PDF copy is attached for your records.
            </p>
        </div>
    </div>

</body>

</html>
