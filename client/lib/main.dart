import 'package:flutter/material.dart';

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
    return MvpnScope(
      state: _state,
      child: MaterialApp(
        title: 'Mbunie VPN',
        debugShowCheckedModeBanner: false,
        theme: MvpnTheme.light,
        darkTheme: MvpnTheme.dark,
        themeMode: ThemeMode.system,
        home: const _Gate(),
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
            Icon(Icons.shield_rounded, color: c.brand, size: 48),
            const SizedBox(height: 16),
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
          destinations: const [
            NavigationDestination(
                icon: Icon(Icons.home_outlined),
                selectedIcon: Icon(Icons.home_rounded),
                label: 'Home'),
            NavigationDestination(
                icon: Icon(Icons.dns_outlined),
                selectedIcon: Icon(Icons.dns_rounded),
                label: 'Servers'),
            NavigationDestination(
                icon: Icon(Icons.settings_outlined),
                selectedIcon: Icon(Icons.settings_rounded),
                label: 'Settings'),
          ],
        ),
      ),
    );
  }
}
