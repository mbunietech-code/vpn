import 'package:flutter/foundation.dart';

enum VpnStatus { disconnected, connecting, connected, reconnecting, error }

enum ProtocolPref { auto, vlessReality, hysteria2 }

extension ProtocolPrefLabel on ProtocolPref {
  String get label => switch (this) {
        ProtocolPref.auto => 'Auto',
        ProtocolPref.vlessReality => 'VLESS-REALITY',
        ProtocolPref.hysteria2 => 'Hysteria2',
      };
}

@immutable
class VpnNode {
  const VpnNode({
    required this.id,
    required this.name,
    required this.code,
    required this.region,
    required this.latencyMs,
    this.quality = 'Good',
    this.optimizedFor,
  });

  final String id;
  final String name;
  final String code; // US-EAST-01
  final String region; // "Americas" | "Europe" | "Asia-Pacific"
  final int latencyMs;
  final String quality;
  final String? optimizedFor;

  bool get latencyKnown => latencyMs > 0;

  /// 0..4 signal bars from latency (0 = unknown → no bars)
  int get bars => switch (latencyMs) {
        <= 0 => 0,
        <= 60 => 4,
        <= 120 => 3,
        <= 200 => 2,
        <= 350 => 1,
        _ => 0,
      };
}

@immutable
class Plan {
  const Plan({
    required this.code,
    required this.name,
    required this.days,
    required this.maxDevices,
    required this.priceDisplay, // {"usd": "$3.99", "cny": "¥28"}
  });

  final String code;
  final String name;
  final int days;
  final int maxDevices;
  final Map<String, String> priceDisplay;
}

@immutable
class PayMethod {
  const PayMethod({
    required this.id,
    required this.type,
    required this.label,
    this.currency,
    this.qrUrl,
    this.accountRef,
    this.instructions,
  });

  final int id;
  final String type; // alipay | wechat | bank | crypto | other
  final String label;
  final String? currency;
  final String? qrUrl;
  final String? accountRef;
  final String? instructions;

  factory PayMethod.fromJson(Map<String, dynamic> j) => PayMethod(
        id: j['id'] as int,
        type: j['type'] as String,
        label: j['label'] as String,
        currency: j['currency'] as String?,
        qrUrl: j['qr_url'] as String?,
        accountRef: j['account_ref'] as String?,
        instructions: j['instructions'] as String?,
      );
}

@immutable
class SessionStats {
  const SessionStats({
    required this.duration,
    required this.downloadedBytes,
    required this.uploadedBytes,
    required this.peakMbps,
    required this.protocol,
    required this.downSeries,
    required this.upSeries,
  });

  final Duration duration;
  final int downloadedBytes;
  final int uploadedBytes;
  final int peakMbps;
  final String protocol;
  final List<double> downSeries;
  final List<double> upSeries;

  static const empty = SessionStats(
    duration: Duration.zero,
    downloadedBytes: 0,
    uploadedBytes: 0,
    peakMbps: 0,
    protocol: '—',
    downSeries: [],
    upSeries: [],
  );
}
