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

Scenari a due fasi (controllo / upgrade): fresh (sito mai enrollato), v1, v2 dentro la finestra
di grazia, v2 con grazia scaduta, revocato, job bulk in corso (ripreso dal cron), webhook in
attesa di retry. In questi il push positivo che porta il sito al candidato lo esegue il codice
della 1.38.1 (e' lei che scrive il file): dimostrano che il CANDIDATO si comporta come la 1.38.1
sullo stato lasciato dalla 1.38.1.

Due blocchi a se' (senza controllo) provano invece il self-update ESEGUITO DAL CANDIDATO:
    candidate-self-update   il candidato installato riceve un push firmato (versione successiva):
                            backup, rename, sanity check, opcache_invalidate sono SUOI; poi
                            stessa versione con e senza force, downgrade vietato.
    backup-failed           con una DIRECTORY al posto del .bak il push si ferma con
                            500 wphc_update_backup_failed e il file resta invariato.

Il file candidato e' parametrizzabile:
    WPHC_CANDIDATE_FILE=percorso   file da installare (dopo il refactoring: la build vera);
                                   senza, per gli scenari a due fasi e' una copia della baseline con
                                   Version 1.99.0 e per i due blocchi e' la baseline stessa.
    WPHC_BASELINE_REF=commit       baseline letta da git (default 1d6725b = v1.38.1).
Con un candidato della STESSA versione della baseline il push usa force (reinstallazione).

Uso (con l'ambiente e2e acceso, vedi tests/e2e/README.md):
    python -I tests/e2e/upgrade_test.py [--only nome] [--list]

Il file reale mu-plugins/wp-health-check.php non viene mai scritto: il plugin sotto test e' la
copia montata nel container; il push positivo avviene solo li'.
"""
import argparse
import copy
import importlib.util
import json
import os
import re
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import e2e_common as c  # noqa: E402

# Verifica dei webhook in ingresso: modulo dell'hub non vendorizzato, importato dal percorso.
_spec = importlib.util.spec_from_file_location("hub_webhook_ref", c.MONOREPO_DIR / "hub" / "services" / "fleet" / "webhook.py")
hub_webhook = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(hub_webhook)  # type: ignore[union-attr]

UPDATE_ROUTE = "/health-check/v1/update"
PREV_ROUTE = "/health-check/v1/ping"
BULK_ROUTE = "/health-check/v1/update/bulk"
BULK_PLUGINS = ["hello.php", "akismet/akismet.php", "e2e-missing-a/a.php", "e2e-missing-b/b.php", "e2e-missing-c/c.php", "e2e-missing-d/d.php", "e2e-missing-e/e.php"]


# ---------------------------------------------------------------------------
# Contesto di un'esecuzione: hub, supporto, trascrizione
# ---------------------------------------------------------------------------

# Il nome del file dell'anteprima ha un suffisso numerico che dipende dalle esecuzioni precedenti
# (wp-health-check-site-thumb.png, ...-thumb-1.png, ...): si maschera il numero.
_THUMB_NAME_RE = re.compile(r"site-thumb(-\d+)?\.")


def stable_thumb(value):
    """URL dell'anteprima senza il numero di suffisso del nome file."""
    return _THUMB_NAME_RE.sub("site-thumb<N>.", value) if isinstance(value, str) else value


def stable_site_payload(result: dict) -> dict:
    """Risposta di fetch_site_health/fetch_site_ping dell'hub, completa, senza i valori volatili per natura.

    Resta tutto il summary (versioni, conteggi, flag di auto-update, IP, ...), last_access, l'errore: cosi'
    un campo che cambia fra 1.38.1 e candidato fa fallire il confronto. Si tolgono SOLO: la durata della
    chiamata (response_time_ms) e il numero nel nome del file dell'anteprima (thumbnail). I timestamp
    (updates_checked_at, last_access, enrolled_at) li maschera normalize() ma ne conserva la presenza.
    """
    payload = copy.deepcopy(result)
    payload.pop("response_time_ms", None)
    summary = payload.get("summary")
    if isinstance(summary, dict) and "thumbnail" in summary:
        summary["thumbnail"] = stable_thumb(summary["thumbnail"])
    return payload


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
        """Chiamata di alto livello dell'hub: registra l'esito COMPLETO, anche se solleva."""
        try:
            result = fn()
        except Exception as exc:  # noqa: BLE001 - il tipo e il codice fanno parte dell'osservazione
            self.note(label, ["eccezione", type(exc).__name__, getattr(exc, "code", None)])
            return None
        if isinstance(result, dict) and "status" in result and "response_time_ms" in result:
            # health/ping: stato, risposta intera dell'hub (summary con TUTTI i valori, last_access, errore)
            # senza i campi volatili (vedi stable_site_payload), e agent_version.
            agent_version = result.get("summary", {}).get("agent_version") if isinstance(result.get("summary"), dict) else result.get("agent_version")
            self.note(label, [result["status"], stable_site_payload(result), agent_version])
        else:
            self.note(label, ["dict", sorted(result.keys())] if isinstance(result, dict) else str(type(result)))
        return result

    def state_summary(self, label: str) -> None:
        """Option del plugin, cron, log, contatore throttle: tutto cio' che deve sopravvivere all'upgrade."""
        state = self.support.state()
        transients = {k: v for k, v in state["transients"].items() if not k.startswith(("wphc_fail_", "wphc_health_cache", "wphc_latest_version_cache", "wphc_nonce_"))}
        # La prima /health genera davvero l'anteprima del sito: l'ambiente e2e risponde con un PNG fisso
        # (rete isolata), ma l'allegato creato ha un id che cresce a ogni esecuzione e da' il suffisso al nome
        # del file. Si maschera solo l'id (thumb_id) e il numero nell'URL (thumb); IP pubblico, timestamp
        # e codice d'errore sono ora deterministici (l'ipify del supporto risponde con un IP fisso) e si confrontano.
        options = {k: stable_thumb(v) if k == "wp_health_check_thumb" else ("<VOLATILE>" if k == "wp_health_check_thumb_id" else v) for k, v in state["options"].items()}
        self.note(label, {
            "options": options,
            # Colonna autoload GREZZA di ogni option del plugin ('on', 'off', 'auto', ...): un upgrade che la
            # cambiasse altererebbe il carico di ogni richiesta del sito.
            "options_autoload": state["options_autoload"],
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


def _tick_in_cron_context(x: Ctx, label: str):
    """Fa girare un tick del bulk e registra se l'esecuzione e' in CONTESTO CRON.

    La rotta di supporto rende vero wp_doing_cron() durante l'evento. Prova che lo e': si scrive un valore
    sentinella nell'ultimo accesso, e wphc_record_access() (che in contesto cron NON scrive) deve lasciarlo
    intatto; in una richiesta REST normale il tick lo sovrascriverebbe con l'IP del chiamante.
    """
    sentinel = {"wp_health_check_last_request_at": "SENTINELLA-AT", "wp_health_check_last_request_ip": "SENTINELLA-IP"}
    x.support.set_options(sentinel)
    ran = x.support.cron_run("wphc_bulk_update_tick")
    options = x.support.state()["options"]
    x.note(label, ran)
    x.note("contesto cron (wp_doing_cron vero): il tick non tocca l'ultimo accesso", all(options.get(k) == v for k, v in sentinel.items()))


def sc_bulk_phase1(x: Ctx):
    _tick_in_cron_context(x, "tick di cron (pre-upgrade)")
    _bulk_status(x, "job dopo il primo tick")
    x.state_summary("stato dopo fase 1")


def sc_bulk_phase2(x: Ctx):
    """Il job interrotto dall'upgrade riprende dal cron e arriva in fondo; il webhook parte firmato."""
    _tick_in_cron_context(x, "tick di cron (post-upgrade)")
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
        "i tick girano in contesto cron (wp_doing_cron vero), prima e dopo l'upgrade": [e[1] for e in t if e[0].startswith("contesto cron")] == [True, True],
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
    """Restituisce (contenuto, firma k2 di test, force, versione). Rifiuta candidati incoerenti.

    Il candidato e' WPHC_CANDIDATE_FILE (validato da e2e_common: header Version, costante
    WP_HEALTH_CHECK_VERSION coerente) oppure, senza, la baseline con Version 1.99.0.
    """
    baseline_text = baseline.decode("utf-8")
    baseline_version = c.plugin_version(baseline_text)
    info = c.plugin_under_test_info()
    if info.from_candidate_file:
        contents = info.contents
        version = info.version
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

    # Dimensione del body del push: come lo spedisce l'hub (json.dumps del solo {"package": ...}).
    # Una build piu' grande del limite non puo' essere distribuita in push: il test FALLISCE qui, con la misura.
    body_size, gz_size = c.push_sizes(c.build_push_package(contents, sig, force))
    base_body, base_gz = c.push_sizes(c.build_push_package(baseline, c.sign_release(baseline, "v" + baseline_version)))
    print(
        f"Body del push: candidato {body_size} byte (baseline {base_body}, differenza {body_size - base_body:+d}); "
        f"limite {c.PUSH_BODY_LIMIT} (margine {c.PUSH_BODY_LIMIT - body_size})"
    )
    print(
        f"gzip+base64 del contenuto: candidato {gz_size} (baseline {base_gz}, differenza {gz_size - base_gz:+d}); "
        f"budget di progetto {c.PUSH_GZIP_B64_BUDGET} (solo informativo)"
        + (("  (oltre il budget, come la baseline)" if base_gz > c.PUSH_GZIP_B64_BUDGET else "  ATTENZIONE: il candidato supera un budget che la baseline rispettava") if gz_size > c.PUSH_GZIP_B64_BUDGET else "")
    )
    if body_size > c.PUSH_BODY_LIMIT:
        raise SystemExit(
            f"ERRORE: il body del push del candidato e' {body_size} byte, oltre il limite di {c.PUSH_BODY_LIMIT} "
            f"(+{body_size - c.PUSH_BODY_LIMIT}): la build non e' distribuibile con il push. Ridurre il file."
        )
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
        x.support.opcache_invalidate()  # sostituzione esterna: opcache va invalidata come fa un deploy vero
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
    # Il PHP del sito deve eseguire DAVVERO il candidato (versione in memoria, sha256 del file incluso,
    # opcache fresca): se non succede entro il timeout il test FALLISCE con il motivo, non prosegue in silenzio.
    c.wait_until_loaded(x.support, contents, timeout=30, what=f"il candidato {version} dopo il push ({via})")
    return body


# ---------------------------------------------------------------------------
# Esecuzione di uno scenario (controllo o upgrade) e confronto
# ---------------------------------------------------------------------------

def run_once(name, build, phase1, phase2, hub, support, baseline, candidate, upgrade: bool):
    contents, sig, force, version = candidate
    support.reset()
    support.webhook(status=200, clear=True)
    # La baseline (1.38.1 vera) nella copia montata, opcache invalidata, e attesa che il PHP la esegua.
    c.clear_install_residue()
    c.install_and_load(support, baseline)
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
    elif via in ("push", "push_previous") and not getattr(build, "enroll_for_push", False):
        # Controllo equivalente: la STESSA richiesta autenticata di POST /update (stesse credenziali, quindi le
        # stesse tracce: ultimo accesso, riga grace_window_hit, throttle), ma con la versione corrente e senza
        # force, cosi' risponde up_to_date e non scrive il file. Senza questo passo il controllo non avrebbe
        # alcun "ultimo accesso" fino alla fase 2, mentre l'upgrade lo ha dal push (e /health lo riporta in last_access).
        noop = c.build_push_package(baseline, c.sign_release(baseline, "v" + c.plugin_version(baseline.decode("utf-8"))))
        support.clear_throttle()
        if via == "push_previous":
            previous = x.hub.previous_secret()
            c.raw_request("POST", UPDATE_ROUTE, previous, hmac_secret=previous, body={"package": noop})
        else:
            x.hub.request("POST", UPDATE_ROUTE, json_body={"package": noop})
    start = len(x.transcript)
    phase2(x)
    phase2_transcript = x.transcript[start:]
    secrets = x.secret_map()
    # Versione che il candidato RIPORTA dopo l'upgrade (ping reale dell'hub; un sito revocato non e' contattabile:
    # in quel caso si usa la versione del codice caricato) e verdetto della regola di compatibilita' push dell'hub.
    reported = None
    if upgrade and via != "file":
        support.clear_throttle()
        try:
            reported = hub.ping().get("agent_version")
        except Exception:  # noqa: BLE001 - se l'hub non riesce a contattare il sito il controllo successivo fallisce
            reported = None
    if reported is None:
        reported = support.state()["loaded_version"]
    supports_push, _min_agent = c.hub_push_support()
    return {
        "reported_agent_version": reported,
        "agent_supports_push": supports_push(reported),
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


# ---------------------------------------------------------------------------
# Blocchi a se': il self-update lo esegue il CODICE DEL CANDIDATO
# ---------------------------------------------------------------------------

def post_update(hub: c.Hub, support: c.Support, package: dict):
    """POST /update con l'hub vero (firma HMAC v2 e retry sul precedente al 401). Ritorna (status, corpo)."""
    support.clear_throttle()
    resp = hub.request("POST", UPDATE_ROUTE, json_body={"package": package})
    try:
        return resp.status_code, resp.json()
    except ValueError:
        return resp.status_code, resp.text


def block_candidate_self_update(report: c.Report, hub: c.Hub, support: c.Support, initial: dict) -> None:
    """Il candidato INSTALLATO esegue il proprio self-update (push firmato k2).

    Negli scenari a due fasi il push positivo lo esegue la 1.38.1 (e' lei che scrive il file): backup,
    rename atomico, sanity check e opcache_invalidate sono suoi e del candidato gira solo il rifiuto
    `up_to_date`. Qui il sito parte dal candidato e riceve un pacchetto con la versione SUCCESSIVA:
    tutta la pipeline di installazione (integrita', firma, backup, scrittura, sanity, opcache) e'
    quella del candidato.

    Il candidato e' il plugin sotto test (WPHC_CANDIDATE_FILE, oppure la baseline stessa). Sequenza:
      1. baseline in push con force: se il candidato e' PIU' NUOVO della baseline il downgrade e' vietato
         (up_to_date, file invariato); se ha la STESSA versione e' una reinstallazione (updated=true);
         se e' piu' vecchio il passo non ha un'attesa sensata e si salta;
      2. push di make_candidate(candidato, X.Y.Z+1): updated, from, to, signature=verified, byte del file,
         nessun .bak/.tmp, codice caricato dal PHP, versione riportata dal sito;
      3. stessa versione senza force: up_to_date; con force: updated=true (reinstallazione);
      4. downgrade con force (baseline e candidato originale, ora piu' vecchi dell'installato): up_to_date.
    Se candidato e baseline sono lo stesso file (default, senza WPHC_CANDIDATE_FILE) i passi 2-4 valgono
    uguale, perche' il bump a X.Y.Z+1 rende "piu' vecchi" sia la baseline sia il candidato originale; il passo 1
    diventa la reinstallazione forzata alla stessa versione.
    """
    report.section("Blocco candidate-self-update (il self-update lo esegue il CANDIDATO)")
    under = c.plugin_under_test_info()
    candidate, cand_version = under.contents, under.version
    baseline = c.baseline_bytes()
    base_version = c.plugin_version(baseline.decode("utf-8"))
    ordering = (version_tuple(cand_version) > version_tuple(base_version)) - (version_tuple(cand_version) < version_tuple(base_version))
    if candidate == baseline:
        kind = "stesso file della baseline"
    elif ordering == 0:
        kind = "stessa versione della baseline, byte diversi"
    else:
        kind = "versione piu' nuova della baseline" if ordering > 0 else "versione piu' vecchia della baseline"
    print(f"  candidato {cand_version} ({kind}); baseline {base_version}")

    def file_bytes() -> bytes:
        return c.installed_plugin_text().encode("utf-8")

    def clean() -> bool:
        return c.install_residue() == []

    try:
        # Preparazione: candidato installato e caricato, sito enrollato e ruotato (protocollo 2, richieste firmate).
        c.clear_install_residue()
        c.install_and_load(support, candidate)
        support.reset()
        support.webhook(status=200, clear=True)
        hub.reset()
        hub.enroll()
        hub.rotate()

        # ---- 1. baseline in push con force sul candidato installato
        base_package = c.build_push_package(baseline, c.sign_release(baseline, "v" + base_version), force=True)
        status, body = post_update(hub, support, base_package)
        if ordering > 0:
            report.check("baseline in push con force, candidato piu' nuovo: up_to_date (downgrade vietato)", status == 200 and body.get("updated") is False and body.get("reason") == "up_to_date", str((status, body)))
            report.check("  il file installato e' invariato (candidato)", file_bytes() == candidate and clean())
        elif ordering == 0:
            ok = status == 200 and body.get("updated") is True and body.get("from") == base_version and body.get("to") == base_version and body.get("signature") == "verified"
            report.check("baseline in push con force, stessa versione del candidato: updated=true (reinstallazione)", ok, str((status, body)))
            if not ok:
                return
            c.wait_until_loaded(support, baseline, what="la baseline reinstallata dal candidato")
            report.check("  il file scritto e' quello del pacchetto e non restano .bak/.tmp", file_bytes() == baseline and clean(), str(c.install_residue()))
            if baseline != candidate:
                # Stessa versione ma byte diversi: si riporta il candidato per i passi successivi.
                c.install_and_load(support, candidate)
        else:
            print("  (passo 1 saltato: il candidato e' piu' vecchio della baseline, la baseline sarebbe un aggiornamento)")

        # ---- 2. push positivo X.Y.Z+1 eseguito dal candidato
        next_v = c.next_version(cand_version)
        pushed = c.make_candidate(candidate.decode("utf-8"), next_v).encode("utf-8")
        sig = c.sign_release(pushed, "v" + next_v)
        package = c.build_push_package(pushed, sig)
        body_size, _ = c.push_sizes(package)
        report.check(f"il body del push del candidato {next_v} e' entro {c.PUSH_BODY_LIMIT} byte", body_size <= c.PUSH_BODY_LIMIT, f"{body_size} byte")
        status, body = post_update(hub, support, package)
        ok = (
            status == 200 and body.get("updated") is True and body.get("from") == cand_version and body.get("to") == next_v
            and body.get("signature") == "verified" and body.get("mode") == "push"
        )
        report.check(f"push firmato k2: updated=true da {cand_version} a {next_v}, signature=verified, mode=push", ok, str((status, body)))
        if not ok:
            return
        state = c.wait_until_loaded(support, pushed, what=f"il candidato {next_v} scritto dal suo stesso self-update")
        report.check("  il file della copia e' il pacchetto, byte per byte", file_bytes() == pushed)
        report.check("  nessun .bak ne' .tmp residuo (backup rimosso, temporaneo rinominato)", clean(), str(c.install_residue()))
        report.check("  loaded_version = versione successiva (opcache invalidata dal candidato)", state["loaded_version"] == next_v, str(state["loaded_version"]))
        support.clear_throttle()
        ping = hub.ping()
        report.check(f"  /ping riporta agent_version {next_v}", ping.get("status") == "ok" and ping.get("agent_version") == next_v, str(ping))
        supports_push, min_agent = c.hub_push_support()
        report.check(f"  la versione riportata e' idonea al push secondo l'hub (>= {'.'.join(map(str, min_agent))})", supports_push(ping.get("agent_version")))
        support.clear_throttle()
        fresh = hub.health(fresh=True)
        report.check("  /health?fresh=1 con lo stesso segreto: ok e nuova versione", fresh["status"] == "ok" and fresh["summary"].get("agent_version") == next_v, str(fresh.get("summary", {}).get("agent_version")))

        # ---- 3. stessa versione: senza force rifiutata, con force reinstallata
        status, body = post_update(hub, support, package)
        report.check("stessa versione senza force: up_to_date", status == 200 and body.get("updated") is False and body.get("reason") == "up_to_date" and body.get("mode") == "push", str((status, body)))
        report.check("  il file e' invariato", file_bytes() == pushed and clean())
        status, body = post_update(hub, support, c.build_push_package(pushed, sig, force=True))
        ok = status == 200 and body.get("updated") is True and body.get("from") == next_v and body.get("to") == next_v and body.get("signature") == "verified"
        report.check("stessa versione con force: updated=true (reinstallazione di riparazione)", ok, str((status, body)))
        if ok:
            c.wait_until_loaded(support, pushed, what="la reinstallazione forzata")
            report.check("  il file e' il pacchetto e non restano .bak/.tmp", file_bytes() == pushed and clean(), str(c.install_residue()))

        # ---- 4. downgrade con force: vietato, anche con force
        downgrades = [("baseline", baseline, base_version)]
        if candidate != baseline:
            downgrades.append(("candidato originale", candidate, cand_version))
        for label, contents, version in downgrades:
            if version_tuple(version) >= version_tuple(next_v):
                continue
            status, body = post_update(hub, support, c.build_push_package(contents, c.sign_release(contents, "v" + version), force=True))
            report.check(f"downgrade a {version} ({label}) con force: up_to_date", status == 200 and body.get("updated") is False and body.get("reason") == "up_to_date", str((status, body)))
            report.check("  il file installato resta quello della versione successiva", file_bytes() == pushed and clean())
        report.check("  il PHP continua a eseguire la versione successiva", support.state()["loaded_version"] == next_v)
    finally:
        # Pulizia: sito azzerato, residui tolti, plugin sotto test di nuovo nella copia (anche se un passo e' fallito).
        support.reset()
        support.webhook(status=200, clear=True)
        c.clear_install_residue()
        c.install_and_load(support, candidate)
        c.assert_repo_plugin_untouched(initial)


def block_backup_failed(report: c.Report, hub: c.Hub, support: c.Support, initial: dict) -> None:
    """Ramo wphc_update_backup_failed: una DIRECTORY al posto di wp-health-check.php.bak.

    Il plugin crea il backup con copy() PRIMA di scrivere: se non riesce risponde 500
    `wphc_update_backup_failed` e non tocca il file. Una directory con quel nome rende copy() impossibile
    senza bisogno di permessi particolari. La directory si toglie SEMPRE (try/finally).
    I rami wphc_update_write_failed e wphc_update_sanity_failed NON sono coperti: richiederebbero un
    rename()/lettura che falliscono su un filesystem sano (vedi README, "Cosa il test non dimostra").
    """
    report.section("Blocco backup-failed (directory al posto del .bak)")
    under = c.plugin_under_test_info()
    candidate, cand_version = under.contents, under.version
    bak = c.WORK_MU_DIR / "wp-health-check.php.bak"
    try:
        c.clear_install_residue()
        c.install_and_load(support, candidate)
        support.reset()
        support.webhook(status=200, clear=True)
        hub.reset()
        hub.enroll()
        hub.rotate()

        next_v = c.next_version(cand_version)
        pushed = c.make_candidate(candidate.decode("utf-8"), next_v).encode("utf-8")
        package = c.build_push_package(pushed, c.sign_release(pushed, "v" + next_v))
        bak.mkdir()  # il .bak e' una directory: copy() del plugin non puo' scriverci
        status, body = post_update(hub, support, package)
        code = body.get("code") if isinstance(body, dict) else None
        report.check("push valido con .bak impossibile: HTTP 500", status == 500, str((status, body)))
        report.check("  codice d'errore wphc_update_backup_failed", code == "wphc_update_backup_failed", str(body))
        report.check("  il file installato e' invariato byte per byte", c.installed_plugin_text().encode("utf-8") == candidate)
        report.check("  la directory .bak non e' stata toccata e non c'e' alcun .tmp", bak.is_dir() and [n for n in c.install_residue() if n != bak.name] == [], str(c.install_residue()))
        state = support.state()
        report.check("  il PHP continua a eseguire il candidato non aggiornato", state["loaded_version"] == cand_version, str(state["loaded_version"]))
    finally:
        # La directory si toglie sempre, anche se un controllo e' fallito o il test e' stato interrotto.
        if bak.is_dir():
            bak.rmdir()
        elif bak.exists():
            bak.unlink()
        support.reset()
        support.webhook(status=200, clear=True)
        c.clear_install_residue()
        c.install_and_load(support, candidate)
        c.assert_repo_plugin_untouched(initial)


# Blocchi a se' (non hanno la struttura controllo/upgrade): nome -> funzione.
BLOCKS = {
    "candidate-self-update": block_candidate_self_update,
    "backup-failed": block_backup_failed,
}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--only", help="esegue solo lo scenario o il blocco indicato")
    parser.add_argument("--list", action="store_true", help="elenca scenari e blocchi")
    parser.add_argument("--verbose", action="store_true", help="stampa le trascrizioni normalizzate dell'upgrade")
    args = parser.parse_args()
    names = [s[0] for s in SCENARIOS] + list(BLOCKS)
    if args.list:
        print("\n".join(names))
        return 0
    if args.only and args.only not in names:
        raise SystemExit(f"Scenario sconosciuto: {args.only}. Disponibili: {', '.join(names)}")

    report = c.Report("upgrade_test")
    initial = c.repo_plugin_fingerprint()
    c.check_config_matches_test_keys()
    # Il mu-plugin di supporto prima dell'attesa: la sua rotta /state e' il segnale di ambiente pronto.
    c.install_support_plugin()
    support = c.Support()
    support.wait_ready()

    under_test = c.announce_plugin_under_test("upgrade_test")
    baseline = c.baseline_bytes()
    baseline_version = c.plugin_version(baseline.decode("utf-8"))
    candidate = prepare_candidate(baseline)
    contents, sig, force, version = candidate
    print(f"Baseline: {baseline_version} (git {c.BASELINE_REF}), candidato degli scenari a due fasi: {version}"
          + (f" da {os.environ['WPHC_CANDIDATE_FILE']}" if os.environ.get("WPHC_CANDIDATE_FILE") else " (copia della baseline con versione alzata)")
          + (" [force: stessa versione]" if force else ""))
    print(f"Candidato: sha256 {sig['sha256']}  tag {sig['tag']}  firmato con la k2 di test")

    hub = c.Hub()
    selected = [s for s in SCENARIOS if not args.only or s[0] == args.only]

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
        _, min_agent = c.hub_push_support()
        report.check(
            f"upgrade: la versione riportata dal candidato ({upgraded['reported_agent_version']}) e' idonea al push secondo l'hub (>= {'.'.join(map(str, min_agent))})",
            upgraded["agent_supports_push"],
        )

        for phase in ("phase1", "phase2"):
            differences = c.diff_transcripts(control[phase], upgraded[phase])
            label = "prima del push" if phase == "phase1" else "dopo il push"
            report.check(f"stessi esiti del controllo {label} ({len(control[phase])} osservazioni)", not differences, "\n        ".join(differences[:12]))

        if args.verbose:
            print(json.dumps({"fase1": upgraded["phase1"], "fase2": upgraded["phase2"]}, indent=1, ensure_ascii=False, default=str)[:6000])
        absolute = expect(upgraded["raw_transcript"])
        for description, condition in absolute.items():
            report.check(f"atteso in assoluto: {description}", bool(condition))

    # Blocchi a se': il self-update lo esegue il candidato.
    for name, block in BLOCKS.items():
        if not args.only or args.only == name:
            block(report, hub, support, initial)

    # Pulizia: stato del sito azzerato e il plugin sotto test di nuovo nella copia, caricato dal PHP.
    support.reset()
    support.webhook(status=200, clear=True)
    c.clear_install_residue()
    c.install_and_load(support, under_test.contents)
    c.assert_repo_plugin_untouched(initial)
    return report.finish()


if __name__ == "__main__":
    sys.exit(main())
