import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:window_manager/window_manager.dart';

import 'l10n/app_text.dart';
import 'screens/home_screen.dart';
import 'screens/onboarding.dart';
import 'screens/servers_screen.dart';
import 'screens/settings_screen.dart';
import 'services/app_state.dart';
import 'services/app_updater.dart';
import 'services/desktop_tray.dart';
import 'services/mvpn_scope.dart';
import 'theme/mvpn_theme.dart';
import 'widgets/common.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  if (DesktopTray.supported) await windowManager.ensureInitialized();
  runApp(const MvpnApp());
}

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
    WidgetsBinding.instance.addObserver(_state); // pay -> connect on return
    _state.bootstrap();
    if (DesktopTray.supported) DesktopTray(_state).init();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(_state);
    _state.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {

    return MvpnScope(
      state: _state,
      child: ListenableBuilder(
        listenable: _state,
        builder: (context, _) {
          // The app ships in English only (owner's decision, 2026-10-10).
          const code = 'en';
          return AppTextScope(
            code: code,
            child: MaterialApp(
              title: 'Mbunie VPN',
              debugShowCheckedModeBanner: false,
              theme: MvpnTheme.light,
              darkTheme: MvpnTheme.dark,
              themeMode: ThemeMode.system,
              locale: const Locale(code),
              supportedLocales: const [Locale('en')],
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
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const BrandMark(size: 64),
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

  /// The update already offered in this run ("Later" asks again next start).
  String? _offeredUpdate;

  static const _tabs = [HomeScreen(), ServersScreen(), SettingsScreen()];

  /// Pops up "Update available" once per version and run.
  void _maybeOfferUpdate(AppState state) {
    final v = state.updateVersion;
    if (v == null || v == _offeredUpdate) return;
    _offeredUpdate = v;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final tr = context.tr;
      showDialog<void>(
        context: context,
        barrierDismissible: !state.updateRequired,
        builder: (ctx) => AlertDialog(
          title: Text(tr.t('update.title')),
          content: Text(
            tr.p(state.updateRequired ? 'update.bodyRequired' : 'update.body', v),
          ),
          actions: [
            if (!state.updateRequired)
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: Text(tr.t('update.later')),
              ),
            FilledButton(
              onPressed: () {
                Navigator.pop(ctx);
                if (AppUpdater.supported) {
                  state.startUpdate();
                } else if (state.updateUrl != null) {
                  launchUrl(Uri.parse(state.updateUrl!), mode: LaunchMode.externalApplication);
                }
              },
              child: Text(tr.t('update.now')),
            ),
          ],
        ),
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    _maybeOfferUpdate(MvpnScope.of(context));
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
