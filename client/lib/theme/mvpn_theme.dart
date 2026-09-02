import 'package:flutter/material.dart';

/// MVPN design tokens — see 06-Design-System-MVPN.md
class MvpnColors {
  const MvpnColors({
    required this.brand,
    required this.brandDark,
    required this.brandAccent,
    required this.bg,
    required this.surface,
    required this.surfaceAlt,
    required this.border,
    required this.textPrimary,
    required this.textSecondary,
    required this.textHint,
    required this.stateIdle,
    required this.success,
    required this.warning,
    required this.danger,
  });

  final Color brand,
      brandDark,
      brandAccent,
      bg,
      surface,
      surfaceAlt,
      border,
      textPrimary,
      textSecondary,
      textHint,
      stateIdle,
      success,
      warning,
      danger;

  static const light = MvpnColors(
    brand: Color(0xFF2563EB),
    brandDark: Color(0xFF1D4ED8),
    brandAccent: Color(0xFF3B82F6),
    bg: Color(0xFFF7F9FC),
    surface: Color(0xFFFFFFFF),
    surfaceAlt: Color(0xFFF4F6FA),
    border: Color(0xFFEEF1F6),
    textPrimary: Color(0xFF1A2233),
    textSecondary: Color(0xFF6B7688),
    textHint: Color(0xFF9AA4B2),
    stateIdle: Color(0xFFB0B7C3),
    success: Color(0xFF16A34A),
    warning: Color(0xFFF59E0B),
    danger: Color(0xFFDC2626),
  );

  static const dark = MvpnColors(
    brand: Color(0xFF3B82F6),
    brandDark: Color(0xFF2563EB),
    brandAccent: Color(0xFF60A5FA),
    bg: Color(0xFF0E1420),
    surface: Color(0xFF161D2B),
    surfaceAlt: Color(0xFF1E2635),
    border: Color(0xFF252E3F),
    textPrimary: Color(0xFFEEF2F8),
    textSecondary: Color(0xFF9AA6B8),
    textHint: Color(0xFF6B7688),
    stateIdle: Color(0xFF3A4456),
    success: Color(0xFF22C55E),
    warning: Color(0xFFFBBF24),
    danger: Color(0xFFF87171),
  );
}

/// Access the active palette: `context.mvpn`
extension MvpnThemeX on BuildContext {
  MvpnColors get mvpn =>
      Theme.of(this).brightness == Brightness.dark ? MvpnColors.dark : MvpnColors.light;
}

class MvpnTheme {
  static ThemeData _base(MvpnColors c, Brightness b) {
    final scheme = ColorScheme.fromSeed(
      seedColor: c.brand,
      brightness: b,
    ).copyWith(
      primary: c.brand,
      surface: c.surface,
      error: c.danger,
    );

    return ThemeData(
      useMaterial3: true,
      brightness: b,
      colorScheme: scheme,
      scaffoldBackgroundColor: c.bg,
      fontFamily: null, // system stack
      dividerColor: c.border,
      appBarTheme: AppBarTheme(
        backgroundColor: c.bg,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
          color: c.textPrimary,
          fontSize: 20,
          fontWeight: FontWeight.w600,
        ),
        iconTheme: IconThemeData(color: c.textPrimary),
      ),
      cardTheme: CardThemeData(
        color: c.surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: BorderSide(color: c.border),
        ),
        margin: EdgeInsets.zero,
      ),
      switchTheme: SwitchThemeData(
        thumbColor: const WidgetStatePropertyAll(Colors.white),
        trackColor: WidgetStateProperty.resolveWith(
          (s) => s.contains(WidgetState.selected) ? c.brand : const Color(0xFFD5DBE4),
        ),
        trackOutlineColor: const WidgetStatePropertyAll(Colors.transparent),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: c.brand,
          foregroundColor: Colors.white,
          elevation: 0,
          minimumSize: const Size.fromHeight(48),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: c.surface,
        surfaceTintColor: Colors.transparent,
        indicatorColor: Colors.transparent,
        elevation: 0,
        labelTextStyle: WidgetStateProperty.resolveWith(
          (s) => TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: s.contains(WidgetState.selected) ? c.brand : c.textHint,
          ),
        ),
        iconTheme: WidgetStateProperty.resolveWith(
          (s) => IconThemeData(
            color: s.contains(WidgetState.selected) ? c.brand : c.textHint,
          ),
        ),
      ),
      textTheme: Typography.blackMountainView.apply(
        bodyColor: c.textPrimary,
        displayColor: c.textPrimary,
      ),
    );
  }

  static ThemeData get light => _base(MvpnColors.light, Brightness.light);
  static ThemeData get dark => _base(MvpnColors.dark, Brightness.dark);
}
