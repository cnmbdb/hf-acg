#!/usr/bin/env sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
env_file="$root/.env"

if [ ! -f "$env_file" ]; then
    echo "Missing $env_file. Copy .env.example to .env first." >&2
    exit 1
fi

set -a
. "$env_file"
set +a

if [ "${CFTUN_ENABLED:-false}" != "true" ]; then
    echo "Cloudflare Tunnel is disabled (set CFTUN_ENABLED=true in .env to enable it)."
    exit 0
fi

protocol="${CFTUN_PROTOCOL:-quic}"
origin_url="${CFTUN_ORIGIN_URL:-http://127.0.0.1:8080}"
runtime_dir="$root/runtime/cftun"
mkdir -p "$runtime_dir"

if [ -n "${CFTUN_TUNNEL_ID:-}" ]; then
    tunnel_id="$CFTUN_TUNNEL_ID"
    credentials_file="${CFTUN_CREDENTIALS_FILE:-}"
    if [ -z "$credentials_file" ] || [ ! -f "$credentials_file" ]; then
        echo "CFTUN_CREDENTIALS_FILE is required for CFTUN_TUNNEL_ID." >&2
        exit 1
    fi
else
    if [ -z "${CFTUN_TOKEN:-}" ]; then
        echo "CFTUN_TOKEN or CFTUN_TUNNEL_ID is required when Cloudflare Tunnel is enabled." >&2
        exit 1
    fi
    credentials_file="$runtime_dir/credentials.json"

    # Materialize token credentials only in runtime. This keeps the token out of
    # the process arguments and supports existing remotely managed connectors.
    tunnel_id=$(CFTUN_CREDENTIALS_FILE="$credentials_file" php -r '
$token = (string)getenv("CFTUN_TOKEN");
$padding = strlen($token) % 4;
if ($padding > 0) {
    $token .= str_repeat("=", 4 - $padding);
}
$payload = json_decode((string)base64_decode(strtr($token, "-_", "+/"), true), true);
if (!is_array($payload) || empty($payload["a"]) || empty($payload["t"]) || empty($payload["s"])) {
    fwrite(STDERR, "Invalid CFTUN_TOKEN payload.\n");
    exit(1);
}
$credentials = [
    "AccountTag" => $payload["a"],
    "TunnelSecret" => $payload["s"],
    "TunnelID" => $payload["t"],
];
$file = (string)getenv("CFTUN_CREDENTIALS_FILE");
if (file_put_contents($file, json_encode($credentials, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write tunnel credentials.\n");
    exit(1);
}
chmod($file, 0600);
echo $payload["t"];
')
fi

exec cloudflared tunnel --no-autoupdate --protocol "$protocol" --edge-ip-version 4 \
    --url "$origin_url" --credentials-file "$credentials_file" run "$tunnel_id"
