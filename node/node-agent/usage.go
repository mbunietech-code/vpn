// Per-customer traffic and speed, for the admin's Devices › Traffic page.
//
// Every minute the agent posts, per VPN session (customer credential × source
// IP × listener): bytes up/down, peak Mbps, and bytes per coarse category
// (calls, video, social, …). Host names never leave the node: they are only
// used here to pick a category. Nothing is written to disk except sing-box's
// short-lived log in /run (RAM), which the agent empties every few seconds.
//
// Sources:
//   - Xray (VLESS+REALITY): its stats API, per-user counters (reset on read)
//     and the online IP list. No per-destination split.
//   - sing-box (Hysteria2): the Clash API lists every open connection with
//     its bytes, host and the customer's source ip:port, but not the user.
//     The user comes from sing-box's info log ("[user] inbound connection
//     to …"), joined on the log line's connection id.
//   - OpenVPN: the status files (CLIENT_LIST rows, cumulative bytes).
package main

import (
	"bufio"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/netip"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

const (
	clashSampleEvery   = 2 * time.Second
	xraySampleEvery    = 5 * time.Second
	usageReportEvery   = 60 * time.Second
	addrUserTTL        = 6 * time.Hour
	singboxLogMaxBytes = 256 << 20 // safety: empty the log if it ever grows this big
)

type usageConfig struct {
	Enabled      bool
	ClashAddr    string // sing-box Clash API, loopback only
	SingboxLog   string // sing-box info log, on tmpfs
	XrayAPI      string
	OpenvpnGlob  string // OpenVPN status files (status-version 2)
	SafetyCronAt string // cron file that empties the log if the agent is down
}

func envUsageConfig() usageConfig {
	return usageConfig{
		Enabled:      getenv("MVPN_USAGE_STATS", "1") != "0",
		ClashAddr:    getenv("MVPN_SINGBOX_CLASH", "127.0.0.1:9095"),
		SingboxLog:   getenv("MVPN_SINGBOX_LOG", "/run/mvpn-singbox.log"),
		XrayAPI:      getenv("MVPN_XRAY_API", "127.0.0.1:10085"),
		OpenvpnGlob:  getenv("MVPN_OPENVPN_STATUS", "/var/log/openvpn/*status*.log"),
		SafetyCronAt: getenv("MVPN_USAGE_CRON", "/etc/cron.d/mvpn-usage-log"),
	}
}

// ---- report sent to POST /api/node/usage -----------------------------------

type usageSession struct {
	RemoteID     string              `json:"remote_id"`
	Protocol     string              `json:"protocol"` // vless-reality | hysteria2 | openvpn
	Inbound      string              `json:"inbound"`  // hy2-in, hy2-obfs-in, vless-reality, mbunie-udp…
	SourceIP     string              `json:"source_ip"`
	Up           int64               `json:"up"`
	Down         int64               `json:"down"`
	PeakUpMbps   float64             `json:"peak_up_mbps"`
	PeakDownMbps float64             `json:"peak_down_mbps"`
	Connections  int                 `json:"connections"`
	Categories   map[string][2]int64 `json:"categories,omitempty"` // category -> [up, down]
}

type usageReport struct {
	WindowSeconds int            `json:"window_seconds"`
	Sessions      []usageSession `json:"sessions"`
	Unattributed  [2]int64       `json:"unattributed"` // Hysteria2 bytes whose customer was not known
}

type usageKey struct{ remoteID, protocol, inbound, ip string }

type usageAgg struct {
	up, down         int64
	peakUp, peakDown float64
	conns            map[string]struct{}
	cats             map[string]*[2]int64
}

func (a *usageAgg) add(cat string, up, down int64) {
	a.up += up
	a.down += down
	if cat == "" {
		return
	}
	if a.cats == nil {
		a.cats = map[string]*[2]int64{}
	}
	c := a.cats[cat]
	if c == nil {
		c = &[2]int64{}
		a.cats[cat] = c
	}
	c[0] += up
	c[1] += down
}

// ---- collector ---------------------------------------------------------------

type clashPrev struct {
	up, down int64
	key      usageKey
	resolved bool
	cat      string
}

type addrUser struct {
	user, inbound string
	seen          time.Time
}

type usageCollector struct {
	cfg     config
	ucfg    usageConfig
	client  *http.Client
	window  map[usageKey]*usageAgg
	started time.Time
	unattr  [2]int64

	// sing-box
	pending    map[string]string   // log id -> "ip|port"
	addrUsers  map[string]addrUser // "ip|port" -> user
	ipUsers    map[string]addrUser // ip -> user (only kept when one user per ip)
	ipShared   map[string]bool     // ip seen with several users
	clashPrev  map[string]*clashPrev
	clashAt    time.Time
	clashReady bool

	// xray
	xrayAt    time.Time
	xrayReady bool
	xrayIPs   map[string]string // user -> most recent ip
	xrayIPsAt time.Time

	// openvpn
	ovpnPrev map[string][2]int64 // file|cn|addr|since -> [recv, sent]
	ovpnAt   map[string]time.Time
}

func newUsageCollector(cfg config, ucfg usageConfig) *usageCollector {
	return &usageCollector{
		cfg:       cfg,
		ucfg:      ucfg,
		client:    &http.Client{Timeout: 5 * time.Second},
		window:    map[usageKey]*usageAgg{},
		started:   time.Now(),
		pending:   map[string]string{},
		addrUsers: map[string]addrUser{},
		ipUsers:   map[string]addrUser{},
		ipShared:  map[string]bool{},
		clashPrev: map[string]*clashPrev{},
		xrayIPs:   map[string]string{},
		ovpnPrev:  map[string][2]int64{},
		ovpnAt:    map[string]time.Time{},
	}
}

func (u *usageCollector) run(ctx context.Context) {
	installLogSafetyCron(u.ucfg)
	clashT := time.NewTicker(clashSampleEvery)
	xrayT := time.NewTicker(xraySampleEvery)
	reportT := time.NewTicker(usageReportEvery)
	defer clashT.Stop()
	defer xrayT.Stop()
	defer reportT.Stop()
	var lastErr string
	note := func(err error) {
		if err != nil && err.Error() != lastErr {
			log.Printf("usage: %v", err)
		}
		if err != nil {
			lastErr = err.Error()
		}
	}
	for {
		select {
		case <-ctx.Done():
			return
		case <-clashT.C:
			note(u.sampleSingbox(ctx))
		case <-xrayT.C:
			note(u.sampleXray(ctx))
		case <-reportT.C:
			note(u.sampleOpenvpn())
			rep := u.flush()
			if len(rep.Sessions) == 0 && rep.Unattributed == [2]int64{} {
				continue
			}
			go func() {
				if err := postUsage(ctx, u.cfg, rep); err != nil {
					log.Printf("usage: post: %v", err)
				}
			}()
		}
	}
}

func (u *usageCollector) agg(k usageKey) *usageAgg {
	a := u.window[k]
	if a == nil {
		a = &usageAgg{conns: map[string]struct{}{}}
		u.window[k] = a
	}
	return a
}

func (u *usageCollector) flush() usageReport {
	secs := int(time.Since(u.started).Seconds())
	rep := usageReport{WindowSeconds: max(secs, 1), Sessions: []usageSession{}, Unattributed: u.unattr}
	for k, a := range u.window {
		if a.up == 0 && a.down == 0 {
			continue
		}
		s := usageSession{
			RemoteID: k.remoteID, Protocol: k.protocol, Inbound: k.inbound, SourceIP: k.ip,
			Up: a.up, Down: a.down,
			PeakUpMbps: round2(a.peakUp), PeakDownMbps: round2(a.peakDown),
			Connections: len(a.conns),
		}
		if len(a.cats) > 0 {
			s.Categories = map[string][2]int64{}
			for c, v := range a.cats {
				s.Categories[c] = *v
			}
		}
		rep.Sessions = append(rep.Sessions, s)
	}
	u.window = map[usageKey]*usageAgg{}
	u.unattr = [2]int64{}
	u.started = time.Now()
	return rep
}

func round2(f float64) float64 { return float64(int64(f*100+0.5)) / 100 }

func mbps(bytes int64, dt time.Duration) float64 {
	if dt <= 0 {
		return 0
	}
	return float64(bytes) * 8 / dt.Seconds() / 1e6
}

// ---- sing-box (Hysteria2) ------------------------------------------------------

// INFO [3394829823 0ms] inbound/hysteria2[hy2-in]: inbound connection from 1.2.3.4:5678
// INFO [3394829823 0ms] inbound/hysteria2[hy2-in]: [user] inbound connection to example.com:443
var hy2LogLine = regexp.MustCompile(`\[(\d+) [^\]]*\] inbound/hysteria2\[([^\]]+)\]: (?:\[([^\]]+)\] )?inbound (?:packet )?connection (from|to) (\S+)`)

// parseSingboxLog feeds log lines into the source-address -> user map.
func (u *usageCollector) parseSingboxLog(r io.Reader, now time.Time) {
	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	for sc.Scan() {
		line := strings.ReplaceAll(sc.Text(), "\x00", "")
		m := hy2LogLine.FindStringSubmatch(line)
		if m == nil {
			continue
		}
		id, inbound, user, dir, addr := m[1], m[2], m[3], m[4], m[5]
		if dir == "from" {
			if src := splitHostPort(addr); src != "" {
				if len(u.pending) > 50000 {
					u.pending = map[string]string{}
				}
				u.pending[id] = src
			}
			continue
		}
		src, ok := u.pending[id]
		delete(u.pending, id)
		if !ok || user == "" {
			continue
		}
		au := addrUser{user: user, inbound: inbound, seen: now}
		u.addrUsers[src] = au
		ip := src[:strings.IndexByte(src, '|')]
		if prev, ok := u.ipUsers[ip]; ok && prev.user != user {
			u.ipShared[ip] = true
		}
		u.ipUsers[ip] = au
	}
}

// splitHostPort turns "1.2.3.4:5678" / "[2001:db8::1]:5678" into "ip|port".
func splitHostPort(addr string) string {
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return ""
	}
	return normIP(host) + "|" + port
}

// normIP unmaps IPv4-in-IPv6 so both sources key the same way.
func normIP(s string) string {
	s = strings.TrimSuffix(strings.TrimPrefix(s, "["), "]")
	if a, err := netip.ParseAddr(s); err == nil {
		return a.Unmap().String()
	}
	return s
}

func (u *usageCollector) readSingboxLog(now time.Time) error {
	f, err := os.OpenFile(u.ucfg.SingboxLog, os.O_RDWR, 0)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}
	defer f.Close()
	u.parseSingboxLog(io.LimitReader(f, singboxLogMaxBytes), now)
	// Empty it: sing-box appends, so new lines start again at 0.
	return f.Truncate(0)
}

type clashSnapshot struct {
	Connections []struct {
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
	} `json:"connections"`
}

func (u *usageCollector) sampleSingbox(ctx context.Context) error {
	now := time.Now()
	if err := u.readSingboxLog(now); err != nil {
		return fmt.Errorf("sing-box log: %w", err)
	}
	secret := clashSecret(u.cfg.SingboxConfig)
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "http://"+u.ucfg.ClashAddr+"/connections", nil)
	if secret != "" {
		req.Header.Set("Authorization", "Bearer "+secret)
	}
	resp, err := u.client.Do(req)
	if err != nil {
		return fmt.Errorf("clash api: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("clash api: status %d", resp.StatusCode)
	}
	var snap clashSnapshot
	if err := json.NewDecoder(resp.Body).Decode(&snap); err != nil {
		return fmt.Errorf("clash api: %w", err)
	}
	u.applyClash(snap, now)
	u.expireAddrUsers(now)
	return nil
}

// applyClash turns a snapshot into per-session deltas and speeds.
func (u *usageCollector) applyClash(snap clashSnapshot, now time.Time) {
	dt := now.Sub(u.clashAt)
	first := !u.clashReady
	u.clashAt, u.clashReady = now, true

	sample := map[usageKey][2]int64{}
	seen := make(map[string]struct{}, len(snap.Connections))
	for _, c := range snap.Connections {
		inType, inbound, _ := strings.Cut(c.Metadata.Type, "/")
		if inType != "hysteria2" {
			continue
		}
		seen[c.ID] = struct{}{}
		p := u.clashPrev[c.ID]
		if p == nil {
			p = &clashPrev{cat: categorize(c.Metadata.Host, c.Metadata.DestinationIP, c.Metadata.Network, c.Metadata.DestinationPort)}
			if first {
				// Already open when the agent started: count from now on.
				p.up, p.down = c.Upload, c.Download
			}
			u.clashPrev[c.ID] = p
		}
		if !p.resolved {
			ip := normIP(c.Metadata.SourceIP)
			if au, ok := u.addrUsers[ip+"|"+c.Metadata.SourcePort]; ok {
				p.key, p.resolved = usageKey{au.user, "hysteria2", inbound, ip}, true
			} else if au, ok := u.ipUsers[ip]; ok && !u.ipShared[ip] {
				p.key, p.resolved = usageKey{au.user, "hysteria2", inbound, ip}, true
			} else {
				p.key = usageKey{"", "hysteria2", inbound, ip}
			}
		}
		up, down := c.Upload-p.up, c.Download-p.down
		p.up, p.down = c.Upload, c.Download
		if up < 0 || down < 0 {
			continue
		}
		if !p.resolved {
			u.unattr[0] += up
			u.unattr[1] += down
			continue
		}
		a := u.agg(p.key)
		a.conns[c.ID] = struct{}{}
		a.add(p.cat, up, down)
		s := sample[p.key]
		sample[p.key] = [2]int64{s[0] + up, s[1] + down}
	}
	for id := range u.clashPrev {
		if _, ok := seen[id]; !ok {
			delete(u.clashPrev, id)
		}
	}
	if first || dt <= 0 {
		return
	}
	for k, s := range sample {
		a := u.agg(k)
		a.peakUp = maxf(a.peakUp, mbps(s[0], dt))
		a.peakDown = maxf(a.peakDown, mbps(s[1], dt))
	}
}

func (u *usageCollector) expireAddrUsers(now time.Time) {
	for k, v := range u.addrUsers {
		if now.Sub(v.seen) > addrUserTTL {
			delete(u.addrUsers, k)
		}
	}
	for k, v := range u.ipUsers {
		if now.Sub(v.seen) > addrUserTTL {
			delete(u.ipUsers, k)
			delete(u.ipShared, k)
		}
	}
}

func maxf(a, b float64) float64 {
	if b > a {
		return b
	}
	return a
}

// clashSecret reads the Clash API secret the agent wrote into sing-box's config.
func clashSecret(path string) string {
	raw, err := os.ReadFile(path)
	if err != nil {
		return ""
	}
	var root struct {
		Experimental struct {
			ClashAPI struct {
				Secret string `json:"secret"`
			} `json:"clash_api"`
		} `json:"experimental"`
	}
	_ = json.Unmarshal(raw, &root)
	return root.Experimental.ClashAPI.Secret
}

// patchSingboxUsage turns on what the collector reads: the Clash API on
// loopback (with a secret) and an info-level log in RAM.
func patchSingboxUsage(root map[string]any, ucfg usageConfig) {
	exp, _ := root["experimental"].(map[string]any)
	if exp == nil {
		exp = map[string]any{}
		root["experimental"] = exp
	}
	clash, _ := exp["clash_api"].(map[string]any)
	if clash == nil {
		clash = map[string]any{}
		exp["clash_api"] = clash
	}
	clash["external_controller"] = ucfg.ClashAddr
	if s, _ := clash["secret"].(string); s == "" {
		b := make([]byte, 16)
		_, _ = rand.Read(b)
		clash["secret"] = hex.EncodeToString(b)
	}
	lg, _ := root["log"].(map[string]any)
	if lg == nil {
		lg = map[string]any{}
		root["log"] = lg
	}
	lg["level"] = "info"
	lg["output"] = ucfg.SingboxLog
	lg["timestamp"] = true
}

// patchXrayUsage keeps per-user counters and the online IP list ("statsUserOnline").
func patchXrayUsage(root map[string]any) {
	pol, _ := root["policy"].(map[string]any)
	if pol == nil {
		pol = map[string]any{}
		root["policy"] = pol
	}
	levels, _ := pol["levels"].(map[string]any)
	if levels == nil {
		levels = map[string]any{}
		pol["levels"] = levels
	}
	l0, _ := levels["0"].(map[string]any)
	if l0 == nil {
		l0 = map[string]any{}
		levels["0"] = l0
	}
	l0["statsUserUplink"] = true
	l0["statsUserDownlink"] = true
	l0["statsUserOnline"] = true
	if _, ok := root["stats"]; !ok {
		root["stats"] = map[string]any{}
	}
}

// installLogSafetyCron empties sing-box's log if the agent is stopped for a
// long time, so /run (RAM) never fills up.
func installLogSafetyCron(ucfg usageConfig) {
	if ucfg.SafetyCronAt == "" || ucfg.SafetyCronAt == "none" {
		return
	}
	line := fmt.Sprintf("# mvpn-agent: keep sing-box's info log (RAM) small if the agent is down\n"+
		"*/5 * * * * root [ -f %[1]s ] && [ $(stat -c%%s %[1]s) -gt 104857600 ] && truncate -s 0 %[1]s\n", ucfg.SingboxLog)
	if cur, err := os.ReadFile(ucfg.SafetyCronAt); err == nil && string(cur) == line {
		return
	}
	if err := os.WriteFile(ucfg.SafetyCronAt, []byte(line), 0o644); err != nil {
		log.Printf("usage: safety cron: %v", err)
	}
}

// ---- Xray (VLESS+REALITY) ------------------------------------------------------

type xrayStats struct {
	Stat []struct {
		Name  string          `json:"name"`
		Value json.RawMessage `json:"value"`
	} `json:"stat"`
}

func rawInt(r json.RawMessage) int64 {
	s := strings.Trim(string(r), `"`)
	n, _ := strconv.ParseInt(s, 10, 64)
	return n
}

func (u *usageCollector) xray(ctx context.Context, args ...string) ([]byte, error) {
	cctx, cancel := context.WithTimeout(ctx, 4*time.Second)
	defer cancel()
	args = append([]string{"api", args[0], "--server=" + u.ucfg.XrayAPI}, args[1:]...)
	return exec.CommandContext(cctx, u.cfg.XrayBin, args...).Output()
}

func (u *usageCollector) sampleXray(ctx context.Context) error {
	now := time.Now()
	out, err := u.xray(ctx, "statsquery", "-pattern", "user>>>", "-reset")
	if err != nil {
		return fmt.Errorf("xray stats: %w", err)
	}
	var st xrayStats
	if err := json.Unmarshal(out, &st); err != nil {
		return fmt.Errorf("xray stats: %w", err)
	}
	dt := now.Sub(u.xrayAt)
	first := !u.xrayReady
	u.xrayAt, u.xrayReady = now, true
	if first {
		return nil // counters held everything since Xray started; now reset
	}
	per := map[string][2]int64{}
	for _, s := range st.Stat {
		// user>>>{email}>>>traffic>>>uplink|downlink
		parts := strings.Split(s.Name, ">>>")
		if len(parts) != 4 || parts[0] != "user" {
			continue
		}
		v := per[parts[1]]
		switch parts[3] {
		case "uplink":
			v[0] += rawInt(s.Value)
		case "downlink":
			v[1] += rawInt(s.Value)
		}
		per[parts[1]] = v
	}
	if now.Sub(u.xrayIPsAt) > usageReportEvery || len(u.xrayIPs) == 0 {
		u.refreshXrayIPs(ctx, per)
		u.xrayIPsAt = now
	}
	for user, v := range per {
		if v[0] == 0 && v[1] == 0 {
			continue
		}
		if _, ok := u.xrayIPs[user]; !ok {
			u.refreshXrayIP(ctx, user)
		}
		a := u.agg(usageKey{user, "vless-reality", "vless-reality", u.xrayIPs[user]})
		a.add("", v[0], v[1])
		a.peakUp = maxf(a.peakUp, mbps(v[0], dt))
		a.peakDown = maxf(a.peakDown, mbps(v[1], dt))
	}
	return nil
}

func (u *usageCollector) refreshXrayIPs(ctx context.Context, active map[string][2]int64) {
	u.xrayIPs = map[string]string{}
	for user := range active {
		u.refreshXrayIP(ctx, user)
	}
}

// refreshXrayIP keeps the IP this user most recently connected from.
func (u *usageCollector) refreshXrayIP(ctx context.Context, user string) {
	out, err := u.xray(ctx, "statsonlineiplist", "-email", user)
	u.xrayIPs[user] = ""
	if err != nil {
		return
	}
	var r struct {
		IPs map[string]json.RawMessage `json:"ips"`
	}
	if json.Unmarshal(out, &r) != nil {
		return
	}
	var best string
	var bestAt int64 = -1
	for ip, at := range r.IPs {
		if t := rawInt(at); t > bestAt {
			best, bestAt = ip, t
		}
	}
	u.xrayIPs[user] = normIP(best)
}

// ---- OpenVPN ---------------------------------------------------------------------

func (u *usageCollector) sampleOpenvpn() error {
	files, _ := filepath.Glob(u.ucfg.OpenvpnGlob)
	now := time.Now()
	seen := map[string]struct{}{}
	for _, file := range files {
		raw, err := os.ReadFile(file)
		if err != nil {
			continue
		}
		inbound := strings.TrimSuffix(strings.TrimSuffix(filepath.Base(file), ".log"), "-status")
		for _, line := range strings.Split(string(raw), "\n") {
			f := strings.Split(strings.TrimSpace(line), ",")
			// CLIENT_LIST,CN,Real Address,Virtual Address,Virtual IPv6,Bytes Received,Bytes Sent,Connected Since,time_t,...
			if len(f) < 9 || f[0] != "CLIENT_LIST" || !strings.HasPrefix(f[1], "mvpn-") {
				continue
			}
			recv, _ := strconv.ParseInt(f[5], 10, 64)
			sent, _ := strconv.ParseInt(f[6], 10, 64)
			ip := ovpnRealIP(f[2])
			key := file + "|" + f[1] + "|" + f[2] + "|" + f[8]
			seen[key] = struct{}{}
			prev, had := u.ovpnPrev[key]
			prevAt := u.ovpnAt[key]
			u.ovpnPrev[key] = [2]int64{recv, sent}
			u.ovpnAt[key] = now
			if !had {
				continue // first sight: start counting from here
			}
			up, down := recv-prev[0], sent-prev[1]
			if up < 0 || down < 0 || (up == 0 && down == 0) {
				continue
			}
			a := u.agg(usageKey{strings.TrimPrefix(f[1], "mvpn-"), "openvpn", inbound, ip})
			a.add("", up, down)
			a.conns[key] = struct{}{}
			dt := now.Sub(prevAt)
			a.peakUp = maxf(a.peakUp, mbps(up, dt))
			a.peakDown = maxf(a.peakDown, mbps(down, dt))
		}
	}
	for k := range u.ovpnPrev {
		if _, ok := seen[k]; !ok {
			delete(u.ovpnPrev, k)
			delete(u.ovpnAt, k)
		}
	}
	return nil
}

// ovpnRealIP: "1.2.3.4:5678", "udp4:1.2.3.4:5678", "[AF_INET6]::ffff:1.2.3.4:5678" -> ip.
func ovpnRealIP(s string) string {
	s = strings.TrimPrefix(s, "[AF_INET6]")
	for _, p := range []string{"udp4:", "udp6:", "tcp4-server:", "tcp6-server:", "udp:", "tcp:"} {
		s = strings.TrimPrefix(s, p)
	}
	if h, _, err := net.SplitHostPort(s); err == nil {
		return normIP(h)
	}
	if i := strings.LastIndexByte(s, ':'); i > 0 {
		return normIP(s[:i])
	}
	return normIP(s)
}

// ---- categories -----------------------------------------------------------------

// Coarse traffic kinds, enough to tell a call from a video stream or a big
// download. Matched on the sniffed host name (suffix) or, for calls, on UDP.
var categoryHosts = []struct {
	cat      string
	suffixes []string
}{
	{"calls", []string{"zoom.us", "zoom.com", "zoomgov.com", "teams.microsoft.com", "skype.com", "meet.google.com",
		"discord.media", "webex.com", "agora.io", "agoralab.co", "facetime.apple.com"}},
	{"messaging", []string{"whatsapp.net", "whatsapp.com", "wa.me", "telegram.org", "t.me", "tdesktop.com",
		"signal.org", "line.me", "line-scdn.net", "viber.com", "messenger.com", "discord.com", "discord.gg", "discordapp.com", "discordapp.net"}},
	{"video", []string{"youtube.com", "googlevideo.com", "ytimg.com", "youtu.be", "youtube-nocookie.com", "netflix.com",
		"nflxvideo.net", "nflxso.net", "tiktok.com", "tiktokv.com", "tiktokcdn.com", "byteoversea.com", "ibytedtos.com",
		"muscdn.com", "vimeo.com", "vimeocdn.com", "twitch.tv", "ttvnw.net", "dailymotion.com", "primevideo.com",
		"aiv-cdn.net", "disneyplus.com", "bamgrid.com", "hulu.com", "bilibili.com", "iqiyi.com", "youku.com", "hbomax.com", "max.com"}},
	{"social", []string{"facebook.com", "fbcdn.net", "instagram.com", "cdninstagram.com", "twitter.com", "x.com",
		"twimg.com", "snapchat.com", "sc-cdn.net", "reddit.com", "redd.it", "redditmedia.com", "linkedin.com", "licdn.com", "threads.net", "pinterest.com", "pinimg.com"}},
	{"downloads", []string{"dl.google.com", "gvt1.com", "play.googleapis.com", "windowsupdate.com", "update.microsoft.com",
		"delivery.mp.microsoft.com", "download.microsoft.com", "dl.delivery.mp.microsoft.com", "swcdn.apple.com",
		"updates.cdn-apple.com", "mesu.apple.com", "appldnld.apple.com", "steamcontent.com", "steamserver.net",
		"githubusercontent.com", "github-releases.githubusercontent.com", "dropbox.com", "dropboxusercontent.com",
		"mega.nz", "mega.co.nz", "mediafire.com", "drive.usercontent.google.com", "icloud-content.com", "epicgames-download1.akamaized.net", "download.jetbrains.com"}},
}

// Meta's networks: WhatsApp / Messenger call media goes to these by IP.
var metaPrefixes = mustPrefixes("157.240.0.0/16", "31.13.24.0/21", "31.13.64.0/18", "102.132.96.0/20",
	"163.70.128.0/17", "129.134.0.0/16", "179.60.192.0/22", "185.60.216.0/22", "69.171.224.0/19",
	"66.220.144.0/20", "173.252.64.0/18", "204.15.20.0/22", "45.64.40.0/22", "2a03:2880::/32")

func mustPrefixes(ss ...string) []netip.Prefix {
	out := make([]netip.Prefix, 0, len(ss))
	for _, s := range ss {
		out = append(out, netip.MustParsePrefix(s))
	}
	return out
}

func inMeta(ip string) bool {
	a, err := netip.ParseAddr(normIP(ip))
	if err != nil {
		return false
	}
	for _, p := range metaPrefixes {
		if p.Contains(a) {
			return true
		}
	}
	return false
}

func hostCategory(host string) string {
	host = strings.TrimSuffix(strings.ToLower(host), ".")
	if host == "" {
		return ""
	}
	for _, c := range categoryHosts {
		for _, s := range c.suffixes {
			if host == s || strings.HasSuffix(host, "."+s) {
				return c.cat
			}
		}
	}
	return ""
}

func categorize(host, dstIP, network, dstPort string) string {
	cat := hostCategory(host)
	if network != "udp" {
		if cat == "" {
			return "web"
		}
		return cat
	}
	port, _ := strconv.Atoi(dstPort)
	switch {
	case cat == "calls", cat == "messaging":
		return "calls" // WhatsApp/Telegram/Zoom over UDP is voice or video calls
	case port >= 3478 && port <= 3481, port >= 19302 && port <= 19309: // STUN/TURN, Google Meet
		return "calls"
	case cat == "" && inMeta(dstIP):
		return "calls"
	case cat != "":
		return cat // e.g. YouTube over QUIC
	case port == 443:
		return "web"
	default:
		return "other_udp"
	}
}

func postUsage(ctx context.Context, cfg config, rep usageReport) error {
	body, _ := json.Marshal(rep)
	pctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	resp, err := httpClient().Do(newRequest(pctx, cfg, http.MethodPost, "/api/node/usage", body))
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return checkStatus(resp)
}
