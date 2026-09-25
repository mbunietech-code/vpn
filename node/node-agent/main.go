// MVPN Node Agent
//
// A small daemon that runs on every VPN node. It:
//   1. Heartbeats with the control plane, sending the node's public protocol
//      parameters (node-info.json) so the admin never copies keys by hand.
//   2. Polls the control plane for the authoritative peer list.
//   3. Renders the Xray (VLESS+REALITY) and sing-box (Hysteria2) client
//      lists into their config files, validates them, and reloads the engines.
//   4. Reports node health back.
//
// It holds NO business data - only opaque peer IDs + protocol credentials.
// See 05-Addendum-MVPN.md §A1, §A2.
package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"strings"
	"syscall"
	"time"
)

const version = "0.2.0"

type config struct {
	ControlPlane  string
	NodeToken     string
	Domain        string
	NodeInfo      string
	XrayConfig    string
	SingboxConfig string
	XrayBin       string
	SingboxBin    string
}

func envConfig() config {
	return config{
		ControlPlane:  strings.TrimRight(os.Getenv("MVPN_CONTROL_PLANE"), "/"),
		NodeToken:     os.Getenv("MVPN_NODE_TOKEN"),
		Domain:        os.Getenv("MVPN_DOMAIN"),
		NodeInfo:      getenv("MVPN_NODE_INFO", "/etc/mvpn/config/node-info.json"),
		XrayConfig:    os.Getenv("MVPN_XRAY_CONFIG"),
		SingboxConfig: os.Getenv("MVPN_SINGBOX_CONFIG"),
		XrayBin:       getenv("MVPN_XRAY_BIN", "xray"),
		SingboxBin:    getenv("MVPN_SINGBOX_BIN", "sing-box"),
	}
}

func getenv(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}

// ---- control-plane API types ------------------------------------------------

type peer struct {
	RemoteID string `json:"remote_id"` // UUID for VLESS, username for hysteria2
	Protocol string `json:"protocol"`  // "vless-reality" | "hysteria2"
	Secret   string `json:"secret"`    // password for hysteria2; unused for vless
	Status   string `json:"status"`    // "active" | "disabled"
}

type peerListResp struct {
	Version int    `json:"version"`
	Peers   []peer `json:"peers"`
}

type healthReport struct {
	AgentVersion string            `json:"agent_version"`
	Version      int               `json:"applied_version"`
	Uptime       int64             `json:"uptime_seconds"`
	ActivePeers  int               `json:"active_peers"`
	Engines      map[string]string `json:"engines"` // name -> "up"/"down"
	Traffic      map[string]int64  `json:"traffic"` // remote_id -> bytes (delta)
	LastError    string            `json:"last_error,omitempty"`
	NodeInfo     json.RawMessage   `json:"node_info,omitempty"`
}

// ---------------------------------------------------------------------------

func main() {
	cfg := envConfig()
	if cfg.ControlPlane == "" || cfg.NodeToken == "" {
		log.Fatal("MVPN_CONTROL_PLANE and MVPN_NODE_TOKEN are required")
	}
	log.Printf("mvpn-agent %s starting for node %s -> %s", version, cfg.Domain, cfg.ControlPlane)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	start := time.Now()
	appliedVersion := -1
	activePeers := 0
	lastError := ""

	syncTicker := time.NewTicker(15 * time.Second)
	healthTicker := time.NewTicker(60 * time.Second)
	defer syncTicker.Stop()
	defer healthTicker.Stop()

	sync := func() {
		list, err := fetchPeers(ctx, cfg)
		if err != nil {
			log.Printf("fetchPeers: %v", err)
			return
		}
		if list.Version == appliedVersion {
			return
		}
		n, err := applyPeers(cfg, list.Peers)
		if err != nil {
			// Leave appliedVersion untouched so the next tick retries.
			lastError = err.Error()
			log.Printf("applyPeers: %v", err)
			return
		}
		lastError = ""
		appliedVersion = list.Version
		activePeers = n
		log.Printf("applied peer list version %d (%d active peers)", list.Version, n)
	}

	health := func() {
		rep := healthReport{
			AgentVersion: version,
			Version:      appliedVersion,
			Uptime:       int64(time.Since(start).Seconds()),
			ActivePeers:  activePeers,
			Engines: map[string]string{
				"xray":    engineState("mvpn-xray"),
				"singbox": engineState("mvpn-singbox"),
			},
			Traffic:   map[string]int64{},
			LastError: lastError,
			NodeInfo:  readNodeInfo(cfg.NodeInfo),
		}
		if err := postHealth(ctx, cfg, rep); err != nil {
			log.Printf("postHealth: %v", err)
		}
	}

	// Health first: on a brand-new node it registers the protocol params and
	// flips the node online, which is what provisions peers onto it.
	health()
	sync()
	for {
		select {
		case <-ctx.Done():
			log.Println("shutting down")
			return
		case <-syncTicker.C:
			sync()
		case <-healthTicker.C:
			health()
		}
	}
}

func httpClient() *http.Client { return &http.Client{Timeout: 20 * time.Second} }

func newRequest(ctx context.Context, cfg config, method, path string, body []byte) *http.Request {
	req, _ := http.NewRequestWithContext(ctx, method, cfg.ControlPlane+path, bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+cfg.NodeToken)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "mvpn-agent/"+version)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	return req
}

func fetchPeers(ctx context.Context, cfg config) (*peerListResp, error) {
	resp, err := httpClient().Do(newRequest(ctx, cfg, http.MethodGet, "/api/node/peers", nil))
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("status %d", resp.StatusCode)
	}
	var out peerListResp
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return nil, err
	}
	return &out, nil
}

func postHealth(ctx context.Context, cfg config, rep healthReport) error {
	body, _ := json.Marshal(rep)
	resp, err := httpClient().Do(newRequest(ctx, cfg, http.MethodPost, "/api/node/health", body))
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return fmt.Errorf("status %d", resp.StatusCode)
	}
	return nil
}

func readNodeInfo(path string) json.RawMessage {
	raw, err := os.ReadFile(path)
	if err != nil || !json.Valid(raw) {
		return nil
	}
	return raw
}

// applyPeers rewrites the engine configs' client lists, validates them with
// the engines' own checkers, swaps them in and reloads. A config that fails
// validation is never installed. Returns the number of active peers.
func applyPeers(cfg config, peers []peer) (int, error) {
	vless := []map[string]any{}
	hy2 := []map[string]any{}
	for _, p := range peers {
		if p.Status != "active" {
			continue
		}
		switch p.Protocol {
		case "vless-reality":
			vless = append(vless, map[string]any{"id": p.RemoteID, "email": p.RemoteID, "flow": "xtls-rprx-vision"})
		case "hysteria2":
			hy2 = append(hy2, map[string]any{"name": p.RemoteID, "password": p.Secret})
		}
	}

	xrayChanged, err := patchJSONFile(cfg.XrayConfig, func(root map[string]any) error {
		in, err := inboundByTag(root, "vless-reality")
		if err != nil {
			return err
		}
		settings, _ := in["settings"].(map[string]any)
		if settings == nil {
			return fmt.Errorf("inbound vless-reality has no settings")
		}
		settings["clients"] = vless
		return nil
	}, func(path string) error {
		return runCheck(cfg.XrayBin, "run", "-test", "-config", path)
	})
	if err != nil {
		return 0, fmt.Errorf("xray config: %w", err)
	}

	sbChanged, err := patchJSONFile(cfg.SingboxConfig, func(root map[string]any) error {
		in, err := inboundByTag(root, "hy2-in")
		if err != nil {
			return err
		}
		in["users"] = hy2
		return nil
	}, func(path string) error {
		return runCheck(cfg.SingboxBin, "check", "-c", path)
	})
	if err != nil {
		return 0, fmt.Errorf("singbox config: %w", err)
	}

	// Xray has no config reload: a restart drops live VLESS sessions (clients
	// reconnect in ~1s). sing-box reloads in place on SIGHUP.
	if xrayChanged {
		if err := systemctl("restart", "mvpn-xray"); err != nil {
			return 0, err
		}
	}
	if sbChanged {
		if err := systemctl("reload-or-restart", "mvpn-singbox"); err != nil {
			return 0, err
		}
	}
	return len(vless) + len(hy2), nil
}

func inboundByTag(root map[string]any, tag string) (map[string]any, error) {
	ins, _ := root["inbounds"].([]any)
	for _, raw := range ins {
		if in, ok := raw.(map[string]any); ok && in["tag"] == tag {
			return in, nil
		}
	}
	return nil, fmt.Errorf("inbound %q not found", tag)
}

// patchJSONFile applies mutate to the JSON at path, validates the result via
// check on a temp file, and atomically replaces the original. Reports whether
// the content actually changed.
func patchJSONFile(path string, mutate func(map[string]any) error, check func(string) error) (bool, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return false, err
	}
	var root map[string]any
	if err := json.Unmarshal(raw, &root); err != nil {
		return false, err
	}
	if err := mutate(root); err != nil {
		return false, err
	}
	out, err := json.MarshalIndent(root, "", "  ")
	if err != nil {
		return false, err
	}
	if bytes.Equal(bytes.TrimSpace(out), bytes.TrimSpace(raw)) {
		return false, nil
	}
	tmp := strings.TrimSuffix(path, ".json") + ".next.json" // xray picks the parser by extension
	if err := os.WriteFile(tmp, out, 0o600); err != nil {
		return false, err
	}
	if err := check(tmp); err != nil {
		os.Remove(tmp)
		return false, err
	}
	return true, os.Rename(tmp, path)
}

func runCheck(bin string, args ...string) error {
	out, err := exec.Command(bin, args...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s %s: %v: %s", bin, strings.Join(args, " "), err, strings.TrimSpace(string(out)))
	}
	return nil
}

func systemctl(args ...string) error {
	out, err := exec.Command("systemctl", args...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("systemctl %s: %v: %s", strings.Join(args, " "), err, strings.TrimSpace(string(out)))
	}
	return nil
}

func engineState(unit string) string {
	if err := exec.Command("systemctl", "is-active", "--quiet", unit).Run(); err == nil {
		return "up"
	}
	return "down"
}
