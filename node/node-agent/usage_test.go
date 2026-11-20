package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

const sampleLog = `+0800 2026-11-16 10:00:00 INFO [111 0ms] inbound/hysteria2[hy2-in]: inbound connection from 1.2.3.4:5678
+0800 2026-11-16 10:00:00 INFO [111 0ms] inbound/hysteria2[hy2-in]: [alice] inbound connection to rr1---sn-x.googlevideo.com:443
+0800 2026-11-16 10:00:00 INFO [111 1ms] outbound/direct[direct]: outbound connection to rr1---sn-x.googlevideo.com:443
+0800 2026-11-16 10:00:01 INFO [222 0ms] inbound/hysteria2[hy2-obfs-in]: inbound packet connection from [2001:db8::7]:4444
+0800 2026-11-16 10:00:01 INFO [222 0ms] inbound/hysteria2[hy2-obfs-in]: [bob] inbound packet connection to 157.240.1.10:3478
`

func snap(conns ...[7]string) clashSnapshot {
	var s clashSnapshot
	for _, c := range conns {
		var e struct {
			ID       string `json:"id"`
			Upload   int64  `json:"upload"`
			Download int64  `json:"download"`
			Metadata struct {
				Network         string `json:"network"`
				Type            string `json:"type"`
				SourceIP        string `json:"sourceIP"`
				SourcePort      string `json:"sourcePort"`
				DestinationIP   string `json:"destinationIP"`
				DestinationPort string `json:"destinationPort"`
				Host            string `json:"host"`
			} `json:"metadata"`
		}
		e.ID = c[0]
		e.Metadata.Type, e.Metadata.SourceIP, e.Metadata.SourcePort = c[1], c[2], c[3]
		e.Metadata.Host, e.Metadata.Network, e.Metadata.DestinationPort = c[4], c[5], c[6]
		if c[4] == "" {
			e.Metadata.DestinationIP = "157.240.1.10"
		}
		s.Connections = append(s.Connections, e)
	}
	return s
}

func TestHysteriaSessionsAreAttributedToTheirUser(t *testing.T) {
	u := newUsageCollector(config{}, usageConfig{})
	t0 := time.Now()
	u.parseSingboxLog(strings.NewReader(sampleLog), t0)

	video := [7]string{"c1", "hysteria2/hy2-in", "1.2.3.4", "5678", "rr1---sn-x.googlevideo.com", "tcp", "443"}
	call := [7]string{"c2", "hysteria2/hy2-obfs-in", "2001:db8::7", "4444", "", "udp", "3478"}
	stranger := [7]string{"c3", "hysteria2/hy2-in", "9.9.9.9", "1", "example.com", "tcp", "443"}

	// First snapshot is a baseline (connections already open at start).
	s := snap(video, call, stranger)
	s.Connections[0].Download, s.Connections[1].Download = 1000, 50
	u.applyClash(s, t0)

	s = snap(video, call, stranger)
	s.Connections[0].Download, s.Connections[0].Upload = 1000+2_500_000, 10_000 // 2.5 MB in 2s = 10 Mbps
	s.Connections[1].Download, s.Connections[1].Upload = 50+40_000, 30_000
	s.Connections[2].Download = 777
	u.applyClash(s, t0.Add(2*time.Second))

	rep := u.flush()
	byUser := map[string]usageSession{}
	for _, x := range rep.Sessions {
		byUser[x.RemoteID] = x
	}
	a := byUser["alice"]
	if a.Down != 2_500_000 || a.Up != 10_000 || a.Inbound != "hy2-in" || a.SourceIP != "1.2.3.4" {
		t.Fatalf("alice: %+v", a)
	}
	if a.PeakDownMbps != 10 {
		t.Fatalf("alice peak = %v, want 10", a.PeakDownMbps)
	}
	if a.Categories["video"] != [2]int64{10_000, 2_500_000} {
		t.Fatalf("alice categories: %+v", a.Categories)
	}
	b := byUser["bob"]
	if b.Inbound != "hy2-obfs-in" || b.SourceIP != "2001:db8::7" || b.Categories["calls"] != [2]int64{30_000, 40_000} {
		t.Fatalf("bob: %+v", b)
	}
	if rep.Unattributed != [2]int64{0, 777} {
		t.Fatalf("unattributed = %v", rep.Unattributed)
	}
	if len(u.window) != 0 {
		t.Fatal("flush must reset the window")
	}
}

func TestReadSingboxLogEmptiesTheFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "sb.log")
	if err := os.WriteFile(path, []byte(sampleLog), 0o644); err != nil {
		t.Fatal(err)
	}
	u := newUsageCollector(config{}, usageConfig{SingboxLog: path})
	if err := u.readSingboxLog(time.Now()); err != nil {
		t.Fatal(err)
	}
	if fi, _ := os.Stat(path); fi.Size() != 0 {
		t.Fatalf("log not emptied: %d bytes", fi.Size())
	}
	if u.addrUsers["1.2.3.4|5678"].user != "alice" || u.addrUsers["2001:db8::7|4444"].user != "bob" {
		t.Fatalf("addrUsers = %+v", u.addrUsers)
	}
}

func TestCategorize(t *testing.T) {
	cases := []struct{ host, ip, net, port, want string }{
		{"www.youtube.com", "", "tcp", "443", "video"},
		{"rr3---sn-a5m.googlevideo.com", "", "udp", "443", "video"},
		{"", "157.240.22.5", "udp", "3478", "calls"},
		{"", "157.240.22.5", "udp", "40000", "calls"},
		{"g.whatsapp.net", "", "tcp", "443", "messaging"},
		{"g.whatsapp.net", "", "udp", "3478", "calls"},
		{"dl.google.com", "", "tcp", "443", "downloads"},
		{"example.com", "", "tcp", "443", "web"},
		{"", "8.8.8.8", "udp", "27015", "other_udp"},
		{"scontent.cdninstagram.com", "", "tcp", "443", "social"},
	}
	for _, c := range cases {
		if got := categorize(c.host, c.ip, c.net, c.port); got != c.want {
			t.Errorf("categorize(%q,%q,%s,%s) = %q, want %q", c.host, c.ip, c.net, c.port, got, c.want)
		}
	}
}

func TestOpenvpnStatusDeltas(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "mbunie-udp-status.log")
	header := func() string {
		return "TITLE,OpenVPN 2.7.0\nHEADER,CLIENT_LIST,Common Name,Real Address,Virtual Address,Virtual IPv6 Address,Bytes Received,Bytes Sent,Connected Since,Connected Since (time_t),Username,Client ID,Peer ID,Data Channel Cipher\n" +
			"CLIENT_LIST,mvpn-carol,5.6.7.8:1194,10.8.0.2,,"
	}
	write := func(recv, sent string) {
		_ = os.WriteFile(path, []byte(header()+recv+","+sent+",2026-11-16 10:00:00,1794821600,UNDEF,1,0,AES-256-GCM\nEND\n"), 0o644)
	}
	u := newUsageCollector(config{}, usageConfig{OpenvpnGlob: filepath.Join(dir, "*status*.log")})
	write("100", "200")
	_ = u.sampleOpenvpn()
	write("1100", "5200")
	_ = u.sampleOpenvpn()
	rep := u.flush()
	if len(rep.Sessions) != 1 {
		t.Fatalf("sessions = %+v", rep.Sessions)
	}
	s := rep.Sessions[0]
	if s.RemoteID != "carol" || s.Protocol != "openvpn" || s.Inbound != "mbunie-udp" || s.SourceIP != "5.6.7.8" || s.Up != 1000 || s.Down != 5000 {
		t.Fatalf("session = %+v", s)
	}
}

func TestOvpnRealIP(t *testing.T) {
	for in, want := range map[string]string{
		"1.2.3.4:5678":                 "1.2.3.4",
		"udp4:1.2.3.4:5678":            "1.2.3.4",
		"[AF_INET6]::ffff:1.2.3.4:5678": "1.2.3.4",
		"tcp6-server:2001:db8::1:443":  "2001:db8::1",
	} {
		if got := ovpnRealIP(in); got != want {
			t.Errorf("ovpnRealIP(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestUsagePatchesAreStable(t *testing.T) {
	ucfg := usageConfig{ClashAddr: "127.0.0.1:9095", SingboxLog: "/run/mvpn-singbox.log"}
	root := map[string]any{"log": map[string]any{"level": "warn"}}
	patchSingboxUsage(root, ucfg)
	secret := root["experimental"].(map[string]any)["clash_api"].(map[string]any)["secret"].(string)
	if len(secret) != 32 {
		t.Fatalf("secret = %q", secret)
	}
	patchSingboxUsage(root, ucfg)
	if root["experimental"].(map[string]any)["clash_api"].(map[string]any)["secret"] != secret {
		t.Fatal("secret must be kept, or sing-box would reload on every sync")
	}
	if lg := root["log"].(map[string]any); lg["level"] != "info" || lg["output"] != "/run/mvpn-singbox.log" {
		t.Fatalf("log = %+v", lg)
	}
	x := map[string]any{}
	patchXrayUsage(x)
	if x["policy"].(map[string]any)["levels"].(map[string]any)["0"].(map[string]any)["statsUserOnline"] != true {
		t.Fatalf("xray policy = %+v", x["policy"])
	}
}
