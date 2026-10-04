# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.0.0/), e questo
progetto aderisce a [Semantic Versioning](https://semver.org/lang/it/).

## [Unreleased]

## [1.34.0] - 2026-10-04

Finding A-1, A-3 e A-10 della review di sicurezza del 2026-10-03, da rilasciare insieme all'hub 0.211.0.

### Security

- **Niente downgrade dell'enroll (A-1).** Un sito già al protocollo 2 rifiuta le buste v1 con `409 wphc_enroll_downgrade` (dopo la verifica della firma): chi possedeva una vecchia busta v1 poteva riportarlo al token derivato, che conosceva e che è anche la chiave HMAC della firma v2, annullando la rotazione. Anche la busta v1 deve ora rientrare nella finestra di ±300 secondi su `issued_at`.
- **Firma del centro sul self-update (A-3).** Se la release ha l'asset `wp-health-check.php.sig`, `wphc_perform_self_update()` verifica la firma Ed25519 del centro su `"release\n<tag>\n<sha256>"` con la chiave pubblica incorporata, prima di toccare il file: lo SHA-256 da solo stava nella stessa release, quindi non provava l'autenticità. Rollout in due fasi: senza `.sig` l'update procede ancora (`"signature": "missing"` nella risposta), una firma non valida lo ferma sempre. La firma diventerà obbligatoria nella release successiva. Rimosso il ripiego sulla riga `sha256:` nel corpo della release.
- **`trust_proxy` e autologin (A-10).**
  - Con `wp_health_check_trust_proxy` l'IP del chiamante è l'**ultimo** valore valido di `X-Forwarded-For` (prima il primo, scelto dal client: throttle aggirabile).
  - `wphc_require_https()` con `trust_proxy` pretende `X-Forwarded-Proto: https` invece di saltare il controllo, e si applica anche alle rotte dati (`wphc_require_token()`) e a `POST /autologin/token`.
  - Il consumo dell'autologin passa dal throttle per IP (ogni token sconosciuto conta come tentativo fallito, quindi le GET anonime non gonfiano più la tabella di log), richiede HTTPS e imposta un cookie di sessione (`wp_set_auth_cookie( ..., false )`) invece dei 14 giorni di "Ricordami".

### Changed

- `POST /update` riporta `signature` (`verified` o `missing`) quando l'aggiornamento riesce.
- Plugin installer 1.1.0: senza l'asset `.sha256` l'installazione si ferma invece di procedere senza verifica.

## [1.33.0] - 2026-09-30

### Fixed

- **`POST /update/reactivate` riattivava plugin disattivati a mano e ritentava all'infinito quelli cancellati.** L'elenco dei plugin "attesi attivi" si basava solo sulle righe di log degli update, mai aggiornate da disattivazioni o cancellazioni: un plugin rimosso finiva a ogni chiamata in `failed` ("Plugin file does not exist."), uno disattivato volontariamente in wp-admin veniva riattivato. Ora gli hook `deactivated_plugin` e `deleted_plugin` scrivono righe di log con le nuove fasi `deactivated` e `deleted` (`active = 0`), che chiudono il candidato. Le disattivazioni silent di `Plugin_Upgrader` non scattano l'hook, quindi gli update non producono righe `deactivated` spurie.
- **Rete di sicurezza per le cancellazioni fuori da WordPress (FTP, pannello hosting).** `wphc_perform_reactivate()` scarta i candidati il cui file non esiste più: in dry-run li esclude soltanto, in esecuzione reale scrive una sola riga `deleted` per ciascuno (che non compare più alla chiamata successiva) e non li conta in `results`/`failed`.

## [1.32.1] - 2026-09-01

### Fixed

- **Job bulk del core segnalato `stalled` durante un aggiornamento in corso.** Lo `stalled` di `/update/bulk` e `/health` era calcolato solo su `next_run_ts`, aggiornato unicamente a fine tick: un core update (fino a 30 minuti, contro i 10 minuti di grace-period pensati per plugin/temi) veniva quindi segnalato "bloccato" mentre `Core_Upgrader` stava ancora lavorando, inducendo il centro a fermare il polling. Ora un item `running` con `claimed_ts` valorizzato calcola `stall_after_ts` sul proprio timeout (1800s per il core, 600s per plugin/temi) invece che su `next_run_ts`.
- **Job bulk appeso fino a 6 ore se il processo PHP muore a metà di un item.** Il reap degli item `running` orfani girava solo dentro il tick di WP-Cron; `GET /update/bulk` finalizzava un job del genere solo al TTL di 6 ore. Ora la stessa GET fa il reap ad ogni chiamata: il solo polling del centro sblocca un job orfano entro il timeout dell'item, anche su un sito col cron morto.
- **Mutex di drain (300s) più corto del timeout dell'item core (1800s).** Un secondo tick poteva riacquisire il lock a metà di un aggiornamento del core. Il lock viene ora riesteso a 1800s subito prima di `wphc_perform_core_update()`.

## [1.32.0] - 2026-08-31

### Added

- **Aggiornamento del core WordPress nel motore di bulk update.** `POST /update/bulk` accetta ora `{"core":true}` (o `{"items":[{"type":"core"}]}`), in un job **esclusivo**: non può mescolare il core con plugin/temi nella stessa richiesta (`400 wphc_bulk_core_not_exclusive`), perché il core non ha il temp-backup nativo che rende sicuro il rollback di plugin/temi. Segue le stesse regole di retry/backoff degli altri elementi, con una soglia di "elemento interrotto" più larga (30 minuti invece di 10) per via della durata intrinsecamente maggiore dell'operazione. `GET /update/log?source=cron&type=core` mostra ora correttamente le righe generate dal drain (in precedenza `wphc_perform_core_update()` non propagava `source`).
- **`summary.is_multisite` e `summary.comments_pending` in `GET /health`.** Il primo è `is_multisite()`, il secondo il numero di commenti in attesa di moderazione del sito arruolato — entrambi a costo zero, assorbiti dalla micro-cache esistente.
- **Webhook di fleet attivo di default.** Se il campo URL in wp-admin è vuoto, il sito notifica automaticamente `https://hub.mavida.com/api/v1/fleet/webhook/bulk-update` a fine job bulk, senza bisogno di configurare ogni sito uno per uno. Una nuova checkbox "Non inviare notifiche webhook per questo sito" disattiva esplicitamente anche il default.

### Changed

- **Modale del log e dell'endpoint tester nella tab Site Health più larga** (dall'80% della larghezza della pagina invece di un `max-width` fisso di 840px), per leggere le otto colonne del log senza scroll orizzontale.

## [1.31.1] - 2026-08-31

### Fixed

- **`GET /update/bulk`: `items[].from`/`items[].to` sempre `null` per un elemento aggiornato con successo.** `wphc_bulk_apply_outcome()` copiava la versione solo da `outcome['current']`/`outcome['latest']` (usati dai risultati che non scrivono nulla, es. `up_to_date`), mai da `outcome['from']`/`outcome['to']` (usati invece da `wphc_perform_item_update()` per il risultato `updated`). Il job riportava quindi "Aggiornato da vnull a vnull" per ogni elemento riuscito.

## [1.31.0] - 2026-08-13

### Added

- **Stato di auto-update nativo di WordPress esposto in `/health` e
  `/detail/plugins`/`/detail/theme`.** `/health` guadagna `core_auto_update`
  (bool), `core_auto_update_level` (`none|minor|major|all`, quattro valori
  perche' minor e major sono indipendenti — collassarli in tre perderebbe la
  rara combinazione minor-off/major-on), `core_auto_update_blocked_by`
  (`null|file_mods|automatic_updater_disabled`), `plugins_auto_update_enabled`
  e `themes_auto_update_enabled` (gate globale). La derivazione replica
  l'ordine di precedenza del core (`wp_is_file_mod_allowed()` →
  `AUTOMATIC_UPDATER_DISABLED` → option `auto_update_core_{dev,minor,major}`
  → costante `WP_AUTO_UPDATE_CORE` → filtri `allow_{minor,major,dev}_auto_core_updates`)
  senza istanziare `WP_Automatic_Updater` (farebbe un giro sul filesystem
  cercando un checkout VCS, incompatibile col contratto O(1) della rotta —
  documentato come limite noto: su un sito con `.git`/`.svn` il core
  rifiuterebbe comunque l'update indipendentemente da questi flag).
  `/detail/plugins` e `/detail/theme` guadagnano per elemento `auto_update`
  (bool) e `auto_update_forced` (`true|false|null`: null = si applica la
  scelta salvata, altrimenti un filtro di terze parti impone lo stato
  indipendentemente dalla option — distinzione necessaria perche' la
  dashboard deve sapere se un'azione di remediation e' anche solo possibile).
  Nuovi hook di invalidazione cache sulle option `auto_update_plugins`/
  `auto_update_themes`/`auto_update_core_*` (sia `update_option_*` che
  `update_site_option_*`, necessari entrambi per coprire single-site e
  multisite), altrimenti il flag resterebbe stantio fino a un'ora dopo un
  toggle nativo di WordPress.
- **Aggiornamenti bulk (plugin/temi) asincroni via `POST /update/bulk` +
  WP-Cron** (Opzione D di `docs/plugin-update-via-api-analisi.md`, rinviata
  li' come "possibile evoluzione", non vietata). La REST autenticata accoda
  un job; WP-Cron si limita a smaltire una coda gia' autorizzata, elemento
  per elemento, riusando `wphc_perform_item_update()` cosi' com'e' — nessun
  update parte mai senza un trigger autenticato esplicito, coerente con la
  scelta che il sito non ha un cron di auto-update proprio. Il core resta
  sulla rotta sincrona esistente `POST /update/core`: e' l'operazione piu'
  lenta e rischiosa delle tre, con rollback piu' debole, e in un job non
  presidiato un core a meta' e' lo scenario peggiore. Elementi falliti
  vengono ritentati fino a `WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS` (4 = 1
  iniziale + 3 retry) con backoff crescente (0/60/300/900s); una contesa di
  lock con un update singolo concorrente (`locked`) non consuma un
  tentativo. Il drain usa un mutex dedicato (`wp_health_check_bulk_lock`),
  DISTINTO dal lock di update per singolo elemento (che resta interamente
  dentro `wphc_perform_item_update()`), per impedire a due tick dello stesso
  cron di sovrapporsi. Stato esposto in `/health` (`bulk_update`, O(1), con
  un flag `stalled` derivato a lettura — non serve nulla lato sito perche' un
  sito stallato per definizione non puo' segnalarlo da solo) e in dettaglio
  via `GET /update/bulk`. Nuove rotte `POST /update/bulk`,
  `GET /update/bulk`, `POST /update/bulk/cancel`. Modifica minima e
  retrocompatibile a `wphc_perform_item_update()`: nuovo quarto parametro
  `$source` (`'api'|'cron'`), inoltrato alla tabella di log cosi'
  `GET /update/log?source=cron` puo' effettivamente trovare le righe del
  drain. `wphc_record_access()` diventa no-op sotto `wp_doing_cron()` (stessa
  scelta gia' applicata a `/ping`): altrimenti un job da N elementi
  sovrascriverebbe N volte l'audit "ultimo accesso" con un IP di loopback.
- **Webhook firmato di notifica a fine job bulk**, configurabile dalla tab
  Site Health (`wp_health_check_webhook_url`, https obbligatorio, IP letterali
  privati/loopback rifiutati al salvataggio tramite `wphc_ip_is_public()`
  gia' esistente). Firma HMAC-SHA256 speculare (in uscita) al protocollo v2
  di verifica in ingresso: header `X-WPHC-Timestamp`/`X-WPHC-Nonce`/
  `X-WPHC-Signature`/`X-WPHC-Event`/`X-WPHC-Secret-Kid`/`X-WPHC-Delivery`
  (= job id, idempotenza lato ricevente). Retry solo su errori transitori
  (errore di trasporto, HTTP 408/429/5xx — mai su altri 4xx, che significano
  un rifiuto esplicito del ricevente), fino a 3 tentativi con backoff
  5min/30min, ri-firmando ogni volta con timestamp/nonce freschi sullo stesso
  corpo. Un sito non enrollato o revocato non invia nulla (stessa dottrina di
  `wphc_require_token()` in ingresso: un sito revocato tace). Pulsante
  "Invia un webhook di prova" per validare l'integrazione HMAC del ricevente
  prima che giri un job reale.
- **Wiki di documentazione LLM-friendly** rigenerato in `docs/wiki/` per
  riflettere le nuove funzionalita' di questa versione.

## [1.30.1] - 2026-08-13

### Added

- **Wiki di documentazione LLM-friendly** in `docs/wiki/` (generato con
  `graphify`): un articolo per community del grafo di conoscenza del
  repository (rotte REST, protocollo v2, flusso di self-update, design
  update di terze parti, vincoli architetturali, comandi WP-CLI...) con
  `index.md` come punto di ingresso per agenti e sviluppatori. Nessuna
  modifica funzionale al plugin.

## [1.30.0] - 2026-07-30

### Added

- **Protocollo v2: segreto per-sito ruotabile e revocabile** (§5.B di
  `docs/sicurezza-autenticazione-analisi.md`). Fino a oggi il bearer verso il
  plugin era `HMAC-SHA256(url_normalizzato, MASTER_SECRET)`, deterministico e
  identico per tutta la flotta: l'unico modo di invalidare un token trapelato
  era ruotare `MASTER_SECRET`, il che invalidava contemporaneamente ogni sito
  e imponeva un re-enroll globale sincronizzato (§3.4). Due nuove rotte
  firmate Ed25519 come `/enroll` (nessun `permission_callback` a token, la
  firma è verificata nel callback): `POST /rotate` consegna un segreto nuovo
  e sposta l'attuale in `wp_health_check_token_prev` per una finestra di
  grazia di `WP_HEALTH_CHECK_ROTATION_GRACE` secondi (900, nuova costante) —
  senza downtime per la flotta, dato che `wphc_require_token()` accetta
  entrambi i segreti finché la finestra resta aperta; `POST /revoke` cancella
  entrambi i segreti e scrive `wp_health_check_revoked_at`, dopo la quale
  ogni rotta dati risponde `403 wphc_revoked` indipendentemente dal token
  fornito, e invalida anche i token di autologin già emessi e non ancora
  consumati (nuovo elenco `wp_health_check_autologin_pending`, tracciato da
  `wphc_track_pending_autologin()`/`wphc_untrack_pending_autologin()`, così
  la revoca può usare `delete_transient()` anche su siti con object cache
  persistente, dove una query diretta su `wp_options` non basterebbe).
- **Anti-replay sulle chiamate operative** (§3.6): tre header nuovi,
  `X-WPHC-Timestamp`/`X-WPHC-Nonce`/`X-WPHC-Signature`
  (`HMAC-SHA256(secret, METODO \n ROTTA \n sha256(body) \n timestamp \n nonce)`,
  base64url), verificati da `wphc_verify_request_signature()` con finestra di
  freschezza `WP_HEALTH_CHECK_REPLAY_WINDOW` (±300s) e nonce indicizzato per
  hash SHA-256 in un transient dedicato (`WP_HEALTH_CHECK_NONCE_TTL`, 600s) —
  stesso pattern già collaudato dal token di autologin. Obbligatori quando
  `wp_health_check_protocol` (nuova opzione per-sito, default `1`) è `2`;
  verificati comunque se presenti anche a protocollo `1`, per un rollout
  dual-stack: un centro aggiornato può iniziare a firmare prima che il sito
  completi la rotazione. `/enroll` guadagna un secondo formato (`protocol: 2`
  nel body) con `nonce` e finestra di freschezza su `issued_at`: con la
  rotazione introdotta, questo non è più l'irrobustimento "opzionale e non
  implementato" del README — una busta di enroll vecchia riprodotta potrebbe
  riportare il sito a un segreto già ruotato.
- **Rate limiting sui tentativi di autenticazione falliti** (§3.12): nuova
  `wphc_throttle_check()`, transient `wphc_fail_<sha1(ip)>` incrementato solo
  sui fallimenti (mai sulle richieste autenticate con successo, per non
  gonfiare `wp_options` sui siti senza object cache persistente), soglia
  `WP_HEALTH_CHECK_RATE_MAX_FAILS` (10) in `WP_HEALTH_CHECK_RATE_WINDOW`
  secondi (300) oltre la quale si risponde `429` con header `Retry-After`.
- **Gate HTTPS su `/enroll`, `/rotate`, `/revoke`**: nuova `wphc_require_https()`,
  `403 wphc_https_required` se `! is_ssl()`, con lo stesso opt-out già
  previsto per l'IP dietro proxy fidato (`wp_health_check_trust_proxy`) —
  dietro un reverse proxy `is_ssl()` può risultare falso pur essendo il
  traffico reale in HTTPS.
- **`kid` sulla chiave pubblica Ed25519 del centro** (§3.15): la costante
  storica `WP_HEALTH_CHECK_CENTRAL_PUBKEY` diventa il kid `"k1"`, affiancata
  da `WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2` (vuota finché non serve). Una busta
  senza `kid` assume `"k1"`, quindi tutte le buste `/enroll` v1 esistenti
  continuano a verificare senza modifiche. `wphc_verify_central_signature()`
  centralizza la verifica (stesse tre guardie già corrette che erano inline:
  `base64_decode(..., true)` strict, controllo esplicito delle lunghezze
  `SODIUM_CRYPTO_SIGN_*`, `catch SodiumException` → false), condivisa da
  `/enroll`, `/rotate` e `/revoke`.
- **`GET|POST /thumbnail`**: stato e rigenerazione on-demand della thumbnail
  del sito. `wphc_generate_site_thumbnail()` ritorna ora un array strutturato
  invece di un `int|null` silenzioso: ogni punto di fallimento (prima tutti
  indistinguibili) produce un codice diagnostico esplicito —
  `wphc_thumb_uploads_not_writable` (nuovo preflight su `wp_upload_dir()`,
  causa più frequente e prima non distinguibile da un fallimento di rete),
  `wphc_thumb_download_failed`, `wphc_thumb_not_an_image`,
  `wphc_thumb_animated_gif`, `wphc_thumb_sideload_failed` — persistito in
  `wp_health_check_thumb_error` (schema `at`/`code`/`message`/`provider`,
  stesso schema già usato da `wphc_record_enroll_error()`). Nuovo campo
  `summary.thumbnail_error` in `/health` (solo il codice, non il messaggio
  completo, per non gonfiare il payload polled). `wphc_maybe_generate_thumbnail()`
  accetta ora `$force` per ignorare cooldown e attachment esistente: usata da
  `POST /thumbnail` e dal nuovo pulsante "Rigenera anteprima ora" nella tab
  Site Health (handler sincrono, l'esito è visibile subito, a differenza di
  "Elimina e rigenera" che demanda alla prossima `/health`). URL del servizio
  esterno spostato nella costante `WP_HEALTH_CHECK_THUMB_SERVICE` (default
  thum.io, invariato), per permettere a un singolo sito di puntare a un
  renderer diverso da `wp-config.php`.
- **Segreto di flotta visibile nella tab Site Health**: nuova riga "Segreto
  di flotta", mascherata per default (`<primi 6>••••••••<ultimi 4>` più
  fingerprint `sha256:<primi 12 hex>`, data di rotazione e protocollo).
  Pulsante "Mostra" → nuovo handler AJAX `wphc_ajax_reveal_secret`
  (`check_ajax_referer`, `manage_options` e — su multisite — anche
  `manage_network`, dato che il segreto è condiviso da tutta la rete): il
  valore in chiaro raggiunge il browser SOLO in risposta a questa chiamata,
  mai stampato nell'HTML della pagina. Ogni reveal scrive una riga
  `type='token', message='secret_revealed'` in `wphc_update_log` (nessuna
  migrazione: le colonne `source`/`actor` esistono già dalla 1.29.0). Nessun
  pulsante di rotazione: il sito non possiede `MASTER_SECRET` e non può
  auto-assegnarsi un segreto, la rotazione resta un'operazione del centro.
- **`wp health-check secret [--show]`** e **`wp health-check status`**:
  nuovi sottocomandi WP-CLI. Il primo stampa fingerprint/protocollo/data di
  rotazione/stato di revoca, con `--show` anche il segreto in chiaro (recupero
  d'emergenza da shell, dove non c'è browser da cui esfiltrarlo); il secondo
  un riepilogo diagnostico (enrollment, versione, protocollo, ultimo errore
  di enroll, stato thumbnail) senza aprire wp-admin.

### Fixed

- **`wp_health_check_last_autologin` sopravviveva a `wphc_reset_enrollment()`**
  (§3.16): scritta e mai letta da nessuna rotta, non era nell'elenco delle
  opzioni cancellate dal reset. Aggiunta all'elenco insieme a tutte le nuove
  opzioni del protocollo v2 (`wp_health_check_token_prev`,
  `_token_rotated_at`, `_protocol`, `_revoked_at`, `_secret_kid`,
  `_autologin_pending`).

### Changed

- `wphc_require_token()` accetta ora, oltre al token corrente, anche
  `wp_health_check_token_prev` durante la finestra di grazia di una
  rotazione, e verifica la firma anti-replay quando il sito è su protocollo
  2 (o quando la firma è comunque presente). Un sito con
  `wp_health_check_revoked_at` valorizzata riceve `403` da ogni rotta dati
  prima di qualunque altro controllo.
- `Access-Control-Allow-Headers` include ora anche `X-WPHC-Timestamp`,
  `X-WPHC-Nonce`, `X-WPHC-Signature`.

## [1.29.0] - 2026-07-27

### Added

- **`summary.public_ip` e `server.public_ip`** (`/health` e `/detail/server`):
  IP pubblico di uscita del server, per i casi (dietro reverse proxy/load
  balancer/container) in cui `server_ip` (letto da `SERVER_ADDR`) è un
  indirizzo di rete interna (es. `192.168.60.40`), inutile per identificare
  il sito dall'esterno. Se `SERVER_ADDR` è già pubblico viene riusato a
  costo zero; altrimenti si risolve una sola volta via
  [api.ipify.org](https://www.ipify.org/), con esito persistito in un
  transient di 7 giorni e un cooldown di 1 giorno sui fallimenti (stesso
  pattern anti-retry-loop già usato per la thumbnail del sito). Nuovo campo
  booleano `summary.server_ip_is_private`/`server.server_ip_is_private` per
  spiegare alla dashboard perché i due IP differiscono, senza dover
  ripetere la logica di validazione lato client. Il pulsante "Svuota cache
  e ricontrolla" ora cancella anche questi due transient, per forzare una
  nuova risoluzione dopo una migrazione di hosting.
- **Log degli aggiornamenti fatti da WordPress stesso**, non solo di quelli
  passati dalla rotta REST: due colonne nuove nella tabella
  `wphc_update_log`, `source` (`api` | `wp-admin` | `cron` | `wp-cli`) e
  `actor` (lo `user_login` di chi ha avviato l'operazione, `null` per
  cron/CLI). Plugin e temi si agganciano a `upgrader_pre_install`/
  `upgrader_process_complete` (con un flag di richiesta che esclude gli
  update già loggati dal flusso API, per non registrarli due volte); il
  core, che non offre un hook altrettanto affidabile, si rileva per
  divergenza fra la versione osservata e l'ultima vista, controllata a ogni
  `init` (stesso pattern O(1) già usato per l'installazione dello schema) —
  approccio che intercetta anche gli update core fatti via FTP o dal
  pannello dell'hosting, non passati da nessun hook di WordPress. A
  differenza del pattern a due righe del flusso API (che prova che un
  update è stato *avviato* anche se PHP muore a metà), questi update sono
  loggati con una sola riga `completed`, perché l'hook scatta a esito già
  riuscito. `GET /update/log` accetta ora anche il filtro `?source=`, e
  `wp_health_check_db_version` passa da `3` a `4` (`dbDelta()` applica il
  `DEFAULT 'api'` delle due colonne a tutte le righe storiche esistenti,
  nessuna migrazione dati manuale necessaria).
- **`last_login`, `last_login_ip` e `registered`** in `GET /detail/users`:
  data dell'ultimo accesso riuscito (in due user meta scritte sull'azione
  `wp_login`), IP di quell'accesso (stessa fonte già usata per la colonna
  `ip` della tabella di log, rispetta `wp_health_check_trust_proxy`) e data
  di registrazione dell'account (già disponibile in `wp_users`, aggiunta
  senza costo). `last_login` resta `null` finché un utente non effettua un
  accesso dopo l'aggiornamento a questa versione dell'agent: non va letto
  come "account dormiente" prima che sia passato abbastanza tempo da
  escludere semplicemente questo caso.
- **Pulsante "Visualizza log"** nella tab Site Health: apre in una modale
  (la stessa già usata dal tester degli endpoint) la stessa tabella
  restituita da `GET /update/log`, filtrabile per tipo e per origine, con
  paginazione "Carica altri 50". Nuovo handler AJAX
  `wphc_ajax_view_log` (nonce dedicato, `manage_options`), query diretta
  sulla tabella (nessun loopback REST: l'utente è già autenticato in
  wp-admin, non serve il bearer token).

## [1.28.0] - 2026-07-23

### Fixed

- **`summary.thumbnail`**: il servizio thum.io, senza le opzioni `noanimate`
  e `png`, risponde in streaming con un GIF animato (spinner + render
  progressivo del sito) pensato per essere mostrato dal vivo, non salvato
  come file — è per questo che la 1.27.0 salvava un `.gif` invece di
  un'immagine statica. L'URL ora usa `noanimate`/`png` per ottenere il PNG
  finale, e la generazione scarta esplicitamente un eventuale GIF residuo
  (nuovo tentativo al prossimo cooldown scaduto). Anche l'URL sorgente è
  cambiato da `get_site_url()` (indirizzo WordPress) a `get_home_url()` (home
  pubblica del sito), quella effettivamente da fotografare. Rimossa
  l'opzione `crop` (fraintesa come "ritaglio quadrato": è in realtà l'altezza
  in pixel dello screenshot sorgente): l'immagine ora ha larghezza 400px e
  altezza proporzionale; l'anteprima in admin non forza più un'altezza fissa,
  per non deformarla.

### Added

- **Pulsante "Elimina e rigenera"** nella riga "Anteprima sito" della tabella
  informativa del plugin (scheda Site Health): elimina l'attachment dal
  Media Library e le opzioni `wp_health_check_thumb`/`wp_health_check_thumb_id`,
  cosi' la thumbnail viene rigenerata alla prossima chiamata `/health` senza
  attendere il cooldown. Nuova opzione `wp_health_check_thumb_id` (accanto
  a `wp_health_check_thumb`) per permettere l'eliminazione affidabile
  dell'attachment.

## [1.27.0] - 2026-07-23

### Added

- **`summary.thumbnail` di `GET /health`**: screenshot 400×400 del sito,
  generato via [thum.io](https://thum.io/) e caricato nel Media Library alla
  prima chiamata con `wp_health_check_thumb` vuota; l'URL assoluto viene poi
  persistito in quell'opzione, cosi' le chiamate successive restano O(1)
  (nessuna nuova chiamata remota). Un transient di cooldown
  (`wphc_thumb_retry_lock`, 1 giorno) protegge da retry-loop se thum.io non
  risponde. Aggiunta anche una riga "Anteprima sito" (200×200) nella tabella
  informativa del plugin nella scheda Site Health.

## [1.26.0] - 2026-07-22

### Added

- **`GET /ping`**: nuova rotta heartbeat volutamente minimale, per un
  monitoraggio esterno ad alta frequenza (uptime + tempo di risposta) che
  non ha bisogno del sommario completo di `/health`. Verificato che il
  costo di `/health` non sia trascurabile per questo caso d'uso: anche a
  cache calda (transient 60s) scrive sempre `wp_health_check_last_request_*`
  (`wphc_record_access()`), e a cache fredda — di fatto sempre, per un
  polling a cadenza di minuti più lunga del TTL — esegue `get_plugins()` e
  `wp_get_themes()`, le uniche due operazioni con una vera scansione di
  filesystem dell'intera rotta. `/ping` non chiama mai queste due funzioni,
  non scrive mai su `wp_options`, e non usa alcuna cache (il suo scopo è
  misurare il tempo di risposta della chiamata stessa). Stessa
  autenticazione bearer token delle altre rotte dati. Risposta:
  `{ status, site, agent_version, generated_at }`.

## [1.25.0] - 2026-07-20

### Added

- **Audit trail per l'autologin**: `POST /autologin/token` e il consumo del
  token (`wphc_maybe_consume_autologin()`) scrivono ora ciascuno una riga
  nella tabella di log esistente (`wphc_update_log`, la stessa di
  `GET /update/log`), invece di lasciare traccia solo nell'opzione
  `wp_health_check_last_autologin` (che continua a esistere, ma conserva solo
  l'ultima richiesta). Nuovi valori del campo `type`: `token` (alla
  richiesta) e `login` (al consumo, riuscito o fallito), collegati dallo
  stesso `correlation_id` quando l'identità è recuperabile. Per queste righe
  `target` è lo `user_login` dell'utente coinvolto e `name` il suo
  `display_name` (fallback `user_login`); su un consumo fallito con token
  del tutto sconosciuto/scaduto (nessuna identità recuperabile), `target` è
  un prefisso dell'hash del token e il `correlation_id` è nuovo, non
  collegato ad alcuna riga `token` precedente. Riusa `phase` `completed`/
  `failed` già esistenti, nessun nuovo valore. Nessuna modifica di schema:
  `type VARCHAR(10)` ospita già `token`/`login`.
- L'allowlist del filtro `?type=` di `GET /update/log` (`wphc_route_update_log()`)
  è stata estesa con `token`/`login`, altrimenti il filtro sarebbe stato
  silenziosamente ignorato per questi due valori (nessun filtro applicato,
  non un errore).

## [1.24.0] - 2026-07-20

### Changed

- **Inversione del default della restrizione host su `POST /update/plugin`,
  `POST /update/theme` e `POST /update/core`**: di default è ora aggiornabile
  **qualsiasi** plugin/tema, inclusi quelli **premium** (aggiornati da server
  propri) — fino alla `1.23.0` la allowlist (`downloads.wordpress.org`/
  `api.wordpress.org`) era hardcoded e sempre attiva, rifiutando sempre i
  premium con `result: "not_updatable"`. Non è un indebolimento del vincolo
  di sicurezza non negoziabile (nessun `package_url`/`version` accettato nel
  payload): l'host controllato non è mai un valore fornito dalla richiesta
  REST, ma quello che il sistema di aggiornamento del sito stesso ha già
  determinato (transient del core, o di un update-checker premium già
  attivo). La allowlist storica resta disponibile come **opzione per sito**.

### Added

- Nuovo checkbox nella tab Site Health, nello stesso form del kill-switch
  aggiornamenti: "Limita gli aggiornamenti ai soli pacchetti ospitati su
  wordpress.org (esclude i plugin/temi premium)" (opzione
  `wp_health_check_restrict_official_only`, spenta di default; persiste a un
  reset enrollment come `wp_health_check_updates_enabled`).
- `GET /health` → `summary`: nuovo booleano `restrict_official_only`, stato
  della nuova opzione.

## [1.23.0] - 2026-07-19

### Added

- `GET /detail/users`: nuova rotta che elenca gli amministratori del sito
  (`id`, `user_login`, `display_name`, `email`). Su multisite include anche i
  super admin di rete (deduplicati per `user_login`), che possono avere
  accesso pieno senza il ruolo `administrator` sul singolo blog. Nessuna
  cache transient: dato anagrafico a bassa frequenza di interrogazione.
- `POST /update/reactivate`: nuova rotta di riconciliazione a posteriori
  dello stato attivo dei plugin. Su alcuni siti un plugin può restare
  disattivato dopo un aggiornamento per cause esterne al flusso di update
  stesso (la rete di sicurezza già presente in `POST /update/plugin` copre
  solo la disattivazione avvenuta durante l'update immediatamente
  precedente). La nuova rotta confronta, per ogni plugin, l'ultimo stato
  "atteso attivo" registrato nella tabella di log (`wphc_get_reactivation_candidates()`,
  basata sulla riga più recente con `active` valorizzato per quel plugin) con
  lo stato reale corrente (`is_plugin_active()`); per ogni discrepanza tenta
  la riattivazione (`activate_plugin()`) registrando sempre una riga di log
  per il tentativo (`phase` `reactivated` o `reactivation_failed`). Su un
  fallimento la riga viene scritta con `active = NULL` (non `false`), cosi'
  la discrepanza resta rilevabile e viene ritentata alla chiamata successiva.
  Supporta `?check=1` per un dry-run di sola lettura (nessun kill-switch/lock
  richiesto); l'esecuzione reale richiede invece il kill-switch
  `wp_health_check_updates_enabled` e il lock anti-concorrenza condivisi con
  le altre rotte di update.
- Tabella di log degli aggiornamenti: colonna `phase` allargata da
  `VARCHAR(16)` a `VARCHAR(32)` (`WP_HEALTH_CHECK_DB_VERSION` `2` → `3`), per
  ospitare i nuovi valori `reactivated`/`reactivation_failed`.

## [1.22.0] - 2026-07-17

### Added

- `POST /autologin/token`: nuova rotta per aprire `wp-admin` già autenticati
  con un click dalla dashboard. Protetta da `manage_options` (tipicamente
  Application Password), non dal bearer token — l'identità autenticata è
  quella che verrà loggata, risolta nativamente da WordPress. Genera un
  token one-time (256 bit, `random_bytes()`) con TTL 20s
  (`WP_HEALTH_CHECK_AUTOLOGIN_TTL`), indicizzato via hash SHA-256 in un
  transient. Il consumo avviene fuori dalla REST API, via
  `wphc_maybe_consume_autologin()` agganciata su `init`: verifica e
  cancella subito il token (single-use), poi `wp_set_auth_cookie()` e
  redirect fisso a `admin_url()`.

## [1.21.0] - 2026-07-14

### Added

- Tabella di log degli aggiornamenti: nuova colonna `active` (bool, nullable),
  che registra lo stato attivo di un plugin nel momento della riga di log
  (`requested`/`completed`), sempre `NULL` per temi/core. Esposta anche in
  `GET /update/log` (campo `active` per elemento).
- `POST /update/plugin`: controllo più robusto dopo un update riuscito —
  verifica che un plugin **già attivo prima** dell'update **resti attivo
  dopo** (`is_plugin_active()`/`is_plugin_active_for_network()` per il caso
  multisite). In teoria `Plugin_Upgrader::upgrade()` non tocca mai l'opzione
  `active_plugins`, ma una disattivazione può comunque avvenire per cause
  esterne (plugin di sicurezza/hosting, un main file rinominato dalla nuova
  versione...). Se il plugin risulta disattivato, si tenta una
  riattivazione automatica nello stesso ambito di prima (rete o singolo
  sito); se anche questa fallisce, la rotta risponde `updated: true` +
  `result: "reactivation_failed"` + `detail` con il messaggio d'errore
  (stesso pattern "risultato + dettaglio" di `not_updatable`), invece di
  dichiarare un successo pieno che nasconderebbe il problema.
- `GET /health` → `summary`: nuovo booleano `has_ecommerce` (plugin
  e-commerce attivo: WooCommerce o Easy Digital Downloads), calcolato in
  `wphc_detect_site_signals()` sullo stesso modello di `has_gdpr`/`has_builder`.

## [1.20.0] - 2026-07-14

### Fixed

- Dopo un `POST /update/plugin`/`/update/theme` riuscito, il codice cancellava
  l'intero transient `update_plugins`/`update_themes`
  (`wp_clean_plugins_cache( true )`/`wp_clean_themes_cache( true )`) invece di
  correggere solo la entry dell'elemento appena aggiornato. Risultato: dopo
  un update via API, `/health` e `/detail/plugins`/`/detail/theme`
  riportavano "tutto aggiornato" per **tutti** i plugin/temi (non solo per
  quello appena toccato) finché il cron (`wp_update_plugins`, ~2 volte al
  giorno) o una visita a `wp-admin` non ripopolavano il transient — nemmeno
  `?fresh=1` lo correggeva, dato che quella rotta legge di proposito solo il
  transient del cron. Ora, solo sull'esito `completed`, viene rimossa
  chirurgicamente la sola entry dell'elemento da `->response` (con
  `->checked` aggiornato alla nuova versione), lasciando intatte tutte le
  altre righe pendenti, incluse quelle dei plugin/temi **premium**
  mantenute dal cron. Non si è optato per richiamare
  `wp_update_plugins()`/`wp_update_themes()` (soluzione più diretta ma già
  scartata nella `1.13.0`/`1.16.0`): in contesto REST quelle funzioni non
  caricano gli update-checker premium e avrebbero sovrascritto il transient
  completo del cron con uno incompleto. Su `rolled_back`/`failed` il
  transient non viene toccato: l'update è ancora effettivamente pendente
  (rollback) o lo stato è incerto (failed).
- `POST /update/core` riusciti lasciavano `summary.core_update` a `true`
  fino al prossimo `wp_version_check()` da cron. A differenza di
  plugin/temi, per il core non esiste un equivalente "update-checker
  premium": forzare un ricontrollo reale (`wp_version_check( array(), true )`)
  subito dopo un update riuscito è quindi sicuro anche in contesto REST, e
  ripopola `update_core` immediatamente.

## [1.19.0] - 2026-07-14

### Added

- `GET /detail/plugins`: nuovo campo `file` per elemento (plugin file, chiave
  di `get_plugins()`, es. `wordpress-seo/wp-seo.php`) — il valore esatto da
  passare come `plugin` a `POST /update/plugin`, a differenza di `slug` (solo
  la cartella, non univoco per i plugin a file singolo).

### Changed

- Il kill-switch `wp_health_check_updates_enabled` nasce ora **acceso** di
  default (era spento). Resta disattivabile per singolo sito dal checkbox
  nella tab Site Health; l'opzione esplicitamente impostata su un sito (in
  un senso o nell'altro) non viene toccata da questa modifica, che riguarda
  solo il valore di default per i siti su cui l'opzione non è mai stata
  salvata.
- Tab Site Health: il pulsante "Salva" degli aggiornamenti via API è ora
  posizionato sotto il checkbox (era sulla stessa riga), coerente con lo
  stile degli altri form della pagina.

## [1.18.0] - 2026-07-14

### Added

- Aggiornamento di **plugin, temi e core del sito** (non solo dell'agent)
  tramite quattro nuove rotte REST, distinte dal self-update esistente:
  - `POST /update/plugin` e `POST /update/theme`: aggiornano un singolo
    elemento già installato, esclusivamente da wordpress.org, tramite
    `Plugin_Upgrader`/`Theme_Upgrader` con rollback via temp-backup nativo
    (richiede WordPress ≥ 6.3; sotto, `unsupported_wp_version`). Il payload
    indica solo *quale* elemento aggiornare (`plugin`/`theme`), mai una
    sorgente o versione: quelle vengono sempre lette dal transient di update
    che il core stesso popola da `api.wordpress.org`. Query `?check=1` per un
    dry-run.
  - `POST /update/core`: aggiorna il core alla versione che WordPress ha già
    determinato disponibile, tramite `Core_Upgrader` (rollback nativo diverso
    e più debole rispetto a plugin/temi); completa esplicitamente l'upgrade
    del database con `wp_upgrade()` dopo la sostituzione dei file, necessario
    in un contesto headless.
  - `GET /update/log`: lettura paginata (`type`, `limit`, `offset`) della
    nuova tabella custom `{$wpdb->prefix}wphc_update_log`, sempre accessibile
    anche a kill-switch spento. Ogni operazione scrive due righe con lo
    stesso `correlation_id` (una prima dell'update, `phase: requested`; una
    al termine, `completed`/`failed`/`rolled_back`), con prune opportunistico
    delle righe più vecchie di 90 giorni.
  - Le tre rotte di update sono protette, oltre che dal bearer token, da un
    **kill-switch master per sito** (`wp_health_check_updates_enabled`,
    spento di default), con un checkbox dedicato nella tab Site Health
    ("Consenti aggiornamenti (plugin, temi, core) via API"); a spento,
    rispondono `403 disabled` prima di qualunque elaborazione.
  - Allowlist esplicita dell'host del pacchetto (`downloads.wordpress.org`,
    `api.wordpress.org`): i plugin/temi **premium** (aggiornati da server
    propri) sono sempre rifiutati con `not_updatable` in questa versione.
  - Lock anti-concorrenza (`wp_health_check_update_lock`, TTL 300s, rilascio
    garantito anche via `register_shutdown_function`), preflight filesystem
    (`get_filesystem_method() === 'direct'`, altrimenti
    `fs_method_unavailable`, mai richieste credenziali FTP/SSH) e pulizia
    difensiva di un eventuale `.maintenance` orfano, condivisi da tutte le
    rotte di update di terze parti.
- `GET /health` → `summary`: tre nuovi campi derivati dalla funzionalità sopra,
  tutti O(1): `updates_via_api_enabled` (stato del kill-switch), `last_update`
  (ultimo esito, letto da un'opzione autoloaded, non da una query alla
  tabella di log) e `maintenance_stuck` (`true` se un `.maintenance` orfano è
  presente da più di 10 minuti).
- `installer/`: plugin normale "WP Health Check Installer" (+ ZIP) che
  automatizza il primo deploy del mu-plugin. All'attivazione: se
  `mu-plugins/wp-health-check.php` esiste già non fa nulla (notice), altrimenti
  verifica/crea `mu-plugins`, controlla i permessi, scarica l'ultima release da
  GitHub (con verifica SHA-256) e la installa; in caso di errore lascia una
  notice con il motivo. Non fa parte del mu-plugin (`wp-health-check.php`), è un
  aiuto all'installazione.

### Changed

- `wphc_reset_enrollment()` cancella ora anche il lock anti-concorrenza degli
  aggiornamenti (`wp_health_check_update_lock`); lo storico della tabella di
  log e il kill-switch **non** vengono toccati dal reset, perché sono
  audit/config del sito, non stato di enrollment.

## [1.17.0] - 2026-07-13

### Added

- `GET /detail/theme`: nuovo array `themes` con l'elenco completo dei temi
  installati sul sito (non solo l'attivo). Ogni voce espone `name`,
  `stylesheet`, `version`, `active`, `parent` (stylesheet del parent per i
  child theme, altrimenti `null`), `update_available` e `new_version`. I campi
  `active_theme`/`parent_theme` restano invariati per retrocompatibilità. Lo
  stato aggiornamenti riusa i dati già letti dalla rotta, senza accessi
  aggiuntivi ai transient.
- `GET /health` → `summary`: nuovi booleani `has_gdpr` (consent manager GDPR
  attivo: iubenda o Cookiebot) e `has_builder` (page builder attivo: plugin
  Elementor o tema attivo/parent DIVI), calcolati dai plugin/temi attivi. Gli
  slug riconosciuti vivono in un unico punto (`wphc_detect_site_signals()`),
  così è facile aggiungerne altri senza toccare il resto del plugin.

## [1.16.0] - 2026-07-13

### Fixed

- `plugins_updates`/`themes_updates`/`core_update` (in `/health`) e
  `update_available`/`new_version` (in `/detail/plugins` e `/detail/theme`)
  potevano risultare 0/false in contesto REST anche con aggiornamenti reali,
  su siti che disabilitano i controlli update fuori dall'admin registrando
  `add_filter( 'pre_site_transient_update_plugins', '__return_null' )` (e
  analoghi per temi/core). Quel filtro cortocircuita
  `get_site_transient( 'update_plugins' )` facendogli restituire `null` in
  frontend/REST, mentre in admin non e' attivo (da qui la discrepanza:
  admin mostrava 1, `/health` 0). Ora le rotte dati neutralizzano
  temporaneamente SOLO gli short-circuit `pre_site_transient_update_*` prima
  di leggere i transient (ripristinandoli subito dopo), mantenendo attive le
  iniezioni legittime dei plugin premium (es. ACF, Gravity Forms) sui filtri
  di lettura. Diagnosticato con la rotta `/debug` introdotta nella 1.15.0.

### Added

- `GET /debug`: nuovo campo `health_plugins_updates` con il conteggio che
  `/health` riporta dopo il fix (per verificare la correzione senza il bearer
  token).

## [1.15.0] - 2026-07-13

### Added

- Rotta diagnostica `GET /health-check/v1/debug` (gated su `manage_options`,
  quindi chiamabile con una application password, non col bearer token).
  Confronta il transient `update_plugins` FILTRATO (cio' che legge `/health`)
  con quello GREZZO memorizzato (senza i filtri di terze parti) ed elenca i
  callback registrati sui filtri `site_transient_update_plugins` /
  `pre_set_site_transient_update_plugins`. Serve a diagnosticare discrepanze
  fra il numero di aggiornamenti plugin visto in admin e quello via REST
  (tipicamente causate da plugin che modificano quei filtri in modo diverso a
  seconda del contesto admin/REST).

## [1.14.0] - 2026-07-10

### Added

- Tab Site Health: sezione **"Riepilogo plugin e temi"** sotto la tabella
  principale — plugin e temi con totali, attivi e da aggiornare, letti in
  contesto amministrativo (rispecchiano la bacheca).
- Tab Site Health: sezione **"Test degli endpoint"** — un pulsante per ciascun
  endpoint dati GET (`/health`, `/detail/*`, con varianti `?fresh=1`) che esegue
  una chiamata reale e ne mostra il risultato (HTTP status, latenza, JSON
  formattato) in una modale. La chiamata avviene via loopback lato server
  (handler AJAX `wphc_test_endpoint`, `manage_options` + nonce), con bearer token
  aggiunto server-side (mai esposto nel browser) e cache-buster `_cb=<random>`
  casuale ad ogni chiamata.

## [1.13.0] - 2026-07-09

### Added

- Tab Site Health: pulsante "Svuota cache e ricontrolla aggiornamenti". Cancella
  le cache dell'agent (transient `wphc_*`) e forza un ricontrollo COMPLETO degli
  aggiornamenti di core/plugin/temi. Gira in contesto amministrativo, dove gli
  update-checker dei plugin/temi premium sono attivi, quindi ricostruisce
  transient di update completi (a differenza di `?fresh=1` via REST).

### Fixed

- `plugins_updates` (e `themes_updates`, `new_version`) potevano risultare 0 /
  errati con `?fresh=1` in presenza di plugin/temi **premium**. Causa: `?fresh=1`
  chiamava `wp_update_plugins()`/`wp_update_themes()`/`wp_version_check()` in
  contesto REST, dove i plugin/temi premium (che si aggiornano da server propri,
  non da wordpress.org) non caricano i loro update-checker; quella chiamata
  ricostruiva il transient degli update SENZA i loro aggiornamenti e
  **sovrascriveva** quello completo mantenuto dal cron, riportando conteggi
  errati (es. `plugins_updates: 0` con 11 aggiornamenti reali) e corrompendo
  anche il dato mostrato in bacheca.
- `?fresh=1` non forza più alcun controllo remoto: bypassa solo le cache locali
  (payload wphc + liste plugin/temi via `wp_clean_plugins_cache( false )` /
  `wp_clean_themes_cache( false )`, per totali corretti) e legge i transient
  degli update mantenuti dal cron (`update_plugins`/`update_themes`/
  `update_core`), gli stessi della bacheca. La freschezza dell'ultimo check
  resta esposta in `summary.updates_checked_at`.

## [1.12.0] - 2026-07-09

### Fixed

- Conteggi plugin/temi potenzialmente stale dietro un object cache persistente
  mal configurato (che rende persistente il gruppo di cache `plugins`/`themes`):
  `?fresh=1` su `/health` e `/detail/plugins` ora svuota le cache delle liste
  (`wp_clean_plugins_cache( false )` / `wp_clean_themes_cache( false )`) prima
  di ricontare, così `get_plugins()`/`wp_get_themes()` riscansionano la
  cartella e `plugins_total` / `count` / `themes_total` risultano corretti.
  La logica di conteggio in sé era già corretta; il problema era la freschezza
  dei dati (cache dei payload: `/detail/plugins` 1h, `/health` 60s; più il
  transient degli update mantenuto dal cron). Per dati autorevoli usare
  sempre `?fresh=1`.

## [1.11.0] - 2026-07-09

### Added

- Quando un enroll fallisce per URL mismatch (`wphc_enroll_url_mismatch`), oltre
  a registrare il dettaglio in `wp_health_check_last_enroll_error`, il plugin
  invia un'email di alert a `WP_HEALTH_CHECK_ALERT_EMAIL` (nuova costante di
  flotta, default `maurizio@mavida.com`; stringa vuota per disabilitare) con
  URL ricevuto, URL atteso, IP, timestamp e l'elenco completo degli URL validi
  per l'enroll. Rate-limit di un invio all'ora per sito (transient anti-flood).
  L'email riguarda solo il mismatch URL, non gli altri fallimenti; il ramo è
  raggiungibile solo con firma Ed25519 valida. Usa `wp_mail()` del core, nessuna
  dipendenza esterna.

## [1.10.0] - 2026-07-09

### Added

- Tab Site Health: pulsante di **self-update** del plugin (stesso flusso di
  `POST /update` via la nuova funzione condivisa `wphc_perform_self_update()`),
  con verifica dell'**ultima versione disponibile** su GitHub (cachata 1h,
  `wphc_get_latest_version()`) ed evidenziazione se esiste un aggiornamento.
- Tab Site Health: riga con gli **URL validi per l'enroll**
  (`wphc_candidate_site_urls()`, "principale" evidenziato), per sapere con
  quale URL il centro deve firmare la busta.
- Nuova opzione `wp_health_check_last_enroll_error`: registra il motivo
  dell'**ultimo enroll fallito** (codice, motivo, URL inviato, timestamp, IP),
  mostrato nella tab e azzerato automaticamente al primo enroll riuscito.
  Registra tutti i tipi di fallimento (corpo non valido, firma non valida,
  URL mismatch).

### Changed

- La logica di self-update è stata estratta in `wphc_perform_self_update()`,
  condivisa fra la rotta REST `POST /update` (contratto di risposta invariato)
  e il pulsante nella tab Site Health.

### Removed

- Tab Site Health: rimossa la sezione per modificare
  `wp_health_check_dashboard_origin` dalla UI (le chiamate alla flotta sono ora
  server-to-server; l'opzione e la logica CORS restano nel plugin, popolate
  dall'enroll). Rimosso l'helper orfano `wphc_is_valid_origin()`.

## [1.9.0] - 2026-07-09

### Changed

- `POST /enroll`: il confronto tra il `site_url` firmato e l'URL del sito è ora
  **tollerante**. Il sito costruisce un set di URL canonici candidati
  (`home_url()`, `site_url()`, `network_home_url()`, `network_site_url()`,
  ciascuno con/senza `www.`, tutti normalizzati) e accetta l'enroll se il
  `site_url` firmato normalizzato è nel set. Risolve i 403 spurii su siti WPML
  (dove `home_url()` varia per lingua), dietro reverse proxy o con varianti
  www/non-www. La verifica della firma resta prima e obbligatoria.
- Il codice di errore del mismatch URL passa da `wphc_enroll_site_mismatch` a
  `wphc_enroll_url_mismatch` (`403`), che ora espone nel `message` l'URL atteso
  e nei campi `data.expected` / `data.received` atteso e ricevuto.
- Il campo `site` della risposta di `/enroll` è ora il `site_url` firmato
  realmente registrato (la chiave a cui è legato il token), non più
  `home_url()` normalizzato.

### Added

- Opzione `wp_health_check_site_url`: memorizza esattamente il `site_url`
  firmato ricevuto in fase di enroll. Azzerata dal reset (WP-CLI e tab Site
  Health) e mostrata come riga di sola lettura nella tab Site Health.

## [1.8.0] - 2026-07-09

### Added

- Nuovi campi nel `summary` di `GET /health`: `php_memory_limit`, `server_ip`
  (IP del server WordPress da `SERVER_ADDR`, `null` se non determinabile),
  `themes_total` (numero di temi installati), `theme_name` (nome del tema
  attivo) e `parent_theme_name` (nome del tema parent, `null` se il tema
  attivo non è un child theme).
- Campo `server_ip` anche nella sezione `server` di `GET /detail/server`.

## [1.7.0] - 2026-07-08

### Fixed

- `GET /health` riportava sempre `plugins_updates: 0`, `themes_updates: 0` e
  `core_update: false` anche con aggiornamenti realmente disponibili
  (osservato in produzione: `/detail/plugins` mostrava correttamente un
  aggiornamento disponibile per lo stesso sito). Causa: i conteggi di
  `wp_get_update_data()` sono condizionati da
  `current_user_can( 'update_plugins'/'update_themes'/'update_core' )`, che
  in questa rotta vale sempre `false` — l'autenticazione è il bearer token,
  non una sessione utente WordPress. Ora i conteggi vengono letti
  direttamente dagli stessi transient di update mantenuti dal cron
  (`update_plugins`, `update_themes`, `get_core_updates()`), con la stessa
  logica di `wp_get_update_data()` ma senza il controllo di capability.

## [1.6.0] - 2026-07-08

### Fixed

- Il default del core REST API di WordPress (`rest_send_cors_headers()`, che
  riflette qualunque `Origin` con `Access-Control-Allow-Credentials: true`
  per l'intera REST API) girava dopo la logica di questo plugin e la
  sovrascriveva, vanificando di fatto la restrizione su
  `wp_health_check_dashboard_origin`. `wphc_maybe_send_cors_headers()` viene
  ora richiamata una seconda volta su `rest_pre_serve_request` (priorità 20,
  dopo il 10 di default del core), limitata al namespace `health-check/v1`,
  rimuovendo prima qualunque header CORS già impostato dal core.

## [1.5.0] - 2026-07-08

### Fixed

- Le rotte di `health-check/v1` ora impediscono esplicitamente la cache
  HTTP/edge lato server (`nocache_headers()` + costante `DONOTCACHEPAGE`),
  inviati da `wphc_maybe_send_cors_headers()` prima di qualunque header CORS.
  Senza questo, un plugin di page-cache (es. LiteSpeed Cache) poteva mettere
  in cache l'intera risposta — inclusi gli header CORS legati all'`Origin`
  del chiamante — e riservirla identica a chiunque altro, causando errori
  CORS incoerenti in produzione (osservato dietro LiteSpeed Cache: lo stesso
  `Access-Control-Allow-Origin` restituito a prescindere dall'`Origin`
  inviato). La cache applicativa via transient del plugin non è interessata
  da questo fix, resta invariata.

## [1.4.0] - 2026-07-08

### Added

- Nuova tab "WP Health Check" in Strumenti → Salute del sito (via
  `site_health_navigation_tabs`/`site_health_tab_content`, disponibili da
  WordPress 5.8), visibile solo a chi ha `manage_options`: versione plugin,
  repository GitHub configurato, stato enrollment, ultimo accesso, stato
  `trust_proxy`, campo per leggere/modificare `wp_health_check_dashboard_origin`
  e pulsante di reset enrollment (equivalente a `wp health-check reset`).
- Funzione condivisa `wphc_reset_enrollment()`, usata sia dal comando WP-CLI
  sia dal nuovo pulsante di reset nella tab Site Health.

## [1.3.0] - 2026-07-08

### Changed

- Con `wp_health_check_dashboard_origin` non configurata, gli header CORS ora
  autorizzano qualunque origin (riflessa in `Access-Control-Allow-Origin`,
  mai un wildcard letterale), invece di non inviare alcun header. Utile in
  fase di setup/sviluppo prima del primo `/enroll`. Non appena
  `dashboard_origin` viene impostata, torna ad essere l'unica origin
  autorizzata, come prima.

## [1.2.0] - 2026-07-08

### Changed

- `POST /update` torna protetta dal bearer token (`wphc_require_token`), come
  le altre rotte dati. Era stata resa temporaneamente pubblica nella 1.1.0 per
  sbloccare i test da un'app/dashboard in sviluppo locale.

## [1.1.0] - 2026-07-08

### Added

- Campo `plugin_version` nel `summary` di `GET /health`, accanto a `wp_version`
  e `php_version`.

### Changed

- `POST /update` è temporaneamente pubblica (nessun controllo bearer token),
  per sbloccare le chiamate dirette da un'app/dashboard in sviluppo locale.
  Va ripristinata l'autenticazione a token non appena il flusso bearer sarà
  di nuovo attivo lato dashboard (vedi nota nel README).

### Fixed

- `GET /detail/server` poteva rispondere `500` se `WP_Debug_Data::debug_data()`
  lanciava un'eccezione diversa da `ImagickException` durante l'introspezione
  dell'ambiente server (es. su alcuni host). Il catch ora copre `Throwable` in
  generale: la rotta non fallisce più, prosegue senza quella sezione.

## [1.0.0] - 2026-07-08

### Added

- Must-use plugin a file singolo (`mu-plugins/wp-health-check.php`), compatibile
  PHP 7.4+ e WordPress 6.4+.
- Bootstrap firmato Ed25519 su `POST /enroll`, senza scritture in `wp-config.php`.
- Modello del token per-sito `base64url(hmac_sha256(url_normalizzato, MASTER_SECRET))`,
  calcolato lato sistema centrale, senza rotazione né scadenza.
- Autenticazione Bearer token in tempo costante (`hash_equals`) su tutte le rotte
  dati.
- Rotte REST nel namespace `health-check/v1`: `/enroll`, `/health`,
  `/detail/plugins`, `/detail/theme`, `/detail/server`, `/update`.
- Tracciamento dell'ultimo accesso autenticato (timestamp + IP), con supporto
  opzionale a `X-Forwarded-For` dietro proxy fidato.
- Self-update firmato dalle release del repository GitHub pubblico, con verifica
  di integrità (SHA-256 + firma), backup automatico e scrittura atomica.
- Caching per-rotta via transient, con `?fresh=1` come unico meccanismo di
  refresh forzato.
- Gestione CORS con origin esplicita (mai wildcard) verso la dashboard registrata
  in fase di enroll.
- Comando WP-CLI `wp health-check reset` per il re-provisioning/offboarding.
- Script `bin/generate-keys.php` per la generazione della coppia di chiavi
  Ed25519 lato sistema centrale.
- Tooling di sviluppo: PHPCS/WPCS + PHPCompatibilityWP, PHPStan con stub
  WordPress, configurazione wp-env.

[Unreleased]: https://github.com/mavidasnc/wp-health-check/compare/v1.34.0...HEAD
[1.34.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.33.0...v1.34.0
[1.30.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.29.0...v1.30.0
[1.29.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.28.0...v1.29.0
[1.28.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.27.0...v1.28.0
[1.27.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.26.0...v1.27.0
[1.26.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.25.0...v1.26.0
[1.25.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.24.0...v1.25.0
[1.24.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.23.0...v1.24.0
[1.23.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.22.0...v1.23.0
[1.22.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.21.0...v1.22.0
[1.21.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.20.0...v1.21.0
[1.20.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.19.0...v1.20.0
[1.19.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.18.0...v1.19.0
[1.18.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.17.0...v1.18.0
[1.17.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.16.0...v1.17.0
[1.16.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.15.0...v1.16.0
[1.15.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.14.0...v1.15.0
[1.14.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.13.0...v1.14.0
[1.13.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.12.0...v1.13.0
[1.12.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.11.0...v1.12.0
[1.11.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.10.0...v1.11.0
[1.10.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/mavidasnc/wp-health-check/releases/tag/v1.0.0
