<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #111827;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }

        th {
            width: 30%;
            background: #f3f4f6;
        }
    </style>
</head>

<body>
    <h2>Rent Deed</h2>
    <table>
        <tbody>
            <tr>
                <th>Agreement Number</th>
                <td>{{ $rentDeed['agreement_number'] }}</td>
            </tr>
            <tr>
                <th>Agreement Date</th>
                <td>{{ $rentDeed['agreement_date'] ?? '-' }}</td>
            </tr>
            <tr>
                <th>Property</th>
                <td>{{ $rentDeed['property_name'] }}</td>
            </tr>
            <tr>
                <th>Address</th>
                <td>{{ $rentDeed['property_address'] ?? '-' }}</td>
            </tr>
            <tr>
                <th>Owner</th>
                <td>{{ $rentDeed['owner_name'] ?? '-' }}</td>
            </tr>
            <tr>
                <th>Tenant</th>
                <td>{{ $rentDeed['tenant_name'] ?? '-' }}</td>
            </tr>
            <tr>
                <th>Manager</th>
                <td>{{ $rentDeed['manager_name'] ?? '-' }}</td>
            </tr>
            <tr>
                <th>Size</th>
                <td>{{ $rentDeed['size'] ?? '-' }}</td>
            </tr>
            <tr>
                <th>Usage</th>
                <td>{{ $rentDeed['usage'] ?? '-' }}</td>
            </tr>
            <tr>
                <th>Monthly Rent</th>
                <td>{{ $rentDeed['monthly_rent'] !== null ? \App\Support\Currency::format($rentDeed['monthly_rent']) : '-' }}</td>
            </tr>
            <tr>
                <th>Rent Due Date</th>
                <td>{{ $rentDeed['rent_due_date'] ?? '-' }}</td>
            </tr>
            <tr>
                <th>Maintenance Charges</th>
                <td>{{ $rentDeed['maintenance_charges'] ?? '-' }}</td>
            </tr>
            <tr>
                <th>Other Details</th>
                <td>{{ $rentDeed['other_details'] ?? '-' }}</td>
            </tr>
        </tbody>
    </table>
</body>

</html>
