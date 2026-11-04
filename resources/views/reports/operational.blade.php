<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:8px;color:#222}h1{font-size:18px}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #ccc;padding:4px;overflow-wrap:break-word}th{background:#eee}thead{display:table-header-group}tr{page-break-inside:avoid}@page{margin:25px 25px 35px}
@if ($print ?? false)
body{font-size:10px;margin:20px}button{font:inherit;padding:8px 16px;cursor:pointer}button:focus-visible{outline:2px solid #222;outline-offset:2px}@page{size:A4 landscape}@media print{body{margin:0}.print-controls{display:none}}
@endif
</style>
</head><body>
@if ($print ?? false)
<div class="print-controls"><button type="button" onclick="window.print()">Print</button><p>Use your browser's print dialog to choose a printer or save as PDF.</p></div>
@endif
<h1>Battlefront Computer Trading - {{ $title }}</h1>
<p>Generated: {{ $generatedAt }}  |  {{ count($rows) }} records</p>
<p>Filters: {{ collect($filters)->map(fn ($value, $key) => $key.': '.(string) $value)->implode('  |  ') ?: 'Default selection' }}</p>
<table><thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>
@forelse($rows as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($headers) }}">No matching records.</td></tr>@endforelse
</tbody></table>
@if ($print ?? false)
<script>
    window.addEventListener('load', async () => {
        await document.fonts.ready;
        window.print();
    });
</script>
@endif
</body></html>
