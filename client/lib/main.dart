import 'package:flutter/material.dart';

import 'screens/home_screen.dart';
import 'screens/servers_screen.dart';
import 'screens/settings_screen.dart';
import 'services/mvpn_scope.dart';
import 'services/vpn_controller.dart';
import 'theme/mvpn_theme.dart';

void main() => runApp(const MvpnApp());

class MvpnApp extends StatefulWidget {
  const MvpnApp({super.key});

  @override
  State<MvpnApp> createState() => _MvpnAppState();
}

class _MvpnAppState extends State<MvpnApp> {
  final _controller = VpnController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MvpnScope(
      controller: _controller,
      child: MaterialApp(
        title: 'Mbunie VPN',
        debugShowCheckedModeBanner: false,
        theme: MvpnTheme.light,
        darkTheme: MvpnTheme.dark,
        themeMode: ThemeMode.system,
        home: const RootShell(),
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

  static const _tabs = [
    HomeScreen(),
    ServersScreen(),
    SettingsScreen(),
  ];

  @override
  Widget build(BuildContext context) {
    final c = context.mvpn;
    return Scaffold(
      body: IndexedStack(index: _index, children: _tabs),
      bottomNavigationBar: DecoratedBox(
        decoration: BoxDecoration(
          border: Border(top: BorderSide(color: c.border)),
        ),
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
