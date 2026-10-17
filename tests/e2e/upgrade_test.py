"""Test di UPGRADE: un sito gia' enrollato con la 1.38.1 VERA continua a funzionare dopo
l'aggiornamento al file candidato, e l'hub (codice vendorizzato, invariato) lo parla senza modifiche.

Idea: ogni scenario crea uno stato realistico sulla 1.38.1 e poi si esegue DUE volte, partendo
dallo stesso stato e con le stesse operazioni:

    controllo:  costruzione dello stato -> fase 1 -> fase 2         (sempre sulla 1.38.1)
    upgrade:    costruzione dello stato -> fase 1 -> PUSH -> fase 2 (fase 2 sul file candidato)

Le trascrizioni delle due esecuzioni (esiti HTTP, codici d'errore, forma delle risposte, option
del sito, cron, log, chiamate al webhook) devono coincidere: gli STESSI segreti e le STESSE option
danno gli STESSI esiti prima e dopo. In piu' ogni scenario controlla esiti attesi in assoluto
(un controllo "uguale" fra due esecuzioni entrambe sbagliate non basterebbe).

Scenari: fresh (sito mai enrollato), v1, v2 dentro la finestra di grazia, v2 con grazia
scaduta, revocato, job bulk in corso (ripreso dal cron), webhook in attesa di retry.

Il file candidato e' parametrizzabile:
    WPHC_CANDIDATE_FILE=percorso   file da installare (dopo il refactoring: la build vera);
                                   senza, e' una copia della baseline con Version 1.99.0.
    WPHC_BASELINE_REF=commit       baseline letta da git (default 1d6725b = v1.38.1).
Con un candidato della STESSA versione della baseline il push usa force (reinstallazione).

Uso (con l'ambiente e2e acceso, vedi tests/e2e/README.md):
    python -I tests/e2e/upgrade_test.py [--only nome] [--list]

Il file reale mu-plugins/wp-health-check.php non viene mai scritto: il plugin sotto test e' la
copia montata nel container; il push positivo avviene solo li'.
"""
import argparse
import importlib.util
import json
import os
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import e2e_common as c  # noqa: E402

# Verifica dei webhook in ingresso: modulo dell'hub non vendorizzato, importato dal percorso.
_spec = importlib.util.spec_from_file_location("hub_webhook_ref", c.MONOREPO_DIR / "hub" / "services" / "fleet" / "webhook.py")
hub_webhook = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(hub_webhook)  # type: ignore[union-attr]

PREV_ROUTE = "/health-check/v1/ping"
BULK_ROUTE = "/health-check/v1/update/bulk"
BULK_PLUGINS = ["hello.php", "akismet/akismet.php", "e2e-missing-a/a.php", "e2e-missing-b/b.php", "e2e-missing-c/c.php", "e2e-missing-d/d.php", "e2e-missing-e/e.php"]


# ---------------------------------------------------------------------------
# Contesto di un'esecuzione: hub, supporto, trascrizione
# ---------------------------------------------------------------------------

class Ctx:
    """Strumenti per costruire lo stato, eseguire le fasi e registrare cio' che si osserva."""

    def __init__(self, hub: c.Hub, support: c.Support):
        self.hub = hub
        self.support = support
        self.transcript: list = []

    # -- registrazione ---------------------------------------------------------------
    def note(self, label: str, value) -> None:
        self.transcript.append([label, value])

    def raw(self, label: str, bearer, hmac_secret=None, bad_signature=False, method="GET", route=PREV_ROUTE, body=None):
        """Richiesta diretta al plugin: registra (status, codice o stato)."""
        status, payload = c.raw_request(method, route, bearer, hmac_secret=hmac_secret, bad_signature=bad_signature, body=body)
        code = payload.get("code") or payload.get("status") if isinstance(payload, dict) else str(payload)[:60]
        self.note(label, [status, code])
        return status, payload

    def hub_call(self, label: str, fn):
        """Chiamata di alto livello dell'hub: registra la forma dell'esito, anche se solleva."""
        try:
            result = fn()
        except Exception as exc:  # noqa: BLE001 - il tipo e il codice fanno parte dell'osservazione
            self.note(label, ["eccezione", type(exc).__name__, getattr(exc, "code", None)])
            return None
        if isinstance(result, dict) and "status" in result and "response_time_ms" in result:
            # health/ping: stato, e forma (chiavi) del sommario o corpo dell'errore.
            shape = sorted(result["summary"].keys()) if isinstance(result.get("summary"), dict) else (result.get("error_detail", {}).get("body", {}) or {}).get("code") if isinstance(result.get("error_detail", {}).get("body"), dict) else None
            self.note(label, [result["status"], shape, result.get("summary", {}).get("agent_version") if isinstance(result.get("summary"), dict) else result.get("agent_version")])
        else:
            self.note(label, ["dict", sorted(result.keys())] if isinstance(result, dict) else str(type(result)))
        return result

    def state_summary(self, label: str) -> None:
        """Option del plugin, cron, log, contatore throttle: tutto cio' che deve sopravvivere all'upgrade."""
        state = self.support.state()
        transients = {k: v for k, v in state["transients"].items() if not k.startswith(("wphc_fail_", "wphc_health_cache", "wphc_latest_version_cache", "wphc_nonce_"))}
        # La prima /health genera davvero l'anteprima del sito (thum.io) e risolve l'IP pubblico (rete del
        # container): sono valori che cambiano a ogni esecuzione (id dell'allegato). Si confronta se ci sono,
        # non il loro contenuto.
        volatile = ("wp_health_check_thumb", "wp_health_check_thumb_id", "wp_health_check_thumb_at", "wp_health_check_thumb_error")
        options = {k: ("<VOLATILE>" if k in volatile else v) for k, v in state["options"].items()}
        transients = {k: ("<VOLATILE>" if k.startswith(("wphc_public_ip", "wphc_thumb_retry_lock")) else v) for k, v in transients.items()}
        self.note(label, {
            "options": options,
            "transients": transients,
            "cron": sorted([e["hook"], bool(e["args"])] for e in state["cron"]),
            "log": [[r["type"], r["target"], r["phase"], r["message"], r["source"]] for r in state["log"]],
        })

    # -- segreti noti, per mascherare i valori nei confronti ----------------------------
    def secret_map(self) -> dict:
        return {"CURRENT": self.hub.current_secret(), "PREVIOUS": self.hub.previous_secret() or "", "V1": c.v1_token(), "OLDCURRENT": getattr(self, "old_current", "")}

    # -- operazioni ricorrenti ------------------------------------------------------------
    def fresh_site(self):
        self.hub.reset()

    def enroll_and_rotate(self):
        self.hub.reset()
        self.hub.enroll()
        self.old_current = c.v1_token()
        self.hub.rotate()

    def webhook_calls(self) -> list:
        return self.support.state()["webhook_calls"]

    def webhook_observation(self, calls: list) -> list:
        """Per ogni chiamata al finto ricevente: evento, tentativo, esito della verifica della firma con la
        funzione dell'hub, e la parte stabile del corpo."""
        secret = self.hub.current_secret()
        out = []
        for call in calls:
            headers = {k.lower(): v for k, v in call["headers"].items()}
            body = call["body"].encode("utf-8") if isinstance(call["body"], str) else call["body"]
            valid = hub_webhook.verify_webhook_signature([secret], headers.get("x-wphc-event", ""), body, headers.get("x-wphc-timestamp", ""), headers.get("x-wphc-nonce", ""), headers.get("x-wphc-signature", ""))
            parsed = json.loads(call["body"])
            out.append({
                "event": headers.get("x-wphc-event"),
                "attempt": headers.get("x-wphc-attempt"),
                "site_header": headers.get("x-wphc-site"),
                "kid": headers.get("x-wphc-secret-kid"),
                "signature_valid": valid,
                "schema": parsed.get("schema"),
                "job_status": parsed.get("job", {}).get("status"),
                "totals": parsed.get("totals"),
                "items": [[i["type"], i["target"], i["result"], i["attempts"]] for i in parsed.get("items", [])],
            })
        return out


# ---------------------------------------------------------------------------
# Scenari: costruzione dello stato, fase 1 (prima del push), fase 2 (dopo)
# ---------------------------------------------------------------------------

def sc_fresh_build(x: Ctx):
    x.fresh_site()


# Un sito mai enrollato non ha un segreto: il push (rotta autenticata) richiede un enroll
# provvisorio, poi il sito torna "mai enrollato" (option azzerate) con il file gia' aggiornato.
sc_fresh_build.enroll_for_push = True


def sc_fresh_phase1(x: Ctx):
    x.support.clear_throttle()
    x.hub_call("health non enrollato", x.hub.health)


def sc_fresh_phase2(x: Ctx):
    """Un sito mai enrollato prima dell'upgrade percorre tutto il ciclo: enroll v1, rotate, dati."""
    x.support.clear_throttle()
    x.hub_call("health non enrollato", x.hub.health)
    x.hub_call("enroll v1", lambda: {k: v for k, v in x.hub.enroll().items() if k != "user_agent_used"})
    x.support.clear_throttle()
    x.hub_call("health v1", x.hub.health)
    x.hub_call("detail plugins v1", lambda: x.hub.detail("plugins"))
    x.hub_call("rotate", x.hub.rotate)
    x.support.clear_throttle()
    x.hub_call("health v2", x.hub.health)
    x.hub_call("ping v2", x.hub.ping)
    x.state_summary("stato finale")


def sc_v1_build(x: Ctx):
    x.fresh_site()
    x.hub.enroll()


def sc_v1_probes(x: Ctx):
    token = c.v1_token()
    x.support.clear_throttle()
    x.hub_call("health", x.hub.health)
    x.raw("bearer v1: 200", token)
    x.raw("bearer errato: 401", "errato")
    x.raw("bearer v1 + firma valida (dual-stack): 200", token, hmac_secret=token)
    x.raw("bearer v1 + firma sbagliata: 401", token, hmac_secret=token, bad_signature=True)
    x.raw("senza Authorization: 401", None)
    x.hub_call("detail server", lambda: x.hub.detail("server"))
    x.note("fallimenti nel throttle", x.support.throttle_failures())
    x.state_summary("stato")


def sc_v2_grace_build(x: Ctx):
    x.enroll_and_rotate()


# Il push firmato col segreto NUOVO chiuderebbe la grazia (il precedente decade al primo uso del nuovo):
# per provare che la grazia sopravvive all'upgrade il push viaggia con le credenziali PRECEDENTI.
sc_v2_grace_build.upgrade_via = "push_previous"


def sc_v2_grace_phase1(x: Ctx):
    x.support.clear_throttle()
    old = c.v1_token()
    x.raw("precedente + sua firma, in grazia: 200", old, hmac_secret=old)
    x.raw("precedente senza firma: 401", old)
    x.state_summary("stato dopo fase 1")


def sc_v2_grace_phase2(x: Ctx):
    x.support.clear_throttle()
    old = c.v1_token()
    x.raw("precedente + sua firma, ancora in grazia dopo l'upgrade: 200", old, hmac_secret=old)
    x.note("segreto precedente ancora nelle option", "wp_health_check_token_prev" in x.support.state()["options"])
    x.hub_call("health col segreto nuovo", x.hub.health)
    x.note("segreto precedente decaduto al primo uso del nuovo", "wp_health_check_token_prev" not in x.support.state()["options"])
    x.raw("precedente dopo il primo uso del nuovo: 401", old, hmac_secret=old)
    x.raw("nuovo + firma: 200", x.hub.current_secret(), hmac_secret=x.hub.current_secret())
    x.note("righe grace_window_hit nel log", sum(1 for r in x.support.state()["log"] if r["message"] == "grace_window_hit"))
    x.state_summary("stato finale")


def sc_v2_expired_build(x: Ctx):
    x.enroll_and_rotate()
    # Rotazione "vecchia di 20 minuti": oltre la finestra di grazia (900 s).
    x.support.set_options({"wp_health_check_token_rotated_at": int(time.time()) - 1200})


def sc_v2_expired_phase1(x: Ctx):
    x.support.clear_throttle()
    old = c.v1_token()
    x.raw("precedente + sua firma, grazia scaduta: 401", old, hmac_secret=old)
    x.state_summary("stato dopo fase 1")


def sc_v2_expired_phase2(x: Ctx):
    x.support.clear_throttle()
    old = c.v1_token()
    x.raw("precedente + sua firma, grazia scaduta dopo l'upgrade: 401", old, hmac_secret=old)
    x.hub_call("health col segreto nuovo", x.hub.health)
    x.raw("nuovo + firma: 200", x.hub.current_secret(), hmac_secret=x.hub.current_secret())
    x.state_summary("stato finale")


def sc_revoked_build(x: Ctx):
    x.enroll_and_rotate()
    x.hub.revoke()


# Un sito revocato rifiuta TUTTE le rotte dati, anche /update: l'upgrade avviene come un deploy
# manuale/installer (il file della copia viene sostituito dall'esterno).
sc_revoked_build.upgrade_via = "file"


def sc_revoked_probes(x: Ctx):
    x.support.clear_throttle()
    current = x.hub.current_secret()
    x.raw("corrente + firma, sito revocato: 403", current, hmac_secret=current)
    x.raw("precedente + firma, sito revocato: 403", c.v1_token(), hmac_secret=c.v1_token())
    x.raw("Bearer errato, sito revocato: 403 (non 401)", "errato")
    x.raw("rotta di update, sito revocato: 403", current, hmac_secret=current, method="POST", route="/health-check/v1/update", body={})
    x.hub_call("health (l'hub non contatta il sito)", x.hub.health)
    x.note("cron del webhook senza pendenti", x.support.cron_run("wphc_webhook_retry"))
    x.note("fallimenti nel throttle (la revoca non li conta)", x.support.throttle_failures())
    x.state_summary("stato")


def sc_bulk_build(x: Ctx):
    x.enroll_and_rotate()
    x.support.empty_updates()
    x.support.webhook(status=200, clear=True)
    x.support.clear_throttle()
    resp = x.hub.request("POST", BULK_ROUTE, json_body={"plugins": BULK_PLUGINS})
    body = resp.json()
    x.note("enqueue bulk", [resp.status_code, body.get("accepted"), body.get("total"), body.get("rejected")])


def _bulk_status(x: Ctx, label: str):
    x.support.clear_throttle()
    resp = x.hub.request("GET", BULK_ROUTE)
    job = resp.json().get("job") or {}
    x.note(label, {
        "http": resp.status_code,
        "status": job.get("status"),
        "counters": job.get("counters"),
        "webhook": {k: job.get("webhook", {}).get(k) for k in ("sent", "attempts", "code", "last_error")},
        "items": [[i["type"], i["target"], i["state"], i["result"], i["attempts"]] for i in job.get("items", [])],
    })
    return job


def sc_bulk_phase1(x: Ctx):
    x.note("tick di cron (pre-upgrade)", x.support.cron_run("wphc_bulk_update_tick"))
    _bulk_status(x, "job dopo il primo tick")
    x.state_summary("stato dopo fase 1")


def sc_bulk_phase2(x: Ctx):
    """Il job interrotto dall'upgrade riprende dal cron e arriva in fondo; il webhook parte firmato."""
    x.note("tick di cron (post-upgrade)", x.support.cron_run("wphc_bulk_update_tick"))
    job = _bulk_status(x, "job dopo il secondo tick")
    ticks = 0
    # Gli item in backoff chiedono ulteriori tick: al massimo qualche giro, uguale nelle due esecuzioni.
    while job.get("status") in ("queued", "running") and ticks < 4:
        # Il backoff e' in secondi reali: si fa scadere riportando indietro next_after dell'item nel job.
        _expire_bulk_backoff(x)
        x.note(f"tick extra {ticks + 1}", x.support.cron_run("wphc_bulk_update_tick"))
        job = _bulk_status(x, f"job dopo il tick extra {ticks + 1}")
        ticks += 1
    x.note("webhook ricevuti", x.webhook_observation(x.webhook_calls()))
    x.state_summary("stato finale")


def _expire_bulk_backoff(x: Ctx):
    """Azzera next_after degli item del job (il backoff reale richiederebbe minuti di attesa)."""
    options = x.support.state()["options"]
    job = options.get("wp_health_check_bulk_job")
    if not job:
        return
    for item in job["items"]:
        item["next_after"] = 0
    x.support.set_options({"wp_health_check_bulk_job": job})


def sc_webhook_build(x: Ctx):
    x.enroll_and_rotate()
    x.support.empty_updates()
    x.support.webhook(status=500, clear=True)
    x.support.clear_throttle()
    resp = x.hub.request("POST", BULK_ROUTE, json_body={"plugins": ["hello.php"]})
    x.note("enqueue bulk", [resp.status_code, resp.json().get("accepted"), resp.json().get("total")])


def sc_webhook_phase1(x: Ctx):
    x.note("tick di cron: il job finisce e il webhook fallisce (500)", x.support.cron_run("wphc_bulk_update_tick"))
    state = x.support.state()
    pending = state["options"].get("wp_health_check_webhook_pending")
    x.note("retry in attesa", {"pending": bool(pending), "attempts": pending.get("attempts") if pending else None, "event": pending.get("event") if pending else None,
                               "cron": sorted(e["hook"] for e in state["cron"])})
    x.note("webhook ricevuti", x.webhook_observation(x.webhook_calls()))
    x.state_summary("stato dopo fase 1")


def sc_webhook_phase2(x: Ctx):
    """Dopo l'upgrade il retry pianificato parte con lo stesso corpo, nuova firma e tentativo 2."""
    x.support.webhook(status=200)
    x.note("evento di retry eseguito", x.support.cron_run("wphc_webhook_retry"))
    state = x.support.state()
    x.note("pendenti dopo il retry", bool(state["options"].get("wp_health_check_webhook_pending")))
    calls = x.webhook_calls()
    x.note("webhook ricevuti", x.webhook_observation(calls))
    x.note("stesso corpo nei due tentativi", len({call["body"] for call in calls}) == 1)
    x.note("firme diverse nei due tentativi", len({{k.lower(): v for k, v in call["headers"].items()}["x-wphc-signature"] for call in calls}) == len(calls))
    x.state_summary("stato finale")


# Ogni scenario: (nome, build, fase1, fase2, controlli assoluti sulla trascrizione dell'upgrade)
def _find(transcript: list, label: str):
    for entry in transcript:
        if entry[0] == label:
            return entry[1]
    raise KeyError(label)


def expect_fresh(t: list):
    return {
        "ciclo completo dopo l'upgrade: enroll v1 ok": _find(t, "enroll v1")[:2] == ["dict", ["protocol_version", "site", "token"]],
        "health v1 ok": _find(t, "health v1")[0] == "ok",
        "health v2 firmato ok": _find(t, "health v2")[0] == "ok",
        "ping v2 ok": _find(t, "ping v2")[0] == "ok",
        "rotate ok": _find(t, "rotate")[1] == ["ambiguous", "protocol_version", "rotated", "rotated_at", "site_id"],
    }


def expect_v1(t: list):
    return {
        "bearer v1: 200": _find(t, "bearer v1: 200")[0] == 200,
        "bearer errato: 401 wphc_unauthorized": _find(t, "bearer errato: 401") == [401, "wphc_unauthorized"],
        "dual-stack: 200": _find(t, "bearer v1 + firma valida (dual-stack): 200")[0] == 200,
        "firma sbagliata: 401": _find(t, "bearer v1 + firma sbagliata: 401")[0] == 401,
        "health ok": _find(t, "health")[0] == "ok",
    }


def expect_grace(t: list):
    return {
        "grazia: 200 prima dell'upgrade": any(e[0].startswith("precedente + sua firma, in grazia") and e[1][0] == 200 for e in t),
        "grazia: 200 anche dopo l'upgrade": any(e[0].startswith("precedente + sua firma, ancora in grazia") and e[1][0] == 200 for e in t),
        "il precedente decade al primo uso del nuovo": _find(t, "segreto precedente decaduto al primo uso del nuovo") is True,
        "dopo la decadenza il precedente e' 401": any(e[0].startswith("precedente dopo il primo uso") and e[1][0] == 401 for e in t),
        "grace_window_hit tracciati": _find(t, "righe grace_window_hit nel log") >= 2,
    }


def expect_expired(t: list):
    return {
        "grazia scaduta: 401 prima": any(e[0].endswith("grazia scaduta: 401") and e[1][0] == 401 for e in t),
        "grazia scaduta: 401 dopo": any("dopo l'upgrade" in e[0] and e[1][0] == 401 for e in t),
        "il segreto nuovo funziona": any(e[0] == "nuovo + firma: 200" and e[1][0] == 200 for e in t),
    }


def expect_revoked(t: list):
    return {
        "corrente revocato: 403 wphc_revoked": _find(t, "corrente + firma, sito revocato: 403") == [403, "wphc_revoked"],
        "precedente revocato: 403": _find(t, "precedente + firma, sito revocato: 403")[0] == 403,
        "Bearer errato su sito revocato: 403 non 401": _find(t, "Bearer errato, sito revocato: 403 (non 401)")[0] == 403,
        "hub non contatta il sito (site_revoked)": _find(t, "health (l'hub non contatta il sito)") == ["eccezione", "FleetError", "site_revoked"],
        "la revoca non conta nel throttle": _find(t, "fallimenti nel throttle (la revoca non li conta)") == 0,
    }


def expect_bulk(t: list):
    after1 = _find(t, "job dopo il primo tick")
    final = [e[1] for e in t if e[0].startswith("job dopo il") and e[0] != "job dopo il primo tick"][-1]
    webhooks = _find(t, "webhook ricevuti")
    return {
        "enqueue accettato con 7 elementi": _find(t, "enqueue bulk")[:3] == [202, True, 7],
        "dopo il primo tick il job e' a meta' (running, 5 elementi trattati)": after1["status"] == "running" and after1["counters"]["done"] == 5 and after1["counters"]["pending"] == 2,
        "dopo l'upgrade il job riprende e finisce": final["status"] in ("completed", "completed_with_errors", "failed") or final["counters"]["pending"] == 0,
        "nessun elemento resta pendente": final["counters"]["pending"] == 0,
        "webhook di fine job inviato una volta, firmato, tentativo 1": len(webhooks) == 1 and webhooks[0]["signature_valid"] and webhooks[0]["attempt"] == "1" and webhooks[0]["event"] == "wphc.bulk_update.completed",
        "il webhook riporta i 7 elementi": len(webhooks[0]["items"]) == 7 if webhooks else False,
    }


def expect_webhook(t: list):
    pending = _find(t, "retry in attesa")
    hooks = [e[1] for e in t if e[0] == "webhook ricevuti"]
    return {
        "dopo il fallimento c'e' un retry in attesa (tentativo 1)": pending["pending"] and pending["attempts"] == 1 and "wphc_webhook_retry" in pending["cron"],
        "primo invio: evento completed, tentativo 1, firma valida": len(hooks[0]) == 1 and hooks[0][0]["signature_valid"] and hooks[0][0]["attempt"] == "1",
        "retry dopo l'upgrade eseguito": _find(t, "evento di retry eseguito") == 1,
        "pendenti svuotati dopo il retry riuscito": _find(t, "pendenti dopo il retry") is False,
        "retry: tentativo 2, firma valida col segreto corrente": len(hooks[1]) == 2 and hooks[1][1]["attempt"] == "2" and hooks[1][1]["signature_valid"],
        "stesso corpo nei due tentativi": _find(t, "stesso corpo nei due tentativi") is True,
        "firme diverse (timestamp e nonce nuovi)": _find(t, "firme diverse nei due tentativi") is True,
    }


SCENARIOS = [
    ("fresh", sc_fresh_build, sc_fresh_phase1, sc_fresh_phase2, expect_fresh),
    ("v1", sc_v1_build, sc_v1_probes, sc_v1_probes, expect_v1),
    ("v2-grace", sc_v2_grace_build, sc_v2_grace_phase1, sc_v2_grace_phase2, expect_grace),
    ("v2-grace-scaduta", sc_v2_expired_build, sc_v2_expired_phase1, sc_v2_expired_phase2, expect_expired),
    ("revocato", sc_revoked_build, sc_revoked_probes, sc_revoked_probes, expect_revoked),
    ("bulk-in-corso", sc_bulk_build, sc_bulk_phase1, sc_bulk_phase2, expect_bulk),
    ("webhook-retry", sc_webhook_build, sc_webhook_phase1, sc_webhook_phase2, expect_webhook),
]


# ---------------------------------------------------------------------------
# Candidato e push
# ---------------------------------------------------------------------------

def version_tuple(version: str):
    return tuple(int(part) for part in version.split("-")[0].split(".")[:3])


def prepare_candidate(baseline: bytes):
    """Restituisce (contenuto, firma k2 di test, force, versione). Rifiuta candidati incoerenti."""
    baseline_text = baseline.decode("utf-8")
    baseline_version = c.plugin_version(baseline_text)
    candidate_path = os.environ.get("WPHC_CANDIDATE_FILE")
    if candidate_path:
        contents = Path(candidate_path).read_bytes()
        text = contents.decode("utf-8")
        version = c.plugin_version(text)
        if f"define( 'WP_HEALTH_CHECK_VERSION', '{version}' )" not in text:
            raise SystemExit(f"Il candidato dichiara Version {version} ma la costante WP_HEALTH_CHECK_VERSION non coincide")
    else:
        version = "1.99.0"
        contents = c.make_candidate(baseline_text, version).encode("utf-8")
    if version_tuple(version) < version_tuple(baseline_version):
        raise SystemExit(f"Il candidato ({version}) e' piu' vecchio della baseline ({baseline_version}): il plugin rifiuta i downgrade")
    force = version_tuple(version) == version_tuple(baseline_version)
    tag = "v" + version
    sig = c.sign_release(contents, tag)
    # Doppio controllo: la firma coincide con quella dello script ORIGINALE dell'hub.
    if sig != c.hub_script_sign_release(contents, tag):
        raise SystemExit("La firma dell'helper locale diverge da hub/scripts/sign_release.py")
    c.write_release_assets(c.CANDIDATES_DIR / "upgrade", contents, sig)
    return contents, sig, force, version


def push_candidate(x: Ctx, contents: bytes, sig: dict, force: bool, version: str, via: str) -> dict:
    """Porta il sito al candidato.

    via == "push":           POST /update con il pacchetto firmato, con l'hub vero (_site_request firmata);
    via == "push_previous":  lo stesso push, ma con le credenziali del segreto PRECEDENTE (grazia);
    via == "file":           sostituzione esterna del file (deploy manuale), per i siti che rifiutano il push.
    Ritorna il corpo della risposta (per "file": un finto esito) e verifica che il candidato sia in uso.
    """
    x.support.clear_throttle()
    if via == "file":
        c.install_plugin(contents)
        body = {"updated": True, "to": version, "signature": "n/a"}
    else:
        package = c.build_push_package(contents, sig, force=force)
        if via == "push_previous":
            previous = x.hub.previous_secret()
            status, body = c.raw_request("POST", "/health-check/v1/update", previous, hmac_secret=previous, body={"package": package})
        else:
            resp = x.hub.request("POST", "/health-check/v1/update", json_body={"package": package})
            status, body = resp.status_code, resp.json()
        if status != 200 or body.get("updated") is not True or body.get("to") != version:
            raise SystemExit(f"Push del candidato fallito: HTTP {status} {body}")
    # opcache puo' impiegare qualche secondo a rivalidare un file sostituito dall'esterno.
    deadline = time.time() + 15
    while time.time() < deadline and x.support.state()["loaded_version"] != version:
        time.sleep(1)
    return body


# ---------------------------------------------------------------------------
# Esecuzione di uno scenario (controllo o upgrade) e confronto
# ---------------------------------------------------------------------------

def run_once(name, build, phase1, phase2, hub, support, baseline, candidate, upgrade: bool):
    contents, sig, force, version = candidate
    support.reset()
    support.webhook(status=200, clear=True)
    c.install_plugin(baseline)
    support.wait_ready()
    x = Ctx(hub, support)
    build(x)
    start = len(x.transcript)
    phase1(x)
    phase1_transcript = x.transcript[start:]
    push_response = None
    via = getattr(build, "upgrade_via", "push")
    if upgrade:
        if getattr(build, "enroll_for_push", False):
            x.hub.reset()
            x.hub.enroll()
        push_response = push_candidate(x, contents, sig, force, version, via)
        if getattr(build, "enroll_for_push", False):
            support.reset()
            support.webhook(status=200, clear=True)
            x.hub.reset()
    elif via == "push_previous":
        # Controllo equivalente: la stessa richiesta con le credenziali precedenti (lascia le stesse tracce
        # nel log), ma senza aggiornare il file.
        support.clear_throttle()
        previous = x.hub.previous_secret()
        c.raw_request("GET", PREV_ROUTE, previous, hmac_secret=previous)
    start = len(x.transcript)
    phase2(x)
    phase2_transcript = x.transcript[start:]
    secrets = x.secret_map()
    return {
        "ctx": x,
        "phase1": c.normalize(phase1_transcript, secrets, (c.plugin_version(baseline.decode("utf-8")), version)),
        "phase2": c.normalize(phase2_transcript, secrets, (c.plugin_version(baseline.decode("utf-8")), version)),
        "raw_transcript": x.transcript,
        "push": push_response,
        "via": via,
        "installed_version": c.plugin_version(c.installed_plugin_text()),
        "loaded_version": support.state()["loaded_version"],
        "file_is_candidate": c.installed_plugin_text().encode("utf-8") == contents,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--only", help="esegue solo lo scenario indicato")
    parser.add_argument("--list", action="store_true", help="elenca gli scenari")
    parser.add_argument("--verbose", action="store_true", help="stampa le trascrizioni normalizzate dell'upgrade")
    args = parser.parse_args()
    if args.list:
        print("\n".join(s[0] for s in SCENARIOS))
        return 0

    report = c.Report("upgrade_test")
    initial = c.repo_plugin_fingerprint()
    c.check_config_matches_test_keys()
    support = c.Support()
    support.wait_ready()
    c.install_support_plugin()

    baseline = c.baseline_bytes()
    baseline_version = c.plugin_version(baseline.decode("utf-8"))
    candidate = prepare_candidate(baseline)
    contents, sig, force, version = candidate
    print(f"Baseline: {baseline_version} (git {c.BASELINE_REF}), candidato: {version}"
          + (f" da {os.environ['WPHC_CANDIDATE_FILE']}" if os.environ.get("WPHC_CANDIDATE_FILE") else " (copia della baseline con versione alzata)")
          + (" [force: stessa versione]" if force else ""))
    print(f"Candidato: sha256 {sig['sha256']}  tag {sig['tag']}  firmato con la k2 di test")

    hub = c.Hub()
    names = [s[0] for s in SCENARIOS]
    selected = [s for s in SCENARIOS if not args.only or s[0] == args.only]
    if not selected:
        raise SystemExit(f"Scenario sconosciuto: {args.only}. Disponibili: {', '.join(names)}")

    for name, build, phase1, phase2, expect in selected:
        report.section(f"Scenario {name}")
        control = run_once(name, build, phase1, phase2, hub, support, baseline, candidate, upgrade=False)
        upgraded = run_once(name, build, phase1, phase2, hub, support, baseline, candidate, upgrade=True)
        c.assert_repo_plugin_untouched(initial)

        report.check("controllo: il file resta la baseline", control["installed_version"] == baseline_version and control["loaded_version"] == baseline_version)
        how = {"push": "push firmato", "push_previous": "push firmato con le credenziali precedenti", "file": "sostituzione esterna del file"}[upgraded["via"]]
        report.check(
            f"upgrade ({how}): {baseline_version} -> {version}, file della copia = candidato",
            bool(upgraded["push"]) and (upgraded["via"] == "file" or upgraded["push"].get("signature") == "verified") and upgraded["file_is_candidate"] and upgraded["installed_version"] == version,
            str(upgraded["push"]),
        )
        report.check("upgrade: il PHP del sito carica la versione candidata", upgraded["loaded_version"] == version, str(upgraded["loaded_version"]))

        for phase in ("phase1", "phase2"):
            differences = c.diff_transcripts(control[phase], upgraded[phase])
            label = "prima del push" if phase == "phase1" else "dopo il push"
            report.check(f"stessi esiti del controllo {label} ({len(control[phase])} osservazioni)", not differences, "\n        ".join(differences[:12]))

        if args.verbose:
            print(json.dumps({"fase1": upgraded["phase1"], "fase2": upgraded["phase2"]}, indent=1, ensure_ascii=False, default=str)[:6000])
        absolute = expect(upgraded["raw_transcript"])
        for description, condition in absolute.items():
            report.check(f"atteso in assoluto: {description}", bool(condition))

    # Pulizia: lo stato del sito e la baseline di nuovo nella copia.
    support.reset()
    support.webhook(status=200, clear=True)
    c.install_plugin(baseline)
    c.assert_repo_plugin_untouched(initial)
    return report.finish()


if __name__ == "__main__":
    sys.exit(main())
