"""Test di contratto hub <-> plugin: l'hub (codice vendorizzato, invariato) parla con un
sito WordPress reale che esegue il plugin.

Percorso (come nella vita di un sito della flotta):
  enroll v1 (token HMAC) -> /health -> rotate (v2) -> grazia del segreto precedente ->
  /health firmato HMAC -> /detail/* -> push di un aggiornamento firmato con la k2 di test
  (con i rifiuti: firma errata, contenuto manomesso, stessa versione, pacchetto non valido)
  -> revoke.

Il file installato e' il PLUGIN SOTTO TEST (e2e_common.plugin_under_test): WPHC_CANDIDATE_FILE se
impostata (un file esterno), altrimenti mu-plugins/wp-health-check.php del repo (letto, mai scritto, uguale alla
build strip di src/). Il candidato del push e' lo stesso file con la versione successiva (X.Y.Z+1), quindi il
self-update positivo (backup, rename, sanity check, opcache) lo esegue il CODICE DEL PLUGIN SOTTO TEST.

Prima del giro "dal vivo" si controllano, senza rete, le funzioni pure di firma del codice
vendorizzato contro i vettori di contratto (tests/fixtures/contract-vectors.json, generati
dal codice dell'hub): se la copia vendorizzata dovesse divergere dall'hub, qui si vede.

Uso (dalla radice di wp-health-check, con l'ambiente e2e acceso):
    python -I tests/e2e/hub_contract.py

Il file reale mu-plugins/wp-health-check.php non viene mai scritto: il plugin sotto test e' la
copia montata nel container (tests/e2e/.work/mu-plugins/).
"""
import importlib.util
import json
import sys
import types
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import e2e_common as c  # noqa: E402


def offline_vector_checks(report: c.Report) -> None:
    """Funzioni pure del codice vendorizzato contro i vettori generati dall'hub."""
    report.section("Vettori di contratto (funzioni pure, nessuna rete)")
    vectors = json.loads(c.VECTORS_FILE.read_text(encoding="utf-8"))
    key_k1 = c.base64.b64encode(c.SEED_K1).decode("ascii")

    for vector in vectors["token_v1"]:
        token = c.hub_enroll.derive_enroll_token(vector["url_normalizzato"], vector["master_secret"])
        report.check(f"token v1 {vector['url_normalizzato']}", token == vector["token"])

    vector = vectors["enroll_v1"][0]
    signature = c.hub_enroll.sign_enroll_payload(vector["site_url"], vector["token"], vector["dashboard_origin"], vector["issued_at"], key_k1)
    report.check("firma busta enroll v1", signature == vector["signature"])

    vector = vectors["rotate"][0]
    signature = c.hub_enroll.sign_fleet_payload("rotate", [vector["site_url"], vector["new_token"], vector["nonce"], str(vector["issued_at"]), str(vector["protocol"])], key_k1)
    report.check("firma busta rotate", signature == vector["signature"])

    vector = vectors["revoke"][0]
    signature = c.hub_enroll.sign_fleet_payload("revoke", [vector["site_url"], vector["nonce"], str(vector["issued_at"])], key_k1)
    report.check("firma busta revoke", signature == vector["signature"])

    # Firma HMAC delle richieste: il codice vero con orologio e nonce bloccati sul vettore.
    vector = vectors["request_hmac"][0]
    real_time, real_secrets = c.hub_proxy.time, c.hub_proxy.secrets
    c.hub_proxy.time = types.SimpleNamespace(time=lambda: int(vector["timestamp"]))
    c.hub_proxy.secrets = types.SimpleNamespace(token_hex=lambda _n: vector["nonce"])
    try:
        headers = c.hub_proxy._sign_site_request(vector["secret"], vector["method"], vector["route"], vector["body"].encode())
    finally:
        c.hub_proxy.time, c.hub_proxy.secrets = real_time, real_secrets
    report.check("firma HMAC delle richieste operative", headers["X-WPHC-Signature"] == vector["signature"])

    # Webhook in ingresso: la verifica dell'hub (modulo non vendorizzato: importato dal percorso).
    spec = importlib.util.spec_from_file_location("hub_webhook_ref", c.MONOREPO_DIR / "hub" / "services" / "fleet" / "webhook.py")
    webhook = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(webhook)  # type: ignore[union-attr]
    vector = vectors["webhook"][0]
    report.check(
        "verifica webhook dell'hub sul vettore",
        webhook.verify_webhook_signature([vector["secret"]], vector["event"], vector["body"].encode(), vector["timestamp"], vector["nonce"], vector["signature"]),
    )

    # Release: la firma dell'helper locale coincide con quella dello script ORIGINALE dell'hub.
    vector = vectors["release"][0]
    content = vector["content"].encode()
    report.check("firma release (helper locale) == vettore", c.sign_release(content, vector["tag"])["signature"] == vector["signature"])
    report.check("firma release (script hub) == helper locale", c.hub_script_sign_release(content, vector["tag"]) == c.sign_release(content, vector["tag"]))


def main() -> int:
    report = c.Report("hub_contract")
    initial = c.repo_plugin_fingerprint()
    c.check_config_matches_test_keys()

    offline_vector_checks(report)

    # Il mu-plugin di supporto va installato PRIMA di attendere l'ambiente (la sua rotta /state e' il segnale di pronto).
    c.install_support_plugin()
    support = c.Support()
    support.wait_ready()
    # Il plugin sotto test: WPHC_CANDIDATE_FILE o il file del repo (mu-plugins/). `baseline` resta il nome
    # del "file iniziale" nei controlli che seguono; la versione si ricava dal file installato.
    baseline = c.announce_plugin_under_test("hub_contract").contents
    baseline_version = c.plugin_version(baseline.decode("utf-8"))
    pushed_version = c.next_version(baseline_version)  # versione del candidato del push positivo

    # Stato di partenza: plugin sotto test (copia), sito pulito. Installa, invalida opcache e attende che il PHP lo esegua.
    c.install_and_load(support, baseline)
    support.reset()
    support.webhook(status=200, clear=True)
    hub = c.Hub()

    # ------------------------------------------------------------------ enroll v1
    report.section("Enroll v1 (token HMAC derivato)")
    health = hub.health()
    report.check("sito non enrollato: /health = errore 503 wphc_not_enrolled", health["status"] == "error" and health["error_detail"]["body"].get("code") == "wphc_not_enrolled", str(health))

    result = hub.enroll()
    report.check("enroll_site: ok, protocollo 1, token derivato", result["site"] == c.SITE_URL and result["protocol_version"] == 1 and result["token"] == c.v1_token(), str(result))
    options = support.state()["options"]
    report.check("il sito salva il token v1 e l'URL firmato", options.get("wp_health_check_token") == c.v1_token() and options.get("wp_health_check_site_url") == c.SITE_URL, str(options.keys()))
    report.check("il sito salva l'origin della dashboard", options.get("wp_health_check_dashboard_origin") == c.DASHBOARD_ORIGIN)

    # ------------------------------------------------------------------ dati con v1
    report.section("Rotte dati con protocollo 1 (solo Bearer)")
    health = hub.health()
    report.check("/health: status ok", health["status"] == "ok", str(health)[:300])
    report.check("/health: agent_version = versione del file", health["summary"].get("agent_version") == baseline_version, str(health["summary"].get("agent_version")))
    ping = hub.ping()
    report.check("/ping: ok", ping["status"] == "ok" and ping.get("agent_version") == baseline_version, str(ping))
    for section in ("plugins", "theme", "server", "users"):
        body = hub.detail(section)
        report.check(f"/detail/{section}: risposta JSON del sito", isinstance(body, dict) and body.get("site") == c.SITE_URL, str(body)[:200])
    status, body = c.raw_request("GET", "/health-check/v1/ping", "token-sbagliato")
    report.check("token errato: 401 wphc_unauthorized", status == 401 and body.get("code") == "wphc_unauthorized", str((status, body)))

    # ------------------------------------------------------------------ rotate
    report.section("Rotazione (protocollo 2) e grazia")
    old_token = c.v1_token()
    rotated = hub.rotate()
    report.check("rotate_site_secret: ok, non ambiguo, protocollo 2", rotated["rotated"] and not rotated["ambiguous"] and rotated["protocol_version"] == 2, str(rotated))
    new_token = hub.current_secret()
    state = support.state()
    options = state["options"]
    report.check("il sito ha il segreto nuovo e quello vecchio in grazia", options.get("wp_health_check_token") == new_token and options.get("wp_health_check_token_prev") == old_token)
    report.check("il sito e' al protocollo 2 con data di rotazione", str(options.get("wp_health_check_protocol")) == "2" and bool(options.get("wp_health_check_token_rotated_at")))

    # Il contatore dei fallimenti (throttle) si azzera spesso: vedi il controllo dedicato piu' sotto.
    support.clear_throttle()
    status, body = c.raw_request("GET", "/health-check/v1/ping", old_token, hmac_secret=old_token)
    report.check("segreto precedente + sua firma, dentro la grazia: 200", status == 200 and body.get("status") == "ok", str((status, body)))
    report.check("l'uso del precedente e' tracciato (grace_window_hit)", any(row["message"] == "grace_window_hit" for row in support.state()["log"]))
    status, body = c.raw_request("GET", "/health-check/v1/ping", old_token)
    report.check("segreto precedente SENZA firma (protocollo 2): 401", status == 401, str((status, body)))

    support.clear_throttle()
    health = hub.health()
    report.check("primo uso del segreto nuovo (firmato HMAC): /health ok", health["status"] == "ok", str(health)[:300])
    options = support.state()["options"]
    report.check("al primo uso del nuovo, il precedente decade subito", "wp_health_check_token_prev" not in options and "wp_health_check_token_rotated_at" not in options)
    status, body = c.raw_request("GET", "/health-check/v1/ping", old_token, hmac_secret=old_token)
    report.check("segreto precedente dopo il primo uso del nuovo: 401", status == 401, str((status, body)))
    status, body = c.raw_request("GET", "/health-check/v1/ping", new_token)
    report.check("segreto nuovo senza firma: 401", status == 401, str((status, body)))

    support.clear_throttle()
    # Replay: la stessa terna (timestamp, nonce, firma) accettata una volta sola.
    headers = {"Authorization": f"Bearer {new_token}", "User-Agent": c.hub_enroll.SITE_USER_AGENT, **c.hub_proxy._sign_site_request(new_token, "GET", "/health-check/v1/ping", b"")}
    first = c.requests.get(f"{c.BASE_URL}/wp-json/health-check/v1/ping", headers=headers, timeout=30).status_code
    second = c.requests.get(f"{c.BASE_URL}/wp-json/health-check/v1/ping", headers=headers, timeout=30).status_code
    report.check("anti-replay: stessa richiesta firmata, prima 200 poi 401", (first, second) == (200, 401), str((first, second)))

    for section in ("plugins", "theme", "server", "users"):
        support.clear_throttle()
        body = hub.detail(section)
        report.check(f"/detail/{section} con segreto v2 firmato", isinstance(body, dict) and body.get("site") == c.SITE_URL)
    support.clear_throttle()
    report.check("/ping con segreto v2 firmato", hub.ping()["status"] == "ok")

    # COMPORTAMENTO SCOMODO da preservare (o cambiare con decisione esplicita): su richieste HTTP
    # reali la callback di permesso gira DUE volte (la seconda in rest_send_allow_header() del
    # core, che ne scarta l'esito) e la seconda vede il nonce gia' usato, quindi ogni richiesta
    # firmata VALIDA aggiunge 1 al contatore dei fallimenti; una non valida ne aggiunge 2.
    # In-process (rest_do_request, suite PHPUnit) il fenomeno non si vede.
    support.clear_throttle()
    hub.ping()
    report.check("ogni richiesta v2 valida incrementa di 1 il contatore dei fallimenti", support.throttle_failures() == 1, str(support.throttle_failures()))
    support.clear_throttle()
    c.raw_request("GET", "/health-check/v1/ping", "token-sbagliato")
    report.check("ogni richiesta con Bearer errato lo incrementa di 2", support.throttle_failures() == 2, str(support.throttle_failures()))
    support.clear_throttle()

    # ------------------------------------------------------------------ push update
    report.section("Push di un aggiornamento firmato (k2 di test)")
    # Candidato = il plugin sotto test con la versione successiva (header e costante): il self-update che lo
    # installa e' quello del plugin sotto test.
    candidate_text = c.make_candidate(baseline.decode("utf-8"), pushed_version)
    candidate = candidate_text.encode("utf-8")
    tag = "v" + pushed_version
    sig = c.sign_release(candidate, tag)
    c.write_release_assets(c.CANDIDATES_DIR / "contract", candidate, sig)
    update_route = "/health-check/v1/update"

    def push(package):
        resp = hub.request("POST", update_route, json_body={"package": package})
        try:
            return resp.status_code, resp.json()
        except ValueError:
            return resp.status_code, resp.text

    def file_unchanged() -> bool:
        return c.installed_plugin_text().encode("utf-8") == baseline and c.plugin_version(c.installed_plugin_text()) == baseline_version

    # Rifiuti: il file installato non deve mai cambiare.
    support.clear_throttle()
    bad_sig = c.sign_release(candidate, tag, seed=c.SEED_K1, kid="k2")  # firmata con la chiave sbagliata
    status, body = push(c.build_push_package(candidate, bad_sig))
    report.check("firma con la chiave sbagliata: 200 updated=false integrity_check_failed", status == 200 and body.get("updated") is False and body.get("reason") == "integrity_check_failed", str((status, body)))
    report.check("  il file installato e' invariato", file_unchanged())

    tampered = candidate + b"\n// manomesso\n"
    package = c.build_push_package(candidate, sig)
    package["contents_gz_b64"] = c.base64.b64encode(c.gzip.compress(tampered, 9)).decode("ascii")
    status, body = push(package)
    report.check("contenuto manomesso (hash non coincide): integrity_check_failed", status == 200 and body.get("reason") == "integrity_check_failed", str((status, body)))
    report.check("  il file installato e' invariato", file_unchanged())

    same_sig = c.sign_release(baseline, "v" + baseline_version)
    status, body = push(c.build_push_package(baseline, same_sig))
    report.check("stessa versione senza force: up_to_date", status == 200 and body.get("updated") is False and body.get("reason") == "up_to_date" and body.get("mode") == "push", str((status, body)))

    status, body = push({"tag": tag})
    report.check("pacchetto incompleto: 400 wphc_update_push_invalid", status == 400 and body.get("code") == "wphc_update_push_invalid", str((status, body)))

    # Push positivo.
    support.clear_throttle()
    status, body = push(c.build_push_package(candidate, sig))
    report.check(
        f"push valido: updated=true da {baseline_version} a {pushed_version}, firma verificata, mode=push",
        status == 200 and body.get("updated") is True and body.get("from") == baseline_version and body.get("to") == pushed_version and body.get("signature") == "verified" and body.get("mode") == "push",
        str((status, body)),
    )
    report.check("  il file della copia ora e' il candidato (byte per byte)", c.installed_plugin_text().encode("utf-8") == candidate)
    report.check("  nessun backup o temporaneo residuo in mu-plugins", not [p.name for p in c.WORK_MU_DIR.iterdir() if p.name.endswith((".bak", ".tmp")) and p.name != ".install.tmp"], str(sorted(p.name for p in c.WORK_MU_DIR.iterdir())))
    c.assert_repo_plugin_untouched(initial)

    support.clear_throttle()
    health = hub.health()
    report.check("dopo l'update: /health ok con lo STESSO segreto (stato conservato)", health["status"] == "ok", str(health)[:300])
    # /health ha una cache di 60 secondi (transient wphc_health_cache) che l'update non svuota:
    # subito dopo l'aggiornamento la risposta normale riporta ancora la vecchia agent_version.
    report.check("  /health (da cache) riporta ancora la vecchia agent_version", health["summary"].get("agent_version") == baseline_version, str(health["summary"].get("agent_version")))
    support.clear_throttle()
    fresh = hub.health(fresh=True)
    report.check(f"  /health?fresh=1: agent_version = {pushed_version}", fresh["status"] == "ok" and fresh["summary"].get("agent_version") == pushed_version, str(fresh.get("summary", {}).get("agent_version")))
    support.clear_throttle()
    report.check(f"  /ping: agent_version = {pushed_version}", hub.ping().get("agent_version") == pushed_version)
    # Il codice caricato (versione in memoria, sha256 del file incluso, opcache) e' quello scritto dal self-update.
    loaded = support.state()
    report.check("  il PHP carica la nuova versione e il file scritto (sha256)", not c.loaded_code_mismatch(loaded, candidate), str(c.loaded_code_mismatch(loaded, candidate)))

    support.clear_throttle()
    status, body = push(c.build_push_package(candidate, sig))
    report.check("secondo push della stessa versione: up_to_date", status == 200 and body.get("reason") == "up_to_date", str((status, body)))

    # ------------------------------------------------------------------ revoke
    report.section("Revoca")
    support.clear_throttle()
    revoked = hub.revoke()
    report.check("revoke_site_secret: revocato e sito avvisato", revoked["revoked"] and revoked["site_notified"], str(revoked))
    report.check("il sito registra la revoca", bool(support.state()["options"].get("wp_health_check_revoked_at")))
    status, body = c.raw_request("GET", "/health-check/v1/ping", new_token, hmac_secret=new_token)
    report.check("segreto e firma corretti ma sito revocato: 403 wphc_revoked", status == 403 and body.get("code") == "wphc_revoked", str((status, body)))
    try:
        hub.health()
        report.check("l'hub non contatta piu' un sito revocato (FleetError site_revoked)", False, "nessuna eccezione")
    except c.FleetError as error:
        report.check("l'hub non contatta piu' un sito revocato (FleetError site_revoked)", getattr(error, "code", None) == "site_revoked", str(error))

    # ------------------------------------------------------------------ pulizia
    support.reset()
    support.webhook(status=200, clear=True)
    c.install_and_load(support, baseline)
    c.assert_repo_plugin_untouched(initial)
    return report.finish()


if __name__ == "__main__":
    sys.exit(main())
