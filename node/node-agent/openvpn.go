package main

// Per-subscription OpenVPN certificates.
//
// The CA stays on the node: for every active "openvpn" peer the agent issues
// a client certificate with CN "mvpn-<remote_id>" (easy-rsa), builds the
// client profile and uploads it to the control plane, which serves it to that
// subscriber only. A peer that leaves the list (plan ended, device removed,
// keys rotated) has its certificate revoked and the CRL refreshed; the
// OpenVPN servers read crl.pem on every connection.
//
// Only "mvpn-" certificates are touched: the server certificate and the old
// shared client profile keep working.

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

const ovpnPrefix = "mvpn-"

// CRLs expire (easy-rsa default: 180 days) and an expired CRL makes OpenVPN
// refuse every client, so it is re-signed well before that.
const crlMaxAge = 30 * 24 * time.Hour

var ovpnRemoteID = regexp.MustCompile(`^[A-Za-z0-9-]{1,64}$`)

type ovpnConfig struct {
	PKIDir    string // easy-rsa dir (contains ./easyrsa and pki/)
	ServerDir string // /etc/openvpn/server: ta.key, crl.pem
	Host      string // hostname clients connect to
}

func (o ovpnConfig) enabled() bool {
	_, err := os.Stat(filepath.Join(o.PKIDir, "easyrsa"))
	return err == nil && o.Host != ""
}

type ovpnCert struct {
	Valid  bool
	Serial string
}

// readIndex returns the easy-rsa index for mvpn- certificates, keyed by CN.
// A CN can appear several times (revoked, then re-issued); a valid entry wins.
func readIndex(pkiDir string) (map[string]ovpnCert, error) {
	raw, err := os.ReadFile(filepath.Join(pkiDir, "pki", "index.txt"))
	if err != nil {
		return nil, err
	}
	out := map[string]ovpnCert{}
	for _, line := range strings.Split(string(raw), "\n") {
		f := strings.Split(line, "\t")
		if len(f) < 6 {
			continue
		}
		i := strings.Index(f[5], "CN=")
		if i < 0 {
			continue
		}
		cn := strings.SplitN(f[5][i+3:], "/", 2)[0]
		if !strings.HasPrefix(cn, ovpnPrefix) {
			continue
		}
		if f[0] == "V" {
			out[cn] = ovpnCert{Valid: true, Serial: strings.ToUpper(f[3])}
		} else if _, seen := out[cn]; !seen {
			out[cn] = ovpnCert{Valid: false}
		}
	}
	return out, nil
}

func easyrsa(o ovpnConfig, args ...string) error {
	cmd := exec.Command("./easyrsa", append([]string{"--batch"}, args...)...)
	cmd.Dir = o.PKIDir
	cmd.Env = append(os.Environ(), "EASYRSA_BATCH=1")
	if out, err := cmd.CombinedOutput(); err != nil {
		// easy-rsa output names files, never key material.
		return fmt.Errorf("easyrsa %s: %w: %s", args[0], err, lastLines(string(out), 3))
	}
	return nil
}

func lastLines(s string, n int) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return strings.Join(lines, " | ")
}

// installCRL re-signs the CRL and puts it where the servers read it.
func installCRL(o ovpnConfig) error {
	if err := easyrsa(o, "gen-crl"); err != nil {
		return err
	}
	crl, err := os.ReadFile(filepath.Join(o.PKIDir, "pki", "crl.pem"))
	if err != nil {
		return err
	}
	dst := filepath.Join(o.ServerDir, "crl.pem")
	tmp := dst + ".next"
	if err := os.WriteFile(tmp, crl, 0o644); err != nil {
		return err
	}
	return os.Rename(tmp, dst)
}

func ovpnProfile(o ovpnConfig, cn string) (string, error) {
	read := func(p string) (string, error) {
		b, err := os.ReadFile(p)
		return strings.TrimSpace(string(b)), err
	}
	ca, err := read(filepath.Join(o.PKIDir, "pki", "ca.crt"))
	if err != nil {
		return "", err
	}
	certFile, err := read(filepath.Join(o.PKIDir, "pki", "issued", cn+".crt"))
	if err != nil {
		return "", err
	}
	// issued/*.crt has a text dump before the PEM; clients only need the PEM.
	if i := strings.Index(certFile, "-----BEGIN CERTIFICATE-----"); i >= 0 {
		certFile = certFile[i:]
	}
	key, err := read(filepath.Join(o.PKIDir, "pki", "private", cn+".key"))
	if err != nil {
		return "", err
	}
	ta, err := read(filepath.Join(o.ServerDir, "ta.key"))
	if err != nil {
		return "", err
	}

	var b strings.Builder
	// TCP 443 first: it gets through the most networks. OpenVPN Connect tries
	// the next remote when one fails; the MVPN app uses the first.
	fmt.Fprintf(&b, `client
dev tun
remote %[1]s 443 tcp
remote %[1]s 1194 udp
remote %[1]s 1194 tcp
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
data-ciphers AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305
auth SHA256
verb 3
`, o.Host)
	fmt.Fprintf(&b, "<ca>\n%s\n</ca>\n<cert>\n%s\n</cert>\n<key>\n%s\n</key>\n<tls-crypt>\n%s\n</tls-crypt>\n", ca, certFile, key, ta)
	return b.String(), nil
}

func postOvpnProfile(ctx context.Context, cfg config, remoteID, serial, profile string) error {
	body, _ := json.Marshal(map[string]string{"remote_id": remoteID, "serial": serial, "profile": profile})
	resp, err := httpClient().Do(newRequest(ctx, cfg, http.MethodPost, "/api/node/openvpn-profile", body))
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return checkStatus(resp)
}

// applyOpenvpn makes the node's mvpn- certificates match the active openvpn
// peers. Returns how many openvpn peers are active.
func applyOpenvpn(ctx context.Context, cfg config, peers []peer) (int, error) {
	o := cfg.Openvpn
	if !o.enabled() {
		return 0, nil
	}

	want := map[string]peer{} // CN -> peer
	for _, p := range peers {
		if p.Status != "active" || p.Protocol != "openvpn" {
			continue
		}
		if !ovpnRemoteID.MatchString(p.RemoteID) {
			log.Printf("openvpn: skipping peer with odd remote_id %q", p.RemoteID)
			continue
		}
		want[ovpnPrefix+p.RemoteID] = p
	}

	index, err := readIndex(o.PKIDir)
	if err != nil {
		return 0, fmt.Errorf("openvpn index: %w", err)
	}

	var errs []string
	for cn, p := range want {
		cert := index[cn]
		if !cert.Valid {
			if err := easyrsa(o, "build-client-full", cn, "nopass"); err != nil {
				errs = append(errs, err.Error())
				continue
			}
			log.Printf("openvpn: issued %s", cn)
			if index, err = readIndex(o.PKIDir); err != nil {
				return 0, fmt.Errorf("openvpn index: %w", err)
			}
			cert = index[cn]
		}
		if cert.Serial == "" || strings.EqualFold(cert.Serial, p.OpenvpnSerial) {
			continue // the control plane already has this certificate
		}
		profile, err := ovpnProfile(o, cn)
		if err != nil {
			errs = append(errs, fmt.Sprintf("profile %s: %v", cn, err))
			continue
		}
		if err := postOvpnProfile(ctx, cfg, p.RemoteID, cert.Serial, profile); err != nil {
			errs = append(errs, fmt.Sprintf("upload %s: %v", cn, err))
			continue
		}
		log.Printf("openvpn: uploaded profile for %s (serial %s)", cn, cert.Serial)
	}

	// An empty peer list while certificates exist looks like a control-plane
	// fault, not every customer leaving at once: keep them until it recovers.
	revoked := 0
	if len(peers) > 0 {
		for cn, cert := range index {
			if !cert.Valid {
				continue
			}
			if _, keep := want[cn]; keep {
				continue
			}
			if err := easyrsa(o, "revoke", cn); err != nil {
				errs = append(errs, err.Error())
				continue
			}
			log.Printf("openvpn: revoked %s", cn)
			revoked++
		}
	}

	if revoked > 0 || crlTooOld(o) {
		if err := installCRL(o); err != nil {
			errs = append(errs, "crl: "+err.Error())
		}
	}

	if len(errs) > 0 {
		return len(want), fmt.Errorf("openvpn: %s", strings.Join(errs, "; "))
	}
	return len(want), nil
}

func crlTooOld(o ovpnConfig) bool {
	st, err := os.Stat(filepath.Join(o.ServerDir, "crl.pem"))
	return err != nil || time.Since(st.ModTime()) > crlMaxAge
}

// refreshCRL keeps the CRL fresh even when the peer list never changes.
func refreshCRL(o ovpnConfig) {
	if !o.enabled() || !crlTooOld(o) {
		return
	}
	if err := installCRL(o); err != nil {
		log.Printf("openvpn: crl refresh: %v", err)
		return
	}
	log.Printf("openvpn: crl re-signed")
}

// ovpnEngineState: "up" while the MVPN OpenVPN servers run.
func ovpnEngineState(o ovpnConfig) string {
	if !o.enabled() {
		return ""
	}
	out, _ := exec.Command("systemctl", "list-units", "--type=service", "--state=running",
		"--no-legend", "openvpn-server@mbunie*").Output()
	if bytes.Count(out, []byte("openvpn-server@")) == 0 {
		return "down"
	}
	return "up"
}
