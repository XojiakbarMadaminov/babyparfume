<!doctype html><html lang="uz"><head><meta charset="utf-8"><style>
@font-face { font-family: OsonPrint; src: url("file://{{ str_replace('\\', '/', public_path('fonts/DejaVuSans.ttf')) }}") format("truetype"); }
@font-face { font-family: OsonPrint; font-weight: bold; src: url("file://{{ str_replace('\\', '/', base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf')) }}") format("truetype"); }
@page { margin: 0; } body { margin: 0; font-family: OsonPrint, sans-serif; color: #000; }
.label { box-sizing: border-box; padding: {{ (float) ($profile['layout']['margin_mm'] ?? 1) }}mm; text-align: center; page-break-after: always; page-break-inside: avoid; }
.label:last-child { page-break-after: auto; } .name { font-size: {{ $profile['width_mm'] < 40 ? 6 : 9 }}pt; line-height: 1.1; }
.code { font-size: {{ $profile['width_mm'] < 40 ? 6 : 8 }}pt; } img { display: block; width: 92%; height: {{ min(12, max(5, (float) $profile['height_mm'] * .34)) }}mm; margin: .5mm auto; }
</style></head><body>
@foreach ($payload['labels'] as $label)
<div class="label"><div class="name">{{ mb_strimwidth($label['name'], 0, $profile['width_mm'] < 40 ? 36 : 65, '…') }}</div>
<img src="{{ $label['svg'] }}" alt="Barcode"><div class="code">{{ $label['barcode'] }}</div>
@if ($profile['layout']['show_price'] ?? false)<div class="name"><strong>{{ number_format((float) $label['price'], 0, '.', ' ') }} so‘m</strong></div>@endif
</div>
@endforeach
</body></html>
