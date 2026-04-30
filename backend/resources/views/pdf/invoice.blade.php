<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
        }

        th {
            background: #f2f2f2;
        }

        h2 {
            margin-bottom: 5px;
        }
    </style>
</head>

<body>

    <h2>Invoice {{ $invoice['invoice_number'] ?? '' }}</h2>

    <p>
        <strong>Property:</strong> {{ $invoice['property'] }}<br>
        <strong>Tenant:</strong> {{ $invoice['tenant'] }}<br>
        <strong>Issue Date:</strong> {{ $invoice['issue_date'] ?? '-' }}<br>
        <strong>Due Date:</strong> {{ $invoice['due_date'] ?? '-' }}<br>
        <strong>Reference:</strong> {{ $invoice['payment_reference'] ?? ($invoice['stripe_invoice_id'] ?? '-') }}
    </p>

    <table>
        <thead>
            <tr>
                <th align="left">Description</th>
                <th align="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice['items'] as $item)
            <tr>
                <td>{{ $item['label'] }}</td>
                <td align="right">{{ \App\Support\Currency::format($item['amount']) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th>Subtotal</th>
                <th align="right">{{ \App\Support\Currency::format($invoice['subtotal'] ?? $invoice['total']) }}</th>
            </tr>
            <tr>
                <th>Total</th>
                <th align="right">{{ \App\Support\Currency::format($invoice['total']) }}</th>
            </tr>
        </tfoot>
    </table>

    <p style="margin-top:20px;">
        This is a system generated invoice.
    </p>

</body>

</html>
