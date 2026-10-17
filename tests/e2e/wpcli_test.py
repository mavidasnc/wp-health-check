"""Test dei comandi WP-CLI del plugin con la WP-CLI VERA, dentro il container "cli" dell'ambiente e2e.

Cosa si verifica (e non si puo' verificare in PHPUnit, dove WP-CLI non e' caricata):
  - `wp help health-check` e `wp help health-check secret`: WP-CLI legge descrizioni, sinossi e
    OPZIONI dai docblock via reflection, quindi i docblock devono restare (lo strip dei commenti
    del build non deve toccarli). La parte descrittiva dell'help e' confrontata con un file
    versionato (tests/e2e/fixtures/wpcli-help-health-check.txt);
  - `wp health-check status | secret [--show] | reset` con sito non enrollato, enrollato v1 e v2;
  - la validazione degli argomenti fatta da WP-CLI sulla sinossi `[--show]`: parametro
    sconosciuto, argomenti posizionali in piu', sottocomando inesistente.

Uso (con l'ambiente e2e acceso):
    python -I tests/e2e/wpcli_test.py [--update-fixture]

Il comando docker exec usa il container cli dell'ambiente e2e (mai quelli degli altri progetti).

Il file installato e' il PLUGIN SOTTO TEST (e2e_common.plugin_under_test): WPHC_CANDIDATE_FILE se
impostata, altrimenti la baseline 1.38.1 da git. Con la build del refactoring questo script prova
quindi che i docblock (letti da WP-CLI via reflection) e i comandi sono rimasti identici.
"""
import argparse
import hashlib
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import e2e_common as c  # noqa: E402

FIXTURE = c.E2E_DIR / "fixtures" / "wpcli-help-health-check.txt"


def clean(text: str) -> str:
    """Testo di help senza spazi finali e senza la sezione dei parametri globali (dipende da WP-CLI)."""
    text = text.replace("\r\n", "\n")
    head = text.split("GLOBAL PARAMETERS")[0]
    return "\n".join(line.rstrip() for line in head.strip().splitlines()) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--update-fixture", action="store_true", help="riscrive il file di confronto dell'help")
    args = parser.parse_args()

    report = c.Report("wpcli_test")
    initial = c.repo_plugin_fingerprint()
    c.check_config_matches_test_keys()
    c.install_support_plugin()
    support = c.Support()
    support.wait_ready()

    # Plugin sotto test (candidato o baseline); la versione attesa dallo status si ricava dal file installato.
    under_test = c.announce_plugin_under_test("wpcli_test").contents
    version = c.plugin_version(under_test.decode("utf-8"))
    c.install_and_load(support, under_test)
    support.reset()
    hub = c.Hub()

    # ------------------------------------------------------------------ help
    report.section("wp help (docblock letti da WP-CLI via reflection)")
    code, out, err = c.wp_cli("help", "health-check")
    report.check("wp help health-check: exit 0", code == 0, err[:300])
    help_text = clean(out)
    report.check("descrizione del gruppo di comandi", "Comandi WP-CLI del fleet agent wp-health-check." in help_text)
    report.check("i tre sottocomandi sono elencati", all(re.search(rf"^\s+{name}\s+\S", help_text, re.MULTILINE) for name in ("reset", "secret", "status")), help_text)
    if args.update_fixture or not FIXTURE.exists():
        FIXTURE.parent.mkdir(parents=True, exist_ok=True)
        FIXTURE.write_text(help_text, encoding="utf-8")
        print(f"  (file di confronto scritto: {FIXTURE.relative_to(c.PROJECT_DIR)})")
    report.check("help identico al file di confronto versionato", FIXTURE.read_text(encoding="utf-8") == help_text, "diverso da " + str(FIXTURE))

    code, out, err = c.wp_cli("help", "health-check", "secret")
    secret_help = clean(out)
    report.check("wp help health-check secret: exit 0", code == 0, err[:300])
    report.check("l'opzione [--show] e' nella sinossi", "wp health-check secret [--show]" in secret_help, secret_help)
    report.check("descrizione di --show", "Stampa anche il segreto in chiaro, non solo il fingerprint." in secret_help, secret_help)

    # ------------------------------------------------------------------ sito non enrollato
    report.section("Sito non enrollato")
    code, out, err = c.wp_cli("health-check", "status")
    expected_status = [
        f"Versione agent: {version}",
        "Enrollment: non registrato",
        "Protocollo: 1",
        "Revocato: no",
        "Ultimo enroll fallito: nessuno",
    ]
    lines = out.strip().splitlines()
    report.check("status: exit 0 e righe attese", code == 0 and lines[:5] == expected_status and lines[5].startswith("Thumbnail: "), out)
    code, out, err = c.wp_cli("health-check", "secret")
    report.check("secret senza enroll: exit 1 con messaggio d'errore", code == 1 and "Error: Nessun segreto registrato (sito non ancora enrollato)." in err, err)
    report.check("  nessun output su stdout", out.strip() == "")

    # ------------------------------------------------------------------ enrollato v1
    report.section("Sito enrollato (protocollo 1)")
    token = hub.enroll()["token"]
    code, out, err = c.wp_cli("health-check", "status")
    report.check("status: registrato, protocollo 1", code == 0 and "Enrollment: registrato" in out and "Protocollo: 1" in out, out)
    code, out, err = c.wp_cli("health-check", "secret")
    fingerprint = hashlib.sha256(token.encode()).hexdigest()[:12]
    report.check("secret: fingerprint, protocollo, rotazione, revoca (4 righe, senza segreto)", code == 0 and out.strip().splitlines() == [f"Fingerprint: {fingerprint}", "Protocollo: 1", "Ultima rotazione: mai", "Revocato: no"], out)
    report.check("  il segreto non compare", token not in out)
    code, out, err = c.wp_cli("health-check", "secret", "--show")
    report.check("secret --show: quinta riga con il segreto in chiaro", code == 0 and out.strip().splitlines()[-1] == f"Segreto: {token}" and len(out.strip().splitlines()) == 5, out)
    code, out, err = c.wp_cli("health-check", "secret", "--show=false")
    report.check("secret --show=false: la stringa 'false' non e' vuota, quindi STAMPA il segreto", code == 0 and f"Segreto: {token}" in out, out)
    code, out, err = c.wp_cli("health-check", "secret", "--show=0")
    report.check("secret --show=0: non stampa il segreto", code == 0 and token not in out, out)

    # ------------------------------------------------------------------ validazione degli argomenti
    report.section("Validazione degli argomenti (sinossi letta dal docblock)")
    code, out, err = c.wp_cli("health-check", "secret", "--foo")
    report.check("parametro sconosciuto --foo: exit 1 'unknown --foo parameter'", code == 1 and "unknown --foo parameter" in err, err)
    code, out, err = c.wp_cli("health-check", "secret", "extra")
    report.check("argomento posizionale in piu': exit 1 'Too many positional arguments'", code == 1 and "Too many positional arguments: extra" in err, err)
    code, out, err = c.wp_cli("health-check", "nonexistent")
    report.check("sottocomando inesistente: exit 1 'is not a registered subcommand'", code == 1 and "is not a registered subcommand" in err, err)
    code, out, err = c.wp_cli("health-check", "status", "--foo")
    # Senza sezione OPTIONS nel docblock WP-CLI non ha una sinossi da far rispettare: accetta qualunque
    # parametro (comportamento da preservare: la validazione esiste solo dove c'e' [--show]).
    report.check("status --foo: accettato (nessuna sinossi dichiarata), exit 0", code == 0 and "Enrollment:" in out, err)

    # ------------------------------------------------------------------ v2, revocato, reset
    report.section("Dopo rotazione e revoca, poi reset")
    hub.rotate()
    new_token = hub.current_secret()
    code, out, err = c.wp_cli("health-check", "secret", "--show")
    rows = out.strip().splitlines()
    report.check("dopo la rotazione: protocollo 2, data di rotazione ISO 8601, segreto nuovo", code == 0 and rows[1] == "Protocollo: 2" and re.fullmatch(r"Ultima rotazione: \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00", rows[2]) is not None and rows[-1] == f"Segreto: {new_token}", out)
    hub.revoke()
    code, out, err = c.wp_cli("health-check", "status")
    report.check("status dopo la revoca: Revocato: si'", code == 0 and "Revocato: si'" in out, out)
    code, out, err = c.wp_cli("health-check", "secret")
    report.check("secret dopo la revoca: la riga Revocato riporta la data", code == 0 and re.search(r"^Revocato: \d{4}-\d{2}-\d{2}T", out, re.MULTILINE) is not None, out)

    code, out, err = c.wp_cli("health-check", "reset")
    report.check("reset: exit 0 e messaggio di successo", code == 0 and 'Success: Enrollment resettato: il sito e\' tornato allo stato "non registrato".' in out, out + err)
    options = support.state()["options"]
    report.check("reset: le option di enrollment sono sparite", not [k for k in options if k.endswith(("_token", "_site_url", "_protocol", "_revoked_at", "_token_prev", "_secret_kid"))], str(list(options)))
    code, out, err = c.wp_cli("health-check", "status")
    report.check("status dopo il reset: non registrato", code == 0 and "Enrollment: non registrato" in out and "Revocato: no" in out, out)
    code, out, err = c.wp_cli("health-check", "reset")
    report.check("reset su un sito gia' non enrollato: exit 0", code == 0)

    support.reset()
    c.assert_repo_plugin_untouched(initial)
    return report.finish()


if __name__ == "__main__":
    sys.exit(main())
