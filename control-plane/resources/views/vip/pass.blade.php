<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
@include('vip._head')
<title>{{ __('vip.pass_title') }} · {{ $event->name }}</title>
</head>
<body>
@php
  $invoice = $pass->invoice;
  $amount = $invoice ? \App\Models\VipEvent::money((int) $invoice->amount_cents, (string) $invoice->currency) : null;
  $downloads = [
    'android' => [$android, __('vip.download_android')],
    'windows' => [$windows, __('vip.download_windows')],
    'macos' => [$macos, __('vip.download_mac')],
  ];
  $primary = $downloads[$platform] ?? null;
@endphp
<div class="wrap">
  @include('vip._top')

  <h1>{{ __('vip.pass_title') }}</h1>
  <p class="muted" style="margin:0">{{ $event->name }} · {{ __('vip.devices') }}: {{ $pass->devices }} · {{ __('vip.ends', ['date' => $event->endsLabel()]) }}</p>

  {{-- ---------------- waiting for the money ---------------- --}}
  @if ($state === 'pending' || $state === 'review')
    @if ($cancelled)
      <div class="note warn">{{ __('vip.cancelled_payment') }} <a href="{{ $event->publicUrl() }}">{{ __('vip.try_again') }} →</a></div>
    @endif

    @if ($invoice?->provider === 'manual' && $state === 'pending')
      <div class="card">
        <h2>{{ __('vip.pay_manual', ['amount' => $amount, 'method' => $invoice->payment_method]) }}</h2>
        <p class="muted" style="margin-top:0">{{ __('vip.pay_manual_help') }}</p>
        @if ($method?->qrUrl())
          <img class="qr" style="width:240px;height:240px" src="{{ $method->qrUrl() }}" alt="QR">
        @endif
        @if ($method?->account_ref)
          <p class="center" style="font-weight:600">{{ $method->account_ref }}</p>
        @endif
        @if ($method?->instructions)
          <p style="white-space:pre-line">{{ $method->instructions }}</p>
        @endif
        <form method="post" action="{{ route('vip.pass.proof', $pass->page_token) }}" enctype="multipart/form-data">
          @csrf
          <label class="f" for="proof">{{ __('vip.upload') }}</label>
          <input id="proof" type="file" name="proof" accept="image/*" required>
          <label class="f" for="note">{{ __('vip.note') }}</label>
          <input id="note" type="text" name="note" maxlength="300">
          @if ($errors->any())
            <div class="err">{{ $errors->first() }}</div>
          @endif
          <button class="btn" type="submit">{{ __('vip.send') }}</button>
        </form>
      </div>
    @else
      <div class="card center">
        <div class="spin"></div>
        <h2>{{ $state === 'review' ? __('vip.state_review') : __('vip.state_pending') }}</h2>
        <p class="muted" style="margin:0">{{ $state === 'review' ? __('vip.state_review_help') : __('vip.state_pending_help') }}</p>
        @if ($amount)
          <p style="margin:10px 0 0;font-weight:600">{{ __('vip.total') }}: {{ $amount }}</p>
        @endif
        <a class="btn ghost" href="{{ $pass->pageUrl() }}">{{ __('vip.refresh') }}</a>
      </div>
    @endif

  {{-- ---------------- ready: code + app ---------------- --}}
  @elseif ($state === 'active')
    <div class="note ok">✓ {{ __('vip.state_active') }}</div>

    <div class="card">
      <h2>{{ __('vip.code') }}</h2>
      <div class="code" id="code">{{ $pass->displayCode() }}</div>
      <p class="help center">{{ __('vip.used', ['used' => $pass->devicesUsed(), 'n' => $pass->devices]) }}</p>
      <p class="help center" style="margin-top:0">{{ __('vip.code_help', ['n' => $pass->devices]) }}</p>
      <button class="btn ghost" type="button" data-copy="{{ $pass->displayCode() }}">{{ __('vip.copy_code') }}</button>
    </div>

    @if ($platform !== 'ios')
      <div class="card">
        <ol class="steps">
          <li>{{ __('vip.step1') }}</li>
          <li>{{ __('vip.step2') }}</li>
          <li>{{ __('vip.step3') }}</li>
        </ol>
        @if ($primary)
          <a class="btn" href="{{ $primary[0] }}" data-tag="{{ $pass->clipboardTag() }}">⬇ {{ $primary[1] }}</a>
        @endif
        <a class="btn ghost" href="{{ $pass->appLink() }}" data-tag="{{ $pass->clipboardTag() }}">{{ __('vip.open_app') }}</a>
        <p class="help">{{ __('vip.manual_code') }}</p>
        <details style="margin-top:10px">
          <summary class="muted">Android · Windows · Mac</summary>
          @foreach ($downloads as $key => [$url, $label])
            @continue($key === $platform)
            <a class="btn ghost" href="{{ $url }}" data-tag="{{ $pass->clipboardTag() }}">⬇ {{ $label }}</a>
          @endforeach
        </details>
      </div>
    @endif

    @if ($iosImport)
      <div class="card">
        <h2>{{ __('vip.iphone') }}</h2>
        <ol class="steps">
          <li>{{ __('vip.iphone1') }}</li>
          <li>{{ __('vip.iphone2') }}</li>
        </ol>
        <a class="btn {{ $platform === 'ios' ? '' : 'ghost' }}" href="https://apps.apple.com/app/sing-box-mt/id6785326793">App Store · sing-box</a>
        <a class="btn {{ $platform === 'ios' ? '' : 'ghost' }}" href="{{ $iosImport }}">{{ __('vip.iphone_btn') }}</a>
      </div>
    @endif

    @if ($platform !== 'ios' && $platform !== 'android')
      <div class="card center">
        <h2>{{ __('vip.other_device') }}</h2>
        <img class="qr" src="{{ route('vip.pass.qr', $pass->page_token) }}" alt="QR">
        <p class="help">{{ __('vip.other_device_help') }}</p>
      </div>
    @endif

  {{-- ---------------- switched off / over ---------------- --}}
  @else
    <div class="note {{ $state === 'ended' ? 'warn' : 'bad' }}">{{ __('vip.state_'.$state) }}</div>
  @endif

  <p class="foot">{{ __('vip.support') }}</p>
</div>

<script>
(function () {
  function copy(text) {
    try { if (navigator.clipboard) return navigator.clipboard.writeText(text); } catch (e) {}
    var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(t);
  }
  // Downloading / opening the app copies the code: the app finds it on first start.
  document.querySelectorAll('[data-tag]').forEach(function (a) {
    a.addEventListener('click', function () { copy(a.dataset.tag); });
  });
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      copy(b.dataset.copy);
      var old = b.textContent; b.textContent = @json(__('vip.copied')) + ' ✓';
      setTimeout(function () { b.textContent = old; }, 1600);
    });
  });

  // Waiting for the payment: ask the server every few seconds.
  var state = @json($state);
  if (state === 'pending' || state === 'review') {
    var tries = 0;
    var timer = setInterval(function () {
      if (++tries > 150) return clearInterval(timer);
      fetch(@json(route('vip.pass.status', $pass->page_token)), { headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.state && d.state !== state) location.replace(location.pathname); })
        .catch(function () {});
    }, 4000);
  }
})();
</script>
</body>
</html>
