import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config.dart';
import '../models.dart';
import '../services/api_client.dart';
import '../services/mvpn_scope.dart';
import '../theme/mvpn_theme.dart';
import '../widgets/common.dart';

// ============================ AUTH ====================================

class AuthScreen extends StatefulWidget {
  const AuthScreen({super.key});

  @override
  State<AuthScreen> createState() => _AuthScreenState();
}

class _AuthScreenState extends State<AuthScreen> {
  final _id = TextEditingController();
  final _code = TextEditingController();
  bool _sent = false;
  bool _busy = false;
  String? _error;
  String? _debugHint;

  Future<void> _run(Future<void> Function() action) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await action();
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (_) {
      setState(() => _error = 'Tatizo la mtandao. Angalia muunganisho.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _send0() => _run(() async {
        final hint = await MvpnScope.read(context).requestOtpReturningDebug(_id.text);
        setState(() {
          _sent = true;
          _debugHint = hint;
        });
      });

  @override
  Widget build(BuildContext context) {
    final state = MvpnScope.of(context);
    final c = context.mvpn;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 400),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SizedBox(height: 12),
                  Center(
                    child: Container(
                      width: 68,
                      height: 68,
                      decoration: BoxDecoration(
                        gradient: c.brandGradient,
                        borderRadius: BorderRadius.circular(20),
                        boxShadow: [
                          BoxShadow(
                              color: c.brand.withValues(alpha: 0.35),
                              blurRadius: 28,
                              offset: const Offset(0, 12)),
                        ],
                      ),
                      child: Icon(Icons.shield_rounded,
                          color: c.onBrand, size: 34),
                    ),
                  ),
                  const SizedBox(height: 20),
                  Text('Mbunie VPN',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                          fontSize: 26,
                          fontWeight: FontWeight.w800,
                          color: c.textPrimary)),
                  const SizedBox(height: 6),
                  Text(
                    _sent
                        ? 'Tumetuma msimbo wa tarakimu 6 kwenda\n${_id.text.trim()}'
                        : 'Intaneti bila mipaka. Ingia kwa simu au email.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        fontSize: 13.5, height: 1.4, color: c.textSecondary),
                  ),
                  const SizedBox(height: 30),
                  AnimatedSwitcher(
                    duration: const Duration(milliseconds: 220),
                    child: _sent ? _verifyStep(state, c) : _idStep(c),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 16),
                    Text(_error!,
                        textAlign: TextAlign.center,
                        style: TextStyle(color: c.danger, fontSize: 13)),
                  ],
                  const SizedBox(height: 24),
                  Text(
                    'Kwa kuendelea unakubali Masharti ya Huduma\nna Sera ya Faragha.',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 11, height: 1.5, color: c.textHint),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _idStep(MvpnColors c) {
    return Column(
      key: const ValueKey('id'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: _id,
          keyboardType: TextInputType.emailAddress,
          autofocus: true,
          onChanged: (_) => setState(() {}),
          onSubmitted: (_) =>
              _id.text.trim().isNotEmpty && !_busy ? _send0() : null,
          decoration: const InputDecoration(
            hintText: '+8613… au barua@pepe.com',
            prefixIcon: Icon(Icons.alternate_email_rounded),
          ),
        ),
        const SizedBox(height: 14),
        ElevatedButton(
          onPressed: _busy || _id.text.trim().isEmpty ? null : _send0,
          child: _busy ? const BtnSpinner() : const Text('Tuma msimbo'),
        ),
      ],
    );
  }

  Widget _verifyStep(dynamic state, MvpnColors c) {
    return Column(
      key: const ValueKey('code'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: _code,
          keyboardType: TextInputType.number,
          maxLength: 6,
          autofocus: true,
          textAlign: TextAlign.center,
          style: const TextStyle(
              fontSize: 26, fontWeight: FontWeight.w800, letterSpacing: 10),
          onChanged: (v) {
            setState(() {});
            if (v.length == 6 && !_busy) _run(() => state.verifyOtp(v));
          },
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          decoration: const InputDecoration(counterText: '', hintText: '••••••'),
        ),
        if (_debugHint != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text('Msimbo wa majaribio: $_debugHint',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 12, color: c.textHint)),
          ),
        const SizedBox(height: 14),
        ElevatedButton(
          onPressed: _busy || _code.text.length != 6
              ? null
              : () => _run(() => state.verifyOtp(_code.text)),
          child: _busy ? const BtnSpinner() : const Text('Thibitisha na uingie'),
        ),
        TextButton(
          onPressed: _busy
              ? null
              : () => setState(() {
                    _sent = false;
                    _code.clear();
                    _error = null;
                  }),
          child: const Text('Badilisha namba / email'),
        ),
      ],
    );
  }
}

// ============================ PLANS ===================================

class PlansScreen extends StatefulWidget {
  const PlansScreen({super.key});

  @override
  State<PlansScreen> createState() => _PlansScreenState();
}

class _PlansScreenState extends State<PlansScreen> {
  String _currency = 'cny';
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final state = MvpnScope.read(context);
    try {
      await state.loadPlans();
      await state.refreshSubscription();
      if (mounted) {
        setState(() {
          _currency = state.currencies.first;
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _error = 'Imeshindwa kupakia mipango. Jaribu tena.';
          _loading = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = MvpnScope.of(context);
    final c = context.mvpn;
    final canPop = Navigator.of(context).canPop();

    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: canPop,
        title: const Text('Chagua kifurushi'),
        actions: [
          if (!canPop)
            TextButton(onPressed: state.logout, child: const Text('Toka')),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? _ErrorBox(text: _error!, onRetry: _load)
              : ListView(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
                  children: [
                    Text('Fungua intaneti nzima',
                        style: TextStyle(
                            fontSize: 22,
                            fontWeight: FontWeight.w800,
                            color: c.textPrimary)),
                    const SizedBox(height: 4),
                    Text('Kasi ya juu · seva Tokyo · vifaa vingi',
                        style:
                            TextStyle(fontSize: 13, color: c.textSecondary)),
                    const SizedBox(height: 18),
                    if (state.awaitingPayment)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 14),
                        child: InlineNotice(
                          text: 'Malipo yako yanashughulikiwa…',
                          tone: NoticeTone.warning,
                          action: 'Angalia',
                          onAction: state.refreshSubscription,
                        ),
                      ),
                    if (state.currencies.length > 1)
                      Center(
                        child: SegmentedButton<String>(
                          showSelectedIcon: false,
                          segments: [
                            for (final cur in state.currencies)
                              ButtonSegment(
                                value: cur,
                                label: Text(cur == 'cny' ? '¥ Yuan' : '\$ USD'),
                              ),
                          ],
                          selected: {_currency},
                          onSelectionChanged: (s) =>
                              setState(() => _currency = s.first),
                        ),
                      ),
                    const SizedBox(height: 16),
                    for (var i = 0; i < state.plans.length; i++)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: _PlanCard(
                          plan: state.plans[i],
                          currency: _currency,
                          highlight: state.plans.length > 2 &&
                              i == state.plans.length - 2,
                          onChoose: () => Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) => CheckoutScreen(
                                plan: state.plans[i],
                                currency: _currency,
                                price: state.plans[i].priceDisplay[_currency] ?? '—',
                              ),
                            ),
                          ),
                        ),
                      ),
                    const SizedBox(height: 8),
                    Center(
                      child: Text(
                        'Malipo salama kupitia Alipay · WeChat · Kadi · Crypto',
                        style: TextStyle(fontSize: 11.5, color: c.textHint),
                      ),
                    ),
                  ],
                ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({
    required this.plan,
    required this.currency,
    required this.onChoose,
    this.highlight = false,
  });
  final Plan plan;
  final String currency;
  final VoidCallback onChoose;
  final bool highlight;

  String get _perMonth {
    final price = plan.priceDisplay[currency] ?? '';
    final n = double.tryParse(price.replaceAll(RegExp(r'[^0-9.]'), ''));
    if (n == null || plan.days < 28) return '';
    final months = plan.days / 30.0;
    final sym = price.replaceAll(RegExp(r'[0-9.,]'), '').trim();
    return '$sym${(n / months).toStringAsFixed(n / months >= 10 ? 0 : 2)} / mwezi';
  }

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return MvpnCard(
      onTap: onChoose,
      borderColor: highlight ? c.brand : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(plan.name,
                  style: TextStyle(
                      fontSize: 16.5,
                      fontWeight: FontWeight.w800,
                      color: c.textPrimary)),
              const SizedBox(width: 8),
              if (highlight)
                MvpnBadge('Maarufu', color: c.brand, icon: Icons.star_rounded),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(plan.priceDisplay[currency] ?? '—',
                  style: TextStyle(
                      fontSize: 28,
                      fontWeight: FontWeight.w800,
                      color: c.textPrimary)),
              const SizedBox(width: 8),
              if (_perMonth.isNotEmpty)
                Text(_perMonth,
                    style: TextStyle(fontSize: 12.5, color: c.textSecondary)),
            ],
          ),
          const SizedBox(height: 14),
          _feature(c, Icons.event_available_rounded, 'Siku ${plan.days}'),
          _feature(c, Icons.devices_rounded, 'Vifaa ${plan.maxDevices} kwa wakati mmoja'),
          _feature(c, Icons.all_inclusive_rounded, 'Data bila kikomo'),
          _feature(c, Icons.bolt_rounded, 'VLESS-REALITY + Hysteria2'),
          const SizedBox(height: 14),
          ElevatedButton(
            onPressed: onChoose,
            style: highlight
                ? null
                : ElevatedButton.styleFrom(
                    backgroundColor: c.surfaceAlt,
                    foregroundColor: c.textPrimary),
            child: const Text('Chagua kifurushi'),
          ),
        ],
      ),
    );
  }

  Widget _feature(MvpnColors c, IconData icon, String text) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          children: [
            Icon(icon, size: 15, color: c.success),
            const SizedBox(width: 9),
            Text(text, style: TextStyle(fontSize: 13, color: c.textSecondary)),
          ],
        ),
      );
}

// ============================ CHECKOUT (manual v1) ====================

class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({
    super.key,
    required this.plan,
    required this.currency,
    required this.price,
  });

  final Plan plan;
  final String currency;
  final String price;

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

enum _Step { pickMethod, pay, waiting }

class _CheckoutScreenState extends State<CheckoutScreen> {
  _Step _step = _Step.pickMethod;
  PayMethod? _method;
  int? _invoiceId;
  File? _proof;
  final _note = TextEditingController();
  bool _busy = false;
  String? _error;

  bool get _devMode =>
      MvpnConfig.apiBase.contains('localhost') ||
      MvpnConfig.apiBase.contains('10.0.2.2');

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadMethods());
  }

  Future<void> _loadMethods() async {
    try {
      await MvpnScope.read(context).loadPayMethods();
    } catch (_) {}
    if (mounted) setState(() {});
  }

  Future<void> _createInvoice() async {
    final state = MvpnScope.read(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      _invoiceId = await state.startManualCheckout(
          widget.plan.code, widget.currency, _method?.id);
      setState(() => _step = _Step.pay);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (_) {
      setState(() => _error = 'Imeshindwa. Jaribu tena.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickProof() async {
    final r = await FilePicker.platform.pickFiles(
      type: FileType.image,
      withData: false,
    );
    final path = r?.files.single.path;
    if (path != null) setState(() => _proof = File(path));
  }

  Future<void> _submit() async {
    if (_proof == null || _invoiceId == null) return;
    final state = MvpnScope.read(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await state.submitProof(_invoiceId!, _proof!, note: _note.text.trim());
      setState(() => _step = _Step.waiting);
      _poll();
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (_) {
      setState(() => _error = 'Imeshindwa kupakia. Jaribu tena.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _poll() async {
    final state = MvpnScope.read(context);
    for (var i = 0; i < 200 && mounted && _step == _Step.waiting; i++) {
      await Future<void>.delayed(const Duration(seconds: 5));
      try {
        await state.refreshSubscription();
        if (state.subStatus == 'active' && mounted) {
          Navigator.of(context).popUntil((r) => r.isFirst);
          return;
        }
      } catch (_) {}
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = MvpnScope.of(context);
    final c = context.mvpn;

    return Scaffold(
      appBar: AppBar(title: const Text('Malipo')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
        children: [
          MvpnCard(
            child: Row(
              children: [
                Expanded(
                  child: Text('${widget.plan.name} · siku ${widget.plan.days}',
                      style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          color: c.textPrimary)),
                ),
                Text(widget.price,
                    style: TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w800,
                        color: c.textPrimary)),
              ],
            ),
          ),
          const SizedBox(height: 8),
          if (_step == _Step.pickMethod) ..._pickMethodStep(state, c),
          if (_step == _Step.pay) ..._payStep(c),
          if (_step == _Step.waiting) _waitingCard(c),
          if (_error != null) ...[
            const SizedBox(height: 14),
            Text(_error!, style: TextStyle(color: c.danger, fontSize: 13)),
          ],
          if (_devMode && _step != _Step.waiting) ...[
            const SizedBox(height: 10),
            OutlinedButton(
              onPressed: () async {
                await state.devCompletePayment();
                if (context.mounted && state.subStatus == 'active') {
                  Navigator.of(context).popUntil((r) => r.isFirst);
                }
              },
              child: const Text('(dev) Kamilisha sasa'),
            ),
          ],
        ],
      ),
    );
  }

  List<Widget> _pickMethodStep(dynamic state, MvpnColors c) {
    final methods = state.payMethods as List<PayMethod>;
    return [
      const SectionCaption('Chagua njia ya malipo'),
      if (methods.isEmpty)
        InlineNotice(
          text: 'Njia za malipo hazijawekwa bado. Wasiliana na support.',
          tone: NoticeTone.warning,
        )
      else
        for (final m in methods)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: _MethodTile(
              method: m,
              selected: _method?.id == m.id,
              onTap: () => setState(() => _method = m),
            ),
          ),
      const SizedBox(height: 8),
      ElevatedButton(
        onPressed: _busy || _method == null ? null : _createInvoice,
        child: _busy ? const BtnSpinner() : const Text('Endelea'),
      ),
    ];
  }

  List<Widget> _payStep(MvpnColors c) {
    final m = _method!;
    return [
      const SectionCaption('Lipa kiasi hiki'),
      MvpnCard(
        child: Column(
          children: [
            Text('Lipa kwa ${m.label}',
                style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: c.textPrimary)),
            const SizedBox(height: 4),
            Text(widget.price,
                style: TextStyle(
                    fontSize: 30,
                    fontWeight: FontWeight.w800,
                    color: c.brand)),
            if (m.qrUrl != null) ...[
              const SizedBox(height: 14),
              ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Image.network(m.qrUrl!,
                    height: 220,
                    fit: BoxFit.contain,
                    errorBuilder: (_, _, _) => const SizedBox.shrink()),
              ),
            ],
            if (m.accountRef != null) ...[
              const SizedBox(height: 12),
              SelectableText(m.accountRef!,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: c.textPrimary)),
            ],
            if (m.instructions != null) ...[
              const SizedBox(height: 12),
              Text(m.instructions!,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                      fontSize: 12.5, height: 1.4, color: c.textSecondary)),
            ],
          ],
        ),
      ),
      const SectionCaption('Pakia uthibitisho wa malipo'),
      MvpnCard(
        child: Column(
          children: [
            if (_proof == null)
              OutlinedButton.icon(
                onPressed: _pickProof,
                icon: const Icon(Icons.upload_rounded, size: 18),
                label: const Text('Chagua picha ya risiti / screenshot'),
              )
            else
              Column(
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(12),
                    child: Image.file(_proof!, height: 160, fit: BoxFit.cover),
                  ),
                  TextButton(
                      onPressed: _pickProof, child: const Text('Badilisha picha')),
                ],
              ),
            const SizedBox(height: 8),
            TextField(
              controller: _note,
              maxLength: 300,
              decoration: const InputDecoration(
                hintText: 'Kumbukumbu ya malipo (hiari)',
                counterText: '',
              ),
            ),
          ],
        ),
      ),
      const SizedBox(height: 10),
      ElevatedButton(
        onPressed: _busy || _proof == null ? null : _submit,
        child: _busy ? const BtnSpinner() : const Text('Tuma kwa uthibitisho'),
      ),
    ];
  }

  Widget _waitingCard(MvpnColors c) => MvpnCard(
        child: Column(
          children: [
            Icon(Icons.hourglass_top_rounded, size: 34, color: c.warning),
            const SizedBox(height: 12),
            Text('Inasubiri idhini ya admin',
                style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    color: c.textPrimary)),
            const SizedBox(height: 6),
            Text(
              'Tumepokea uthibitisho wako. Admin ataangalia na kuidhinisha '
              'haraka iwezekanavyo. App itajiwasha yenyewe ukishaidhinishwa.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 12.5, height: 1.4, color: c.textSecondary),
            ),
            const SizedBox(height: 14),
            const SizedBox(
                width: 26,
                height: 26,
                child: CircularProgressIndicator(strokeWidth: 3)),
          ],
        ),
      );
}

class _MethodTile extends StatelessWidget {
  const _MethodTile(
      {required this.method, required this.selected, required this.onTap});
  final PayMethod method;
  final bool selected;
  final VoidCallback onTap;

  IconData get _icon => switch (method.type) {
        'alipay' => Icons.account_balance_wallet_rounded,
        'wechat' => Icons.chat_rounded,
        'bank' => Icons.account_balance_rounded,
        'crypto' => Icons.currency_bitcoin_rounded,
        _ => Icons.payments_rounded,
      };

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: selected ? c.brand.withValues(alpha: 0.06) : c.surface,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
              color: selected ? c.brand : c.border, width: selected ? 1.6 : 1),
        ),
        child: Row(
          children: [
            Icon(_icon, color: selected ? c.brand : c.textSecondary, size: 22),
            const SizedBox(width: 12),
            Expanded(
              child: Text(method.label,
                  style: TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                      color: c.textPrimary)),
            ),
            Icon(
              selected
                  ? Icons.radio_button_checked_rounded
                  : Icons.radio_button_unchecked_rounded,
              color: selected ? c.brand : c.textHint,
              size: 20,
            ),
          ],
        ),
      ),
    );
  }
}

class _ErrorBox extends StatelessWidget {
  const _ErrorBox({required this.text, required this.onRetry});
  final String text;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.cloud_off_rounded, size: 44, color: c.textHint),
            const SizedBox(height: 14),
            Text(text,
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 14, color: c.textSecondary)),
            const SizedBox(height: 16),
            OutlinedButton(onPressed: onRetry, child: const Text('Jaribu tena')),
          ],
        ),
      ),
    );
  }
}
