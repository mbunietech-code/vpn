import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';

/// MVPN design tokens — premium, sales-grade. See 06-Design-System-MVPN.md
class MvpnColors {
  const MvpnColors({
    required this.brand,
    required this.brandDark,
    required this.brandAccent,
    required this.bg,
    required this.bgElevated,
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
    required this.onBrand,
  });

  final Color brand,
      brandDark,
      brandAccent,
      bg,
      bgElevated,
      surface,
      surfaceAlt,
      border,
      textPrimary,
      textSecondary,
      textHint,
      stateIdle,
      success,
      warning,
      danger,
      onBrand;

  /// Hero gradient used on the connect surface + primary CTAs.
  LinearGradient get brandGradient => LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: [brandAccent, brand, brandDark],
      );

  Color get scrim => const Color(0x14101828);

  static const light = MvpnColors(
    brand: Color(0xFF2563EB),
    brandDark: Color(0xFF1B44C7),
    brandAccent: Color(0xFF4F8BFF),
    bg: Color(0xFFF6F8FC),
    bgElevated: Color(0xFFFFFFFF),
    surface: Color(0xFFFFFFFF),
    surfaceAlt: Color(0xFFEFF3FA),
    border: Color(0xFFE6EBF3),
    textPrimary: Color(0xFF141B2D),
    textSecondary: Color(0xFF5B6577),
    textHint: Color(0xFF97A1B2),
    stateIdle: Color(0xFFAEB6C4),
    success: Color(0xFF12A150),
    warning: Color(0xFFE79217),
    danger: Color(0xFFDF3E3E),
    onBrand: Color(0xFFFFFFFF),
  );

  static const dark = MvpnColors(
    brand: Color(0xFF5C93FF),
    brandDark: Color(0xFF2E63E0),
    brandAccent: Color(0xFF7DB0FF),
    bg: Color(0xFF0B111E),
    bgElevated: Color(0xFF121A2A),
    surface: Color(0xFF141D2E),
    surfaceAlt: Color(0xFF1C2740),
    border: Color(0xFF243149),
    textPrimary: Color(0xFFEEF2FB),
    textSecondary: Color(0xFF9CA9BF),
    textHint: Color(0xFF6B7691),
    stateIdle: Color(0xFF3A465F),
    success: Color(0xFF31C971),
    warning: Color(0xFFF3B24C),
    danger: Color(0xFFFF6B6B),
    onBrand: Color(0xFF0B111E),
  );
}

extension MvpnThemeX on BuildContext {
  MvpnColors get mvpn => Theme.of(this).brightness == Brightness.dark
      ? MvpnColors.dark
      : MvpnColors.light;

  bool get isDark => Theme.of(this).brightness == Brightness.dark;
}

class MvpnTheme {
  static ThemeData _base(MvpnColors c, Brightness b) {
    final scheme = ColorScheme.fromSeed(seedColor: c.brand, brightness: b)
        .copyWith(primary: c.brand, surface: c.surface, error: c.danger);

    final text = (b == Brightness.dark
            ? Typography.whiteMountainView
            : Typography.blackMountainView)
        .apply(bodyColor: c.textPrimary, displayColor: c.textPrimary);

    return ThemeData(
      useMaterial3: true,
      brightness: b,
      colorScheme: scheme,
      scaffoldBackgroundColor: c.bg,
      splashFactory: InkSparkle.splashFactory,
      dividerColor: c.border,
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: {
          TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
          TargetPlatform.windows: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.macOS: CupertinoPageTransitionsBuilder(),
          TargetPlatform.linux: FadeForwardsPageTransitionsBuilder(),
        },
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: c.bg,
        surfaceTintColor: Colors.transparent,
        scrolledUnderElevation: 0,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
            color: c.textPrimary, fontSize: 19, fontWeight: FontWeight.w700),
        iconTheme: IconThemeData(color: c.textPrimary),
      ),
      textTheme: text,
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: c.surfaceAlt,
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide.none,
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: c.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: c.brand, width: 1.6),
        ),
        labelStyle: TextStyle(color: c.textSecondary),
        floatingLabelStyle: TextStyle(color: c.brand),
        counterStyle: TextStyle(color: c.textHint),
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith(
            (s) => s.contains(WidgetState.selected) ? Colors.white : c.surface),
        trackColor: WidgetStateProperty.resolveWith((s) =>
            s.contains(WidgetState.selected) ? c.brand : c.stateIdle),
        trackOutlineColor: const WidgetStatePropertyAll(Colors.transparent),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: c.brand,
          foregroundColor: c.onBrand,
          disabledBackgroundColor: c.stateIdle,
          disabledForegroundColor: c.surface,
          elevation: 0,
          minimumSize: const Size.fromHeight(52),
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontSize: 15.5, fontWeight: FontWeight.w700),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: c.textPrimary,
          minimumSize: const Size.fromHeight(48),
          side: BorderSide(color: c.border),
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w600),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: c.brand,
          textStyle: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: c.surface,
        surfaceTintColor: Colors.transparent,
        indicatorColor: c.brand.withValues(alpha: 0.12),
        elevation: 0,
        height: 66,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        labelTextStyle: WidgetStateProperty.resolveWith((s) => TextStyle(
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
              color: s.contains(WidgetState.selected) ? c.brand : c.textHint,
            )),
        iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(
            size: 24,
            color: s.contains(WidgetState.selected) ? c.brand : c.textHint)),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: c.textPrimary,
        contentTextStyle: TextStyle(color: c.bg, fontWeight: FontWeight.w500),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
      segmentedButtonTheme: SegmentedButtonThemeData(
        style: ButtonStyle(
          side: WidgetStatePropertyAll(BorderSide(color: c.border)),
          backgroundColor: WidgetStateProperty.resolveWith((s) =>
              s.contains(WidgetState.selected) ? c.brand : c.surface),
          foregroundColor: WidgetStateProperty.resolveWith((s) =>
              s.contains(WidgetState.selected) ? c.onBrand : c.textSecondary),
        ),
      ),
    );
  }

  static ThemeData get light => _base(MvpnColors.light, Brightness.light);
  static ThemeData get dark => _base(MvpnColors.dark, Brightness.dark);
}
