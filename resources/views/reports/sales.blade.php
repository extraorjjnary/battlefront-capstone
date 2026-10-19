<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Battlefront Computer Trading Sales Report</title>
    <style>
        @page { size: A4 portrait; margin: 18mm 15mm; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10pt; line-height: 1.5; color: #111318; background: white; }
        main { max-width: 180mm; margin: 0 auto; }
        h1 { font-size: 19pt; line-height: 1.3; margin: 0 0 4mm; }
        h2 { font-size: 13pt; margin: 8mm 0 3mm; page-break-after: avoid; }
        .product-report { page-break-before: always; }
        header { border-bottom: 2pt solid #B91C1C; padding-bottom: 4mm; }
        p { margin: 1mm 0; }
        .metadata, .note { color: #444; font-size: 9pt; }
        .summary { margin-top: 5mm; }
        .summary th, .summary td { width: 25%; text-align: left; }
        .summary td { font-size: 12pt; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 3mm; }
        th, td { padding: 2.5mm 2mm; border-bottom: 1px solid #ccc; vertical-align: top; overflow-wrap: anywhere; word-wrap: break-word; }
        th { background: #f1f1f1; font-weight: bold; text-align: left; font-size: 9pt; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .number { text-align: right; white-space: nowrap; }
        .product-name { width: 34%; }
        .category-name { width: 22%; }
        .sales-count { width: 9%; }
        .quantity { width: 10%; }
        .revenue { width: 25%; }
        .empty { padding: 3mm; border: 1px solid #ccc; }
        @media screen { body { padding: 24px; } }
        @media print { body { margin: 0; padding: 0; } }
    </style>
</head>
<body>
@php
    $currency = static fn (string|float|int $amount): string => '₱'.number_format((float) $amount, 2, '.', ',');
    $isFiltered = $filters['product_id'] !== null || $filters['category_id'] !== null;
    $canViewProfitability = array_key_exists('gross_profit', $kpis);
@endphp
<main>
    <header>
        <h1>Battlefront Computer Trading</h1>
        <p><strong>Sales report</strong></p>
        <p class="metadata">Generated: {{ $generatedAt }}</p>
        <p>Inclusive dates: {{ $filters['from'] }} to {{ $filters['to'] }}</p>
        <p>Reporting period: {{ ucfirst($filters['period']) }}</p>
        <p>Product: {{ $active_filters['product']['name'] ?? 'All products' }}</p>
        <p>Category: {{ $active_filters['category']['name'] ?? 'All categories' }}</p>
    </header>

    <table class="summary" aria-label="Sales summary">
        <thead><tr><th>Completed orders</th><th>Product sales revenue</th><th>Average order value</th><th>Items sold</th></tr></thead>
        <tbody><tr>
            <td>{{ number_format($kpis['total_sales']) }}</td>
            <td>{{ $currency($kpis['total_revenue']) }}</td>
            <td>{{ $currency($kpis['average_order_value']) }}</td>
            <td>{{ number_format($kpis['total_items_sold']) }}</td>
        </tr></tbody>
    </table>
    <p class="note">Revenue and average order value reflect product sales only and exclude third-party delivery charges. {{ $isFiltered ? 'The selected filters use purchase-time item revenue.' : 'Product revenue uses saved purchase-time order and item subtotals.' }}</p>

    @if (array_key_exists('gross_profit', $kpis))
        <h2>Profitability</h2>
        <table aria-label="Profitability summary">
            <thead><tr><th>Sales returns</th><th>Net product sales</th><th>Cost of goods sold</th><th>Gross profit</th><th>Gross margin</th></tr></thead>
            <tbody><tr>
                <td>{{ $currency($kpis['sales_returns']) }}</td>
                <td>{{ $currency($kpis['net_sales']) }}</td>
                <td>{{ $currency($kpis['cost_of_goods_sold']) }}</td>
                <td>{{ $kpis['cost_data_complete'] ? $currency($kpis['gross_profit']) : 'Unavailable' }}</td>
                <td>{{ $kpis['gross_margin'] === null ? '—' : $kpis['gross_margin'].'%' }}</td>
            </tr></tbody>
        </table>
        <p class="note">Gross profit subtracts cost of goods sold from net product sales. Product and category gross profit below is before returns.</p>
    @endif

    <h2>Refund payments and outstanding balances</h2>
    <p class="note">Refund payments use payment dates. Gross less refunds is a period measure, not profit. Outstanding balances include approvals and payments through {{ $refundMeasures['cutoff'] }}.</p>
    <table aria-label="Refund summary">
        <thead><tr><th>Gross item sales</th><th>Refunds paid in period</th><th>Gross less refunds</th><th>Outstanding approved refunds</th></tr></thead>
        <tbody><tr><td>{{ $currency($refundMeasures['gross_item_sales']) }}</td><td>{{ $currency($refundMeasures['refund_payments']) }}</td><td>{{ $currency($refundMeasures['gross_less_refunds']) }}</td><td>{{ $currency($refundMeasures['outstanding_at_cutoff']) }}</td></tr></tbody>
    </table>

    @if ($kpis['total_sales'] === 0)
        <p class="empty">No recorded sales match these filters.</p>
    @endif

    <h2>Sales by reporting period</h2>
    <p class="note">Calendar periods are shown; totals include only the selected dates.</p>
    <table aria-label="Sales by reporting period">
        <thead><tr><th style="width: 55%">Period</th><th class="number" style="width: 15%">Sales</th><th class="number" style="width: 30%">Revenue (PHP)</th></tr></thead>
        <tbody>
        @foreach ($timeline['labels'] as $index => $label)
            <tr><td style="width: 55%">{{ $label }}</td><td class="number" style="width: 15%">{{ number_format($timeline['sales'][$index]) }}</td><td class="number" style="width: 30%">{{ $currency($timeline['revenue'][$index]) }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <h2>Category sales breakdown</h2>
    <table aria-label="Category sales breakdown">
        <thead><tr><th>Current category</th><th class="number">Sales</th><th class="number">Units sold</th><th class="number">Item revenue (PHP)</th>@if ($canViewProfitability)<th class="number">Cost (PHP)</th><th class="number">Gross profit before returns</th><th class="number">Margin</th>@endif</tr></thead>
        <tbody>
        @forelse ($categories as $category)
            <tr><td>{{ $category['name'] }}</td><td class="number">{{ number_format($category['sales_count']) }}</td><td class="number">{{ number_format($category['quantity_sold']) }}</td><td class="number">{{ $currency($category['item_revenue']) }}</td>@if (array_key_exists('cost_of_goods_sold', $category))<td class="number">{{ $currency($category['cost_of_goods_sold']) }}</td><td class="number">{{ $category['gross_profit_before_returns'] === null ? 'Unavailable' : $currency($category['gross_profit_before_returns']) }}</td><td class="number">{{ $category['gross_margin_before_returns'] === null ? '—' : $category['gross_margin_before_returns'].'%' }}</td>@endif</tr>
        @empty
            <tr><td colspan="4">No category sales to report.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2 @class(['product-report' => $kpis['total_items_sold'] > 0])>Product sales breakdown</h2>
    <table aria-label="Product sales breakdown">
        <thead><tr><th class="product-name">Product</th><th class="category-name">Current category</th><th class="number sales-count">Sales</th><th class="number quantity">Units<br>sold</th><th class="number revenue">Item revenue (PHP)</th>@if ($canViewProfitability)<th class="number">Cost (PHP)</th><th class="number">Gross profit before returns</th><th class="number">Margin</th>@endif</tr></thead>
        <tbody>
        @forelse ($products as $product)
            <tr><td class="product-name">{{ $product['name'] }}</td><td class="category-name">{{ $product['category'] }}</td><td class="number sales-count">{{ number_format($product['sales_count']) }}</td><td class="number quantity">{{ number_format($product['quantity_sold']) }}</td><td class="number revenue">{{ $currency($product['item_revenue']) }}</td>@if (array_key_exists('cost_of_goods_sold', $product))<td class="number">{{ $currency($product['cost_of_goods_sold']) }}</td><td class="number">{{ $product['gross_profit_before_returns'] === null ? 'Unavailable' : $currency($product['gross_profit_before_returns']) }}</td><td class="number">{{ $product['gross_margin_before_returns'] === null ? '—' : $product['gross_margin_before_returns'].'%' }}</td>@endif</tr>
        @empty
            <tr><td colspan="5">No product sales to report.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="note">Sales counts identify distinct sales per product or category; adding these counts can count a sale more than once. Classification and product names reflect current catalog records.</p>
</main>
@if ($print)
    <script>
        window.addEventListener('load', async () => {
            if (document.fonts) await document.fonts.ready;
            window.print();
        }, { once: true });
    </script>
@endif
</body>
</html>
