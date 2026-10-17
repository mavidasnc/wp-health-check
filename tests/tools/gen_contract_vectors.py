"""Generatore dei vettori di contratto hub <-> plugin wp-health-check.

Produce `tests/fixtures/contract-vectors.json`: valori DETERMINISTICI calcolati
dalle funzioni REALI dell'hub (sola lettura, non copiate), cosi' che i test PHP
(`tests/test-contract-vectors.php`) verifichino che il plugin legga/produca
esattamente gli stessi byte del lato Python.

Uso (dalla radice del progetto wp-health-check):

    python -I tests/tools/gen_contract_vectors.py

Requisiti: Python 3.10+ e PyNaCl (`pip install pynacl`). Nessuna variabile
d'ambiente, nessun database, nessuna rete.

Perche' si estraggono le funzioni dai sorgenti dell'hub invece di importarle:
  - `python -I` (modalita' isolata) ignora PYTHONPATH, la cartella corrente e
    il site-packages utente; importare `services.enroll` richiederebbe anche
    `requests` e tutta la catena di `services/__init__.py` (nh3, supabase, ...);
  - le funzioni che servono sono pure (hashlib/hmac/base64/nacl): si prende il
    loro codice con `ast` dai file dell'hub e lo si esegue in un namespace
    isolato. Il codice eseguito e' quindi esattamente quello dell'hub.

Fonte di verita': `hub/` (la copia in `hub-cli/_vendor` e' meno recente e il
suo `enroll.py` diverge; qui si segnala solo a video se le funzioni usate non
coincidono piu').

Le chiavi sono SOLO di test (seed deterministici): k1 = bytes(range(32)) come in
hub/tests/test_enroll_v2.py, k2 = bytes(range(32, 64)). Nessuna chiave di
produzione e' letta o scritta.

Fedelta' ai byte reali dell'hub:
  - i body POST dei vettori HMAC sono prodotti come li produce `_site_request`
    (hub/services/fleet/proxy.py): `json.dumps(dict, allow_nan=False)` con i
    separatori di default ", " e ": " e gli escape \\uXXXX; il generatore verifica
    che quella riga esista ancora nel sorgente dell'hub;
  - c'e' anche un body con byte UTF-8 grezzi non ASCII (non prodotto dall'hub);
  - le firme delle release sono prodotte da `hub/scripts/sign_release.py::sign_release`
    su un file temporaneo FUORI dal repo (con e senza prefisso "v" nel tag), e per ogni
    release si registra anche il JSON `.sig` esattamente come lo scrive quello script;
  - se la copia delle funzioni in hub-cli/_vendor diverge dall'hub il generatore esce con
    codice 3 senza scrivere nulla.
"""
import ast
import base64
import hashlib
import hmac
import json
import site
import sys
import tempfile
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

# --- 1. Percorsi ----------------------------------------------------------------

# Radice del progetto wp-health-check e del monorepo (sibling "hub").
PROJECT_DIR = Path(__file__).resolve().parents[2]
MONOREPO_DIR = PROJECT_DIR.parent
HUB_DIR = MONOREPO_DIR / "hub"
VENDOR_DIR = MONOREPO_DIR / "hub-cli" / "_vendor"
OUTPUT = PROJECT_DIR / "tests" / "fixtures" / "contract-vectors.json"

# --- 2. PyNaCl ------------------------------------------------------------------

# `python -I` esclude il site-packages utente, dove di solito sta PyNaCl: lo si
# riaggiunge esplicitamente (solo in coda, mai davanti alla libreria standard).
try:
    from nacl.signing import SigningKey  # noqa: E402
except ImportError:
    sys.path.append(site.getusersitepackages())
    from nacl.signing import SigningKey  # noqa: E402

# --- 3. Valori fissi di test ------------------------------------------------------

SEED_K1 = bytes(range(32))          # come hub/tests/test_enroll_v2.py
SEED_K2 = bytes(range(32, 64))      # chiave "release" di test
ISSUED_AT = 1700000000              # timestamp fisso delle buste
NONCE = "00112233445566778899aabbccddeeff"
NONCE_2 = "ffeeddccbbaa99887766554433221100"


def b64(raw: bytes) -> str:
    """Base64 standard (con padding), come la firma Ed25519 trasmessa."""
    return base64.b64encode(raw).decode("ascii")


# --- 4. Estrazione delle funzioni dall'hub ------------------------------------------


class _ConfigError(Exception):
    """Segnaposto di services.errors.ConfigError (non serve l'originale)."""


def estrai_funzioni(percorso: Path, nomi: set, extra_globals: dict) -> dict:
    """Esegue SOLO le funzioni `nomi` del modulo `percorso` e le restituisce.

    Le funzioni sono compilate dal sorgente originale dell'hub: nessuna copia
    manuale, quindi nessuna possibilita' di divergenza silenziosa.
    """
    albero = ast.parse(percorso.read_text(encoding="utf-8"))
    ns = {"__name__": "hub_estratto", **extra_globals}
    trovati = set()
    for nodo in albero.body:
        if isinstance(nodo, ast.FunctionDef) and nodo.name in nomi:
            exec(compile(ast.Module([nodo], []), str(percorso), "exec"), ns)  # noqa: S102
            trovati.add(nodo.name)
    mancanti = nomi - trovati
    if mancanti:
        raise SystemExit(f"Funzioni non trovate in {percorso}: {sorted(mancanti)}")
    return {n: ns[n] for n in nomi}


def sorgente_funzione(percorso: Path, nome: str) -> str:
    """Testo sorgente di una funzione (per il confronto hub / vendor)."""
    testo = percorso.read_text(encoding="utf-8")
    for nodo in ast.parse(testo).body:
        if isinstance(nodo, ast.FunctionDef) and nodo.name == nome:
            return ast.get_source_segment(testo, nodo) or ""
    return ""


def sha256_file(percorso: Path) -> str:
    """SHA-256 dei byte del file, con fine riga normalizzati (stesso valore su Windows e Linux)."""
    return hashlib.sha256(percorso.read_bytes().replace(b"\r\n", b"\n")).hexdigest()


# Orologio e RNG finti: `_sign_site_request` chiama time.time() e secrets.token_hex()
# internamente; iniettandoli si ottiene un risultato deterministico dal codice reale.
class _Orologio:
    valore = ISSUED_AT

    @classmethod
    def time(cls):
        return cls.valore


class _Segreti:
    nonce = NONCE

    @classmethod
    def token_hex(cls, _n):
        return cls.nonce


ENROLL = estrai_funzioni(
    HUB_DIR / "services" / "enroll.py",
    {"normalize_url", "derive_enroll_token", "_load_ed25519_signing_key",
     "sign_enroll_payload", "sign_fleet_payload"},
    {
        "base64": base64, "hmac": hmac, "hashlib": hashlib,
        "urlsplit": urlsplit, "urlunsplit": urlunsplit,
        "ConfigError": _ConfigError,
    },
)
PROXY = estrai_funzioni(
    HUB_DIR / "services" / "fleet" / "proxy.py",
    {"_sign_site_request"},
    {"base64": base64, "hmac": hmac, "hashlib": hashlib, "time": _Orologio, "secrets": _Segreti},
)
WEBHOOK = estrai_funzioni(
    HUB_DIR / "services" / "fleet" / "webhook.py",
    {"build_webhook_canonical", "verify_webhook_signature"},
    {"base64": base64, "hmac": hmac, "hashlib": hashlib},
)

# Il firmatario reale delle release (hub/scripts/sign_release.py): funzioni pure, nacl e' importato
# dentro di esse. Estratte con ast come le altre (un import creerebbe __pycache__ dentro hub/).
SIGN_RELEASE = estrai_funzioni(
    HUB_DIR / "scripts" / "sign_release.py",
    {"_chiave_di_firma", "sign_release"},
    {"base64": base64, "hashlib": hashlib, "Path": Path},
)

K1_B64 = b64(SEED_K1)
K2_B64 = b64(SEED_K2)

# La riga con cui l'hub serializza i body POST firmati: i vettori HMAC "come l'hub" dipendono da essa.
RIGA_BODY_HUB = "json.dumps(json_body, allow_nan=False).encode('utf-8')"


def pubkey_b64(seed: bytes) -> str:
    """Chiave pubblica Ed25519 (base64 standard) di un seed."""
    return b64(bytes(SigningKey(seed).verify_key))


# --- 5. Casi di normalize_url -------------------------------------------------------

# Ogni caso: (input, override del plugin o None, nota).
# L'hub e' la fonte del valore `hub`; quando il plugin DIVERGE (misurato sulla 1.38.1)
# l'esito atteso del plugin e' indicato qui a mano in `plugin`, con la nota del perche'.
# Un caso senza override impone che plugin e hub producano esattamente la stessa stringa.
CASI_URL = [
    ("https://example.com", None, "base"),
    ("https://example.com/", None, "slash finale"),
    ("https://www.Example.com/Blog/", None, "www + maiuscole host, path case-sensitive"),
    ("HTTP://EXAMPLE.COM/", None, "schema e host maiuscoli"),
    ("http://example.com/", None, "http resta http"),
    ("https://example.com:8443/sub/", None, "porta non standard"),
    ("https://example.com:443/", None, "porta standard esplicita: conservata da entrambi"),
    ("https://example.com/path?x=1#frag", None, "query e fragment scartati"),
    ("https://example.com//", None, "slash finali multipli"),
    ("https://example.com/a/b///", None, "slash finali multipli dopo un path"),
    ("https://esempio.com/blog/", None, "esempio del README"),
    ("https://example.com/Path/CaSe", None, "path case-sensitive"),
    ("https://xn--bcher-kva.example/", None, "IDN gia' in punycode"),
    ("https://bücher.example/", None, "IDN con carattere minuscolo non ASCII: nessuna conversione punycode"),
    ("https://[::1]:8080/x/", None, "IPv6 con porta"),
    ("https://EXAMPLE.com/Blog", None, "host maiuscolo senza slash"),
    ("http://example.com:80/", None, "http con porta 80 esplicita: conservata da entrambi (nessun default per schema)"),
]

# Casi in cui hub e plugin divergono: il plugin e' caratterizzato cosi' com'e'.
CASI_URL_DIVERGENTI = [
    ("https://BÜCHER.example/", "https://bÜcher.example",
     "host con maiuscola non ASCII: Python .lower() la abbassa, strtolower() di PHP e' solo ASCII"),
    ("https://user:pw@Example.com/x", "https://example.com/x",
     "userinfo: l'hub lo conserva nel netloc, il plugin lo scarta (wp_parse_url separa user/pass)"),
    ("https://example.com\\", "https://example.com",
     "backslash finale: untrailingslashit() lo toglie, rstrip('/') dell'hub no"),
    ("example.com", "https://example.com",
     "senza schema: l'hub solleva ValueError; per il plugin 'example.com' e' un path (nessun host) e lo accoda a 'https://'"),
    ("ftp://example.com", "ftp://example.com",
     "schema non http(s): l'hub solleva ValueError, il plugin lo accetta cosi' com'e'"),
    ("", "https:",
     "stringa vuota: l'hub solleva ValueError; il plugin non ha host e restituisce 'https:' (untrailingslashit di 'https://')"),
    ("//example.com/x", "https://example.com/x",
     "URL relativo al protocollo: l'hub solleva ValueError (niente schema), il plugin assume https"),
]


def vettore_url(testo: str, plugin: str | None, nota: str) -> dict:
    """Esito dell'hub (o dell'errore) per un URL, piu' l'atteso del plugin."""
    try:
        hub = ENROLL["normalize_url"](testo)
        errore = None
    except ValueError as e:
        hub, errore = None, str(e)
    voce = {"input": testo, "hub": hub, "hub_error": errore, "note": nota}
    # Nessun override = il plugin DEVE coincidere con l'hub.
    voce["plugin"] = plugin if plugin is not None else hub
    voce["diverge"] = plugin is not None and plugin != hub
    return voce


# --- 6. Generazione -----------------------------------------------------------------


def genera() -> dict:
    sign_enroll = ENROLL["sign_enroll_payload"]
    sign_fleet = ENROLL["sign_fleet_payload"]
    derive = ENROLL["derive_enroll_token"]

    # --- token v1: HMAC(url_normalizzato, MASTER_SECRET) in base64url senza padding
    token_cases = []
    for url, master in [
        ("https://esempio.com/blog", "K7xVq2mZ9pL4wRt8yUbN3cF6hJdS1oGiEaXk0nT5vM2r"),  # esempio README
        ("https://example.org", "master-di-test"),
        ("https://www.example.org:8443/sub", "master-di-test"),
    ]:
        token_cases.append({"url_normalizzato": url, "master_secret": master, "token": derive(url, master)})
    # Controllo incrociato con il valore pubblicato nel README del plugin.
    assert token_cases[0]["token"] == "hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI", "token README non coincide"

    # --- enroll v1: payload "site\ntoken\norigin\nissued_at"
    enroll_v1 = []
    for site_url, token, origin in [
        ("https://example.org", token_cases[1]["token"], ""),
        ("https://example.org", token_cases[1]["token"], "https://fleet.example"),
        ("https://example.org/blog", "tok-v1-fisso", ""),
    ]:
        enroll_v1.append({
            "site_url": site_url, "token": token, "dashboard_origin": origin, "issued_at": ISSUED_AT,
            "canonical": "\n".join([site_url, token, origin, str(ISSUED_AT)]),
            "signature": sign_enroll(site_url, token, origin, ISSUED_AT, K1_B64),
        })

    # --- enroll v2: "enroll\nsite\ntoken\norigin\nnonce\nissued_at\n2"
    enroll_v2 = []
    for site_url, token, origin, nonce in [
        ("https://example.org", "tok-v2-fisso-1", "https://fleet.example", NONCE),
        ("https://example.org", "tok-v2-fisso-2", "", NONCE_2),
    ]:
        parts = [site_url, token, origin, nonce, str(ISSUED_AT), "2"]
        enroll_v2.append({
            "site_url": site_url, "token": token, "dashboard_origin": origin, "nonce": nonce,
            "issued_at": ISSUED_AT, "protocol": 2,
            "canonical": "enroll\n" + "\n".join(parts),
            "signature": sign_fleet("enroll", parts, K1_B64),
        })

    # --- rotate: "rotate\nsite\nnew_token\nnonce\nissued_at\nprotocol"
    rotate = []
    parts = ["https://example.org", "nuovo-segreto-fisso", NONCE, str(ISSUED_AT), "2"]
    rotate.append({
        "site_url": parts[0], "new_token": parts[1], "nonce": NONCE, "issued_at": ISSUED_AT, "protocol": 2,
        "canonical": "rotate\n" + "\n".join(parts),
        "signature": sign_fleet("rotate", parts, K1_B64),
    })

    # --- revoke: "revoke\nsite\nnonce\nissued_at"
    revoke = []
    parts = ["https://example.org", NONCE, str(ISSUED_AT)]
    revoke.append({
        "site_url": parts[0], "nonce": NONCE, "issued_at": ISSUED_AT,
        "canonical": "revoke\n" + "\n".join(parts),
        "signature": sign_fleet("revoke", parts, K1_B64),
    })

    # --- HMAC delle richieste operative (X-WPHC-*), dal codice reale _sign_site_request
    request_hmac = []
    # Body POST "come l'hub": json.dumps(dict, allow_nan=False) -> separatori ", " / ": " e escape \uXXXX.
    corpo_hub_ascii = json.dumps(
        {"items": [{"type": "plugin", "target": "akismet/akismet.php"}, {"type": "theme", "target": "twentytwentyfive"}],
         "note": "caff\u00e8 \u20ac \u00fc", "force": False, "dry_run": True, "after": None},
        allow_nan=False,
    ).encode("utf-8")
    assert b"\\u00e8" in corpo_hub_ascii and b", " in corpo_hub_ascii and b'": ' in corpo_hub_ascii
    corpo_piccolo_hub = json.dumps({"plugin": "akismet/akismet.php"}, allow_nan=False).encode("utf-8")
    # Byte UTF-8 grezzi non ASCII (NON e' quello che produce l'hub: serve a fissare che il plugin
    # calcoli lo SHA-256 sui byte esatti del corpo, senza ricodifiche).
    corpo_utf8_grezzo = '{"note":"caff\u00e8 \u20ac \u00fc"}'.encode("utf-8")
    assert b"\xc3\xa8" in corpo_utf8_grezzo
    for segreto, metodo, rotta, corpo, produttore in [
        ("segreto-di-test-1", "GET", "/health-check/v1/health", b"", "nessun corpo"),
        ("segreto-di-test-1", "POST", "/health-check/v1/update/plugin", b'{"plugin":"akismet/akismet.php"}',
         "stringa scritta a mano, senza spazi (NON e' il formato dell'hub)"),
        ("segreto-di-test-1", "POST", "/health-check/v1/update/plugin", corpo_piccolo_hub,
         "json.dumps(dict, allow_nan=False) dell'hub"),
        ("segreto-di-test-1", "POST", "/health-check/v1/update/bulk", corpo_hub_ascii,
         "json.dumps(dict, allow_nan=False) dell'hub, con escape \\uXXXX non ASCII"),
        ("segreto-di-test-2", "POST", "/health-check/v1/update/bulk", corpo_utf8_grezzo,
         "byte UTF-8 grezzi non ASCII"),
        ("segreto-di-test-2", "GET", "/health-check/v1/detail/plugins", b"", "nessun corpo"),
    ]:
        _Orologio.valore, _Segreti.nonce = ISSUED_AT, NONCE
        headers = PROXY["_sign_site_request"](segreto, metodo, rotta, corpo)
        request_hmac.append({
            "secret": segreto, "method": metodo, "route": rotta, "body": corpo.decode("utf-8"),
            "body_producer": produttore, "body_sha256": hashlib.sha256(corpo).hexdigest(),
            "timestamp": headers["X-WPHC-Timestamp"], "nonce": headers["X-WPHC-Nonce"],
            "canonical": "\n".join([metodo, rotta, hashlib.sha256(corpo).hexdigest(),
                                    headers["X-WPHC-Timestamp"], headers["X-WPHC-Nonce"]]),
            "signature": headers["X-WPHC-Signature"],
        })

    # --- webhook in uscita: il plugin firma, l'hub verifica (build_webhook_canonical)
    webhook = []
    for segreto, evento, corpo in [
        ("segreto-di-test-1", "wphc.test", b'{"event":"wphc.test","site":"https://example.org"}'),
        ("segreto-di-test-1", "wphc.bulk_update.completed",
         b'{"event":"wphc.bulk_update.completed","items":[{"target":"akismet/akismet.php","phase":"completed"}]}'),
    ]:
        timestamp = str(ISSUED_AT)
        canonical = WEBHOOK["build_webhook_canonical"](evento, corpo, timestamp, NONCE)
        # L'hub non espone una funzione di firma (solo di verifica): la firma e' la stessa
        # formula di verify_webhook_signature, e il generatore controlla che l'hub la accetti.
        firma = base64.urlsafe_b64encode(
            hmac.new(segreto.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256).digest()
        ).rstrip(b"=").decode("ascii")
        assert WEBHOOK["verify_webhook_signature"]([segreto], evento, corpo, timestamp, NONCE, firma)
        webhook.append({
            "secret": segreto, "event": evento, "body": corpo.decode("utf-8"),
            "timestamp": timestamp, "nonce": NONCE, "canonical": canonical, "signature": firma,
        })

    # --- release: "release\n<tag>\n<sha256>" firmata con k2 dal firmatario REALE dell'hub
    # (hub/scripts/sign_release.py::sign_release) su un file temporaneo FUORI dal repo. Contenuto FISSO
    # (mai il plugin reale: l'hash cambierebbe con i fine riga del checkout), con l'header Version
    # coerente con il tag (cosi' supera i controlli di integrita' di wphc_install_release). Il tag
    # "senza v" e' ammesso dalla regex di wphc_is_release_tag e va nel messaggio firmato cosi' com'e'.
    release = []
    with tempfile.TemporaryDirectory(prefix="wphc-vettori-") as cartella:
        for tag in ("v1.38.1", "1.38.1", "v9.9.9", "9.9.9"):
            versione = tag.lstrip("v")
            contenuto = (f"<?php\n/**\n * Version:     {versione}\n */\n// contenuto fisso di test {versione}\n").encode("utf-8")
            percorso = Path(cartella) / "wp-health-check.php"
            percorso.write_bytes(contenuto)
            firmato = SIGN_RELEASE["sign_release"](percorso, tag, K2_B64, "k2")
            sha = hashlib.sha256(contenuto).hexdigest()
            assert firmato["sha256"] == sha and firmato["tag"] == tag and firmato["kid"] == "k2"
            # Stessa firma di sign_fleet_payload('release', ...): lo script del firmatario e il codice
            # dell'enroll devono restare allineati.
            assert firmato["signature"] == sign_fleet("release", [tag, sha], K2_B64), "sign_release e sign_fleet_payload divergono"
            release.append({
                "tag": tag, "content": contenuto.decode("utf-8"), "sha256": sha, "kid": "k2",
                "canonical": f"release\n{tag}\n{sha}",
                "signature": firmato["signature"],
                # JSON del file .sig come lo scrive main() di sign_release.py: json.dumps(risultato, indent=2) + "\n".
                "sig": firmato,
                "sig_json": json.dumps(firmato, indent=2) + "\n",
            })

    return {
        "_meta": {
            "descrizione": "Vettori di contratto hub -> plugin wp-health-check. File GENERATO: non modificare a mano.",
            "generato_da": "tests/tools/gen_contract_vectors.py",
            "hub_sorgenti_sha256": {
                "services/enroll.py": sha256_file(HUB_DIR / "services" / "enroll.py"),
                "services/fleet/proxy.py": sha256_file(HUB_DIR / "services" / "fleet" / "proxy.py"),
                "services/fleet/webhook.py": sha256_file(HUB_DIR / "services" / "fleet" / "webhook.py"),
                "scripts/sign_release.py": sha256_file(HUB_DIR / "scripts" / "sign_release.py"),
            },
            "issued_at_fisso": ISSUED_AT,
        },
        "keys": {
            "k1": {"seed_b64": K1_B64, "public_b64": pubkey_b64(SEED_K1), "uso": "buste centro -> sito (envelope)"},
            "k2": {"seed_b64": K2_B64, "public_b64": pubkey_b64(SEED_K2), "uso": "release"},
        },
        "normalize_url": [vettore_url(*c) for c in CASI_URL + CASI_URL_DIVERGENTI],
        "token_v1": token_cases,
        "enroll_v1": enroll_v1,
        "enroll_v2": enroll_v2,
        "rotate": rotate,
        "revoke": revoke,
        "request_hmac": request_hmac,
        "webhook": webhook,
        "release": release,
    }


def vendor_diverge() -> bool:
    """True (e messaggio su stderr) se la copia in hub-cli/_vendor delle funzioni usate differisce dall'hub."""
    diverge = False
    coppie = [("services/enroll.py", n) for n in ("normalize_url", "derive_enroll_token", "sign_enroll_payload", "sign_fleet_payload")]
    coppie += [("services/fleet/proxy.py", "_sign_site_request")]
    for rel, nome in coppie:
        v = VENDOR_DIR / rel
        if not v.exists() or sorgente_funzione(v, nome) != sorgente_funzione(HUB_DIR / rel, nome):
            print(f"ERRORE: {rel}::{nome} nel vendor di hub-cli differisce dall'hub", file=sys.stderr)
            diverge = True
    return diverge


def main() -> int:
    # La riga con cui l'hub serializza i body deve esistere ancora: i vettori HMAC "come l'hub" ne dipendono.
    if RIGA_BODY_HUB not in (HUB_DIR / "services" / "fleet" / "proxy.py").read_text(encoding="utf-8"):
        print(f"ERRORE: in proxy.py non c'e' piu' `{RIGA_BODY_HUB}`: aggiornare i vettori HMAC", file=sys.stderr)
        return 2
    # Il codice dell'hub e la copia vendorizzata (usata dagli e2e) devono coincidere: altrimenti
    # i vettori e gli e2e non descrivono lo stesso contratto. Si esce PRIMA di scrivere il file.
    if vendor_diverge():
        return 3
    dati = genera()
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    # Fine riga LF e chiavi in ordine di inserimento: file stabile e confrontabile in git.
    with open(OUTPUT, "w", encoding="utf-8", newline="\n") as f:
        json.dump(dati, f, ensure_ascii=False, indent=2)
        f.write("\n")
    print(f"Scritto {OUTPUT.relative_to(PROJECT_DIR)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
