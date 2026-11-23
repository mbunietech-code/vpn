<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
@include('vip._head')
<title>{{ $event->name }} · {{ __('vip.title') }}</title>
</head>
<body>
<div class="wrap">
  @include('vip._top')

  <h1>{{ $event->name }}</h1>
  <p class="muted" style="margin:0">{{ __('vip.tagline') }}</p>
  @if ($event->description)
    <p style="margin:10px 0 0;white-space:pre-line">{{ $event->description }}</p>
  @endif

  @if ($mine)
    <div class="note ok">
      {{ __('vip.have_pass') }} — <a href="{{ $mine->pageUrl() }}">{{ __('vip.open_pass') }} →</a>
    </div>
  @endif

  @if (! $event->isSelling())
    <div class="note warn">{{ __('vip.closed') }}</div>
  @elseif ($options === [])
    <div class="note warn">{{ __('vip.no_methods') }}</div>
  @else
    <form class="card" method="post" action="{{ route('vip.checkout', $event->slug) }}" id="buy">
      @csrf
      <div class="price"><b>{{ $event->priceLabel('cny') }}</b><span class="muted">{{ __('vip.per_device') }}</span></div>
      <div class="muted">{{ __('vip.until', ['date' => $event->endsLabel()]) }}</div>

      <label class="f" for="devices">{{ __('vip.devices') }}</label>
      <div class="stepper">
        <button type="button" data-step="-1" aria-label="−">−</button>
        <input id="devices" name="devices" type="number" inputmode="numeric" min="1" max="{{ max(1, $event->max_devices_per_order) }}" value="{{ old('devices', 1) }}">
        <button type="button" data-step="1" aria-label="+">+</button>
      </div>
      <div class="help">{{ __('vip.devices_help') }}</div>

      <label class="f">{{ __('vip.pay_with') }}</label>
      @foreach ($options as $i => $o)
        <label class="opt">
          <input type="radio" name="method" value="{{ $o['key'] }}" data-cents="{{ $o['amount_cents'] }}" data-cur="{{ $o['currency'] }}"
            {{ old('method', $options[0]['key']) === $o['key'] ? 'checked' : '' }}>
          <span class="l">{{ $o['label'] }}</span>
          <span class="a" data-each>{{ \App\Models\VipEvent::money($o['amount_cents'], $o['currency']) }}</span>
        </label>
      @endforeach

      <label class="f" for="contact">{{ __('vip.contact') }}</label>
      <input id="contact" name="contact" type="text" maxlength="120" autocomplete="email" value="{{ old('contact') }}">
      <div class="help">{{ __('vip.contact_help') }}</div>

      @if ($errors->any())
        <div class="err">{{ $errors->first() }}</div>
      @endif

      <button class="btn" type="submit" id="paybtn">{{ __('vip.pay', ['amount' => '']) }}</button>
    </form>
  @endif

  <div class="card">
    <h2>{{ __('vip.how') }}</h2>
    <ol class="steps">
      <li>{{ __('vip.how1') }}</li>
      <li>{{ __('vip.how2') }}</li>
      <li>{{ __('vip.how3') }}</li>
    </ol>
  </div>

  <p class="foot">{{ __('vip.support') }}</p>
</div>

<script>
(function () {
  var form = document.getElementById('buy');
  if (!form) return;
  var input = document.getElementById('devices');
  var btn = document.getElementById('paybtn');
  var label = @json(__('vip.pay', ['amount' => '__A__']));
  var max = parseInt(input.max, 10) || 1;

  function money(cents, cur) {
    var v = cents / 100;
    if (cur === 'cny') return '¥' + (cents % 100 ? v.toFixed(2) : v.toLocaleString('en'));
    if (cur === 'tzs') return 'TSh ' + Math.round(v).toLocaleString('en');
    if (cur === 'usd') return '$' + v.toFixed(2);
    return cur.toUpperCase() + ' ' + v.toFixed(2);
  }
  function update() {
    var n = Math.min(max, Math.max(1, parseInt(input.value, 10) || 1));
    var m = form.querySelector('input[name=method]:checked');
    if (m) btn.textContent = label.replace('__A__', money(parseInt(m.dataset.cents, 10) * n, m.dataset.cur));
  }
  form.querySelectorAll('[data-step]').forEach(function (b) {
    b.addEventListener('click', function () {
      var n = (parseInt(input.value, 10) || 1) + parseInt(b.dataset.step, 10);
      input.value = Math.min(max, Math.max(1, n));
      update();
    });
  });
  input.addEventListener('input', update);
  form.querySelectorAll('input[name=method]').forEach(function (r) { r.addEventListener('change', update); });
  form.addEventListener('submit', function () { setTimeout(function () { btn.disabled = true; }, 0); });
  update();
})();
</script>
</body>
</html>
