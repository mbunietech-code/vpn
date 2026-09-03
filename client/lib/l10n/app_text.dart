import 'package:flutter/widgets.dart';

/// Lightweight 3-language localization (no codegen). English is the fallback.
/// Locale codes: en · sw · zh
class AppText {
  AppText(this.code);
  final String code;

  static const supported = ['en', 'sw', 'zh'];
  static const names = {'en': 'English', 'sw': 'Kiswahili', 'zh': '中文'};

  static String resolve(String? saved, Locale device) {
    if (saved != null && supported.contains(saved)) return saved;
    if (supported.contains(device.languageCode)) return device.languageCode;
    return 'en';
  }

  String t(String key) => _map[key]?[code] ?? _map[key]?['en'] ?? key;

  /// with a single {} placeholder
  String p(String key, Object v) => t(key).replaceAll('{}', '$v');
}

extension AppTextX on BuildContext {
  AppText get tr =>
      AppText(AppTextScope.of(this));
  String tt(String key) => tr.t(key);
}

/// Provides the current locale code down the tree; rebuilds on change.
class AppTextScope extends InheritedWidget {
  const AppTextScope({super.key, required this.code, required super.child});
  final String code;

  static String of(BuildContext c) =>
      c.dependOnInheritedWidgetOfExactType<AppTextScope>()?.code ?? 'en';

  @override
  bool updateShouldNotify(AppTextScope old) => old.code != code;
}

const _map = <String, Map<String, String>>{
  // ---- common ----
  'app.name': {'en': 'Mbunie VPN', 'sw': 'Mbunie VPN', 'zh': 'Mbunie VPN'},
  'common.retry': {'en': 'Try again', 'sw': 'Jaribu tena', 'zh': '重试'},
  'common.continue': {'en': 'Continue', 'sw': 'Endelea', 'zh': '继续'},
  'common.cancel': {'en': 'Cancel', 'sw': 'Ghairi', 'zh': '取消'},
  'common.change': {'en': 'Change', 'sw': 'Badilisha', 'zh': '更改'},
  'common.check': {'en': 'Check', 'sw': 'Angalia', 'zh': '查看'},
  'common.close': {'en': 'Close', 'sw': 'Funga', 'zh': '关闭'},

  // ---- auth ----
  'auth.tagline': {
    'en': 'Unlimited internet. Sign in with phone or email.',
    'sw': 'Intaneti bila mipaka. Ingia kwa simu au email.',
    'zh': '无限网络。使用手机或邮箱登录。'
  },
  'auth.sentTo': {
    'en': 'We sent a 6-digit code to\n{}',
    'sw': 'Tumetuma msimbo wa tarakimu 6 kwenda\n{}',
    'zh': '我们已向 {} 发送了 6 位验证码'
  },
  'auth.idHint': {
    'en': '+8613… or you@email.com',
    'sw': '+8613… au barua@pepe.com',
    'zh': '+8613… 或 you@email.com'
  },
  'auth.sendCode': {'en': 'Send code', 'sw': 'Tuma msimbo', 'zh': '发送验证码'},
  'auth.codeHint': {'en': '••••••', 'sw': '••••••', 'zh': '••••••'},
  'auth.verify': {'en': 'Verify & sign in', 'sw': 'Thibitisha na uingie', 'zh': '验证并登录'},
  'auth.changeId': {
    'en': 'Change number / email',
    'sw': 'Badilisha namba / email',
    'zh': '更改手机号 / 邮箱'
  },
  'auth.debugCode': {'en': 'Test code: {}', 'sw': 'Msimbo wa majaribio: {}', 'zh': '测试码：{}'},
  'auth.legal': {
    'en': 'By continuing you agree to the Terms of Service\nand Privacy Policy.',
    'sw': 'Kwa kuendelea unakubali Masharti ya Huduma\nna Sera ya Faragha.',
    'zh': '继续即表示您同意服务条款和隐私政策。'
  },
  'auth.netError': {
    'en': 'Network error. Check your connection.',
    'sw': 'Tatizo la mtandao. Angalia muunganisho.',
    'zh': '网络错误，请检查连接。'
  },

  // ---- plans ----
  'plans.title': {'en': 'Choose a plan', 'sw': 'Chagua kifurushi', 'zh': '选择套餐'},
  'plans.headline': {'en': 'Unlock the full internet', 'sw': 'Fungua intaneti nzima', 'zh': '畅享完整互联网'},
  'plans.sub': {
    'en': 'High speed · Tokyo server · multiple devices',
    'sw': 'Kasi ya juu · seva Tokyo · vifaa vingi',
    'zh': '高速 · 东京服务器 · 多设备'
  },
  'plans.processing': {
    'en': 'Your payment is being processed…',
    'sw': 'Malipo yako yanashughulikiwa…',
    'zh': '正在处理您的付款…'
  },
  'plans.loadError': {
    'en': 'Could not load plans. Try again.',
    'sw': 'Imeshindwa kupakia mipango. Jaribu tena.',
    'zh': '无法加载套餐，请重试。'
  },
  'plans.choose': {'en': 'Choose plan', 'sw': 'Chagua kifurushi', 'zh': '选择套餐'},
  'plans.popular': {'en': 'Popular', 'sw': 'Maarufu', 'zh': '热门'},
  'plans.perMonth': {'en': '{} / month', 'sw': '{} / mwezi', 'zh': '{} / 月'},
  'plans.days': {'en': '{} days', 'sw': 'Siku {}', 'zh': '{} 天'},
  'plans.devices': {
    'en': '{} devices at once',
    'sw': 'Vifaa {} kwa wakati mmoja',
    'zh': '同时 {} 台设备'
  },
  'plans.unlimited': {'en': 'Unlimited data', 'sw': 'Data bila kikomo', 'zh': '无限流量'},
  'plans.protocols': {
    'en': 'VLESS-REALITY + Hysteria2',
    'sw': 'VLESS-REALITY + Hysteria2',
    'zh': 'VLESS-REALITY + Hysteria2'
  },
  'plans.payFooter': {
    'en': 'Secure payment via Alipay · WeChat · Card · Crypto',
    'sw': 'Malipo salama kupitia Alipay · WeChat · Kadi · Crypto',
    'zh': '通过支付宝 · 微信 · 银行卡 · 加密货币安全支付'
  },
  'plans.logout': {'en': 'Log out', 'sw': 'Toka', 'zh': '退出'},

  // ---- checkout ----
  'checkout.title': {'en': 'Payment', 'sw': 'Malipo', 'zh': '付款'},
  'checkout.pickMethod': {'en': 'Choose a payment method', 'sw': 'Chagua njia ya malipo', 'zh': '选择支付方式'},
  'checkout.instant': {'en': 'Instant payment', 'sw': 'Malipo ya papo hapo', 'zh': '即时支付'},
  'checkout.instantSub': {
    'en': 'Pay and get access automatically',
    'sw': 'Lipa na upate access moja kwa moja',
    'zh': '付款后自动开通'
  },
  'checkout.manualSection': {'en': 'Or pay manually', 'sw': 'Au lipa kwa mkono', 'zh': '或手动付款'},
  'checkout.openingPay': {'en': 'Opening payment page…', 'sw': 'Inafungua ukurasa wa malipo…', 'zh': '正在打开支付页面…'},
  'checkout.waitingAuto': {
    'en': 'Waiting for payment…',
    'sw': 'Inasubiri malipo yakamilike…',
    'zh': '等待付款完成…'
  },
  'checkout.waitingAutoBody': {
    'en': 'Finish the payment in the page that opened. The app unlocks automatically.',
    'sw': 'Kamilisha malipo kwenye ukurasa uliofunguka. App itajiwasha yenyewe.',
    'zh': '在打开的页面中完成付款。应用将自动解锁。'
  },
  'checkout.noMethods': {
    'en': 'No payment methods are set up yet. Contact support.',
    'sw': 'Njia za malipo hazijawekwa bado. Wasiliana na support.',
    'zh': '尚未设置支付方式。请联系客服。'
  },
  'checkout.payWith': {'en': 'Pay with {}', 'sw': 'Lipa kwa {}', 'zh': '使用 {} 支付'},
  'checkout.payAmount': {'en': 'Pay this amount', 'sw': 'Lipa kiasi hiki', 'zh': '支付此金额'},
  'checkout.uploadProof': {
    'en': 'Upload payment proof',
    'sw': 'Pakia uthibitisho wa malipo',
    'zh': '上传付款凭证'
  },
  'checkout.pickImage': {
    'en': 'Choose a receipt / screenshot',
    'sw': 'Chagua picha ya risiti / screenshot',
    'zh': '选择收据 / 截图'
  },
  'checkout.changeImage': {'en': 'Change image', 'sw': 'Badilisha picha', 'zh': '更换图片'},
  'checkout.noteHint': {
    'en': 'Payment reference (optional)',
    'sw': 'Kumbukumbu ya malipo (hiari)',
    'zh': '付款备注（可选）'
  },
  'checkout.submit': {'en': 'Submit for approval', 'sw': 'Tuma kwa uthibitisho', 'zh': '提交审核'},
  'checkout.waitingTitle': {
    'en': 'Waiting for admin approval',
    'sw': 'Inasubiri idhini ya admin',
    'zh': '等待管理员审批'
  },
  'checkout.waitingBody': {
    'en':
        'We received your proof. An admin will review and approve it as soon as possible. The app will unlock automatically once approved.',
    'sw':
        'Tumepokea uthibitisho wako. Admin ataangalia na kuidhinisha haraka iwezekanavyo. App itajiwasha yenyewe ukishaidhinishwa.',
    'zh': '我们已收到您的凭证。管理员将尽快审核批准。批准后应用将自动解锁。'
  },
  'checkout.failStart': {'en': 'Failed. Try again.', 'sw': 'Imeshindwa. Jaribu tena.', 'zh': '失败，请重试。'},
  'checkout.failUpload': {
    'en': 'Upload failed. Try again.',
    'sw': 'Imeshindwa kupakia. Jaribu tena.',
    'zh': '上传失败，请重试。'
  },

  // ---- home ----
  'home.connected': {'en': 'Connected', 'sw': 'Umeunganishwa', 'zh': '已连接'},
  'home.connecting': {'en': 'Connecting…', 'sw': 'Inaunganisha…', 'zh': '连接中…'},
  'home.reconnecting': {'en': 'Reconnecting…', 'sw': 'Inaunganisha upya…', 'zh': '重新连接中…'},
  'home.failed': {'en': 'Failed', 'sw': 'Imeshindwa', 'zh': '失败'},
  'home.disconnected': {'en': 'Not connected', 'sw': 'Hujaunganishwa', 'zh': '未连接'},
  'home.subConnected': {
    'en': 'Your traffic is hidden and encrypted',
    'sw': 'Trafiki yako imefichwa na imesimbwa',
    'zh': '您的流量已隐藏并加密'
  },
  'home.subConnecting': {
    'en': 'Setting up a secure route',
    'sw': 'Tunaanzisha njia salama',
    'zh': '正在建立安全通道'
  },
  'home.subReconnecting': {
    'en': 'The connection dropped briefly',
    'sw': 'Muunganisho ulikatika kidogo',
    'zh': '连接短暂中断'
  },
  'home.subFailed': {'en': 'Try again', 'sw': 'Jaribu tena', 'zh': '请重试'},
  'home.subIdle': {'en': 'Your traffic is exposed', 'sw': 'Trafiki yako iko wazi', 'zh': '您的流量未受保护'},
  'home.daysLeft': {'en': '{} days', 'sw': 'Siku {}', 'zh': '{} 天'},
  'home.currentServer': {'en': 'Current server', 'sw': 'Seva ya sasa', 'zh': '当前服务器'},
  'home.optimizedFor': {'en': 'Optimized for {}', 'sw': 'Imeboreshwa kwa {}', 'zh': '为 {} 优化'},
  'home.session': {'en': 'Session', 'sw': 'Kikao', 'zh': '会话'},
  'home.download': {'en': 'Download', 'sw': 'Pakua', 'zh': '下载'},
  'home.upload': {'en': 'Upload', 'sw': 'Pakia', 'zh': '上传'},
  'home.protocol': {'en': 'Protocol', 'sw': 'Protocol', 'zh': '协议'},
  'home.details': {'en': 'Details', 'sw': 'Zaidi', 'zh': '详情'},
  'home.demoNote': {
    'en': 'The real tunnel is not wired on this platform yet. Use the desktop version for now.',
    'sw': 'Toleo la simu bado halijaunganisha tunnel halisi. Tumia toleo la kompyuta kwa sasa.',
    'zh': '此平台尚未接入真实隧道。目前请使用桌面版。'
  },
  'home.subExpired': {
    'en': 'Your plan has expired. Renew to keep going.',
    'sw': 'Kifurushi chako kimeisha. Renew ili kuendelea.',
    'zh': '您的套餐已过期。请续订以继续。'
  },
  'home.subSuspended': {
    'en': 'Your account is suspended. Contact support.',
    'sw': 'Akaunti imesimamishwa. Wasiliana na support.',
    'zh': '您的账户已被暂停。请联系客服。'
  },
  'home.renew': {'en': 'Renew', 'sw': 'Renew', 'zh': '续订'},
  'home.pendingProof': {
    'en': 'Your payment is awaiting admin approval. Access unlocks once approved.',
    'sw': 'Malipo yako yanasubiri idhini ya admin. Utapata access ukishaidhinishwa.',
    'zh': '您的付款正在等待管理员审批。批准后即可使用。'
  },
  'home.pendingNoProof': {
    'en': 'You have an incomplete payment. Open "Plan & payment" to finish.',
    'sw': 'Una malipo ambayo hayajakamilika. Fungua "Kifurushi & malipo" kumalizia.',
    'zh': '您有一笔未完成的付款。打开"套餐与付款"以完成。'
  },

  // ---- servers ----
  'servers.title': {'en': 'Choose a server', 'sw': 'Chagua seva', 'zh': '选择服务器'},
  'servers.optimalTitle': {'en': 'Best server automatically', 'sw': 'Seva bora kiotomatiki', 'zh': '自动选择最佳服务器'},
  'servers.optimalSub': {
    'en': 'Connect to the fastest server',
    'sw': 'Unganisha kwenye seva ya haraka zaidi',
    'zh': '连接到最快的服务器'
  },

  // ---- session stats ----
  'stats.title': {'en': 'Session stats', 'sw': 'Takwimu za kikao', 'zh': '会话统计'},
  'stats.secure': {'en': 'Secure connection', 'sw': 'Muunganisho salama', 'zh': '安全连接'},
  'stats.duration': {'en': 'DURATION', 'sw': 'MUDA', 'zh': '时长'},
  'stats.throughput': {'en': 'Throughput', 'sw': 'Mwendokasi', 'zh': '吞吐量'},
  'stats.downloaded': {'en': 'Downloaded', 'sw': 'Imepakuliwa', 'zh': '已下载'},
  'stats.uploaded': {'en': 'Uploaded', 'sw': 'Imepakiwa', 'zh': '已上传'},
  'stats.peak': {'en': 'Peak', 'sw': 'Kilele', 'zh': '峰值'},

  // ---- settings ----
  'settings.title': {'en': 'Settings', 'sw': 'Mipangilio', 'zh': '设置'},
  'settings.account': {'en': 'Account', 'sw': 'Akaunti', 'zh': '账户'},
  'settings.user': {'en': 'User', 'sw': 'Mtumiaji', 'zh': '用户'},
  'settings.noPlan': {'en': 'No active plan', 'sw': 'Hakuna kifurushi hai', 'zh': '无有效套餐'},
  'settings.planAndPay': {'en': 'Plan & payment', 'sw': 'Kifurushi & malipo', 'zh': '套餐与付款'},
  'settings.daysRemain': {'en': '{} days remaining', 'sw': 'siku {} zimebaki', 'zh': '剩余 {} 天'},
  'settings.expired': {'en': 'expired', 'sw': 'imeisha', 'zh': '已过期'},
  'settings.logout': {'en': 'Log out', 'sw': 'Toka', 'zh': '退出登录'},
  'settings.logoutConfirm': {
    'en': 'You will need to sign in again with a code.',
    'sw': 'Utahitaji kuingia tena kwa msimbo.',
    'zh': '您需要使用验证码重新登录。'
  },
  'settings.connection': {'en': 'Connection', 'sw': 'Muunganisho', 'zh': '连接'},
  'settings.killSwitch': {'en': 'Kill-switch', 'sw': 'Kill-switch', 'zh': '断网保护'},
  'settings.killSwitchSub': {
    'en': 'Block all internet if the VPN drops',
    'sw': 'Zuia intaneti yote endapo VPN itakatika',
    'zh': 'VPN 断开时阻断所有网络'
  },
  'settings.autoConnect': {'en': 'Connect on launch', 'sw': 'Unganisha app ikianzishwa', 'zh': '启动时连接'},
  'settings.autoConnectSub': {
    'en': 'Automatically connect to the last server',
    'sw': 'Jiunganishe kiotomatiki kwenye seva ya mwisho',
    'zh': '自动连接到上次的服务器'
  },
  'settings.autoReconnect': {'en': 'Auto-reconnect', 'sw': 'Unganisha upya kiotomatiki', 'zh': '自动重连'},
  'settings.autoReconnectSub': {
    'en': 'After a network change or drop',
    'sw': 'Baada ya kubadilika kwa mtandao au kukatika',
    'zh': '网络变化或断开后'
  },
  'settings.protocol': {'en': 'Protocol', 'sw': 'Protocol', 'zh': '协议'},
  'settings.protoAuto': {'en': 'Auto — picks the fastest', 'sw': 'Auto — huchagua ya haraka', 'zh': 'Auto — 自动选择最快'},
  'settings.protoReality': {'en': 'Disguised as HTTPS (TCP)', 'sw': 'Ficha kama HTTPS (TCP)', 'zh': '伪装为 HTTPS (TCP)'},
  'settings.protoHy2': {'en': 'High speed (QUIC/UDP)', 'sw': 'Kasi ya juu (QUIC/UDP)', 'zh': '高速 (QUIC/UDP)'},
  'settings.language': {'en': 'Language', 'sw': 'Lugha', 'zh': '语言'},
  'settings.about': {'en': 'About', 'sw': 'Kuhusu', 'zh': '关于'},
  'settings.version': {'en': 'Version', 'sw': 'Toleo', 'zh': '版本'},
  'settings.copyPeer': {'en': 'Copy Peer ID', 'sw': 'Nakili Peer ID', 'zh': '复制 Peer ID'},
  'settings.peerCopied': {'en': 'Peer ID copied', 'sw': 'Peer ID imenakiliwa', 'zh': '已复制 Peer ID'},
  'settings.tos': {'en': 'Terms of Service', 'sw': 'Masharti ya Huduma', 'zh': '服务条款'},
  'settings.privacy': {'en': 'Privacy Policy', 'sw': 'Sera ya Faragha', 'zh': '隐私政策'},

  // ---- nav ----
  'nav.home': {'en': 'Home', 'sw': 'Nyumbani', 'zh': '主页'},
  'nav.servers': {'en': 'Servers', 'sw': 'Seva', 'zh': '服务器'},
  'nav.settings': {'en': 'Settings', 'sw': 'Mipangilio', 'zh': '设置'},

  // ---- android engine handoff ----
  'handoff.androidNote': {
    'en': 'On Android, tap Connect to open the Mbunie VPN Engine and start the tunnel there.',
    'sw': 'Kwenye Android, gusa Unganisha kufungua Mbunie VPN Engine na kuanzisha tunnel hapo.',
    'zh': '在 Android 上，点击"连接"打开 Mbunie VPN 引擎并在那里启动隧道。'
  },
  'handoff.connect': {'en': 'Open Mbunie VPN Engine', 'sw': 'Fungua Mbunie VPN Engine', 'zh': '打开 Mbunie VPN 引擎'},
  'handoff.noSub': {
    'en': 'Buy a plan first, then connect.',
    'sw': 'Lipia kifurushi kwanza, kisha unganisha.',
    'zh': '请先购买套餐，然后连接。'
  },
  'handoff.needEngineTitle': {
    'en': 'Install the VPN engine',
    'sw': 'Sakinisha injini ya VPN',
    'zh': '安装 VPN 引擎'
  },
  'handoff.needEngineBody': {
    'en':
        'Android needs the separate "Mbunie VPN Engine" app to carry traffic. Install it once, then come back and tap Connect.',
    'sw':
        'Android inahitaji app tofauti ya "Mbunie VPN Engine" kubeba trafiki. Sakinisha mara moja, kisha rudi ugonge Unganisha.',
    'zh': 'Android 需要单独的"Mbunie VPN 引擎"应用来传输流量。安装一次后返回并点击"连接"。'
  },
  'handoff.getEngine': {'en': 'Download', 'sw': 'Pakua', 'zh': '下载'},
  'handoff.opened': {
    'en': 'Opened Mbunie VPN Engine — approve the connection there.',
    'sw': 'Mbunie VPN Engine imefunguliwa — idhinisha muunganisho hapo.',
    'zh': '已打开 Mbunie VPN 引擎 — 请在那里批准连接。'
  },

  // ---- vpn controller messages ----
  'vpn.noSub': {
    'en': 'No active subscription. Buy a plan first.',
    'sw': 'Hakuna subscription hai. Lipia kifurushi kwanza.',
    'zh': '没有有效订阅。请先购买套餐。'
  },
  'vpn.failed': {'en': 'Connection failed.', 'sw': 'Muunganisho umeshindwa.', 'zh': '连接失败。'},
};
