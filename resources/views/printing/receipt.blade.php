<!doctype html><html lang="uz"><head><meta charset="utf-8"><style>
@font-face { font-family: OsonPrint; src: url("file://{{ str_replace('\\', '/', public_path('fonts/DejaVuSans.ttf')) }}") format("truetype"); }
@font-face { font-family: OsonPrint; font-weight: bold; src: url("file://{{ str_replace('\\', '/', base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf')) }}") format("truetype"); }
@page { margin: {{ (float) ($profile['layout']['margin_mm'] ?? 2) }}mm; } body { font-family: OsonPrint, sans-serif; font-size: {{ $profile['width_mm'] < 70 ? 8 : 10 }}pt; margin: 0; color: #000; }
.center { text-align: center; } .line { border-bottom: 1px dashed #000; margin: 2mm 0; } .item { page-break-inside: avoid; margin: 2mm 0; } table { width: 100%; border-collapse: collapse; } td { vertical-align: top; } .right { text-align: right; }
</style></head><body>
<div class="center"><strong>{{ $payload['store_name'] }}</strong><br>SAVDO CHEKI
@if ($payload['is_copy'] ?? false)<br><strong>NUSXA</strong>@endif
<br>{{ $payload['receipt_number'] ?? '' }}<br>{{ $payload['date'] }}<br>{{ $payload['status_label'] }}</div>
<div class="line"></div>
@if (!empty($payload['meta']['cashier_name']))<div>Kassir: {{ $payload['meta']['cashier_name'] }}</div>@endif
@if (!empty($payload['meta']['client_name']))<div>Mijoz: {{ $payload['meta']['client_name'] }}</div>@endif
<div>To‘lov: {{ ['cash'=>'Naqd','card'=>'Karta','debt'=>'Qarz','transfer'=>'O‘tkazma','mixed'=>'Naqd + karta','partial'=>'Qisman','preorder'=>'Oldindan buyurtma'][$payload['meta']['payment_type']] ?? $payload['meta']['payment_type'] }}</div>
<div class="line"></div>
@foreach ($payload['items'] as $item)
<div class="item"><strong>{{ $item['name'] }}@if(!empty($item['size'])) ({{ $item['size'] }})@endif</strong>
<table><tr><td>{{ $item['qty'] }} × {{ number_format((float) $item['price'], 2, '.', ' ') }}</td><td class="right">{{ number_format((float) $item['qty'] * (float) $item['price'] - (float) ($item['discount'] ?? 0), 2, '.', ' ') }}</td></tr></table>
@if(!empty($item['discount']))<div>Chegirma: {{ number_format((float) $item['discount'], 2, '.', ' ') }}</div>@endif</div>
@endforeach
<div class="line"></div>
<table>
@if(!empty($payload['meta']['receipt_discount']))<tr><td>Chek chegirmasi:</td><td class="right">{{ number_format((float) $payload['meta']['receipt_discount'], 2, '.', ' ') }}</td></tr>@endif
<tr><td><strong>Jami:</strong></td><td class="right"><strong>{{ number_format((float) $payload['totals']['amount'], 2, '.', ' ') }} so‘m</strong></td></tr>
@foreach (['paid_amount'=>'To‘langan','cash'=>'Naqd','card'=>'Karta','received_cash'=>'Olingan naqd','change'=>'Qaytim','remaining_amount'=>'Qarz'] as $key=>$label)
@if(isset($payload['meta'][$key]))<tr><td>{{ $label }}:</td><td class="right">{{ number_format((float) $payload['meta'][$key], 2, '.', ' ') }}</td></tr>@endif
@endforeach
</table><div class="line"></div><div class="center">Xaridingiz uchun rahmat!</div>
</body></html>
