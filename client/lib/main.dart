import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'l10n/app_text.dart';
import 'screens/home_screen.dart';
import 'screens/onboarding.dart';
import 'screens/servers_screen.dart';
import 'screens/settings_screen.dart';
import 'services/app_state.dart';
import 'services/mvpn_scope.dart';
import 'theme/mvpn_theme.dart';

void main() => runApp(const MvpnApp());

class MvpnApp extends StatefulWidget {
  const MvpnApp({super.key});

  @override
  State<MvpnApp> createState() => _MvpnAppState();
}

class _MvpnAppState extends State<MvpnApp> {
  final _state = AppState();

  @override
  void initState() {
    super.initState();
    _state.bootstrap();
  }

  @override
  void dispose() {
    _state.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final device = WidgetsBinding.instance.platformDispatcher.locale;

    return MvpnScope(
      state: _state,
      child: ListenableBuilder(
        listenable: _state,
        builder: (context, _) {
          final code = AppText.resolve(_state.localeOverride, device);
          return AppTextScope(
            code: code,
            child: MaterialApp(
              title: 'Mbunie VPN',
              debugShowCheckedModeBanner: false,
              theme: MvpnTheme.light,
              darkTheme: MvpnTheme.dark,
              themeMode: ThemeMode.system,
              locale: Locale(code),
              supportedLocales: const [Locale('en'), Locale('sw'), Locale('zh')],
              localizationsDelegates: const [
                GlobalMaterialLocalizations.delegate,
                GlobalWidgetsLocalizations.delegate,
                GlobalCupertinoLocalizations.delegate,
              ],
              home: const _Gate(),
            ),
          );
        },
      ),
    );
  }
}

class _Gate extends StatelessWidget {
  const _Gate();

  @override
  Widget build(BuildContext context) {
    final state = MvpnScope.of(context);
    return switch (state.gate) {
      AuthGate.loading => const _Splash(),
      AuthGate.needsAuth => const AuthScreen(),
      AuthGate.needsPlan => const PlansScreen(),
      AuthGate.ready => const RootShell(),
    };
  }
}

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 64,
              height: 64,
              decoration: BoxDecoration(
                gradient: c.brandGradient,
                borderRadius: BorderRadius.circular(18),
              ),
              child: Icon(Icons.shield_rounded, color: c.onBrand, size: 32),
            ),
            const SizedBox(height: 20),
            const CircularProgressIndicator(),
          ],
        ),
      ),
    );
  }
}

class RootShell extends StatefulWidget {
  const RootShell({super.key});

  @override
  State<RootShell> createState() => _RootShellState();
}

class _RootShellState extends State<RootShell> {
  int _index = 0;

  static const _tabs = [HomeScreen(), ServersScreen(), SettingsScreen()];

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return Scaffold(
      body: IndexedStack(index: _index, children: _tabs),
      bottomNavigationBar: DecoratedBox(
        decoration: BoxDecoration(border: Border(top: BorderSide(color: c.border))),
        child: NavigationBar(
          selectedIndex: _index,
          onDestinationSelected: (i) => setState(() => _index = i),
          destinations: [
            NavigationDestination(
                icon: const Icon(Icons.home_outlined),
                selectedIcon: const Icon(Icons.home_rounded),
                label: context.tt('nav.home')),
            NavigationDestination(
                icon: const Icon(Icons.dns_outlined),
                selectedIcon: const Icon(Icons.dns_rounded),
                label: context.tt('nav.servers')),
            NavigationDestination(
                icon: const Icon(Icons.settings_outlined),
                selectedIcon: const Icon(Icons.settings_rounded),
                label: context.tt('nav.settings')),
          ],
        ),
      ),
    );
  }
}
