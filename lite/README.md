# WPHC Lite

Agent minimale per i siti **non WordPress** (per esempio Astro serviti da Apache o LiteSpeed con PHP). Risponde come il mu-plugin `wp-health-check` per le sole rotte che servono a fleet per ping, stato e tab Server:

| Rotta (sotto `/wp-json/health-check/v1/`) | Metodo | Cosa fa |
| --- | --- | --- |
| `enroll` | POST | consegna il token con una busta firmata Ed25519 (`k1`), protocollo 1 o 2 |
| `rotate` | POST | ruota il segreto, finestra di grazia di 900 s per il token precedente |
| `revoke` | POST | revoca: le rotte dati rispondono `403 wphc_revoked` |
| `ping` | GET | heartbeat, senza cache e senza traccia dell'accesso |
| `health` | GET | sommario ridotto (PHP, IP, versione), cache 60 s, `?fresh=1` |
| `detail/server` | GET | dati del server, cache 12 ore, `?fresh=1` |

Ogni altra rotta risponde `404 rest_no_route`. Non ci sono update, plugin, tema, utenti, autologin, miniatura, webhook e CORS: l'hub chiama l'agent da server a server.

Il contratto (firme, finestre, nonce, codici d'errore, forma delle risposte) e' quello del plugin ed e' provato con i vettori di `tests/fixtures/contract-vectors.json`. Un solo punto differisce di proposito: il rate limit valuta l'autenticazione **una volta sola** per richiesta, quindi una richiesta valida non conta mai come fallimento (il plugin ha il difetto descritto in `../__docs/wp-health-check/finding-throttle-doppia-valutazione.md`).

`agent_version`, `fleet_agent_version` e `plugin_version` valgono `lite-1.0.0` (costante `WPHC_LITE_VERSION`).

## Requisiti

- PHP 7.4 o superiore, con `ext-sodium` (obbligatoria: senza, ogni rotta nota risponde `503 wphc_lite_sodium_missing`).
- `ext-curl` oppure `allow_url_fopen` per risolvere l'IP pubblico (solo se `SERVER_ADDR` e' privato o assente; senza, `public_ip` resta `null`).
- Una cartella dati scrivibile **fuori dalla web root**.
- HTTPS (il Bearer non deve viaggiare in chiaro).

## Installazione su un sito Astro

1. **Copia l'agent** nella cartella `public/` del repo del sito, come `public/wphc-lite/index.php` (il file e' `lite/wphc-lite.php` di questo repo, senza modifiche). Astro lo copia in `dist/wphc-lite/index.php` a ogni build.
2. **Regole di rewrite**: aggiungi il contenuto di `htaccess.sample` a `public/.htaccess`, **prima** di qualunque altra regola di rewrite. Instradano `/wp-json/health-check/v1/<rotta>` verso `wphc-lite/index.php?route=<rotta>`, passano l'header `Authorization` a PHP e negano l'accesso diretto a `config.php`.
3. **Configurazione**: crea `config.php` **direttamente sul server** accanto a `index.php` (`dist/wphc-lite/config.php`, a partire da `config.sample.php`):
   - `site_urls`: gli URL con cui l'hub conosce il sito (la variante con o senza `www` si aggiunge da sola; il primo e' quello restituito come `site`; mai dedotto dall'header `Host`);
   - `data_dir`: cartella dati, di default `dirname($_SERVER['DOCUMENT_ROOT']) . '/wphc-lite-data'`, cioe' accanto alla web root. Si crea da sola (permessi `0700`) se la cartella genitore e' scrivibile, altrimenti l'agent risponde `503 wphc_lite_storage`;
   - `trust_proxy`: `true` solo dietro un proxy fidato che imposta `X-Forwarded-For` e `X-Forwarded-Proto` (vale il valore piu' a destra).
4. **Proteggi `config.php` dal deploy.** Il deploy del sito e' di norma un `git checkout -f` o un rsync con cancellazione: un file non tracciato come `config.php` va escluso (variabile `PROD_PROTECT` del workflow) e aggiunto a `.gitignore` (`public/wphc-lite/config.php`). La cartella dati sta fuori dalla web root proprio per sopravvivere al deploy: dentro ci sono il token e lo stato dell'enroll.
5. **Enroll** dalla SPA fleet (piattaforma non WordPress, agent lite): l'hub firma la busta e il sito conserva il token solo nella cartella dati.

### Cosa contiene la cartella dati

`state.json` (token, token precedente e `token_rotated_at`, protocollo, kid, `enrolled_at`, `revoked_at`, ultimo accesso), `nonces.json` (nonce come `sha256`, TTL 600 s), `rate.json` (fallimenti per IP, hash `sha1`), `health.json`, `detail-server.json`, `public-ip.json`, `lock`. Tutti i file hanno permessi `0600`; ogni richiesta tiene un `flock` esclusivo su `lock`, quindi le scritture non si sovrappongono.

### Reset manuale dopo una revoca

Come nel plugin, un `enroll` riuscito dopo una `revoke` **non** azzera `revoked_at`: le rotte dati restano `403 wphc_revoked`. L'agent lite non ha un'interfaccia di amministrazione, quindi per ripartire da zero si cancella `state.json` nella cartella dati (o l'intera cartella) e si rifa' l'enroll.

## Verifica con curl

```bash
# Prima dell'enroll: 503 wphc_not_enrolled
curl -i https://www.esempio.com/wp-json/health-check/v1/ping

# Dopo l'enroll, token sbagliato: 401 wphc_unauthorized
curl -i -H 'Authorization: Bearer sbagliato' https://www.esempio.com/wp-json/health-check/v1/ping

# Rotta non gestita: 404 rest_no_route
curl -i https://www.esempio.com/wp-json/health-check/v1/detail/plugins
```

Con il protocollo 2 (dopo l'enroll v2 o una rotazione) le richieste valide portano anche gli header `X-WPHC-Timestamp`, `X-WPHC-Nonce` e `X-WPHC-Signature`: il curl a mano con il token giusto riceve 401, come nel plugin. Si verifica dalla SPA fleet (stato `ok`, tab Server).

Se l'header `Authorization` non arriva a PHP (sempre `401` o `503` anche con il token giusto) controlla che le regole di `htaccess.sample` siano attive e vengano prima delle altre: l'agent legge `HTTP_AUTHORIZATION` e `REDIRECT_HTTP_AUTHORIZATION`.

## Test

I test non richiedono WordPress ma `ext-sodium`, che il PHP locale puo' non avere: si lanciano in Docker dalla radice del repo.

```bash
# PHP 8.x (vendor principale)
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit -c lite/phpunit.xml.dist

# PHP 7.4: il vendor principale richiede PHP 8.1, si usa quello della matrice 7.4 (vedi tests/README.md)
docker run --rm -v "$PWD:/app" -w /app php:7.4-cli tests/php74/vendor/bin/phpunit -c lite/phpunit.xml.dist
```

Da Git Bash su Windows prefissa `MSYS_NO_PATHCONV=1` (o usa PowerShell con `${PWD}`), altrimenti i percorsi dei volumi vengono riscritti. I test iniettano l'ora (i vettori hanno `issued_at` fisso `1700000000`), la chiave pubblica (derivata dal seed di test `k1` dei vettori) e il resolver dell'IP pubblico, e usano una cartella dati temporanea per test.
