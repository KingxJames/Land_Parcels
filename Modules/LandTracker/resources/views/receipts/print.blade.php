<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt {{ $receipt->receipt_no }}</title>
<style>
  body { font-family: system-ui, sans-serif; color: #111; background: #fff; max-width: 640px; margin: 24px auto; padding: 0 16px; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  .muted { color: #555; }
  table { width: 100%; border-collapse: collapse; margin-top: 20px; }
  td { padding: 8px 0; border-bottom: 1px solid #ddd; vertical-align: top; }
  td:last-child { text-align: right; }
  .total td { font-weight: 700; font-size: 18px; border-bottom: 2px solid #111; }
  @media print { button { display: none; } body { margin: 0; } }
</style>
</head>
<body>
  <h1>Receipt {{ $receipt->receipt_no }}</h1>
  @if ($org?->logo_path)<img src="{{ url('/api/v1/public/orgs/'.$org->slug.'/logo') }}" alt="" style="max-height:56px;margin-bottom:8px">@endif
  <div class="muted">{{ $org?->name }} · Issued {{ $receipt->issued_at->format('j F Y') }}</div>

  <table>
    <tr><td>Received from</td><td>{{ $payment->owner->full_name }}</td></tr>
    <tr><td>For</td><td>{{ $payment->parcel->label }}, {{ $payment->parcel->land->name }}</td></tr>
    <tr><td>Paid on</td><td>{{ $payment->paid_on->format('j F Y') }}</td></tr>
    <tr><td>Method</td><td>{{ $payment->methodLabel() }}</td></tr>
    @if ($payment->reference)
      <tr><td>Reference</td><td>{{ $payment->reference }}</td></tr>
    @endif
    @foreach ($payment->allocations as $a)
      <tr><td class="muted">Applied to {{ $a->instalment->label }}</td><td class="muted">{{ \Modules\LandTracker\Services\Money::format($a->amount, $org?->currency) }}</td></tr>
    @endforeach
    <tr class="total"><td>Amount received</td><td>{{ \Modules\LandTracker\Services\Money::format($payment->amount, $org?->currency) }}</td></tr>
    <tr><td>Balance left on this parcel</td><td>{{ \Modules\LandTracker\Services\Money::format($receipt->balance_after, $org?->currency) }}</td></tr>
  </table>

  <p><button onclick="window.print()">Print or save as PDF</button></p>
</body>
</html>
