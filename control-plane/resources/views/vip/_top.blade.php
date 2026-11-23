<div class="top">
  <div class="brand">
    <img src="{{ asset('images/mvpn-icon.png') }}?v=5" alt="">
    <span>Mbunie VPN</span>
    <span class="vip">VIP</span>
  </div>
  <nav class="langs">
    @foreach (['en' => 'EN', 'zh' => '中文', 'sw' => 'SW'] as $code => $label)
      <a href="?lang={{ $code }}" class="{{ app()->getLocale() === $code ? 'on' : '' }}">{{ $label }}</a>
    @endforeach
  </nav>
</div>
