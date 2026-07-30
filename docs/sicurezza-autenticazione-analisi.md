# Analisi di sicurezza: autenticazione dashboard → hub → plugin

> Documento di analisi, non un changelog. Nessun codice è stato modificato per prepararlo. Ogni
> affermazione tecnica porta un riferimento `file:linea` verificato direttamente sul codice al momento
> della stesura (luglio 2026). I riferimenti a segreti citano sempre la posizione (file, colonna, header),
> mai il valore.

## 1. Perimetro e metodo

Punto di partenza: un report comparativo (Mavida Fleet v0.40.0 contro MainWP, ManageWP, WP Umbrella,
InfiniteWP, WP Remote) che individua due falle P0 nel canale dashboard↔hub↔sito e conclude che «il gap non
è nell'HMAC verso il sito — è un livello sopra, nell'auth dashboard→hub».

Questa analisi riprende quei riscontri, li verifica riga per riga sul codice reale dei tre repository
coinvolti (`wp-health-check`, `automation_2wp`, `wp-fleet-manager`) e ne aggiunge tre non presenti nel
report di partenza (§3, righe marcate "★ non nel report originale"). Copre l'intera catena, dal browser
dell'operatore fino al cookie di sessione impostato su wp-admin del sito cliente, perché irrobustire un
solo anello lasciando debole il resto non cambia la sicurezza reale del sistema.

Non copre: backup pre-update, bulk update, scheduling/alerting, whitelabel — tutti temi del report di
partenza ma estranei all'autenticazione, quindi fuori perimetro qui.

## 2. La catena di fiducia reale

```
 browser operatore        --- Bearer statico (nel bundle JS) --->   hub Flask
 (dashboard React)            + X-Admin-User-Id auto-dichiarato     (automation_2wp)

 hub Flask                --- Bearer HMAC(url, MASTER_SECRET) --->  plugin WordPress
 (proxy)                      mai scaduto, ricalcolato ad ogni      (rotte dati/update:
                               chiamata, mai persistito nel hub      /health /detail/* /update*)

 hub Flask                --- Basic Auth (Application Password -->  plugin WordPress
 (proxy autologin)            in chiaro su Supabase)                 (/autologin/token,
                                                                       gated manage_options)

 browser operatore        --- ?wphc_autologin=<token 20s, --->      sito WordPress
 (redirect diretto)           single-use> in query string           (cookie sessione, 14 gg)
```

Quattro credenziali distinte, di forza molto diversa. Le ultime due (hub→plugin) sono l'oggetto del
report di partenza e sono effettivamente solide sul piano crittografico (Ed25519, HMAC-SHA256,
`hash_equals`). La prima è invece un bearer condiviso, statico, leggibile da chiunque apra gli strumenti
di sviluppo del browser: non protegge nulla che dipenda dall'identità di chi chiama, solo che chi chiama
possieda quel bundle pubblico. Poiché ogni chiamata verso un sito passa comunque per l'hub, **la
robustezza della catena è quella del suo anello più debole**, ed è il primo.

## 3. Riscontri

Ordinati per impatto reale, non per complessità della causa. Per ognuno: evidenza, cosa ottiene
concretamente un attaccante che lo sfrutta, cosa lo limita oggi.

### 3.1 — P0 · Bearer statico nel bundle pubblico + identità auto-dichiarata

`wp-fleet-manager/src/data/network/fleetApi.js:7-16`:
```js
const AUTH_TOKEN = import.meta.env.VITE_API_AUTH_TOKEN
function fleetHeaders(adminUserId, extra = {}) {
  return { Authorization: `Bearer ${AUTH_TOKEN}`, 'X-Admin-User-Id': adminUserId, ...extra }
}
```
Ogni variabile con prefisso `VITE_` viene inserita in chiaro nel bundle JS servito al browser — il repo
lo dichiara esplicitamente (`wp-fleet-manager/.env.example:7-11`). Lato backend, `app/auth.py:130` e
`app/auth.py:182` (`require_admin`, `require_fleet_actor`) verificano solo che l'header `Authorization`
coincida col token globale `API_AUTH_TOKEN`; l'header `X-Admin-User-Id` (`app/auth.py:135`, `:187`) viene
letto e usato per risolvere `role` su Supabase, ma non è legato a nessuna sessione o firma. Il commento
nel codice lo ammette (`app/auth.py:163-164`): *"nome storico: identifica l'attore, non necessariamente un
admin"*.

**Impatto**: chiunque estragga il token dal bundle pubblico e conosca (o indovini) l'UUID di un utente con
`role in ('admin','user')` può impersonarlo su qualunque endpoint fleet, incluso l'avvio di un autologin
verso wp-admin dei siti clienti (§3.3). Il login OTP (`wp-fleet-manager/src/data/network/authApi.js:32-44`)
non produce alcun token di sessione da verificare: l'identità che arriva al backend è un valore che il
client stesso decide di inviare.

**Limitazioni attuali**: il token va comunque conosciuto (non è pubblicato, solo compilato nel bundle);
serve un UUID valido di un utente abilitato.

### 3.2 — P0 · `POST /enroll` pubblico senza rate limit ★ non nel report originale

`automation_2wp/app/blueprints/v1/enroll_routes.py:5-10,41-43,63-66`:
```python
# ENROLL_REQUIRE_AUTH=false (default) — endpoint pubblico, nessun Bearer richiesto
...
@bp.route('/enroll', methods=['POST'])
@cross_origin()
def enroll():
    ...
    if os.getenv('ENROLL_REQUIRE_AUTH', 'false').lower() in ('true', '1'):
        err = check_bearer_token()
```
La variabile `ENROLL_REQUIRE_AUTH` non è presente nel `.env` di produzione (verificato: assente
dall'elenco delle chiavi effettive), quindi il default `false` è quello realmente in vigore. Nessun
decoratore di rate limit sulla rotta (a differenza di `/fleet/<id>/autologin/token`, che ne ha uno,
`fleet_routes.py:807`).

**Impatto**: chiunque su Internet può inviare `{"url": "<host arbitrario>"}` e ottenere che il centro (a)
firmi una busta Ed25519 valida per quell'URL e (b) esegua esso stesso una `POST` verso quell'host
(`services.enroll_site()` chiama il target). È simultaneamente un **oracolo di enrollment** non
autenticato (chiunque può farsi calcolare/consegnare un token per un sito che controlla, anche non
appartenente alla flotta) e una primitiva **SSRF** in POST verso host a scelta dell'attaccante, senza
alcun throttling.

**Limitazioni attuali**: il payload firmato contiene solo `site_url`/`token`/`issued_at`, nessun dato
interno sensibile; il timeout di rete (15 s, `services.py`) limita l'uso come amplificatore.

### 3.3 — P1 · Application Password in chiaro su Supabase

`automation_2wp/database/migrations/046_add_dashboard_fields_to_generations_fleet.sql:19,27-28,44-45`:
```sql
-- Nota sicurezza: user/pass in chiaro, endpoint solo-admin + HTTPS.
...
ADD COLUMN IF NOT EXISTS "user" TEXT,
ADD COLUMN IF NOT EXISTS "pass" TEXT;
...
COMMENT ON COLUMN public.generations_fleet."pass" IS
    'Password di accesso al sito (credenziale). Write-only a livello applicativo: ...';
```
Nessun `pgcrypto`, nessun KMS, nessun hashing: "write-only" è solo l'esclusione applicativa di `pass`
dalle `SELECT` verso il browser (`services.py:5808-5820`), non protezione at-rest. La checklist del
progetto lo segnala come da fare e non ancora fatto: `wp-fleet-manager/docs/backend-implementation-todo.md:75-80,150`.

**Impatto**: chi legge la tabella — dump, backup, un accesso Supabase Studio, o semplicemente la
service_role key (§3.5) — ottiene in chiaro le credenziali amministrative complete di ogni sito della
flotta, non limitate all'autologin.

**Limitazioni attuali**: la colonna non è mai restituita al browser (`_FLEET_LIST_COLUMNS` la esclude,
`services.py:5808-5820`); redazione nei log applicativi (`app/request_logging.py:35-41`).

### 3.4 — P1 · Token per-sito non ruotabile né revocabile; segreto unico per tutta la flotta

`automation_2wp/services.py:5487-5497`:
```python
def derive_enroll_token(url_norm: str, master_secret: str) -> str:
    """Deriva il token Bearer del sito: HMAC-SHA256(url_normalizzato, MASTER_SECRET).
    Il token è deterministico: stesso URL -> stesso token (re-enroll idempotente)."""
    mac = hmac.new(master_secret.encode("utf-8"), url_norm.encode("utf-8"), hashlib.sha256).digest()
```
Lato plugin, `mu-plugins/wp-health-check.php:942-950` lo dichiara come scelta di progetto: *"Una
ripetizione dell'enroll con lo stesso URL produce sempre lo stesso token ... per questo non serve alcun
controllo anti-replay"*. `wp-health-check.php:1063-1065`: `issued_at` è solo metadato, mai usato per
scadenza.

**Impatto concreto e non teorico**: un token per-sito reale è oggi committato in
`mavida-fleet/httpd/wp-health-check.http:12` (repo GitHub `mavidasnc/mavida-fleet`, privato — verificato
`gh repo view` → `isPrivate: true` — ma presente nella storia git da almeno due commit). Non essendo il
token ruotabile per singolo sito, l'unico modo per invalidarlo è cambiare `MASTER_SECRET`, il che invalida
**contemporaneamente** i token di ogni sito della flotta e richiede un re-enroll globale sincronizzato.
Nella pratica, quindi, un singolo token trapelato non viene quasi mai ruotato.

**Limitazioni attuali**: il token da solo non basta a orchestrare update sull'intera flotta (serve un URL
per sito); `hash_equals` sul confronto (`wp-health-check.php:739`) impedisce timing attack sulla singola
verifica.

### 3.5 — P1 · Service role key Supabase documentata come anon key; RLS non protettiva ★ non nel report originale

`automation_2wp/.env.example:63`: `SUPABASE_KEY=your_supabase_anon_key_here`. La chiave effettivamente in
uso ha claim JWT `"role":"service_role"` (verificato decodificando solo il claim, senza esporre il
segreto). Le policy RLS sono uniformi su circa venti tabelle, incluso `generations_fleet`
(`database/migrations/042_create_generations_fleet.sql`):
```sql
ALTER TABLE public.generations_fleet ENABLE ROW LEVEL SECURITY;
CREATE POLICY "service_role_only" ON public.generations_fleet
    USING (auth.role() = 'service_role');
```

**Impatto**: la policy autorizza esattamente il ruolo con cui il backend si connette — la service_role key
bypassa comunque RLS per definizione in Postgres/Supabase, quindi la USING clause è a tutti gli effetti
un placeholder. Tutta l'autorizzazione (ownership per sito, team sharing, esclusione di `pass` dalle
letture) vive **solo** nel codice Python. Un singolo bug di path o di parametro in una rotta Flask
equivale ad accesso diretto e completo al database, senza un secondo livello di difesa.

**Limitazioni attuali**: nessuna — è un rischio architetturale puro, mitigato solo dalla qualità del
codice applicativo.

### 3.6 — P1 · Nessun anti-replay sulle chiamate operative hub→plugin

Le rotte dati/update del plugin (`/health`, `/detail/*`, `/update*`) accettano `Authorization: Bearer
<token>` senza timestamp, nonce o firma sul corpo (`wp-health-check.php:709-744`,
`wphc_require_token()`). Chiunque intercetti una singola richiesta (proxy compromesso, log applicativo,
strumento di debug di rete) può riproddurla indefinitamente: il bearer non scade e non è legato alla
specifica richiesta.

**Limitazioni attuali**: serve comunque intercettare il traffico HTTPS o accedere a un log che lo
contenga; il canale è TLS.

### 3.7 — P2 · `/enroll` senza nonce né finestra di freschezza su `issued_at`

Già citato in §3.4 come causa; qui l'effetto isolato: una busta di enroll intercettata (non solo il
token, l'intera busta firmata) resta riproducibile senza limiti di tempo, perché nessun controllo verifica
che `issued_at` sia recente. `README.md:294-298` lo definisce un irrobustimento "opzionale e non
implementato".

### 3.8 — P2 · Sessione dashboard senza scadenza né verifica server-side

`wp-fleet-manager/src/hooks/useAuth.js:4-8`: *"Il 'session token' non esiste: l'identità utente è {email,
userId, role, plan}, persistita così com'è"* in IndexedDB (`IndexedDbStorageAdapter.js:19`, chiave
`wp-enroll:session`). Il logout è solo `clearSession()` locale (`useAuth.js:84-87`), nessuna chiamata di
invalidazione al backend. Combinato con §3.1, significa che non esiste alcun momento in cui l'identità
dichiarata dal client viene riverificata dal server dopo il login iniziale.

### 3.9 — P2 · Autologin: sessione da 14 giorni generata da un token che vive 20 secondi

`mu-plugins/wp-health-check.php:3997-3998`:
```php
wp_set_current_user( $user->ID );
wp_set_auth_cookie( $user->ID, true );   // $remember = true
```
Il TTL cortissimo del magic link (`WP_HEALTH_CHECK_AUTOLOGIN_TTL = 20`, `wp-health-check.php:111-113`) è
una scelta di progetto corretta per il link stesso, ma la sessione a cui dà accesso dura il default WP di
circa 14 giorni con `$remember = true`, non 20 secondi. È un'asimmetria: la parte più esposta (il link in
query string) è protetta a dovere, l'esito che produce no.

### 3.10 — P2 · Confronto del Bearer con `==` invece di tempo costante ★ non nel report originale

`automation_2wp/app/auth.py:46,130,182` confrontano il Bearer applicativo con `token == expected_token` /
`auth_header != f"Bearer {expected_token}"`, non `hmac.compare_digest`. Il plugin, sullo stesso tipo di
confronto, fa la cosa corretta: `hash_equals` (`wp-health-check.php:739`, con commento esplicito sul
timing attack). È l'unico punto in cui il backend è meno rigoroso del plugin sullo stesso problema.

**Limitazioni attuali**: un timing attack via rete su un confronto di stringa Python richiede comunque
milioni di richieste e un ambiente a jitter di rete molto basso; il rischio è teorico più che praticabile
da remoto, ma la correzione costa una riga per occorrenza.

### 3.11 — P2 · Rate limit dell'autologin di fatto globale sulla flotta

`app/extensions.py:13-33` usa il Bearer come chiave del rate limiter (*"più sicuro dell'IP in ambienti
multi-tenant"*, `extensions.py:16-17`), ma poiché il Bearer è lo stesso token statico condiviso da tutti i
frontend (§3.1), `RATE_LIMIT_AUTOLOGIN_PER_MIN` (default 5/min, `fleet_routes.py:807`) è in pratica un
limite unico per tutta la dashboard: un utente che lo satura blocca l'autologin per tutti gli altri, e
diventa un piccolo vettore di DoS interno.

### 3.12 — P2 · Nessun rate limit/lockout sulle rotte del plugin; nessun gate HTTPS

Verificato per grep esaustivo su `rate|lockout|429|attempt|throttl` nel mu-plugin: nessun risultato sulle
rotte dati. `is_ssl()` compare solo come campo informativo di `/detail/server`
(`wp-health-check.php:1731`), mai come condizione di accesso.

### 3.13 — P2 · CORS ampio su entrambi i lati

Backend: `app/__init__.py:64-66`, ogni rotta `/fleet*` è decorata `@cross_origin()` con
`CORS_ALLOWED_ORIGINS` di default `*`; il valore reale nel `.env` è `*`. Plugin:
`wp-health-check.php:620-634` riflette qualunque Origin quando `wp_health_check_dashboard_origin` è vuota
(stato pre-enroll o post-reset) — comportamento dichiarato come "difesa in profondità, non controllo di
accesso primario" (`wp-health-check.php:574-576`), corretto nell'impostazione ma comunque un'apertura non
necessaria se non richiesta operativamente.

### 3.14 — P2 · `SECRET_KEY` con default insicuro; ambiente di produzione configurato come development

`automation_2wp/app/config.py:16`: `SECRET_KEY = os.getenv('SECRET_KEY', 'dev-secret-key-change-in-production')`.
Il `.env` osservato ha `FLASK_ENV=development`. Non risulta un uso critico di `SECRET_KEY` nelle rotte
fleet (niente sessioni Flask firmate osservate su questo percorso), ma il fallback silenzioso è un'abitudine
pericolosa da correggere comunque: se in futuro qualcosa iniziasse a fidarsi di quella chiave, il default
la renderebbe pubblica de facto (è nel codice open).

### 3.15 — P3 · Chiave pubblica Ed25519 hardcoded senza identificatore di versione

`wp-health-check.php:69-71` incorpora una singola chiave pubblica come costante. Corretto per l'uso
attuale, ma senza un `kid` non è possibile far convivere due chiavi durante una rotazione: cambiare la
chiave di firma richiede un redeploy simultaneo dell'agente su tutta la flotta.

### 3.16 — P3 · `wp_health_check_last_autologin` scritta e mai letta, esclusa dal reset

Scritta in `wp-health-check.php:3894-3903`, non consultata da nessuna rotta né dalla tab Site Health
(unica occorrenza di lettura: nessuna), e non presente nell'elenco di `wphc_reset_enrollment()`
(`wp-health-check.php:377-398`): sopravvive a un reset dell'enrollment. Difetto di igiene, non di
sicurezza in senso stretto, ma segnala che l'audit trail "vivo" è solo la tabella `wphc_update_log`, non
questa opzione.

### Ciò che è già corretto (va preservato, non "migliorato")

- `hash_equals` sul confronto del bearer nel plugin (`wp-health-check.php:739`).
- Token del transient di autologin indicizzato su SHA-256, mai in chiaro nella chiave (`:3876`).
- Consumo single-use con `delete_transient()` eseguito **prima** di ogni validazione (`:3960`).
- Redirect di destinazione fisso a `admin_url()`: nessun open redirect possibile (`:4004`).
- `Access-Control-Allow-Credentials` sempre rimosso e mai reinviato dal plugin (`:606-607`).
- Guardia SSRF sul proxy della thumbnail del sito, limitata allo stesso host registrato
  (`services.py:6762-6769`).
- Esclusione applicativa di `pass` dalle risposte verso il browser e redazione nei log
  (`services.py:5808-5820`; `app/request_logging.py:35-41`).
- Nessun segreto reale tracciato in git nei repository `wp-health-check` e `automation_2wp`
  (verificato `git ls-files` contro `.gitignore`).
- Verifica Ed25519 con controllo esplicito delle lunghezze e `base64_decode(..., true)` strict
  (`wp-health-check.php:990-1001`), non solo "provo e vedo se lancia".

## 4. Modello obiettivo

Tre livelli, ciascuno con una credenziale propria, a scadenza, revocabile e attribuibile a un attore
specifico — non un token unico che, se compromesso, apre tutto:

| Livello | Oggi | Obiettivo |
|---|---|---|
| Browser → hub | Bearer statico nel bundle + header auto-dichiarato | Sessione opaca per-utente, con scadenza e revoca |
| Hub → plugin (dati/update) | HMAC deterministico, eterno, globale | Segreto per-sito, ruotabile, con anti-replay sulla richiesta |
| Hub → plugin (autologin) | Application Password in chiaro, singolo fattore | Application Password cifrata + secondo fattore di flotta |

## 5. Interventi

### 5.A Browser → hub: sessione firmata per-utente

Il pattern serve già altrove nello stesso backend e non va reinventato:
`generations_agent_sessions` + `services.validate_agent_session()` (`services.py:5382-5460`) — token
opaco `secrets.token_urlsafe(32)`, in DB solo lo SHA-256, `expires_at`, revoca tramite lo stato del record
padre, `last_used_at` aggiornato a ogni verifica.

- `POST /otp-verify` emette anche un session token, oltre a `{user_id, email}`.
- `require_fleet_actor` e `require_admin` derivano `g.fleet_actor_id` **dalla sessione verificata**, non
  più dall'header `X-Admin-User-Id`, che esce dal contratto dell'API.
- `API_AUTH_TOKEN` resta per i soli chiamanti server-to-server (webhook, script interni);
  `VITE_API_AUTH_TOKEN` esce da tutti i frontend Vite del monorepo (sette bundle oggi lo contengono).
- Effetto collaterale positivo: la chiave del rate limiter (`extensions.py:13-33`) diventa
  automaticamente per-utente, come il commento del codice dichiara di voler già ottenere, e
  `RATE_LIMIT_AUTOLOGIN_PER_MIN` smette di essere un limite globale sulla flotta (§3.11).
- Migrazione a doppio binario dietro flag: il percorso legacy resta attivo e loggato come deprecato finché
  il frontend non è aggiornato, poi si disattiva.
- Limite onesto da dichiarare: un bearer in IndexedDB resta esfiltrabile via XSS, come qualunque token
  accessibile da JavaScript. Il modello ancora più forte — refresh token in cookie `HttpOnly` +
  `SameSite`, access token solo in memoria — comporta costi reali quando dashboard e API vivono su
  origini diverse (CORS con credenziali, `SameSite=None` più `Secure`). La sessione opaca con TTL breve e
  revoca resta comunque il salto qualitativo decisivo rispetto a un bearer statico pubblico: passa da
  "chiunque abbia letto il bundle" a "chiunque abbia compromesso il browser di un utente specifico, per
  la durata della sua sessione".

### 5.B Hub → plugin: segreto per-sito, rotazione, revoca, anti-replay

Precisazione di progetto: l'hub è il *client* di questa relazione, deve poter presentare il segreto ad
ogni chiamata — un hash unidirezionale in colonna non è sufficiente, a differenza di un token di sessione
che il server deve solo confrontare. Il segreto per-sito va quindi conservato **cifrato**, non hashato,
con la stessa infrastruttura di envelope encryption introdotta per le Application Password (§5.C): un'unica
KEK, due usi.

- `generations_fleet`: nuove colonne `agent_secret_enc`, `agent_secret_prev_enc`, `secret_rotated_at`,
  `secret_revoked_at`, `protocol_version`.
- Plugin, stato dual (lo stesso schema già usato da WP Umbrella nel report comparativo):
  `wp_health_check_token`, `wp_health_check_token_prev`, `wp_health_check_token_rotated_at`.
  `wphc_require_token()` accetta entrambi i valori; il precedente solo dentro una finestra di grazia breve
  e con una riga di log dedicata; al primo uso del nuovo, il precedente decade. Rotazione senza downtime
  per la flotta.
- Nuove rotte firmate Ed25519, sulla falsariga di `/enroll`: `POST /rotate` e `POST /revoke`, con `nonce`
  e finestra di freschezza che oggi mancano anche in `/enroll` (si veda il punto successivo).
- **Anti-replay sulle chiamate operative** (§3.6): header `X-WPHC-Timestamp`, `X-WPHC-Nonce`,
  `X-WPHC-Signature = HMAC-SHA256(secret, method \n route \n sha256(body) \n timestamp \n nonce)`. Si
  firma la *route* REST, non l'URL completo, per restare compatibili con reverse proxy e varianti
  www/non-www come oggi (`wphc_candidate_site_urls()`). Verifica lato plugin: finestra ±300 s, nonce non
  già visto (transient dedicato), poi `hash_equals` sulla firma. Una richiesta intercettata passa da
  credenziale valida per sempre ad artefatto monouso valido pochi minuti.
- `/enroll`: aggiungere `nonce` e finestra di freschezza su `issued_at`. Con la rotazione introdotta, non
  è più un irrobustimento opzionale come oggi dichiarato (`README.md:294-298`): una busta di enroll vecchia
  riprodotta riporterebbe il sito a un token precedente già ruotato. Il payload canonico cambia forma,
  quindi va versionato esplicitamente (`protocol: 2`); l'hub conosce già `agent_version` per sito e può
  firmare v1 o v2 di conseguenza, rendendo la migrazione per-sito e non un flag globale sincrono.
- Chiudere `POST /enroll` sul backend indipendentemente dal resto: `ENROLL_REQUIRE_AUTH=true`, un rate
  limit dedicato, e un vincolo che l'URL target sia già presente in `generations_fleet` — quest'ultimo è
  ciò che elimina l'SSRF (§3.2). È l'intervento a più basso costo di tutto il documento: configurazione
  più poche righe, nessuna migrazione di schema.
- Aggiungere un identificatore `kid` alla busta firmata e far convivere due chiavi pubbliche nel plugin
  durante una transizione, per poter ruotare la chiave di firma del centro senza un redeploy simultaneo
  dell'agente su tutta la flotta (§3.15).
- Rate limiting sulle rotte del plugin (transient per IP, incrementato solo sui tentativi falliti, per
  non gonfiare `wp_options` sui siti privi di object cache persistente) e `is_ssl()` come condizione di
  accesso sulle rotte che trasportano credenziali, con lo stesso meccanismo di opt-out per reverse proxy
  già usato per l'IP (`wp_health_check_trust_proxy`, `wp-health-check.php:210-226`), perché dietro un
  proxy `is_ssl()` può risultare falso pur essendo il traffico reale in HTTPS.

### 5.C Login gestito: cifratura at rest, secondo fattore, tracciabilità

Il modello a due passi con Application Password resta, come deciso: nessun cambiamento all'architettura
generale di `/autologin/token`, solo alle credenziali che la alimentano.

- Envelope encryption applicativa: `pass_enc = AES-256-GCM(plaintext, DEK)`, DEK avvolta da una KEK
  conservata in un secret manager o in una variabile separata da Supabase, con un identificatore `kid` per
  poterla ruotare. Non `pgcrypto`: con una service_role key la decifratura avverrebbe dentro il database
  e la chiave finirebbe nello statement SQL, accanto al dato che deve proteggere. La decifratura resta
  quindi solo in memoria, dentro `get_fleet_site_credentials()` (`services.py:7091-7117`), che già
  garantisce che le credenziali non escano dal processo che le usa.
- Migrazione: `pass_enc` → backfill dal valore in chiaro → `DROP COLUMN pass`. Passo successivo
  obbligatorio, non opzionale: **rotazione di tutte le Application Password della flotta**. I valori oggi
  in chiaro sono già finiti nei backup del database prima della migrazione, quindi vanno considerati
  compromessi indipendentemente da quanto verrà fatto in seguito.
- Utente WordPress dedicato per l'autologin (es. `mavida-fleet`) invece dell'account personale
  dell'amministratore del sito cliente: revoca puntuale, audit leggibile, nessun secondo fattore umano
  scavalcato in silenzio quando quell'account ne ha uno.
- Secondo fattore sulla rotta: richiedere **sia** `manage_options` (via Application Password) **sia** il
  segreto/firma di flotta di §5.B. Oggi `wphc_debug_permission()` (`wp-health-check.php:3716-3718`) si
  accontenta della sola Application Password: chi la sottrae può generare magic link a ripetizione senza
  passare dall'hub. Con l'AND delle due credenziali, nessuna delle due da sola è sufficiente — non si
  eliminano le Application Password, si aggiunge un fattore indipendente. Effetto collaterale positivo:
  sparisce anche l'anomalia per cui `/autologin/token` funziona su un sito non ancora enrollato, perché il
  secondo fattore richiederebbe comunque un enrollment completato.
- `wp_set_auth_cookie( $user->ID, false )`: un link che vive 20 secondi non deve produrre una sessione da
  circa 14 giorni (§3.9). Per una sessione ancora più breve, un filtro su `auth_cookie_expiration` limitato
  a questo specifico login.
- Il token in query string (§README:880-884, comportamento intrinseco al meccanismo: deve rispondere a
  una navigazione del browser, non a una chiamata con header) non è eliminabile senza cambiare il
  meccanismo stesso. Compensazioni realistiche, da presentare come tali e non come soluzione: header
  `Referrer-Policy: no-referrer` sulla risposta di consumo, HTTPS reso obbligatorio, rate limit sui
  tentativi di consumo, rifiuto del consumo se l'enrollment del sito è stato nel frattempo revocato. Il
  binding a IP o User-Agent non è applicabile per costruzione: il token nasce da una richiesta dell'hub e
  viene consumato dal browser dell'operatore — due client di rete diversi per progetto, non per svista
  (il `README.md` lo dichiara già correttamente, va confermato qui, non contraddetto).
- Tracciabilità dell'attore: l'hub conosce `g.fleet_actor_id` (§5.A) ma oggi non lo trasmette al plugin,
  quindi la riga di log sul sito registra *quale utente WordPress* è entrato, non *quale persona di
  Mavida* ha premuto il pulsante. La tabella `wphc_update_log` ha già una colonna `actor`
  (`wp-health-check.php:2264-2283`, schema DB versione 4): basta che l'hub passi l'attore autenticato nel
  body della richiesta e che il plugin lo scriva. Per un servizio gestito su siti di clienti terzi è una
  lacuna di responsabilità operativa, non un dettaglio tecnico.
- Chiudere `wp_health_check_last_autologin` (§3.16): o viene esposta da una rotta/tab esistente, o va
  rimossa; in ogni caso va aggiunta all'elenco di `wphc_reset_enrollment()`
  (`wp-health-check.php:377-398`), da cui oggi sopravvive.

### 5.D Igiene trasversale

- `hmac.compare_digest` nei tre confronti di `app/auth.py` (righe 46, 130, 182) al posto di `==`/`!=`.
- `SECRET_KEY`: fallire l'avvio in produzione se assente, invece di ripiegare silenziosamente sul default
  committato nel codice.
- `CORS_ALLOWED_ORIGINS` vincolato alle origini reali della dashboard; rimuovere `@cross_origin()` dalle
  rotte che non ne hanno bisogno.
- `automation_2wp/.env.example:63`: correggere la descrizione della chiave Supabase da "anon" a quella
  effettivamente richiesta (service_role), rendendo esplicito e verificato il rischio di RLS non
  protettiva che ne deriva (§3.5), invece di lasciarlo implicito in un commento SQL.
- Ruotare il token già trapelato in `mavida-fleet/httpd/wp-health-check.http` (richiede comunque, per
  costruzione attuale, la rotazione di `MASTER_SECRET` e il re-enroll della flotta — si veda §5.B per il
  percorso che rende questa operazione locale al singolo sito in futuro). Aggiungere il pattern già in uso
  nel repo del plugin (`/http/*.local.http` in `.gitignore`) anche ai file `.http` con valori reali negli
  altri repository del monorepo.

## 6. Piano di migrazione a fasi

Ogni fase è autonoma e rilasciabile indipendentemente; solo la fase 3 cambia il protocollo di rete tra hub
e plugin, e lo fa con compatibilità doppia per non richiedere un cutover sincronizzato della flotta.

| Fase | Contenuto | Cambia il protocollo? |
|---|---|---|
| 0 | `ENROLL_REQUIRE_AUTH=true` + rate limit + allowlist URL su `/enroll`; `hmac.compare_digest`; CORS e `SECRET_KEY`; rotazione del token trapelato; `wp_set_auth_cookie(..., false)` | No |
| 1 | Sessione dashboard per-utente (§5.A), doppio binario poi flip; rimozione di `VITE_API_AUTH_TOKEN` dai frontend | No (solo hub e frontend) |
| 2 | KEK + `pass_enc` + backfill + `DROP COLUMN pass` + rotazione di tutte le Application Password della flotta | No |
| 3 | Protocollo v2: segreto per-sito cifrato, `/rotate`, `/revoke`, request signing con nonce/timestamp, nonce e freschezza su `/enroll`, `kid` sulla chiave di firma; rollout per-sito guidato da `agent_version` | Sì, con stato dual |
| 4 | Secondo fattore sull'autologin (§5.C); rotazione automatica delle Application Password via rotta firmata; rate limit e gate HTTPS nel plugin | Additivo |

## 7. Cosa non fare

Ripreso e ampliato dalla sezione "Cosa non copiare" del report di partenza:

- **Nessuna crittografia custom in userland.** Il caso di InfiniteWP nel report comparativo — RSA
  implementato a mano con fallback MD5 secret-prefix quando OpenSSL non è disponibile — è la causa diretta
  delle sue CVE storiche (auth bypass, path traversal). Ogni intervento qui proposto usa primitive
  standard già presenti: libsodium (Ed25519, già in uso per l'enroll) e HMAC-SHA256 via l'estensione hash
  di PHP.
- **Nessun mTLS o IP allowlist** verso i siti gestiti: la flotta include hosting condiviso dove il cliente
  non controlla né certificati client né IP di uscita stabili. Introdurre questo vincolo escluderebbe una
  parte della base clienti attuale, non solo un'ipotesi futura.
- **Nessuna libreria crittografica esterna nel plugin.** Il vincolo architetturale di file singolo in
  `mu-plugins/` (documentato in `CLAUDE.md`) resta valido: libsodium è già disponibile nel core PHP da
  tempo e già in uso, non serve introdurre una dipendenza.

## 8. Rischi residui accettati

Da dichiarare esplicitamente, non da nascondere dietro l'elenco degli interventi:

- Il segreto resta comunque in chiaro (decifrato) in memoria sul sito durante ogni verifica, perché il
  plugin deve poterlo confrontare: nessuno schema qui proposto elimina questo fatto, lo mitiga con
  rotazione e revoca.
- La revoca di un segreto per-sito richiede che il sito sia raggiungibile per completare la rotazione (o
  che accetti comunque la revoca al prossimo contatto): un sito offline con un segreto compromesso resta
  esposto fino al suo ritorno online.
- Il token dell'autologin resta in query string per l'intera durata della migrazione: è intrinseco al
  meccanismo (navigazione browser), non un difetto implementativo risolvibile con altre rotte REST.
- `MASTER_SECRET` resta la radice di fiducia per ogni sito non ancora migrato al protocollo v2, per tutta
  la durata del rollout a fasi.

## 9. Criteri di verifica

Per ogni intervento, il controllo negativo che ne dimostra l'efficacia:

- `X-Admin-User-Id` falsificato senza sessione valida → 401/403.
- `POST /enroll` senza Bearer, dopo la fase 0 → 401.
- Richiesta operativa (fase 3) riprodotta identica oltre la finestra di freschezza, o con nonce già usato
  → 401.
- Segreto precedente usato oltre la finestra di grazia della rotazione → 401.
- Sito con enrollment revocato → sia le rotte dati sia il consumo di un autologin già emesso devono
  rifiutare.
- Dopo la migrazione di fase 2, `pass` non deve più esistere come colonna in chiaro nello schema
  (`information_schema.columns`).

Strumenti già presenti nel repo da usare per la verifica, senza introdurne di nuovi:
`automation_2wp/tests/test_routes.py` (pytest), `composer run lint` e `composer run analyse` sul plugin,
`npx @wordpress/env start` per il flusso end-to-end enroll→health→autologin, e i file `.http` in
`http/` per gli scenari manuali.
