# Aggiornamento di plugin, temi e core via API — specifiche

Specifica di implementazione per la funzionalità che consente alla dashboard
centrale di avviare, tramite chiamate REST autenticate, l'aggiornamento di
**singoli plugin, temi o del core di WordPress** su un sito della flotta.

Deriva da [plugin-update-via-api-analisi.md](plugin-update-via-api-analisi.md)
(fattibilità e sicurezza) e ne implementa l'**Opzione C** con le decisioni prese.

> Distinzione fondamentale: il `POST /update` esistente aggiorna **solo l'agent**
> (`mu-plugins/wp-health-check.php`) da una release GitHub firmata, con filesystem
> nativo. Le rotte qui specificate (`/update/plugin`, `/update/theme`,
> `/update/core`) aggiornano **software di terze parti del sito** appoggiandosi
> alle primitive del core (`Plugin_Upgrader`, `Theme_Upgrader`, `Core_Upgrader`).

---

## 1. Decisioni congelate

| Tema | Decisione |
|---|---|
| Modello | Opzione C: **una chiamata per singolo elemento**, sincrona; la dashboard orchestra e fa polling. |
| Sorgente | **Solo wordpress.org**. I plugin/temi premium (pacchetto su host proprio) sono rifiutati con `not_updatable`. |
| Ambito | Plugin **e** temi **e** core. |
| Rollback plugin/temi | **temp-backup nativo** di WordPress (6.3+). |
| Rollback core | Meccanismo proprio del `Core_Upgrader` (garanzia **diversa** dal temp-backup, vedi §7.3). |
| Kill-switch | **Interruttore master unico** nella pagina di amministrazione. |
| Log | **Tabella custom** dedicata, con pattern a due righe (prima/dopo). |
| Lettura log | Rotta `GET /update/log` autenticata, con paginazione. |

---

## 2. Vincoli di sicurezza non negoziabili

Riepilogo operativo dai risultati dell'analisi. Ogni implementazione DEVE
rispettarli:

1. **La richiesta indica solo QUALE elemento aggiornare, mai da dove né a quale
   versione.** Nessun campo `package_url`, nessun campo `version` nel payload. La
   sorgente è esclusivamente `->update->package` letto dal transient di update che
   il core popola da `api.wordpress.org`.
2. **Allowlist dell'host del pacchetto**: `downloads.wordpress.org`,
   `api.wordpress.org`. Qualsiasi altro host → `not_updatable` (copre di fatto i
   premium).
3. **`sslverify` sempre attivo** (default della WP HTTP API): mai disabilitarlo.
4. **Filesystem diretto obbligatorio**: se `get_filesystem_method() !== 'direct'` →
   `fs_method_unavailable`. Mai raccogliere credenziali FTP/SSH.
5. **Autenticazione**: stesso `wphc_require_token()` delle altre rotte dati (bearer
   token da enroll, confronto `hash_equals`).
6. **Nessun nuovo segreto sul sito, nessuna scrittura in `wp-config.php`**: stato e
   flag vivono in `wp_options` e nella tabella di log.
7. **Kill-switch rispettato prima di qualunque lavoro**: a interruttore spento le
   rotte di update rispondono `403 disabled`.

---

## 3. Requisiti di ambiente

- **WordPress ≥ 6.3** per la garanzia di rollback via temp-backup su plugin/temi.
  Su versioni precedenti la rotta risponde `unsupported_wp_version` (scelta
  fail-safe: non aggiornare senza rete di sicurezza). Da confermare come requisito
  minimo della flotta.
- **PHP ≥ 7.4** (vincolo già esistente del runtime).
- Metodo filesystem `'direct'` (vedi §2.4).

---

## 4. Rotte REST

Namespace invariato `health-check/v1`. `/update` (senza sotto-path) resta il
self-update dell'agent: **non** viene toccato.

### 4.1 `POST /update/plugin`

```
POST /wp-json/health-check/v1/update/plugin
Authorization: Bearer <token>
Content-Type: application/json

{ "plugin": "akismet/akismet.php" }
```

- `plugin` (obbligatorio): il **plugin file**, chiave di `get_plugins()`. Non uno
  slug, non un URL.
- Query opzionale `?check=1`: **dry-run**, non aggiorna, restituisce solo se un
  update è disponibile e ammissibile (migliora la UX della dashboard, vedi §11).

Esiti (campo `result`):

```json
{ "updated": true,  "type": "plugin", "target": "akismet/akismet.php", "name": "Akismet", "from": "5.3.2", "to": "5.3.4", "log_id": 1287 }
{ "updated": false, "result": "up_to_date",            "current": "5.3.4" }
{ "updated": false, "result": "not_updatable",         "detail": "pacchetto non ospitato su wordpress.org" }
{ "updated": false, "result": "not_found" }              // plugin non installato
{ "updated": false, "result": "fs_method_unavailable" }
{ "updated": false, "result": "locked" }                 // 409, update concorrente
{ "updated": false, "result": "disabled" }               // 403, kill-switch spento
{ "updated": false, "result": "unsupported_wp_version" }
{ "updated": false, "result": "rolled_back", "detail": "..." }  // fallito e ripristinato
{ "updated": false, "result": "error", "code": "...", "message": "..." }
```

`log_id` correla la risposta con le righe di log (§6).

### 4.2 `POST /update/theme`

Identica a `/update/plugin`, con:

```json
{ "theme": "twentytwentyfour" }
```

- `theme` (obbligatorio): lo **stylesheet** (chiave di `wp_get_themes()`).
- Stessa allowlist, stesso dry-run, stessi esiti (`type: "theme"`).

### 4.3 `POST /update/core`

```
POST /wp-json/health-check/v1/update/core
Authorization: Bearer <token>
```

- Nessun campo obbligatorio nel payload. La versione target è l'aggiornamento core
  che WordPress stesso ha determinato (`get_core_updates()` / `find_core_update()`);
  **mai** una versione indicata dal chiamante.
- `?check=1`: dry-run.
- Esiti come sopra, `type: "core"`. Note specifiche del core in §7.3.

### 4.4 `GET /update/log`

```
GET /wp-json/health-check/v1/update/log?type=plugin&limit=50&offset=0
Authorization: Bearer <token>
```

- Query opzionali: `type` (`plugin|theme|core`), `limit` (default 50, max 200),
  `offset` (default 0). Ordinamento per `id` decrescente.
- Risposta:

```json
{
  "site": "https://esempio.com",
  "count": 2,
  "total": 137,
  "entries": [
    {
      "id": 1288, "correlation_id": "a1b2c3d4", "created_at": "2026-07-14T10:00:03+00:00",
      "type": "plugin", "target": "akismet/akismet.php", "name": "Akismet",
      "version_from": "5.3.2", "version_to": "5.3.4",
      "phase": "completed", "message": null, "ip": "203.0.113.7"
    },
    {
      "id": 1287, "correlation_id": "a1b2c3d4", "created_at": "2026-07-14T10:00:00+00:00",
      "type": "plugin", "target": "akismet/akismet.php", "name": "Akismet",
      "version_from": "5.3.2", "version_to": "5.3.4",
      "phase": "requested", "message": null, "ip": "203.0.113.7"
    }
  ]
}
```

---

## 5. Kill-switch (pagina di amministrazione)

- Opzione `wp_health_check_updates_enabled` (boolean) in `wp_options`.
  **Default proposto: `false`** (fail-safe: la feature nasce spenta e va abilitata
  consapevolmente per sito; da confermare, vedi §11).
- UI: un unico checkbox **"Consenti aggiornamenti (plugin, temi, core) via API"**
  aggiunto alla **tab Site Health già esistente** del plugin (coerente con il
  pulsante di update/reset già presenti lì; nessuna nuova pagina admin da creare).
  Salvataggio con nonce e capability `manage_options`.
- A interruttore spento, **tutte** le rotte `/update/{plugin,theme,core}`
  rispondono `403 disabled` **prima** di qualsiasi altra elaborazione. `GET
  /update/log` resta invece sempre leggibile (è sola lettura, utile anche a
  feature spenta).

---

## 6. Sistema di log (tabella custom)

### 6.1 Perché una tabella

Il pattern richiesto (una riga **prima** dell'update, una **dopo**) è
intrinsecamente multi-riga e append-only, con necessità di interrogazione per
elemento/data/esito da parte della dashboard: una option autoloaded crescerebbe
senza limiti, un file sarebbe poco interrogabile. La tabella è la scelta idiomatica.

### 6.2 Schema

Nome tabella: `{$wpdb->prefix}wphc_update_log`.

| Colonna | Tipo | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT, PK | |
| `correlation_id` | CHAR(16) | Lega la riga "prima" e la riga "dopo" della stessa operazione. |
| `created_at` | DATETIME (UTC) | Momento della scrittura. |
| `type` | VARCHAR(10) | `plugin` \| `theme` \| `core`. |
| `target` | VARCHAR(191) | plugin file, stylesheet, oppure `core`. |
| `name` | VARCHAR(191) | Nome leggibile. |
| `version_from` | VARCHAR(32) | Versione installata prima. |
| `version_to` | VARCHAR(32) | Versione target/attesa (dal transient). |
| `phase` | VARCHAR(16) | `requested` \| `completed` \| `failed` \| `rolled_back`. |
| `message` | VARCHAR(255) NULL | Dettaglio in caso di errore/rollback. |
| `ip` | VARCHAR(45) NULL | IP del chiamante (`wphc_get_client_ip()`). |

Indici: PK su `id`, indice su `correlation_id`, indice su `(type, created_at)`.

### 6.3 Creazione e versione schema

I mu-plugin non hanno hook di attivazione: la tabella si crea/aggiorna con
`dbDelta()` **solo quando** l'opzione `wp_health_check_db_version` è diversa dalla
versione schema attesa (controllo di un'opzione autoloaded a ogni load, `dbDelta`
eseguito e `wp-admin/includes/upgrade.php` incluso **solo** in caso di mismatch).

### 6.4 Pattern a due righe (requisito esplicito)

Per ogni operazione di update, con lo stesso `correlation_id`:

1. **Prima** di toccare qualunque file: INSERT riga `phase = 'requested'` con
   `version_from` (installata) e `version_to` (disponibile dal transient). Anche se
   PHP muore durante l'upgrade, questa riga resta come prova che un aggiornamento
   è stato avviato ma mai confermato.
2. Al termine:
   - successo → INSERT riga `phase = 'completed'` (stessi from/to);
   - fallimento con ripristino → INSERT riga `phase = 'rolled_back'` con `message`;
   - fallimento senza necessità di ripristino → INSERT riga `phase = 'failed'`.

### 6.5 Retention

Prune opportunistico (una volta al giorno, gated da transient) delle righe più
vecchie di **90 giorni** (valore configurabile via costante). Evita crescita
illimitata senza dipendere da un cron dedicato.

---

## 7. Flussi di esecuzione

Include **lazy** dentro la callback (coerente con la regola: `/health` resta
economica, gli include pesanti stanno solo dove servono):
`wp-admin/includes/{plugin,theme,update,file,misc,class-wp-upgrader,
class-plugin-upgrader,class-theme-upgrader,class-core-upgrader}.php` a seconda del
tipo.

### 7.1 Preambolo comune a tutte le rotte di update

1. `wphc_record_access()`.
2. Kill-switch: se `wp_health_check_updates_enabled` è falso → `403 disabled`.
3. Requisito versione WP (§3): se `< 6.3` per plugin/temi → `unsupported_wp_version`.
4. **Lock**: acquisisci `wp_health_check_update_lock` (transient con TTL, es. 300s);
   se già preso → `409 locked`. Rilascio garantito anche via
   `register_shutdown_function`.
5. Preflight filesystem: `get_filesystem_method() === 'direct'`, altrimenti
   `fs_method_unavailable`.
6. Pulizia difensiva di un eventuale `.maintenance` orfano (più vecchio di N secondi).

### 7.2 Plugin e temi (temp-backup nativo)

1. Rinfresca lo stato update in modo affidabile: `wp_clean_plugins_cache(false)` /
   `wp_clean_themes_cache(false)` e lettura del transient neutralizzando gli
   short-circuit `pre_` (riuso di `wphc_mute_update_shortcircuit()` /
   `wphc_restore_update_shortcircuit()`, già presenti).
2. L'elemento deve avere un update disponibile nel transient; altrimenti
   `up_to_date`.
3. **Allowlist sorgente**: host di `->update->package` in
   `{downloads.wordpress.org, api.wordpress.org}`; altrimenti `not_updatable`.
4. **Log riga `requested`** (§6.4).
5. Esegui l'upgrade con temp-backup e skin silenziosa:

   ```
   $skin     = new Automatic_Upgrader_Skin();          // nessun output HTML
   $upgrader = new Plugin_Upgrader( $skin );            // o Theme_Upgrader
   $result   = $upgrader->upgrade( $target, array(
       'clear_update_cache' => false,
   ) );
   ```

   Il temp-backup/rollback nativo (6.3+) è attivato dall'upgrader stesso: in caso di
   fallimento ripristina la cartella dal backup temporaneo.
6. **Sanity check**: elemento ancora presente, nuova versione == attesa, nessun
   fatal all'include del file principale. Se KO e il core non ha già ripristinato →
   ripristino, esito `rolled_back`.
7. `opcache_invalidate` dei file rilevanti, invalidazione cache liste/transient.
8. **Log riga finale** (`completed` / `failed` / `rolled_back`).
9. Rilascia il lock.

### 7.3 Core (specificità e avvertenze)

- Sorgente: `$update = find_core_update( $version, $locale )` a partire da
  `get_core_updates()`; si aggiorna **solo** all'update che il core espone.
- Upgrade: `Core_Upgrader::upgrade( $update )` con skin silenziosa.
- **Rollback**: il `Core_Upgrader` **non** usa il temp-backup di plugin/temi; ha il
  proprio percorso di ripristino parziale. La garanzia è quindi **diversa e più
  debole**: l'aggiornamento core è l'operazione più lenta e rischiosa, va trattata
  con la massima cautela (ed è il primo candidato a un futuro passaggio ad
  esecuzione asincrona).
- **Upgrade del database**: dopo la sostituzione dei file core, WordPress esegue le
  routine di upgrade del DB. In contesto headless questo non avviene
  automaticamente: la specifica prevede di invocare `wp_upgrade()` subito dopo
  l'update dei file (con include di `wp-admin/includes/upgrade.php`), e comunque il
  sito completerebbe l'upgrade DB alla prima visita di `/wp-admin/upgrade.php`.
  **Da validare in wp-env** (vedi §10).
- Timeout: rischio elevato; il client della dashboard deve usare un timeout
  generoso e la rotta deve essere idempotente rispetto a un secondo tentativo.

---

## 8. Storage delle opzioni (`wp_options`)

| Opzione | Tipo | Scopo |
|---|---|---|
| `wp_health_check_updates_enabled` | bool | Kill-switch master (§5). |
| `wp_health_check_update_lock` | transient | Lock anti-concorrenza (§7.1). |
| `wp_health_check_db_version` | string | Versione schema tabella log (§6.3). |
| `wp_health_check_log_pruned_at` | transient | Gate del prune giornaliero (§6.5). |

Da aggiungere a `wphc_reset_enrollment()` solo ciò che è legato al sito e ha senso
azzerare a un reset (da decidere: probabilmente **non** il log storico, sì il lock).

---

## 9. Integrazione con `/health` (osservabilità)

Aggiungere al blocco `summary` (operazioni O(1), coerenti col contratto economico):

- `updates_via_api_enabled` (bool): stato del kill-switch.
- `last_update` (oggetto o null): `{ type, target, phase, at }` dell'ultima riga di
  log, per un colpo d'occhio dalla dashboard senza interrogare `/update/log`.
- `maintenance_stuck` (bool): true se esiste un `.maintenance` più vecchio di N
  secondi (segnala un upgrade interrotto).

---

## 10. Verifica (Definition of Done)

Nessun test automatico nel repo: verifica via `composer run lint`, `composer run
analyse` e esercizio manuale in `wp-env`.

1. `lint` e `analyse` puliti.
2. **Plugin**: update reale di un plugin wordpress.org datato in wp-env → riga
   `requested` poi `completed`, versione effettivamente cambiata, risposta con
   `from`/`to` corretti.
3. **Temi**: come sopra su un tema wordpress.org.
4. **Core**: update core in wp-env con versione minor arretrata → file aggiornati,
   upgrade DB completato, sito integro.
5. **Rami di errore**: `not_updatable` (pacchetto non wordpress.org),
   `up_to_date`, `not_found`, `disabled` (kill-switch), `locked` (due chiamate
   concorrenti), `fs_method_unavailable` (simulato), `rolled_back` (fallimento
   iniettato).
6. **Log**: `GET /update/log` pagina correttamente, coppie `requested`/`completed`
   con lo stesso `correlation_id`, prune non cancella righe recenti.
7. **Kill-switch** dalla tab Site Health: spegne davvero le tre rotte, `/update/log`
   resta accessibile.

---

## 11. Migliorie proposte (oltre lo scope minimo)

1. **Dry-run `?check=1`** su tutte le rotte di update (già inserito nei contratti):
   permette alla dashboard di sapere in anticipo cosa è aggiornabile e ammissibile,
   senza eseguire nulla.
2. **`log_id`/`correlation_id` nella risposta**: correlazione immediata fra esito e
   storico.
3. **`maintenance_stuck` in `/health`**: rileva upgrade interrotti anche quando la
   chiamata originale è andata in timeout.
4. **Unificare nel log anche il self-update dell'agent**: cronologia unica di tutti
   gli aggiornamenti (plugin/temi/core/agent) in un solo posto.
5. **Verifica firma pacchetto quando disponibile**: il percorso del core usa già
   `verify_file_signature` dove wordpress.org fornisce la firma; assicurarsi di non
   sopprimerla.
6. **Evoluzione asincrona (Opzione D) per il core**: se i timeout sul core si
   rivelano un problema in produzione, spostare il solo core su un job accodato con
   stato interrogabile via `/update/log`.

---

## 12. Domande aperte residue

1. **Default del kill-switch**: nasce spento (`false`, fail-safe, proposto) o acceso
   sulla flotta?
2. **Requisito WP minimo 6.3**: accettabile come requisito duro, o serve un fallback
   con backup manuale su versioni precedenti?
3. **Upgrade DB del core in headless** (§7.3): confermare in wp-env che
   `wp_upgrade()` post-update sia sufficiente e sicuro, o se preferire che il sito
   completi l'upgrade alla prima visita admin.
4. **Reset**: il `wp health-check reset` e il pulsante di reset devono cancellare
   anche lo storico del log, o preservarlo?
5. **Versionamento**: questa feature è un incremento minore (es. `1.18.0`); confermare.
```
