<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Battlefront Computer Trading - Shipment records</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #222; }
        h1 { font-size: 18px; } p { margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #ccc; padding: 5px; overflow-wrap: break-word; word-wrap: break-word; vertical-align: top; }
        th { background: #eee; } thead { display: table-header-group; } tr { page-break-inside: avoid; }
        @page { margin: 25px 25px 35px; }
        @if ($print)
        body { font-size: 10px; margin: 20px; } button { font: inherit; padding: 8px 16px; cursor: pointer; }
        button:focus-visible { outline: 2px solid #222; outline-offset: 2px; }
        @page { size: A4 landscape; @bottom-right { content: "Page " counter(page) " of " counter(pages); } }
        @media print { body { margin: 0; } .print-controls { display: none; } }
        @endif
    </style>
</head>
<body>
@if ($print)
    <div class="print-controls"><button type="button" onclick="window.print()">Print</button><p>Choose a printer or save as PDF in your browser's print dialog.</p></div>
@endif
<h1>Battlefront Computer Trading - Shipment records</h1>
<p>Generated: {{ $generatedAt }} | {{ count($rows) }} matching records</p>
<p>{{ collect($filters)->map(fn ($value, $key) => $key.': '.$value)->implode(' | ') }}</p>
<p>Internal shipment records. Missing historical information is shown as Not recorded.</p>
<table>
    <thead><tr><th>Order and destination</th><th>Status</th><th>Packed parcels</th><th>Tracking and receipt</th><th>Amounts (PHP)</th><th>Reconciliation</th><th>Handoff (Philippine time)</th></tr></thead>
    <tbody>
    @forelse ($rows as $row)
        <tr>
            <td><p>{{ $row['reference'] }}</p><p>{{ $row['recipient'] }}</p><p>{{ $row['destination'] ?? 'Not recorded' }}</p></td>
            <td>{{ $row['status']['label'] }}</td>
            <td><p>Count: {{ $row['package_count'] ?? 'Not recorded' }}</p><p>Weight: {{ $row['weight'] ?? 'Not recorded' }}{{ $row['weight'] !== null ? ' kg' : '' }}</p><p>Dimensions (cm): {{ $row['length'] !== null && $row['width'] !== null && $row['height'] !== null ? $row['length'].' × '.$row['width'].' × '.$row['height'] : 'Not recorded' }}</p></td>
            <td><p>Tracking: {{ $row['tracking'] ?? 'Not recorded' }}</p><p>Receipt: {{ $row['receipt'] ?? 'Not recorded' }}</p></td>
            <td><p>Declared: {{ $row['declared_value'] ?? 'Not recorded' }}</p><p>Customer delivery: {{ $row['delivery_fee'] }}</p><p>Carrier: {{ $row['carrier_cost'] ?? 'Not recorded' }}</p></td>
            <td>{{ $row['reconciliation'] }}</td>
            <td>{{ $row['handed_at'] ?? 'Not recorded' }}</td>
        </tr>
    @empty
        <tr><td colspan="7">No matching records.</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
