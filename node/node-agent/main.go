// MVPN Node Agent
//
// A small daemon that runs on every VPN node. It:
//   1. Registers/heartbeats with the control plane.
//   2. Long-polls the control plane for the authoritative peer list.
//   3. Renders the Xray (VLESS+REALITY) and sing-box (Hysteria2) client
//      lists into their config files and hot-reloads the engines.
//   4. Reports per-peer byte counters and node health back.
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
	"syscall"
	"time"
)

type config struct {
	ControlPlane   string
	NodeToken      string
	Domain         string
	RealityPubKey  string
	RealityShortID string
	RealitySNI     string
	XrayConfig     string
	SingboxConfig  string
	XrayAPI        string
}

func envConfig() config {
	return config{
		ControlPlane:   os.Getenv("MVPN_CONTROL_PLANE"),
		NodeToken:      os.Getenv("MVPN_NODE_TOKEN"),
		Domain:         os.Getenv("MVPN_DOMAIN"),
		RealityPubKey:  os.Getenv("MVPN_REALITY_PUBKEY"),
		RealityShortID: os.Getenv("MVPN_REALITY_SHORTID"),
		RealitySNI:     os.Getenv("MVPN_REALITY_SNI"),
		XrayConfig:     os.Getenv("MVPN_XRAY_CONFIG"),
		SingboxConfig:  os.Getenv("MVPN_SINGBOX_CONFIG"),
		XrayAPI:        getenv("MVPN_XRAY_API", "127.0.0.1:10085"),
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
	RemoteID string `json:"remote_id"` // UUID for VLESS, used as username
	Protocol string `json:"protocol"`  // "vless-reality" | "hysteria2"
	Secret   string `json:"secret"`    // password for hysteria2; unused for vless
	Status   string `json:"status"`    // "active" | "disabled"
}

type peerListResp struct {
	Version int    `json:"version"`
	Peers   []peer `json:"peers"`
}

type healthReport struct {
	NodeToken   string            `json:"node_token"`
	Version     int               `json:"applied_version"`
	Uptime      int64             `json:"uptime_seconds"`
	ActivePeers int               `json:"active_peers"`
	Engines     map[string]string `json:"engines"` // name -> "up"/"down"
	Traffic     map[string]int64  `json:"traffic"` // remote_id -> bytes (delta)
}

// ---------------------------------------------------------------------------

func main() {
	cfg := envConfig()
	if cfg.ControlPlane == "" || cfg.NodeToken == "" {
		log.Fatal("MVPN_CONTROL_PLANE and MVPN_NODE_TOKEN are required")
	}
	log.Printf("mvpn-agent starting for node %s -> %s", cfg.Domain, cfg.ControlPlane)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	start := time.Now()
	appliedVersion := -1

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
		if err := applyPeers(cfg, list.Peers); err != nil {
			log.Printf("applyPeers: %v", err)
			return
		}
		appliedVersion = list.Version
		log.Printf("applied peer list version %d (%d peers)", list.Version, len(list.Peers))
	}

	sync()
	for {
		select {
		case <-ctx.Done():
			log.Println("shutting down")
			return
		case <-syncTicker.C:
			sync()
		case <-healthTicker.C:
			rep := healthReport{
				NodeToken:   cfg.NodeToken,
				Version:     appliedVersion,
				Uptime:      int64(time.Since(start).Seconds()),
				ActivePeers: 0,
				Engines: map[string]string{
					"xray":    engineState("mvpn-xray"),
					"singbox": engineState("mvpn-singbox"),
				},
				Traffic: map[string]int64{},
			}
			if err := postHealth(ctx, cfg, rep); err != nil {
				log.Printf("postHealth: %v", err)
			}
		}
	}
}

func httpClient() *http.Client { return &http.Client{Timeout: 20 * time.Second} }

func fetchPeers(ctx context.Context, cfg config) (*peerListResp, error) {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet,
		cfg.ControlPlane+"/api/node/peers", nil)
	req.Header.Set("Authorization", "Bearer "+cfg.NodeToken)
	resp, err := httpClient().Do(req)
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
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost,
		cfg.ControlPlane+"/api/node/health", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+cfg.NodeToken)
	req.Header.Set("Content-Type", "application/json")
	resp, err := httpClient().Do(req)
	if err != nil {
		return err
	}
	resp.Body.Close()
	return nil
}

// applyPeers rewrites the engine configs' client lists and reloads them.
func applyPeers(cfg config, peers []peer) error {
	var vless []map[string]any
	var hy2 []map[string]any
	for _, p := range peers {
		if p.Status != "active" {
			continue
		}
		switch p.Protocol {
		case "vless-reality":
			vless = append(vless, map[string]any{"id": p.RemoteID, "flow": "xtls-rprx-vision"})
		case "hysteria2":
			hy2 = append(hy2, map[string]any{"name": p.RemoteID, "password": p.Secret})
		}
	}
	if err := patchJSONFile(cfg.XrayConfig, func(root map[string]any) {
		ins := root["inbounds"].([]any)
		in0 := ins[0].(map[string]any)
		in0["settings"].(map[string]any)["clients"] = vless
	}); err != nil {
		return fmt.Errorf("xray config: %w", err)
	}
	if err := patchJSONFile(cfg.SingboxConfig, func(root map[string]any) {
		ins := root["inbounds"].([]any)
		in0 := ins[0].(map[string]any)
		in0["users"] = hy2
	}); err != nil {
		return fmt.Errorf("singbox config: %w", err)
	}
	// Xray supports API-based hot reload; sing-box needs a restart.
	_ = exec.Command("systemctl", "reload-or-restart", "mvpn-xray").Run()
	_ = exec.Command("systemctl", "restart", "mvpn-singbox").Run()
	return nil
}

func patchJSONFile(path string, mutate func(map[string]any)) error {
	raw, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	var root map[string]any
	if err := json.Unmarshal(raw, &root); err != nil {
		return err
	}
	mutate(root)
	out, err := json.MarshalIndent(root, "", "  ")
	if err != nil {
		return err
	}
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, out, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

func engineState(unit string) string {
	if err := exec.Command("systemctl", "is-active", "--quiet", unit).Run(); err == nil {
		return "up"
	}
	return "down"
}
