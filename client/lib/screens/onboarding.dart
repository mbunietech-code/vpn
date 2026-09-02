import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

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
    } catch (e) {
      setState(() => _error = 'Network error. Check the connection.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

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
              constraints: const BoxConstraints(maxWidth: 380),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Icon(Icons.shield_rounded, color: c.brand, size: 44),
                  const SizedBox(height: 12),
                  Text('Mbunie VPN',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                          fontSize: 22,
                          fontWeight: FontWeight.w700,
                          color: c.textPrimary)),
                  const SizedBox(height: 4),
                  Text('Ingia kwa namba ya simu au email',
                      textAlign: TextAlign.center,
                      style: TextStyle(fontSize: 13, color: c.textSecondary)),
                  const SizedBox(height: 28),
                  if (!_sent) ...[
                    TextField(
                      controller: _id,
                      keyboardType: TextInputType.emailAddress,
                      autofocus: true,
                      onChanged: (_) => setState(() {}),
                      onSubmitted: (_) {
                        if (_id.text.trim().isNotEmpty && !_busy) {
                          _run(() async {
                            final hint =
                                await state.requestOtpReturningDebug(_id.text);
                            setState(() {
                              _sent = true;
                              _debugHint = hint;
                            });
                          });
                        }
                      },
                      decoration: const InputDecoration(
                        labelText: 'Simu (+255…) au email',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    ElevatedButton(
                      onPressed: _busy || _id.text.trim().isEmpty
                          ? null
                          : () => _run(() async {
                                final hint = await state
                                    .requestOtpReturningDebug(_id.text);
                                setState(() {
                                  _sent = true;
                                  _debugHint = hint;
                                });
                              }),
                      child: _busy
                          ? const _BtnSpinner()
                          : const Text('Tuma msimbo (OTP)'),
                    ),
                  ] else ...[
                    TextField(
                      controller: _code,
                      keyboardType: TextInputType.number,
                      maxLength: 6,
                      autofocus: true,
                      onChanged: (v) {
                        setState(() {});
                        if (v.length == 6 && !_busy) {
                          _run(() => state.verifyOtp(v));
                        }
                      },
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                      decoration: const InputDecoration(
                        labelText: 'Msimbo wa tarakimu 6',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    if (_debugHint != null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: Text('Msimbo wa majaribio: $_debugHint',
                            style: TextStyle(fontSize: 12, color: c.textHint)),
                      ),
                    ElevatedButton(
                      onPressed: _busy || _code.text.length != 6
                          ? null
                          : () => _run(() => state.verifyOtp(_code.text)),
                      child: _busy
                          ? const _BtnSpinner()
                          : const Text('Thibitisha'),
                    ),
                    TextButton(
                      onPressed: _busy
                          ? null
                          : () => setState(() {
                                _sent = false;
                                _code.clear();
                              }),
                      child: const Text('Badilisha namba'),
                    ),
                  ],
                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    Text(_error!,
                        textAlign: TextAlign.center,
                        style: TextStyle(color: c.danger, fontSize: 13)),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
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
  String _currency = 'usd';
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
          _currency = state.currencies.contains('usd')
              ? 'usd'
              : state.currencies.first;
          _loading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = 'Imeshindwa kupakia mipango.';
          _loading = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = MvpnScope.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Chagua Mpango'),
        actions: [
          TextButton(
            onPressed: () => state.logout(),
            child: const Text('Toka'),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : ListView(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
                  children: [
                    if (state.awaitingPayment)
                      _AwaitingBanner(onRefresh: () => state.refreshSubscription()),
                    if (state.currencies.length > 1)
                      Align(
                        alignment: Alignment.centerRight,
                        child: SegmentedButton<String>(
                          segments: [
                            for (final cur in state.currencies)
                              ButtonSegment(
                                value: cur,
                                label: Text(cur == 'cny' ? '¥ CNY' : '\$ USD'),
                              ),
                          ],
                          selected: {_currency},
                          onSelectionChanged: (s) =>
                              setState(() => _currency = s.first),
                        ),
                      ),
                    const SizedBox(height: 12),
                    for (final plan in state.plans)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: _PlanCard(
                          plan: plan,
                          price: plan.priceDisplay[_currency] ?? '—',
                          onChoose: () => Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) => CheckoutScreen(
                                plan: plan,
                                currency: _currency,
                                price: plan.priceDisplay[_currency] ?? '—',
                              ),
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({required this.plan, required this.price, required this.onChoose});
  final Plan plan;
  final String price;
  final VoidCallback onChoose;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return MvpnCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(plan.name,
                    style: TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w700,
                        color: c.textPrimary)),
              ),
              Text(price,
                  style: TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.w700,
                      color: c.brand)),
            ],
          ),
          const SizedBox(height: 4),
          Text('Siku ${plan.days} · vifaa ${plan.maxDevices}',
              style: TextStyle(fontSize: 13, color: c.textSecondary)),
          const SizedBox(height: 12),
          ElevatedButton(onPressed: onChoose, child: const Text('Chagua')),
        ],
      ),
    );
  }
}

// ============================ CHECKOUT ================================

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

class _CheckoutScreenState extends State<CheckoutScreen> {
  String _provider = 'stripe';
  bool _busy = false;
  bool _waiting = false;
  String? _error;

  Future<void> _pay() async {
    final state = MvpnScope.read(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final url = await state.startCheckout(widget.plan.code, _provider, widget.currency);
      final uri = Uri.parse(url);
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
      }
      setState(() => _waiting = true);
      _poll();
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'Imeshindwa kuanzisha malipo.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _poll() async {
    final state = MvpnScope.read(context);
    for (var i = 0; i < 60 && mounted && _waiting; i++) {
      await Future<void>.delayed(const Duration(seconds: 3));
      try {
        await state.refreshSubscription();
        if (state.subStatus == 'active') {
          if (mounted) Navigator.of(context).popUntil((r) => r.isFirst);
          return;
        }
      } catch (_) {}
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    final devMode = MvpnConfig.apiBase.contains('localhost') ||
        MvpnConfig.apiBase.contains('10.0.2.2');

    return Scaffold(
      appBar: AppBar(title: const Text('Malipo')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        children: [
          MvpnCard(
            child: Row(
              children: [
                Expanded(
                  child: Text('${widget.plan.name} · siku ${widget.plan.days}',
                      style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: c.textPrimary)),
                ),
                Text(widget.price,
                    style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.w700,
                        color: c.brand)),
              ],
            ),
          ),
          const SectionCaption('Njia ya malipo'),
          RadioGroup<String>(
            groupValue: _provider,
            onChanged: (v) => setState(() => _provider = v ?? _provider),
            child: Column(
              children: [
                for (final entry in MvpnConfig.providers.entries)
                  RadioListTile<String>(
                    value: entry.key,
                    title: Text(entry.key == 'stripe' ? 'Stripe' : 'Cryptomus'),
                    subtitle: Text(entry.value),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          if (_waiting)
            Column(
              children: [
                const CircularProgressIndicator(),
                const SizedBox(height: 12),
                Text('Inasubiri malipo yakamilike…',
                    style: TextStyle(color: c.textSecondary)),
              ],
            )
          else
            ElevatedButton(
              onPressed: _busy ? null : _pay,
              child: _busy ? const _BtnSpinner() : const Text('Lipa sasa'),
            ),
          if (devMode) ...[
            const SizedBox(height: 8),
            OutlinedButton(
              onPressed: () async {
                final state = MvpnScope.read(context);
                await state.startCheckout(widget.plan.code, _provider, widget.currency)
                    .catchError((_) => '');
                await state.devCompletePayment();
                if (context.mounted && state.subStatus == 'active') {
                  Navigator.of(context).popUntil((r) => r.isFirst);
                }
              },
              child: const Text('(dev) Kamilisha malipo sasa'),
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: TextStyle(color: c.danger, fontSize: 13)),
          ],
        ],
      ),
    );
  }
}

// ============================ shared ================================

class _AwaitingBanner extends StatelessWidget {
  const _AwaitingBanner({required this.onRefresh});
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: c.surfaceAlt,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: c.border),
      ),
      child: Row(
        children: [
          Icon(Icons.hourglass_bottom, size: 18, color: c.warning),
          const SizedBox(width: 8),
          Expanded(
              child: Text('Malipo yako yanashughulikiwa…',
                  style: TextStyle(fontSize: 13, color: c.textSecondary))),
          TextButton(onPressed: onRefresh, child: const Text('Angalia')),
        ],
      ),
    );
  }
}

class _BtnSpinner extends StatelessWidget {
  const _BtnSpinner();

  @override
  Widget build(BuildContext context) => const SizedBox(
        height: 18,
        width: 18,
        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
      );
}
