"""Libreria comune dei test e2e di wp-health-check (contratto con l'hub e upgrade).

Che cosa contiene:
  - i percorsi (repo, copia di lavoro del plugin, hub-cli/_vendor) e il controllo di sicurezza
    che il file REALE mu-plugins/wp-health-check.php non venga mai scritto;
  - le chiavi di TEST k1/k2 (stessi seed di tests/bootstrap.php) e la firma delle release,
    che replica hub/scripts/sign_release.py (e lo confronta con l'originale);
  - un client del namespace REST di supporto dell'ambiente e2e (tests/e2e/support);
  - l'"hub in miniatura": il codice VENDORIZZATO dell'hub (hub-cli/_vendor/services: enroll,
    fleet/proxy, fleet/secrets), usato com'e' (sola lettura, importato dal percorso), con un
    falso database Supabase in memoria al posto di quello vero e un adattatore di trasporto
    che porta le richieste https://localhost:8890 sulla porta HTTP del container;
  - piccoli strumenti: registro dei controlli, confronto di trascrizioni, docker exec per WP-CLI.

Gli script si lanciano con `python -I`: la modalita' isolata non aggiunge la cartella dello
script a sys.path ne' il site-packages dell'utente, quindi qui si aggiungono esplicitamente
(la cartella e2e in testa, il site-packages utente SOLO in coda e solo se manca PyNaCl).

Sicurezza: il plugin sotto test scrive su __FILE__ quando riceve un push valido. Per questo
l'ambiente e2e monta una COPIA (tests/e2e/.work/mu-plugins/) e questa libreria rifiuta di
lavorare su qualunque altro percorso.
"""
from __future__ import annotations

import sys

# Nessun __pycache__ ne' dentro il repo ne' dentro hub/ e hub-cli/ (sola lettura): va impostato
# PRIMA di importare qualunque modulo, in particolare quelli dell'hub vendorizzato.
sys.dont_write_bytecode = True

import base64
import copy
import gzip
import hashlib
import importlib.util
import json
import os
import re
import site
import subprocess
import tempfile
import time
from pathlib import Path

# ---------------------------------------------------------------------------
# 1. Percorsi
# ---------------------------------------------------------------------------

E2E_DIR = Path(__file__).resolve().parent
PROJECT_DIR = E2E_DIR.parents[1]                      # radice di wp-health-check
MONOREPO_DIR = PROJECT_DIR.parent                     # mavida-saas-ecosystem
VENDOR_DIR = MONOREPO_DIR / "hub-cli" / "_vendor"     # services/ vendorizzato dell'hub
HUB_SIGN_SCRIPT = MONOREPO_DIR / "hub" / "scripts" / "sign_release.py"

REPO_PLUGIN = PROJECT_DIR / "mu-plugins" / "wp-health-check.php"   # il file REALE: sola lettura
WORK_DIR = E2E_DIR / ".work"
WORK_MU_DIR = WORK_DIR / "mu-plugins"                 # montata come wp-content/mu-plugins
WORK_PLUGIN = WORK_MU_DIR / "wp-health-check.php"     # la COPIA che i test possono sovrascrivere
SUPPORT_SRC = E2E_DIR / "support" / "wphc-e2e-support.php"
CANDIDATES_DIR = WORK_DIR / "candidates"
VECTORS_FILE = PROJECT_DIR / "tests" / "fixtures" / "contract-vectors.json"
E2E_CONFIG = PROJECT_DIR / ".wp-env.e2e.json"

# ---------------------------------------------------------------------------
# 2. Dipendenze (PyNaCl, requests) e codice vendorizzato dell'hub
# ---------------------------------------------------------------------------

try:
    from nacl.signing import SigningKey  # noqa: E402
    import requests  # noqa: E402
except ImportError:
    # `python -I` esclude il site-packages utente, dove di solito stanno PyNaCl e requests:
    # lo si riaggiunge in CODA (dopo la libreria standard), mai davanti.
    sys.path.append(site.getusersitepackages())
    from nacl.signing import SigningKey  # noqa: E402
    import requests  # noqa: E402

# L'hub legge queste variabili al momento dell'uso (non all'import): le si imposta PRIMA delle chiamate.
MASTER_SECRET = "e2e-master-secret-NON-DI-PRODUZIONE"
SEED_K1 = bytes(range(32))
SEED_K2 = bytes(range(32, 64))
KEK_B64 = base64.b64encode(bytes(range(100, 132))).decode("ascii")
DASHBOARD_ORIGIN = "https://dashboard.e2e.test"
os.environ.update(
    {
        "MASTER_SECRET": MASTER_SECRET,
        "PRIVATE_KEY": base64.b64encode(SEED_K1).decode("ascii"),   # k1: firma enroll/rotate/revoke
        "FLEET_KEK": KEK_B64,                                       # cifra il segreto nel falso DB
        "FLEET_KEK_KID": "e2e",
        "HEALTHCHECK_DASHBOARD_ORIGIN": DASHBOARD_ORIGIN,
    }
)

sys.path.insert(0, str(VENDOR_DIR))
from services import _db as hub_db  # noqa: E402
from services import enroll as hub_enroll  # noqa: E402
from services._tables import T_FLEET  # noqa: E402
from services.errors import FleetError  # noqa: E402
from services.fleet import crud as hub_crud  # noqa: E402
from services.fleet import proxy as hub_proxy  # noqa: E402
from services.fleet import secrets as hub_secrets  # noqa: E402

# ---------------------------------------------------------------------------
# 3. Ambiente e2e
# ---------------------------------------------------------------------------

BASE_URL = "http://localhost:8890"          # porta HTTP del container wp-env e2e
SITE_URL = "https://localhost:8890"         # URL che il plugin dichiara (HTTPS forzato dal mu-plugin di supporto)
SUPPORT_KEY = "wphc-e2e-local-only"
BASELINE_VERSION_RE = re.compile(r"^\s*\*\s*Version:\s+(\S+)", re.MULTILINE)


def pubkey_b64(seed: bytes) -> str:
    """Chiave pubblica Ed25519 (base64 standard) di un seed."""
    return base64.b64encode(bytes(SigningKey(seed).verify_key)).decode("ascii")


def check_config_matches_test_keys() -> None:
    """Interrompe se le costanti di .wp-env.e2e.json non derivano dai seed di TEST k1/k2."""
    config = json.loads(E2E_CONFIG.read_text(encoding="utf-8"))["config"]
    if config["WP_HEALTH_CHECK_CENTRAL_PUBKEY"] != pubkey_b64(SEED_K1) or config["WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2"] != pubkey_b64(SEED_K2):
        raise SystemExit("ERRORE: le chiavi di .wp-env.e2e.json non derivano dai seed di test k1/k2")


# ---------------------------------------------------------------------------
# 4. Guardia sul file REALE del plugin
# ---------------------------------------------------------------------------

def _repo_mu_listing() -> list[str]:
    return sorted(p.name for p in REPO_PLUGIN.parent.iterdir())


def repo_plugin_fingerprint() -> dict:
    """Elenco della cartella mu-plugins del repo (il file reale e' l'unico ammesso).

    Il contenuto del file NON e' nell'impronta: altri lavori sul repo possono modificarlo
    legittimamente (prove di mutazione con ripristino). Quello che questi test devono garantire
    e' di non scriverlo mai, e lo garantiscono per costruzione (install_plugin rifiuta i
    percorsi del repo; l'ambiente e2e monta una COPIA); qui si cerca l'effetto tipico di un
    install_release finito sul file sbagliato: un .bak o un .tmp accanto al file reale.
    """
    return {"listing": _repo_mu_listing()}


def assert_repo_plugin_untouched(initial: dict) -> None:
    """Fallisce se accanto al file reale compaiono file nuovi (.bak, .tmp, ...)."""
    now = repo_plugin_fingerprint()
    if now != initial or any(name.endswith((".bak", ".tmp")) for name in now["listing"]):
        raise SystemExit(f"ERRORE GRAVE: la cartella mu-plugins reale del repo e' cambiata: {initial} -> {now}")


# La baseline e' il file della 1.38.1 VERA letto da git (commit del rilascio), non dalla copia di
# lavoro: cosi' non dipende da modifiche temporanee del file e non e' influenzata da CRLF locali.
BASELINE_REF = os.environ.get("WPHC_BASELINE_REF", "1d6725b")


def baseline_bytes() -> bytes:
    """Contenuto di mu-plugins/wp-health-check.php al commit di baseline (default: v1.38.1)."""
    done = subprocess.run(["git", "-C", str(PROJECT_DIR), "show", f"{BASELINE_REF}:mu-plugins/wp-health-check.php"], capture_output=True)
    if done.returncode != 0 or not done.stdout.startswith(b"<?php"):
        raise SystemExit(f"Impossibile leggere la baseline {BASELINE_REF} da git: {done.stderr.decode(errors='replace')}")
    return done.stdout


# Dichiarazione della versione nel file: header "Version:" e costante WP_HEALTH_CHECK_VERSION.
# make_candidate() e il controllo di integrita' del plugin (che cerca "Version: <tag>" nel file) le richiedono entrambe.
VERSION_CONST_RE = re.compile(r"(define\(\s*'WP_HEALTH_CHECK_VERSION',\s*')([^']+)('\s*\))")
CANDIDATE_ENV = "WPHC_CANDIDATE_FILE"


def validate_plugin_source(contents: bytes, origin: str) -> str:
    """Controlla che un file di plugin sia installabile e ne restituisce la versione.

    Falliscono, con un messaggio che dice COSA manca, i file che: non sono UTF-8, non iniziano con
    `<?php`, non hanno l'header `Version:`, non dichiarano la costante WP_HEALTH_CHECK_VERSION nella
    forma `define( 'WP_HEALTH_CHECK_VERSION', 'x.y.z' )` o la dichiarano con un valore diverso
    dall'header. Una build che cambia quella forma non e' sostituibile con make_candidate() e il
    suo self-update non supererebbe il controllo di integrita' del plugin: va corretto il build, non il test.
    """
    try:
        text = contents.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise SystemExit(f"{origin}: il file non e' UTF-8 ({exc})")
    if not text.startswith("<?php"):
        raise SystemExit(f"{origin}: il file non inizia con '<?php'")
    header = BASELINE_VERSION_RE.search(text)
    if not header:
        raise SystemExit(f"{origin}: header ' * Version: x.y.z' non trovato (il plugin lo richiede per il controllo di integrita' del self-update)")
    const = VERSION_CONST_RE.search(text)
    if not const:
        raise SystemExit(f"{origin}: costante WP_HEALTH_CHECK_VERSION non trovata nella forma define( 'WP_HEALTH_CHECK_VERSION', 'x.y.z' )")
    if const.group(2) != header.group(1):
        raise SystemExit(f"{origin}: l'header dichiara Version {header.group(1)} ma WP_HEALTH_CHECK_VERSION vale {const.group(2)}")
    return header.group(1)


class PluginUnderTest:
    """Il file di plugin che gli e2e installano: contenuto, versione, sha256 e provenienza."""

    def __init__(self, contents: bytes, origin: str, from_candidate_file: bool) -> None:
        self.contents = contents
        self.text = contents.decode("utf-8", errors="replace")
        self.origin = origin
        self.from_candidate_file = from_candidate_file
        self.version = validate_plugin_source(contents, origin)
        self.sha256 = hashlib.sha256(contents).hexdigest()

    def describe(self) -> str:
        return f"{self.origin}  versione {self.version}  sha256 {self.sha256}  ({len(self.contents)} byte)"


_PLUGIN_UNDER_TEST: PluginUnderTest | None = None


def plugin_under_test_info() -> PluginUnderTest:
    """Il plugin sotto test: WPHC_CANDIDATE_FILE se impostata, altrimenti la baseline (1.38.1 da git).

    E' l'UNICO punto in cui gli e2e decidono quale file installare: hub_contract, wpcli_test e
    upgrade_test (scenario candidate-self-update e fasi dei controlli) passano da qui.
    """
    global _PLUGIN_UNDER_TEST
    if _PLUGIN_UNDER_TEST is None:
        path = os.environ.get(CANDIDATE_ENV)
        if path:
            file = Path(path)
            if not file.is_file():
                raise SystemExit(f"{CANDIDATE_ENV}={path}: il file non esiste")
            _PLUGIN_UNDER_TEST = PluginUnderTest(file.read_bytes(), str(file.resolve()), True)
        else:
            _PLUGIN_UNDER_TEST = PluginUnderTest(baseline_bytes(), f"baseline git {BASELINE_REF}:mu-plugins/wp-health-check.php", False)
    return _PLUGIN_UNDER_TEST


def plugin_under_test() -> bytes:
    """Byte del plugin sotto test (vedi plugin_under_test_info)."""
    return plugin_under_test_info().contents


def announce_plugin_under_test(script: str) -> PluginUnderTest:
    """Stampa quale file installera' lo script (percorso, versione, sha256): finisce nei log."""
    info = plugin_under_test_info()
    print(f"[{script}] plugin sotto test: {info.describe()}")
    return info


def next_version(version: str) -> str:
    """Versione immediatamente successiva (X.Y.Z -> X.Y.Z+1): quella del candidato del push positivo."""
    match = re.match(r"^(\d+)\.(\d+)\.(\d+)", version)
    if not match:
        raise SystemExit(f"Versione non nel formato X.Y.Z: {version}")
    major, minor, patch = (int(part) for part in match.groups())
    return f"{major}.{minor}.{patch + 1}"


def sha256_hex(contents: bytes) -> str:
    return hashlib.sha256(contents).hexdigest()


def _refuse_repo_path(path: Path) -> None:
    if path.resolve() == REPO_PLUGIN.resolve() or REPO_PLUGIN.parent.resolve() in path.resolve().parents:
        raise SystemExit(f"RIFIUTO: {path} e' dentro la cartella mu-plugins reale del repo")


def install_plugin(contents: bytes | str) -> None:
    """Scrive il plugin nella COPIA montata nel container (scrittura atomica, estensione temporanea neutra)."""
    _refuse_repo_path(WORK_PLUGIN)
    data = contents.encode("utf-8") if isinstance(contents, str) else contents
    WORK_MU_DIR.mkdir(parents=True, exist_ok=True)
    tmp = WORK_MU_DIR / ".install.tmp"
    tmp.write_bytes(data)
    os.replace(tmp, WORK_PLUGIN)


def loaded_code_mismatch(state: dict, contents: bytes) -> list[str]:
    """Motivi per cui il codice CARICATO dal PHP del sito non e' ancora quello di `contents` (vuoto = coincide)."""
    reasons = []
    version = plugin_version(contents.decode("utf-8"))
    if state.get("loaded_version") != version:
        reasons.append(f"versione caricata {state.get('loaded_version')!r} (attesa {version})")
    if state.get("loaded_from_installed") is not True:
        reasons.append("il file installato non risulta fra i file inclusi dal PHP")
    if state.get("loaded_sha256") != sha256_hex(contents):
        reasons.append(f"sha256 del file caricato {state.get('loaded_sha256')} (atteso {sha256_hex(contents)})")
    if state.get("loaded_symbol_matches") is False:
        reasons.append(f"i simboli del plugin sono dichiarati da {state.get('loaded_symbol_file')}, non dal file installato")
    if (state.get("opcache") or {}).get("fresh") is False:
        reasons.append("opcache serve ancora uno script compilato prima dell'ultima modifica del file")
    return reasons


def wait_until_loaded(support: "Support", contents: bytes, timeout: int = 30, what: str = "il plugin installato") -> dict:
    """Attende che il PHP del sito esegua davvero `contents`; scaduto il tempo FALLISCE con il motivo.

    Il controllo non si fida del solo file su disco: opcache (revalidate_freq=2) puo' servire per
    qualche secondo il codice precedente, e un test che gira su codice vecchio dimostrerebbe il nulla.
    Si confrontano versione caricata (in memoria), sha256 del file incluso e freschezza di opcache.
    """
    deadline = time.time() + timeout
    reasons: list[str] = ["stato non letto"]
    state: dict = {}
    while time.time() < deadline:
        state = support.state()
        reasons = loaded_code_mismatch(state, contents)
        if not reasons:
            return state
        time.sleep(1)
    raise SystemExit(f"ERRORE: dopo {timeout}s {what} non risulta caricato dal PHP del sito: " + "; ".join(reasons))


def install_and_load(support: "Support", contents: bytes, timeout: int = 30) -> dict:
    """Installa `contents` nella copia montata, invalida opcache e attende che il PHP lo esegua.

    Da usare dopo OGNI sostituzione esterna del file (install_plugin da solo non basta: opcache).
    """
    install_plugin(contents)
    support.opcache_invalidate()
    return wait_until_loaded(support, contents, timeout)


def install_residue() -> list[str]:
    """Nomi di .bak e .tmp (file O directory) rimasti nella cartella montata, tranne il temporaneo della libreria."""
    return sorted(p.name for p in WORK_MU_DIR.iterdir() if p.name.endswith((".bak", ".tmp")) and p.name != ".install.tmp")


def clear_install_residue() -> None:
    """Toglie da QUESTA cartella montata (mai dal repo) i .bak/.tmp lasciati da un self-update fallito."""
    _refuse_repo_path(WORK_MU_DIR / "x")
    for name in install_residue():
        path = WORK_MU_DIR / name
        if path.is_dir():
            path.rmdir()
        else:
            path.unlink()


def install_support_plugin() -> None:
    """Copia il mu-plugin di supporto nella cartella montata."""
    _refuse_repo_path(WORK_MU_DIR / "x")
    WORK_MU_DIR.mkdir(parents=True, exist_ok=True)
    (WORK_MU_DIR / SUPPORT_SRC.name).write_bytes(SUPPORT_SRC.read_bytes())


def installed_plugin_text() -> str:
    """Contenuto attuale della copia del plugin (puo' essere cambiato da un self-update)."""
    # Lettura in binario: read_text() su Windows convertirebbe CRLF in LF e falserebbe i confronti byte per byte.
    return WORK_PLUGIN.read_bytes().decode("utf-8")


def plugin_version(text: str) -> str:
    """Versione dichiarata nell'header del plugin."""
    match = BASELINE_VERSION_RE.search(text)
    if not match:
        raise ValueError("header Version non trovato")
    return match.group(1)


# ---------------------------------------------------------------------------
# 5. Release: candidato, firma, pacchetto push
# ---------------------------------------------------------------------------

def make_candidate(baseline_text: str, version: str) -> str:
    """Copia del plugin con la versione alzata (header e costante), il resto identico."""
    # Se il file non ha le due dichiarazioni nella forma attesa, si ferma con un messaggio chiaro.
    current = validate_plugin_source(baseline_text.encode("utf-8"), "make_candidate")
    header, n_header = re.subn(r"(^\s*\*\s*Version:\s+)" + re.escape(current), r"\g<1>" + version, baseline_text, count=1, flags=re.MULTILINE)
    const, n_const = re.subn(r"(define\(\s*'WP_HEALTH_CHECK_VERSION',\s*')" + re.escape(current) + r"('\s*\))", r"\g<1>" + version + r"\g<2>", header, count=1)
    if n_header != 1 or n_const != 1:
        raise SystemExit("make_candidate: header Version o costante WP_HEALTH_CHECK_VERSION non sostituibili")
    return const


def sign_release(contents: bytes, tag: str, seed: bytes = SEED_K2, kid: str = "k2") -> dict:
    """Come hub/scripts/sign_release.py: firma "release\\n<tag>\\n<sha256>" con la chiave indicata.

    Restituisce {"tag", "sha256", "kid", "signature"}: il contenuto di wp-health-check.php.sig.
    """
    sha256 = hashlib.sha256(contents).hexdigest()
    message = "release\n" + "\n".join([tag, sha256])
    signature = base64.b64encode(SigningKey(seed).sign(message.encode("utf-8")).signature).decode("ascii")
    return {"tag": tag, "sha256": sha256, "kid": kid, "signature": signature}


def hub_script_sign_release(contents: bytes, tag: str) -> dict:
    """La firma prodotta dallo script ORIGINALE dell'hub (importato dal percorso, sola lettura)."""
    spec = importlib.util.spec_from_file_location("hub_sign_release_ref", HUB_SIGN_SCRIPT)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[union-attr]
    with tempfile.TemporaryDirectory() as tmp:
        target = Path(tmp) / "wp-health-check.php"
        target.write_bytes(contents)
        return module.sign_release(target, tag, base64.b64encode(SEED_K2).decode("ascii"), "k2")


def write_release_assets(directory: Path, contents: bytes, sig: dict) -> None:
    """Scrive i tre asset di una release: .php, .sha256 (formato sha256sum) e .sig (JSON)."""
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "wp-health-check.php").write_bytes(contents)
    (directory / "wp-health-check.php.sha256").write_text(f"{sig['sha256']}  wp-health-check.php\n", encoding="utf-8")
    (directory / "wp-health-check.php.sig").write_text(json.dumps(sig, indent=2) + "\n", encoding="utf-8")


# Limite del body di POST /update (JSON con il pacchetto): 128 KiB. Il budget del progetto per il solo
# contenuto gzip+base64 e' piu' basso (110.000 caratteri) e vale come informazione.
PUSH_BODY_LIMIT = 131072
PUSH_GZIP_B64_BUDGET = 110000


def push_sizes(package: dict) -> tuple[int, int]:
    """(byte del body come lo spedisce l'hub, caratteri di contents_gz_b64) di un pacchetto di push."""
    return len(json_bytes({"package": package})), len(package["contents_gz_b64"])


def build_push_package(contents: bytes, sig: dict, force: bool = False) -> dict:
    """Pacchetto del body di POST /update (push), come hub/services/fleet/agent_release.py:build_push_package."""
    return {
        "tag": sig["tag"],
        "sha256": sig["sha256"],
        "sig": sig,
        "contents_gz_b64": base64.b64encode(gzip.compress(contents, 9)).decode("ascii"),
        "force": bool(force),
    }


_HUB_PROXY = MONOREPO_DIR / "hub" / "services" / "fleet" / "proxy.py"


def hub_push_support():
    """(_agent_supports_push, _PUSH_MIN_AGENT) dell'hub vero, estratti per percorso con `ast`.

    hub/services/fleet/proxy.py non si importa da solo (import relativi, requests, Supabase): come
    fa tests/tools/gen_contract_vectors.py si prende il codice della funzione e della costante dal
    sorgente e lo si esegue in un namespace isolato, senza scrivere bytecode. E' la regola con cui
    l'hub decide se un sito accetta il push: va applicata alla versione che il candidato riporta.
    """
    import ast

    tree = ast.parse(_HUB_PROXY.read_text(encoding="utf-8"))
    namespace: dict = {"re": re, "__name__": "hub_proxy_estratto"}
    wanted = {"_agent_supports_push": False, "_PUSH_MIN_AGENT": False}
    for node in tree.body:
        is_func = isinstance(node, ast.FunctionDef) and node.name == "_agent_supports_push"
        is_const = isinstance(node, ast.Assign) and any(isinstance(t, ast.Name) and t.id == "_PUSH_MIN_AGENT" for t in node.targets)
        if is_func or is_const:
            exec(compile(ast.Module([node], []), str(_HUB_PROXY), "exec"), namespace)  # noqa: S102
            wanted["_agent_supports_push" if is_func else "_PUSH_MIN_AGENT"] = True
    missing = [name for name, found in wanted.items() if not found]
    if missing:
        raise SystemExit(f"{_HUB_PROXY}: {', '.join(missing)} non trovati (l'hub e' cambiato?)")
    return namespace["_agent_supports_push"], namespace["_PUSH_MIN_AGENT"]


# ---------------------------------------------------------------------------
# 6. Client del namespace REST di supporto
# ---------------------------------------------------------------------------

class Support:
    """Client di wphc-e2e/v1 (reset, stato, option, cron, webhook), via HTTP sulla porta del container."""

    def __init__(self) -> None:
        self.session = requests.Session()
        self.session.headers["X-E2E-Key"] = SUPPORT_KEY

    def _url(self, path: str) -> str:
        return f"{BASE_URL}/wp-json/wphc-e2e/v1{path}"

    def wait_ready(self, timeout: int = 120) -> None:
        """Attende che l'ambiente risponda (e che il mu-plugin di supporto sia caricato)."""
        deadline = time.time() + timeout
        last = ""
        while time.time() < deadline:
            try:
                resp = self.session.get(self._url("/state"), timeout=10)
                if resp.status_code == 200:
                    return
                last = f"HTTP {resp.status_code}"
            except requests.RequestException as exc:
                last = str(exc)
            time.sleep(2)
        raise SystemExit(f"Ambiente e2e non raggiungibile su {BASE_URL} ({last}). Avviarlo: vedi tests/e2e/README.md")

    def reset(self) -> None:
        self.session.post(self._url("/reset"), timeout=60).raise_for_status()

    def state(self) -> dict:
        resp = self.session.get(self._url("/state"), timeout=60)
        resp.raise_for_status()
        state = resp.json()
        # PHP codifica un array vuoto come [] e non come {}: si riporta a dict dove serve un dict.
        for key in ("options", "options_autoload", "transients"):
            if isinstance(state.get(key), list) and not state[key]:
                state[key] = {}
        return state

    def set_options(self, values: dict | None = None, delete: list | None = None) -> None:
        resp = self.session.post(self._url("/options"), json={"set": values or {}, "delete": delete or []}, timeout=60)
        resp.raise_for_status()

    def cron_run(self, hook: str) -> int:
        resp = self.session.post(self._url("/cron/run"), json={"hook": hook}, timeout=300)
        resp.raise_for_status()
        return resp.json()["ran"]

    def webhook(self, status: int | None = None, clear: bool = False) -> None:
        body: dict = {"clear": clear}
        if status is not None:
            body["status"] = status
        self.session.post(self._url("/webhook"), json=body, timeout=60).raise_for_status()

    def clear_throttle(self) -> None:
        """Azzera il contatore dei fallimenti (vedi wphc_e2e_throttle nel mu-plugin di supporto)."""
        self.session.post(self._url("/throttle"), timeout=60).raise_for_status()

    def throttle_failures(self) -> int:
        """Valore corrente del contatore dei fallimenti di autenticazione (0 se assente)."""
        counters = [int(v) for k, v in self.state()["transients"].items() if k.startswith("wphc_fail_")]
        return sum(counters)

    def empty_updates(self) -> None:
        self.session.post(self._url("/updates"), timeout=60).raise_for_status()

    def opcache_invalidate(self) -> dict:
        """Invalida l'opcache del file del plugin (dopo una sostituzione esterna del file)."""
        resp = self.session.post(self._url("/opcache"), timeout=60)
        resp.raise_for_status()
        return resp.json()


# ---------------------------------------------------------------------------
# 7. L'hub in miniatura: codice vendorizzato + falso database + adattatore di trasporto
# ---------------------------------------------------------------------------

class _Result:
    def __init__(self, data):
        self.data = data


class _Query:
    """Finta query PostgREST in memoria: select/update/eq/is_/limit/execute, quanto serve all'hub."""

    def __init__(self, rows: list[dict]):
        self._rows = rows
        self._op = "select"
        self._values: dict = {}
        self._filters: list = []

    def select(self, *_args, **_kwargs):
        self._op = "select"
        return self

    def update(self, values: dict):
        self._op = "update"
        self._values = values
        return self

    def eq(self, column, value):
        self._filters.append(lambda row: row.get(column) == value)
        return self

    def is_(self, column, value):
        if value == "null":
            self._filters.append(lambda row: row.get(column) is None)
        return self

    def limit(self, _count):
        return self

    def execute(self):
        matching = [row for row in self._rows if all(f(row) for f in self._filters)]
        if self._op == "update":
            for row in matching:
                row.update(self._values)
        return _Result(copy.deepcopy(matching))


class FakeSupabase:
    """Falso client Supabase: una sola tabella utile (generations_fleet), le altre sono vuote."""

    def __init__(self) -> None:
        self.tables: dict[str, list[dict]] = {T_FLEET: []}

    def table(self, name: str) -> _Query:
        return _Query(self.tables.setdefault(name, []))


class Hub:
    """Un "hub" di test che usa il codice vendorizzato contro il sito del container e2e.

    Il codice dell'hub (enroll_site, rotate_site_secret, revoke_site_secret, _site_request,
    fetch_site_health, fetch_site_detail, ...) gira SENZA modifiche. Cio' che e' sostituito:
      - il client Supabase (hub_db._supabase_client) con FakeSupabase;
      - get_fleet_site / get_fleet_site_by_url (letture di anagrafica) con versioni in memoria;
      - requests.post e enroll.http_get: riscrivono https://localhost:8890 in http://localhost:8890
        (il container serve HTTP; l'HTTPS lo dichiara il mu-plugin di supporto) e nient'altro.
    """

    SITE_ID = "e2e-site-0001"
    _patched = False  # gli adattatori di trasporto si installano una sola volta per processo

    def __init__(self) -> None:
        self.db = FakeSupabase()
        self.row: dict = {}
        self.reset()
        hub_db._supabase_client = self.db
        if not Hub._patched:
            self._patch()
            Hub._patched = True

    def reset(self) -> None:
        """Riporta l'anagrafica del sito allo stato iniziale: protocollo 1, nessun segreto v2, non revocato."""
        self.row.clear()
        self.row.update(
            {
                "id": self.SITE_ID,
                "site_url": SITE_URL,
                "agent_secret_enc": None,
                "agent_secret_prev_enc": None,
                "secret_revoked_at": None,
                "secret_rotated_at": None,
                "secret_confirmed_at": None,
                "protocol_version": 1,
                "agent_version": None,
                "deleted_at": None,
            }
        )
        self.db.tables[T_FLEET][:] = [self.row]

    # -- installazione degli adattatori -------------------------------------------------
    def _patch(self) -> None:
        def get_site(site_id):
            row = self.row
            if row["id"] == site_id and row["deleted_at"] is None:
                return {"id": row["id"], "site_url": row["site_url"], "agent_version": row.get("agent_version")}
            return None

        hub_crud.get_fleet_site = get_site
        hub_proxy.get_fleet_site = get_site
        hub_secrets.get_fleet_site = get_site
        hub_crud.get_fleet_site_by_url = lambda url: (self.row if url == self.row["site_url"] else None)

        def to_http(url: str) -> str:
            return url.replace(SITE_URL, BASE_URL, 1) if url.startswith(SITE_URL) else url

        original_post = requests.post
        original_get = hub_enroll.http_get
        requests.post = lambda url, **kw: original_post(to_http(url), **kw)
        hub_enroll.http_get = lambda url, **kw: original_get(to_http(url), **kw)

    # -- operazioni dell'hub ------------------------------------------------------------
    def enroll(self) -> dict:
        """Enroll del sito (v1 se protocol_version=1, v2 altrimenti): codice vero dell'hub."""
        return hub_enroll.enroll_site(SITE_URL)

    def rotate(self) -> dict:
        return hub_secrets.rotate_site_secret(self.SITE_ID)

    def revoke(self) -> dict:
        return hub_secrets.revoke_site_secret(self.SITE_ID)

    def health(self, fresh: bool = False) -> dict:
        return hub_proxy.fetch_site_health(self.SITE_ID, SITE_URL, fresh)

    def ping(self) -> dict:
        return hub_proxy.fetch_site_ping(self.SITE_ID, SITE_URL)

    def detail(self, section: str):
        return hub_proxy.fetch_site_detail(self.SITE_ID, section)

    def request(self, method: str, route: str, json_body: dict | None = None, timeout: int = 120):
        """Chiamata autenticata (segreto v1/v2, firma se v2, retry sul precedente al 401): _site_request vera."""
        return hub_proxy._site_request(self.SITE_ID, method, route, json_body=json_body, timeout=timeout)

    # -- segreti noti (per mascherare i valori nei confronti) ---------------------------
    def current_secret(self) -> str:
        """Segreto che l'hub userebbe ora (v2 decifrato, oppure il token v1 derivato)."""
        if self.row["agent_secret_enc"]:
            from services.crypto import unseal
            return unseal(self.row["agent_secret_enc"])
        return hub_enroll.derive_enroll_token(SITE_URL, MASTER_SECRET)

    def previous_secret(self) -> str | None:
        if self.row["agent_secret_prev_enc"]:
            from services.crypto import unseal
            return unseal(self.row["agent_secret_prev_enc"])
        return None


def v1_token() -> str:
    """Token v1 derivato (HMAC su MASTER_SECRET) del sito di test."""
    return hub_enroll.derive_enroll_token(SITE_URL, MASTER_SECRET)


# ---------------------------------------------------------------------------
# 8. Richieste "crude" al sito (per scenari che l'hub vero non produce: segreto precedente, ...)
# ---------------------------------------------------------------------------

def raw_request(method: str, route: str, bearer: str | None, hmac_secret: str | None = None,
                body: dict | None = None, params: dict | None = None, bad_signature: bool = False):
    """Richiesta diretta al plugin. `route` senza /wp-json (es. /health-check/v1/ping).

    La firma X-WPHC-* e' calcolata dalla funzione VERA dell'hub (_sign_site_request).
    Restituisce (status, corpo JSON o testo).
    """
    body_bytes = json_bytes(body) if body is not None else b""
    headers = {"User-Agent": hub_enroll.SITE_USER_AGENT}
    if bearer is not None:
        headers["Authorization"] = f"Bearer {bearer}"
    if body is not None:
        headers["Content-Type"] = "application/json"
    if hmac_secret is not None:
        headers.update(hub_proxy._sign_site_request(hmac_secret, method, route, body_bytes))
        if bad_signature:
            headers["X-WPHC-Signature"] = headers["X-WPHC-Signature"][::-1]
    resp = requests.request(method, f"{BASE_URL}/wp-json{route}", headers=headers, data=body_bytes or None, params=params, timeout=60)
    try:
        return resp.status_code, resp.json()
    except ValueError:
        return resp.status_code, resp.text


def json_bytes(value: dict) -> bytes:
    return json.dumps(value, allow_nan=False).encode("utf-8")


# ---------------------------------------------------------------------------
# 9. Registro dei controlli e normalizzazione dei confronti
# ---------------------------------------------------------------------------

class Report:
    """Raccoglie i controlli, li stampa uno per riga e riassume l'esito."""

    def __init__(self, title: str) -> None:
        self.title = title
        self.passed = 0
        self.failed: list[str] = []

    def check(self, name: str, condition: bool, detail: str = "") -> bool:
        if condition:
            self.passed += 1
            print(f"  ok    {name}")
        else:
            self.failed.append(name)
            print(f"  FAIL  {name}" + (f"\n        {detail}" if detail else ""))
        return bool(condition)

    def section(self, text: str) -> None:
        print(f"\n== {text}")

    def finish(self) -> int:
        total = self.passed + len(self.failed)
        print(f"\n{self.title}: {self.passed}/{total} controlli superati" + ("" if not self.failed else f", {len(self.failed)} FALLITI"))
        for name in self.failed:
            print(f"  - {name}")
        return 1 if self.failed else 0


_ISO_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(\+00:00|Z)?$")
_HEX_RE = re.compile(r"^[0-9a-f]{16,}$")


def normalize(value, secrets: dict[str, str] | None = None, versions: tuple[str, ...] = (), now: int | None = None):
    """Rende confrontabili due trascrizioni: maschera segreti noti, versioni dell'agent, timestamp,
    date ISO e identificativi esadecimali. Le chiavi dei dict sono ordinate dal confronto.
    """
    secrets = secrets or {}
    now = now or int(time.time())
    if isinstance(value, dict):
        # Le durate misurate cambiano a ogni esecuzione.
        return {k: ("<MS>" if k in ("duration_ms", "response_time_ms", "took_ms") else normalize(v, secrets, versions, now)) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [normalize(v, secrets, versions, now) for v in value]
    if isinstance(value, bool) or value is None:
        return value
    if isinstance(value, (int, float)):
        return "<TS>" if abs(value - now) <= 86400 * 2 else value
    if isinstance(value, str):
        for label, secret in secrets.items():
            if secret and secret in value:
                value = value.replace(secret, f"<{label}>")
        for version in versions:
            if value == version:
                return "<AGENT_VERSION>"
        if value.isdigit() and abs(int(value) - now) <= 86400 * 2:
            return "<TS>"
        if _ISO_RE.match(value):
            return "<ISO>"
        if _HEX_RE.match(value):
            return "<HEX>"
    return value


def diff_transcripts(left, right, path: str = "") -> list[str]:
    """Elenco delle differenze fra due strutture normalizzate (vuoto = uguali)."""
    if type(left) is not type(right):
        return [f"{path}: {left!r} != {right!r}"]
    if isinstance(left, dict):
        out = []
        for key in sorted(set(left) | set(right)):
            if key not in left or key not in right:
                out.append(f"{path}/{key}: presente solo in {'sinistra' if key in left else 'destra'}")
            else:
                out += diff_transcripts(left[key], right[key], f"{path}/{key}")
        return out
    if isinstance(left, list):
        if len(left) != len(right):
            return [f"{path}: lunghezze diverse {len(left)} != {len(right)}"]
        out = []
        for i, (a, b) in enumerate(zip(left, right)):
            out += diff_transcripts(a, b, f"{path}[{i}]")
        return out
    return [] if left == right else [f"{path}: {left!r} != {right!r}"]


# ---------------------------------------------------------------------------
# 10. WP-CLI nel container
# ---------------------------------------------------------------------------

def cli_container() -> str:
    """Nome del container "cli" dell'ambiente e2e (wp-env-wp-health-check-e2e-<hash>-cli-1)."""
    out = subprocess.run(["docker", "ps", "--format", "{{.Names}}", "--filter", "name=wp-env-wp-health-check-e2e"],
                         capture_output=True, text=True, check=True).stdout.split()
    for name in out:
        if name.endswith("-cli-1"):
            return name
    raise SystemExit("Container cli dell'ambiente e2e non trovato: l'ambiente e' avviato? (tests/e2e/README.md)")


def wp_cli(*args: str, timeout: int = 120) -> tuple[int, str, str]:
    """Esegue `wp <args>` nel container cli dell'ambiente e2e. Restituisce (exit code, stdout, stderr)."""
    cmd = ["docker", "exec", "-w", "/var/www/html", cli_container(), "wp", *args]
    done = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, encoding="utf-8")
    return done.returncode, done.stdout, done.stderr
