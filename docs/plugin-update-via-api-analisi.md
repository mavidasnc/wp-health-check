# Aggiornamento dei plugin del sito via API — analisi di fattibilità e sicurezza

Documento di analisi (non di implementazione) per una nuova funzionalità del
fleet agent `wp-health-check`: consentire alla dashboard centrale di **avviare
l'aggiornamento dei plugin WordPress installati sul sito** (Akismet, WooCommerce,
Elementor, ...) tramite una chiamata REST autenticata, così come oggi già avviene
per il self-update dell'agent stesso via `POST /update`.

> Ambito: questo è distinto dal `POST /update` esistente, che aggiorna **solo il
> file dell'agent** (`mu-plugins/wp-health-check.php`) da una release GitHub
> firmata, usando funzioni filesystem native. Qui parliamo di aggiornare **plugin
> di terze parti** presenti nel sito, un'operazione con un profilo di rischio e
> di complessità tecnica completamente diverso.

---

## 1. Sintesi esecutiva (TL;DR)

**Fattibile? Sì, tecnicamente**, riusando le primitive del core WordPress
(`Plugin_Upgrader`), ma con vincoli operativi importanti che rendono la versione
"ingenua" (aggiorna tutto in una singola chiamata sincrona) fragile in
produzione.

**Sicuro? Sì, a condizione di un vincolo non negoziabile**: la richiesta API può
indicare **quali** plugin aggiornare, ma **mai da dove** (nessun URL di pacchetto
né versione arbitraria nel payload). La sorgente del pacchetto deve provenire
esclusivamente dal transient di update che WordPress stesso popola da
`api.wordpress.org`. Senza questo vincolo, un token compromesso diventerebbe un
vettore di **esecuzione di codice remoto** (installazione di uno ZIP arbitrario).
Con questo vincolo, il rischio incrementale rispetto a oggi è limitato e
paragonabile a quello di un amministratore che clicca "Aggiorna" in bacheca.

**Raccomandazione**: procedere, ma con lo scope ristretto della **Opzione C**
(una chiamata REST per singolo plugin, sincrona, con la dashboard che orchestra e
fa polling), limitata ai plugin ospitati su wordpress.org, con backup della
cartella + rollback e lock anti concorrenza. Valutare in parallelo la **Opzione E**
(l'API si limita ad attivare/disattivare l'auto-update nativo del core): è molto
più semplice e sicura, ma non è "on demand". Le due opzioni non si escludono.

---

## 2. Come WordPress aggiorna un plugin (le primitive disponibili)

Aggiornare un plugin non è "scaricare un file e sovrascriverlo": è scaricare uno
ZIP, scompattarlo, sostituire l'**intera cartella** del plugin, eseguire eventuali
routine di migrazione e invalidare le cache. Il core fornisce la macchina completa:

- `Plugin_Upgrader` (`wp-admin/includes/class-plugin-upgrader.php`), che estende
  `WP_Upgrader` (`class-wp-upgrader.php`). Metodi rilevanti:
  `Plugin_Upgrader::upgrade( $plugin_file, $args )` per un singolo plugin e
  `bulk_upgrade( array $plugin_files, $args )` per più plugin.
- Il pacchetto da scaricare **non** è deciso da noi: `Plugin_Upgrader` legge
  l'URL del pacchetto (`->update->package`) dal transient `update_plugins`, che il
  core popola interrogando `api.wordpress.org`. Questo è il punto di sicurezza
  centrale (vedi §5).
- `WP_Upgrader::fs_connect()` inizializza `WP_Filesystem`. Durante l'operazione il
  core attiva la **maintenance mode** creando un file `.maintenance` nella root, e
  la rimuove al termine.
- Dalla 6.3 il core include un meccanismo di **temp-backup + rollback** nell'upgrader
  (usato dagli auto-update): la cartella del plugin viene copiata prima della
  sostituzione e ripristinata se l'aggiornamento fallisce. Su versioni precedenti
  questo non c'è e va implementato a mano.

Conseguenza pratica: **non dobbiamo (e non dobbiamo voler) reimplementare la
logica di unzip/replace/hook a mano**, come invece fa il self-update dell'agent
(che è un file singolo, caso molto più semplice). Per i plugin ci appoggiamo a
`Plugin_Upgrader`. Questo però trascina dentro alcuni vincoli del core che
nell'attuale contesto REST non sono scontati.

---

## 3. Valutazione di fattibilità tecnica

### 3.1 WP_Filesystem e il metodo "direct" (nodo principale)

`Plugin_Upgrader` richiede `WP_Filesystem`. In una richiesta REST **senza sessione
admin** non è possibile presentare il form delle credenziali FTP/SSH: se
`get_filesystem_method()` non è `'direct'` (cioè il web server non può scrivere
direttamente nella cartella dei plugin con l'utente PHP), `request_filesystem_credentials()`
ritorna `false` e l'upgrade fallisce con `WP_Error`.

**Implicazione**: la funzionalità è utilizzabile **solo su siti con accesso
filesystem diretto** (la stragrande maggioranza degli hosting moderni con
PHP-FPM/suexec). Su host che richiedono FTP per gli aggiornamenti, l'operazione
deve fallire in modo pulito con un errore diagnostico chiaro, **senza** tentare di
raccogliere credenziali. Va fatto un preflight con `get_filesystem_method()`
analogo al preflight di scrivibilità già presente nel self-update.

### 3.2 Plugin premium / non wordpress.org (limitazione importante)

I plugin che si aggiornano da server propri (Elementor Pro, ACF Pro, Gravity Forms,
WooCommerce estensioni a pagamento, ...) iniettano i propri dati di aggiornamento
nel transient `update_plugins` **solo quando il loro update-checker viene caricato**,
cosa che in un contesto REST **non avviene** (è lo stesso motivo per cui i conteggi
update potevano risultare 0, già documentato e mitigato con
`wphc_mute_update_shortcircuit()`). Neutralizzare gli short-circuit `pre_` **non**
è sufficiente qui: quel fix ripristina la *lettura* del transient mantenuto dal
cron, ma il pacchetto premium potrebbe non essere presente, oppure il download
potrebbe richiedere una licenza.

**Implicazione**: in modo affidabile e non presidiato possiamo aggiornare solo i
plugin **ospitati su wordpress.org**. Per i premium: o si accetta che non siano
aggiornabili via API (raccomandato in v1), oppure si affronta il caso in una fase
successiva, con tutte le complicazioni di licenza. Questa limitazione va dichiarata
esplicitamente nel contratto, non nascosta.

### 3.3 Timeout e durata dell'operazione

Download + unzip + sostituzione cartella per un plugin grande (es. WooCommerce,
decine di MB) può superare sia `max_execution_time` del PHP sito sia il timeout
del client HTTP della dashboard. Un `bulk_upgrade()` di 11 plugin in **una sola**
richiesta REST è un invito al timeout, con il rischio di lasciare l'operazione a
metà e la maintenance mode attiva.

**Implicazione**: preferire **un plugin per chiamata** (Opzione C), così ogni
richiesta ha durata contenuta e prevedibile, e la dashboard può mostrare avanzamento
e ritentare il singolo elemento fallito senza rifare tutto.

### 3.4 Maintenance mode orfana

Se PHP muore durante l'upgrade (timeout, OOM), il file `.maintenance` può restare e
lasciare il sito in "In manutenzione, torna tra un minuto". Il core gestisce il
caso, ma un crash improvviso può comunque lasciarlo orfano.

**Implicazione**: prevedere una pulizia difensiva del `.maintenance` (rimozione se
più vecchio di N secondi) all'inizio dell'operazione e in un `register_shutdown_function`,
e comunque esporre in `/health` un flag "maintenance attiva da troppo tempo".

### 3.5 Concorrenza

Due chiamate di update simultanee (o un update mentre il cron del core sta
aggiornando) possono corrompere la cartella del plugin.

**Implicazione**: acquisire un **lock** (transient/option con TTL, o
`WP_Upgrader::create_lock()`) e rifiutare con 409 le chiamate concorrenti.

### 3.6 Rollback e integrità post-aggiornamento

Manualmente, `Plugin_Upgrader::upgrade()` non garantisce da solo il rollback su
tutte le versioni di WP. Serve una rete di sicurezza:

- backup della cartella del plugin prima dell'operazione (o uso del temp-backup
  nativo 6.3+),
- verifica post-aggiornamento (il plugin è ancora presente, la nuova versione è
  quella attesa, il sito non va in fatal error),
- ripristino automatico dal backup in caso di anomalia,
- invalidazione opcache.

Questo è lo **stesso spirito** dell'ordinamento rigoroso già adottato nel self-update
dell'agent, applicato al caso "cartella" invece che "file singolo".

### 3.7 Contesto di esecuzione

`Plugin_Upgrader` e i molti `require_once` di `wp-admin/includes/*` vanno inclusi
lazy dentro la callback (coerente con la regola architetturale del file: `/health`
resta economica, gli include pesanti stanno solo dove servono). Nessun utente WP è
loggato: l'autenticazione resta il bearer token. La classe upgrader non fa controlli
di capability propri, quindi funziona, ma va confermato caso per caso che nessun
hook di terze parti assuma un `current_user_can()` vero.

---

## 4. Modello di minaccia (sicurezza)

### 4.1 Cosa cambia rispetto a oggi

Oggi un token compromesso permette di: leggere i dettagli del sito e forzare il
self-update dell'agent **alla release ufficiale più recente** (codice nostro,
firmato, sostanzialmente benigno). Aggiungere l'update dei plugin **allarga il
raggio d'azione**: se l'attaccante potesse controllare la *sorgente* del pacchetto,
otterrebbe esecuzione di codice arbitrario sul sito.

Il perno difensivo è quindi: **la richiesta non deve mai poter influenzare da dove
arriva il codice**.

### 4.2 Superfici e controlli

| Minaccia | Vettore | Controllo |
|---|---|---|
| RCE via pacchetto arbitrario | payload con `package_url` o versione a scelta | **Vietato accettarli.** La sorgente è solo `->update->package` del transient, popolato dal core da `api.wordpress.org`. Il payload contiene al massimo *quali* plugin, non da dove. |
| Downgrade a versione vulnerabile | payload con versione target | **Vietato.** Si aggiorna solo alla versione che il core ha determinato disponibile (mai a una versione indicata dal chiamante). |
| Host del pacchetto non fidato | transient avvelenato o plugin premium | Allowlist dell'host del `package` (`downloads.wordpress.org`, `api.wordpress.org`). Pacchetti da altri host rifiutati in v1. |
| MITM sul download | rete | `sslverify` **sempre** attivo (default WP HTTP API). Verifica firma pacchetto quando presente (`verify_file_signature`). |
| Trigger non autorizzato | chiamata REST | Stesso `wphc_require_token()` delle altre rotte dati (bearer token da enroll, confronto `hash_equals`). |
| Token rubato | credenziale esfiltrata | Il danno è limitato all'update verso versioni ufficiali. Mitigazioni: audit log di ogni update, possibilità di reset token, opzione kill-switch per disabilitare la rotta. |
| Compromissione a monte di wordpress.org | supply chain repo ufficiale | Rischio **ereditato**, identico a quando l'admin clicca "Aggiorna": la feature non lo introduce ex novo. Va però annotato. |
| Sito lasciato rotto | crash a metà upgrade | Backup + rollback + pulizia `.maintenance` (vedi §3.6). Disponibilità è parte della sicurezza. |

### 4.3 Coerenza con i vincoli architetturali del progetto

- **Nessun nuovo segreto sul sito**: la feature non richiede segreti. La chiave
  pubblica Ed25519 e il modello token restano invariati.
- **Nessuna scrittura in `wp-config.php`**: nulla da persistere lì. Eventuali flag
  (es. kill-switch, ultimo esito) vivono in `wp_options`.
- **`/health` resta economica**: la nuova logica sta in una rotta dedicata, gli
  include pesanti sono lazy.

---

## 5. Opzioni di design a confronto

| Opzione | Descrizione | Pro | Contro | Giudizio |
|---|---|---|---|---|
| **A** — Bulk sincrono | Una `POST /update/plugins` aggiorna tutti i plugin con update in una richiesta | Poche chiamate | Timeout, maintenance orfana, rollback complesso su N elementi | Sconsigliata |
| **B** — Shell out a WP-CLI | Invocare `wp plugin update` da PHP | Riusa WP-CLI | WP-CLI spesso assente/non nel PATH, permessi, output da parsare | Scartata |
| **C** — Un plugin per chiamata, sincrono | `POST /update/plugin` con `{plugin}`, la dashboard orchestra e fa polling | Durata limitata, progress naturale, rollback per singolo elemento, ritentabile | Più round trip | **Raccomandata (v1)** |
| **D** — Job asincrono via WP-Cron | L'API accoda, il cron esegue, stato via `/health` | Nessun timeout sulla chiamata | Affidabilità cron (serve traffico/cron reale), tracking stato complesso | Possibile evoluzione |
| **E** — Solo toggle auto-update nativo | L'API attiva/disattiva `auto_update_plugins` per i plugin scelti | Minimo codice, usa il path core testato, firmato e con rollback | Non on demand (gira sul cron update ~2/die), dipende dal cron | **Forte alternativa/complemento** |

L'Opzione **E** merita attenzione perché è la più semplice e delega tutto al
percorso di auto-update del core (già robusto, con rollback dalla 6.3): l'API si
limiterebbe a scrivere l'array `auto_update_plugins`. Non dà però il controllo "fai
adesso" che probabilmente è il requisito. **C + E** insieme coprono sia
l'on demand sia il "mantieni aggiornato in automatico".

---

## 6. Proposta di dettaglio per la v1 (Opzione C)

### 6.1 Contratto API (bozza)

Nuova rotta, autenticata con lo stesso `wphc_require_token()`:

```
POST /wp-json/health-check/v1/update/plugin
Authorization: Bearer <token>
Content-Type: application/json

{ "plugin": "akismet/akismet.php" }
```

Regole sul payload:

- `plugin` è **il plugin file** già installato (chiave di `get_plugins()`), non uno
  slug arbitrario e **non** un URL.
- Nessun campo `package_url`, nessun campo `version`: la sorgente e la versione
  target le decide il transient del core.

Risposta (esempi):

```json
{ "updated": true, "plugin": "akismet/akismet.php", "from": "5.3.2", "to": "5.3.4" }
{ "updated": false, "reason": "up_to_date", "current": "5.3.4" }
{ "updated": false, "reason": "not_updatable", "detail": "pacchetto non ospitato su wordpress.org" }
{ "updated": false, "reason": "fs_method_unavailable" }
{ "updated": false, "reason": "locked" }        // 409, update concorrente in corso
{ "updated": false, "reason": "rolled_back", "detail": "..." }  // fallito e ripristinato
```

### 6.2 Flusso passo-passo (mutuato dallo spirito del self-update)

1. Registra l'accesso (`wphc_record_access()`), come ogni rotta dati.
2. **Lock**: acquisisci il lock update; se già preso → `409 locked`.
3. Include lazy di `wp-admin/includes/{plugin,update,file,class-wp-upgrader,class-plugin-upgrader}.php`.
4. **Preflight filesystem**: `get_filesystem_method()` deve essere `'direct'`; altrimenti → `fs_method_unavailable`.
5. Rinfresca lo stato update in modo affidabile (neutralizzando gli short-circuit
   `pre_`, come già si fa per i conteggi) e verifica che `plugin` abbia davvero un
   update disponibile nel transient; altrimenti → `up_to_date`.
6. **Allowlist sorgente**: l'host di `->update->package` deve essere in
   `{downloads.wordpress.org, api.wordpress.org}`; altrimenti → `not_updatable`.
7. **Backup** della cartella del plugin (o affidati al temp-backup 6.3+).
8. Esegui `Plugin_Upgrader::upgrade( $plugin, array( 'clear_update_cache' => false ) )`
   con uno `Skin` silenzioso (nessun output HTML).
9. **Sanity check**: plugin ancora presente, nuova versione == attesa, nessun fatal
   in include del file principale. Se KO → ripristina dal backup → `rolled_back`.
10. Pulizia `.maintenance` difensiva, invalida opcache, rilascia il lock, invalida le
    cache dei transient del plugin.
11. **Audit**: salva in `wp_options` l'esito (plugin, from, to, timestamp, IP), sulla
    falsariga di `wp_health_check_last_enroll_error`, visibile nella tab Site Health.

### 6.3 Kill-switch e osservabilità

- Opzione `wp_health_check_plugin_update_enabled` (default a scelta): se falsa, la
  rotta risponde `403 disabled`. Permette di spegnere la feature per sito senza
  redeploy.
- Log/telemetria di ogni tentativo (riusando il pattern degli errori enroll).
- `/health` potrebbe esporre `last_plugin_update` e un flag `maintenance_stuck`.

---

## 7. Anti-requisiti (cosa NON fare)

- **Non** accettare URL di pacchetto o versione dal payload. Mai.
- **Non** raccogliere né memorizzare credenziali FTP/SSH per aggirare l'assenza di
  filesystem diretto: se non è `'direct'`, si fallisce in modo pulito.
- **Non** disabilitare `sslverify`.
- **Non** fare bulk di N plugin in una singola richiesta sincrona (timeout).
- **Non** promettere l'aggiornamento dei plugin premium in v1.
- **Non** avviare update da cron lato sito senza un trigger autenticato esplicito
  (coerente con la filosofia del self-update dell'agent), a meno di scegliere
  consapevolmente l'Opzione E (toggle dell'auto-update nativo).

---

## 8. Rischi residui

| Rischio | Probabilità | Impatto | Mitigazione |
|---|---|---|---|
| Update lascia il sito in fatal error | Bassa | Alto | Backup + rollback + sanity check |
| Maintenance mode orfana dopo crash | Bassa | Medio | Pulizia difensiva `.maintenance`, flag in `/health` |
| Timeout su plugin grandi | Media | Medio | Un plugin per chiamata, timeout client generoso, ritento |
| Token rubato usato per update di massa | Bassa | Medio | Solo versioni ufficiali, audit log, kill-switch, reset token |
| Plugin premium non aggiornabili | Certa | Basso | Dichiarato nel contratto (`not_updatable`) |
| Host senza filesystem diretto | Media | Basso | `fs_method_unavailable`, fallback manuale |

---

## 9. Decisioni da prendere (domande aperte)

1. **On demand, auto, o entrambi?** Opzione C (fai adesso), E (mantieni aggiornato
   in automatico via core) o C+E?
2. **Perimetro v1**: solo wordpress.org, oppure affrontare subito i premium (con
   tutta la complessità licenze)?
3. **Granularità**: singolo plugin (`{plugin}`) o lista con esecuzione sequenziale
   lato sito? La raccomandazione è singolo, con orchestrazione lato dashboard.
4. **Default kill-switch**: la feature nasce abilitata o disabilitata di default
   sulla flotta?
5. **Estendere anche ai temi?** Stessa macchina (`Theme_Upgrader`), stesse cautele.
6. **Rollback**: affidarsi al temp-backup nativo (richiede WP 6.3+) o implementare
   sempre il backup cartella per coprire anche installazioni datate?

---

## 10. Stima di massima ed effort

Roadmap a fasi verificabili:

1. **Fase 0 — decisioni** (§9) → verifica: opzioni scelte e scope congelato.
2. **Fase 1 — rotta singolo plugin (Opzione C)**: preflight fs, allowlist sorgente,
   `Plugin_Upgrader` con skin silenziosa, backup+rollback, lock, audit → verifica:
   update reale di un plugin wordpress.org in wp-env, con test dei rami di errore
   (fs non direct, sorgente non ammessa, concorrenza, rollback simulato).
3. **Fase 2 — osservabilità**: esiti in `/health` e tab Site Health, kill-switch →
   verifica: pannello mostra ultimo esito, la rotta rispetta il flag.
4. **Fase 3 (opzionale) — Opzione E**: toggle `auto_update_plugins` via API →
   verifica: l'array riflette le scelte, l'auto-update del core parte.
5. **Fase 4 (opzionale) — temi e/o premium**: solo dopo che la v1 è stabile.

Complessità stimata della sola Fase 1: medio-alta, non per la logica di business ma
per la robustezza (gli stessi motivi per cui il self-update dell'agent è lungo e
difensivo). L'aggancio a `Plugin_Upgrader` riduce il codice ma aumenta i casi limite
da gestire (filesystem, maintenance, skin, hook di terze parti).

---

## 11. Conclusione

La funzionalità è **fattibile e ragionevolmente sicura** se e solo se il payload non
può influenzare la sorgente del codice installato, e se si accettano i limiti (solo
wordpress.org, solo host con filesystem diretto) dichiarandoli nel contratto. La
strada consigliata è **una rotta per singolo plugin, sincrona, con backup/rollback e
lock**, orchestrata dalla dashboard, con l'auto-update nativo del core (Opzione E)
come complemento a basso costo per il "mantieni aggiornato". Prima di implementare
servono però le decisioni della §9, in particolare on demand vs automatico e il
perimetro premium.
