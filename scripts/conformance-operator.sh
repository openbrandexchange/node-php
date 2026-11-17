#!/usr/bin/env sh
# shellcheck disable=SC2016,SC2329 # PHP code in single quotes is meant literally; cleanup runs from trap
# Operator hook for obx-conformance (--operator-hook, obx-go docs/conformance.md). It runs in the
# node's environment (the same OBX_* settings) and offers the actions the suite cannot do through
# the Node Admin API on its own:
#   simulate_spec_upgrade (P-FEED-7)  raises the cursor epoch, so every cursor issued so far
#                                     answers 410 cursor_expired, as an upgrade 0.2 -> 0.3 does
#   rotate_client_secret (P-AUTH-2)   gives the API key a new secret (the admin's "renew")
# Every other action prints nothing, so the suite uses the admin API for it.
set -eu
here=${OBX_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}
case "${1:-}" in
  simulate_spec_upgrade|rotate_client_secret) ;;
  *) cat >/dev/null; exit 0 ;;
esac
exec php -r '
  require $argv[1] . "/vendor/autoload.php";
  $step = json_decode((string) stream_get_contents(STDIN), true) ?: [];
  $app = new Obx\Node\App(Obx\Node\Config::load($argv[1]));
  $db = $app->db;
  if ($argv[2] === "simulate_spec_upgrade") {
      $v = $db->value("SELECT v FROM obx_settings WHERE k = ?", [Obx\Node\Catalog\Feed::EPOCH_KEY]);
      Obx\Node\Catalog\Feed::setEpoch($db, (is_numeric($v) ? (int) $v : 0) + 1);
      echo json_encode(["simulated" => "true"]), "\n";
      exit(0);
  }
  $id = $db->value("SELECT id FROM obx_api_clients WHERE client_id = ?", [(string) ($step["params"]["client_id"] ?? "")]);
  if (!is_string($id)) {
      fwrite(STDERR, "unknown client_id\n");
      exit(1);
  }
  $r = (new Obx\Node\Services\Retailers($app))->rotateClient($id);
  echo json_encode(["client_secret" => $r["secret"]]), "\n";
' "$here" "$1"
