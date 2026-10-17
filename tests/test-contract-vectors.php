<?php
/**
 * Test dei vettori di contratto hub <-> plugin.
 *
 * I vettori (tests/fixtures/contract-vectors.json) sono calcolati dal codice REALE dell'hub
 * da tests/tools/gen_contract_vectors.py. Qui si verifica che il plugin produca/verifichi
 * esattamente gli stessi byte. Ed25519 e' deterministico: la firma calcolata in PHP con i
 * seed di test deve coincidere byte per byte con quella calcolata in Python.
 *
 * Fedelta' ai byte dell'hub: i body POST dei vettori HMAC sono prodotti come li produce l'hub
 * (json.dumps con i separatori di default e gli escape \uXXXX) piu' un caso con byte UTF-8
 * grezzi; le release sono firmate dal firmatario reale (hub/scripts/sign_release.py), con e
 * senza "v" nel tag, e il builder del messaggio firmato del plugin (wphc_install_release) e'
 * esercitato davvero: vedi test_release_message_builder_accepts_the_hub_signature().
 *
 * Limite noto: le rotte REST controllano l'ora corrente (finestra di +/-300 s), e i vettori
 * hanno un timestamp fisso. Per questo i vettori passano dalle funzioni pure del plugin; il
 * ramo "rotta" e' coperto da test-enroll.php/test-auth.php con buste firmate al volo, che
 * usano gli helper della classe base. Gli helper sono a loro volta confrontati qui con i
 * vettori, quindi la catena hub -> helper -> rotta e' completa.
 *
 * @package WP_Health_Check
 */

/**
 * Contratto di firma e normalizzazione fra hub e plugin.
 */
class WPHC_Test_Contract_Vectors extends WPHC_Test_Case {

	// -----------------------------------------------------------------------
	// Data provider (leggono il JSON generato)
	// -----------------------------------------------------------------------

	/**
	 * Un caso per ogni voce di una sezione dei vettori.
	 *
	 * @param string $section Nome della sezione.
	 * @return array<string,array{0:array}>
	 */
	private static function cases( $section ) {
		$cases = array();
		foreach ( self::contract_vectors()[ $section ] as $index => $vector ) {
			$cases[ $section . '#' . $index ] = array( $vector );
		}

		return $cases;
	}

	/** @return array */
	public function provide_normalize_url() {
		return self::cases( 'normalize_url' );
	}

	/** @return array */
	public function provide_token_v1() {
		return self::cases( 'token_v1' );
	}

	/** @return array */
	public function provide_enroll_v1() {
		return self::cases( 'enroll_v1' );
	}

	/** @return array */
	public function provide_enroll_v2() {
		return self::cases( 'enroll_v2' );
	}

	/** @return array */
	public function provide_rotate() {
		return self::cases( 'rotate' );
	}

	/** @return array */
	public function provide_revoke() {
		return self::cases( 'revoke' );
	}

	/** @return array */
	public function provide_request_hmac() {
		return self::cases( 'request_hmac' );
	}

	/** @return array */
	public function provide_webhook() {
		return self::cases( 'webhook' );
	}

	/** @return array */
	public function provide_release() {
		return self::cases( 'release' );
	}

	// -----------------------------------------------------------------------
	// Chiavi di test
	// -----------------------------------------------------------------------

	/**
	 * Le chiavi pubbliche del plugin nei test sono quelle derivate dai seed dei vettori.
	 */
	public function test_keys_match_plugin_constants() {
		$keys = self::contract_vectors()['keys'];

		$this->assertSame( $keys['k1']['public_b64'], WP_HEALTH_CHECK_CENTRAL_PUBKEY );
		$this->assertSame( $keys['k2']['public_b64'], WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2 );
		$this->assertSame( base64_encode( WPHC_TEST_SEED_K1 ), $keys['k1']['seed_b64'] );
		$this->assertSame( base64_encode( WPHC_TEST_SEED_K2 ), $keys['k2']['seed_b64'] );
	}

	/**
	 * L'URL del sito di test e' fissato in modo INDIPENDENTE dal plugin: own_site_url() lo ricava da
	 * wphc_normalize_site_url(), quindi se quella funzione sbagliasse tutti i test "positivi" sbaglierebbero
	 * insieme a lei. Con HTTPS simulato, home_url() e' https://<dominio della test library>.
	 */
	public function test_site_url_is_the_https_test_domain() {
		$this->assertTrue( is_ssl() );
		$this->assertSame( 'https://' . WP_TESTS_DOMAIN, self::call( 'wphc_normalize_site_url' ) );
		$this->assertSame( 'https://' . WP_TESTS_DOMAIN, $this->own_site_url() );
	}

	// -----------------------------------------------------------------------
	// normalize_url
	// -----------------------------------------------------------------------

	/**
	 * Il plugin normalizza come previsto. Quando l'hub produce un valore e non c'e' una
	 * divergenza dichiarata, plugin e hub DEVONO dare la stessa stringa (e' il contratto
	 * del token: l'hub deriva l'HMAC dall'URL normalizzato, il sito lo confronta).
	 *
	 * @dataProvider provide_normalize_url
	 *
	 * @param array $vector Vettore.
	 */
	public function test_normalize_url( array $vector ) {
		$actual = self::call( 'wphc_normalize_url', $vector['input'] );

		$this->assertSame( $vector['plugin'], $actual, $vector['note'] );

		if ( ! $vector['diverge'] && null !== $vector['hub'] ) {
			$this->assertSame( $vector['hub'], $actual, 'Plugin e hub devono coincidere: ' . $vector['note'] );
		}
	}

	/**
	 * Idempotenza: un URL gia' normalizzato dall'hub resta invariato se lo si normalizza di nuovo con
	 * il plugin (il sito confronta URL gia' normalizzati e il token dell'hub deriva da quella forma).
	 * Vale per ogni voce non divergente in cui l'hub produce un valore.
	 */
	public function test_normalize_url_is_idempotent_on_the_hub_output() {
		$checked = 0;
		foreach ( self::contract_vectors()['normalize_url'] as $vector ) {
			if ( $vector['diverge'] || null === $vector['hub'] ) {
				continue;
			}
			$this->assertSame( $vector['hub'], self::call( 'wphc_normalize_url', $vector['hub'] ), 'idempotenza: ' . $vector['input'] );
			++$checked;
		}
		$this->assertGreaterThan( 10, $checked );
	}

	/**
	 * Le divergenze note sono davvero tali: se un domani plugin e hub coincidessero questo
	 * test lo segnala, cosi' la voce va tolta dall'elenco delle divergenze in modo consapevole.
	 */
	public function test_known_divergences_are_real() {
		$divergent = array_filter(
			self::contract_vectors()['normalize_url'],
			static function ( $v ) {
				return $v['diverge'];
			}
		);

		$this->assertNotEmpty( $divergent );
		foreach ( $divergent as $vector ) {
			$this->assertNotSame( $vector['hub'], self::call( 'wphc_normalize_url', $vector['input'] ), $vector['note'] );
		}
	}

	// -----------------------------------------------------------------------
	// Token v1 (derivazione lato hub; il plugin non la esegue ma ne usa la codifica)
	// -----------------------------------------------------------------------

	/**
	 * Il token v1 e' base64url(HMAC-SHA256(url_normalizzato, MASTER_SECRET)) senza padding:
	 * ricalcolato con la codifica del plugin deve coincidere con quello dell'hub.
	 *
	 * @dataProvider provide_token_v1
	 *
	 * @param array $vector Vettore.
	 */
	public function test_token_v1( array $vector ) {
		$url = $vector['url_normalizzato'];

		// L'URL del vettore e' gia' normalizzato: il plugin non lo cambia.
		$this->assertSame( $url, self::call( 'wphc_normalize_url', $url ) );

		$token = self::call( 'wphc_base64url_encode', hash_hmac( 'sha256', $url, $vector['master_secret'], true ) );
		$this->assertSame( $vector['token'], $token );
		$this->assertSame( $vector['token'], self::b64url( hash_hmac( 'sha256', $url, $vector['master_secret'], true ) ), 'Helper di test e plugin devono codificare allo stesso modo' );
	}

	// -----------------------------------------------------------------------
	// Buste centro -> sito
	// -----------------------------------------------------------------------

	/**
	 * Verifica comune: messaggio canonico, firma del plugin e firma ricalcolata in PHP.
	 *
	 * @param string $canonical Messaggio canonico del vettore.
	 * @param string $built     Messaggio costruito dal plugin.
	 * @param string $signature Firma del vettore (base64).
	 * @param string $helper    Messaggio costruito dagli helper di test.
	 */
	private function assert_envelope_contract( $canonical, $built, $signature, $helper ) {
		// 1. Il plugin costruisce lo stesso messaggio dell'hub.
		$this->assertSame( $canonical, $built, 'Messaggio canonico del plugin diverso da quello dell\'hub' );
		// 2. Gli helper dei test costruiscono lo stesso messaggio (catena helper -> rotta).
		$this->assertSame( $canonical, $helper, 'Messaggio canonico degli helper di test diverso da quello dell\'hub' );
		// 3. Il plugin accetta la firma dell'hub (k1, scopo envelope).
		$this->assertTrue( self::call( 'wphc_verify_central_signature', $canonical, $signature, 'k1' ), 'Firma dell\'hub non accettata dal plugin' );
		// 4. Ed25519 e' deterministico: la firma PHP coincide byte per byte con quella Python.
		$this->assertSame( $signature, self::sign_message( $canonical, 'k1' ), 'Firma PHP diversa da quella Python' );
		// 5. Una firma di busta NON vale come firma di release (separazione k1/k2).
		$this->assertFalse( self::call( 'wphc_verify_central_signature', $canonical, $signature, 'k2', 'release' ) );
		// 6. Un messaggio alterato non verifica.
		$this->assertFalse( self::call( 'wphc_verify_central_signature', $canonical . 'x', $signature, 'k1' ) );
	}

	/**
	 * Enroll v1: "site\ntoken\norigin\nissued_at".
	 *
	 * @dataProvider provide_enroll_v1
	 *
	 * @param array $vector Vettore.
	 */
	public function test_enroll_v1( array $vector ) {
		// L'hub usa '' per "nessuna origin"; il plugin tratta null e '' allo stesso modo.
		$origin = $vector['dashboard_origin'];
		$built  = self::call( 'wphc_build_enroll_signing_payload', $vector['site_url'], $vector['token'], $origin, $vector['issued_at'] );
		$helper = self::message_enroll_v1( $vector['site_url'], $vector['token'], $origin, $vector['issued_at'] );

		$this->assert_envelope_contract( $vector['canonical'], $built, $vector['signature'], $helper );

		if ( '' === $origin ) {
			$this->assertSame( $built, self::call( 'wphc_build_enroll_signing_payload', $vector['site_url'], $vector['token'], null, $vector['issued_at'] ), 'null e stringa vuota devono dare lo stesso messaggio' );
		}
	}

	/**
	 * Enroll v2: "enroll\nsite\ntoken\norigin\nnonce\nissued_at\nprotocol".
	 *
	 * @dataProvider provide_enroll_v2
	 *
	 * @param array $vector Vettore.
	 */
	public function test_enroll_v2( array $vector ) {
		$parts = array(
			$vector['site_url'],
			$vector['token'],
			$vector['dashboard_origin'],
			$vector['nonce'],
			(string) $vector['issued_at'],
			(string) $vector['protocol'],
		);

		$this->assert_envelope_contract(
			$vector['canonical'],
			self::call( 'wphc_build_signed_payload', 'enroll', $parts ),
			$vector['signature'],
			self::message_signed( 'enroll', $parts )
		);
	}

	/**
	 * Rotate: "rotate\nsite\nnew_token\nnonce\nissued_at\nprotocol".
	 *
	 * @dataProvider provide_rotate
	 *
	 * @param array $vector Vettore.
	 */
	public function test_rotate( array $vector ) {
		$parts = array( $vector['site_url'], $vector['new_token'], $vector['nonce'], (string) $vector['issued_at'], (string) $vector['protocol'] );

		$this->assert_envelope_contract(
			$vector['canonical'],
			self::call( 'wphc_build_signed_payload', 'rotate', $parts ),
			$vector['signature'],
			self::message_signed( 'rotate', $parts )
		);
	}

	/**
	 * Revoke: "revoke\nsite\nnonce\nissued_at".
	 *
	 * @dataProvider provide_revoke
	 *
	 * @param array $vector Vettore.
	 */
	public function test_revoke( array $vector ) {
		$parts = array( $vector['site_url'], $vector['nonce'], (string) $vector['issued_at'] );

		$this->assert_envelope_contract(
			$vector['canonical'],
			self::call( 'wphc_build_signed_payload', 'revoke', $parts ),
			$vector['signature'],
			self::message_signed( 'revoke', $parts )
		);
	}

	/**
	 * Il prefisso di dominio impedisce di riusare una firma su un'operazione diversa:
	 * la firma di un rotate non verifica come revoke e viceversa.
	 */
	public function test_domain_prefix_prevents_cross_use() {
		$vectors = self::contract_vectors();
		$rotate  = $vectors['rotate'][0];
		$revoke  = $vectors['revoke'][0];

		// Messaggio di revoke ricostruito con il prefisso del rotate: la firma non e' valida.
		$this->assertFalse( self::call( 'wphc_verify_central_signature', self::call( 'wphc_build_signed_payload', 'revoke', array( $rotate['site_url'], $rotate['nonce'], (string) $rotate['issued_at'] ) ), $rotate['signature'], 'k1' ) );
		$this->assertFalse( self::call( 'wphc_verify_central_signature', $revoke['canonical'], $rotate['signature'], 'k1' ) );
	}

	// -----------------------------------------------------------------------
	// HMAC delle richieste operative (X-WPHC-*)
	// -----------------------------------------------------------------------

	/**
	 * Firma HMAC delle richieste: stringa canonica e firma identiche a quelle dell'hub.
	 * Il plugin non espone una funzione che accetti un timestamp vecchio, quindi qui si
	 * verifica la formula (con la codifica base64url del plugin); l'accettazione reale
	 * di una richiesta firmata dagli helper e' in test-auth.php.
	 *
	 * @dataProvider provide_request_hmac
	 *
	 * @param array $vector Vettore.
	 */
	public function test_request_hmac( array $vector ) {
		$canonical = $vector['method'] . "\n" . $vector['route'] . "\n" . hash( 'sha256', $vector['body'] ) . "\n" . $vector['timestamp'] . "\n" . $vector['nonce'];
		$this->assertSame( $vector['canonical'], $canonical );

		// Codifica del plugin (wphc_base64url_encode) applicata all'HMAC.
		$this->assertSame( $vector['signature'], self::call( 'wphc_base64url_encode', hash_hmac( 'sha256', $canonical, $vector['secret'], true ) ) );

		// Helper di test (usato dai test delle rotte) uguale all'hub.
		$this->assertSame( $vector['signature'], self::hmac_signature( $vector['secret'], $vector['method'], $vector['route'], $vector['body'], $vector['timestamp'], $vector['nonce'] ) );

		// I byte del body sono quelli dell'hub: lo SHA-256 calcolato da Python coincide con quello di PHP
		// sulla stringa letta dal JSON (nessuna ricodifica dei byte UTF-8 o degli escape \uXXXX).
		$this->assertSame( $vector['body_sha256'], hash( 'sha256', $vector['body'] ), $vector['body_producer'] );
	}

	/**
	 * Un body prodotto come lo produce l'hub (separatori ", " e ": ", escape \uXXXX) e un body con byte
	 * UTF-8 grezzi arrivano al plugin identici: WP_REST_Request::get_body() restituisce i byte esatti, e
	 * wphc_verify_request_signature() accetta una firma HMAC calcolata (con timestamp fresco) sullo stesso
	 * corpo. Il timestamp dei vettori e' fisso (e quindi fuori finestra): qui si firma al volo con la
	 * formula gia' verificata contro il vettore dell'hub in test_request_hmac().
	 *
	 * @dataProvider provide_request_hmac
	 *
	 * @param array $vector Vettore.
	 */
	public function test_plugin_accepts_the_hub_body_bytes_with_a_fresh_signature( array $vector ) {
		$headers = $this->signed_headers( $vector['secret'], $vector['method'], substr( $vector['route'], strlen( self::NS ) ), $vector['body'] );

		$request = new WP_REST_Request( $vector['method'], $vector['route'] );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( $vector['body'] );

		$this->assertSame( $vector['body'], $request->get_body() );
		$this->assertTrue( self::call( 'wphc_verify_request_signature', $request, $vector['secret'] ), $vector['body_producer'] );
	}

	/**
	 * Il rifiuto di un vettore HMAC e' dovuto SOLO al timestamp fuori finestra: la firma dell'hub e'
	 * corretta (il test_request_hmac la verifica), e la stessa richiesta con il timestamp fresco e la
	 * firma ricalcolata e' accettata. Il rifiuto ha il codice generico wphc_unauthorized e non brucia
	 * il nonce (che si registra solo a firma verificata).
	 */
	public function test_request_hmac_vector_is_rejected_only_for_its_stale_timestamp() {
		$vector  = self::contract_vectors()['request_hmac'][0];
		$request = new WP_REST_Request( $vector['method'], $vector['route'] );
		$request->set_header( 'X-WPHC-Timestamp', $vector['timestamp'] );
		$request->set_header( 'X-WPHC-Nonce', $vector['nonce'] );
		$request->set_header( 'X-WPHC-Signature', $vector['signature'] );

		// Il timestamp fisso dei vettori e' nel passato: fuori dalla finestra di 300 s.
		$this->assertGreaterThan( WP_HEALTH_CHECK_REPLAY_WINDOW, abs( time() - (int) $vector['timestamp'] ) );

		$result = self::call( 'wphc_verify_request_signature', $request, $vector['secret'] );

		$this->assertWPError( $result );
		$this->assertSame( 'wphc_unauthorized', $result->get_error_code() );
		$this->assertFalse( get_transient( 'wphc_nonce_' . hash( 'sha256', $vector['nonce'] ) ), 'Un rifiuto non registra il nonce' );

		// Stessa richiesta con timestamp fresco e firma ricalcolata: accettata (il solo motivo del rifiuto era l'ora).
		$fresh = $this->signed_headers( $vector['secret'], $vector['method'], substr( $vector['route'], strlen( self::NS ) ), $vector['body'], null, $vector['nonce'] );
		foreach ( $fresh as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$this->assertTrue( self::call( 'wphc_verify_request_signature', $request, $vector['secret'] ) );
	}

	// -----------------------------------------------------------------------
	// Webhook in uscita (plugin firma, hub verifica)
	// -----------------------------------------------------------------------

	/**
	 * Formula del webhook: "POST\n{event}\n{sha256(body)}\n{timestamp}\n{nonce}". Si controlla
	 * (a) che la formula riproduca il vettore dell'hub, (b) che la firma prodotta davvero da
	 * self::call( 'wphc_build_outbound_signature' ) (che usa ora e nonce propri) rispetti la stessa formula.
	 *
	 * @dataProvider provide_webhook
	 *
	 * @param array $vector Vettore.
	 */
	public function test_webhook_signature( array $vector ) {
		// (a) La formula ricostruisce il vettore dell'hub.
		$canonical = "POST\n" . $vector['event'] . "\n" . hash( 'sha256', $vector['body'] ) . "\n" . $vector['timestamp'] . "\n" . $vector['nonce'];
		$this->assertSame( $vector['canonical'], $canonical );
		$this->assertSame( $vector['signature'], self::call( 'wphc_base64url_encode', hash_hmac( 'sha256', $canonical, $vector['secret'], true ) ) );

		// (b) Il plugin applica la stessa formula con il proprio timestamp/nonce.
		$out = self::call( 'wphc_build_outbound_signature', $vector['event'], $vector['body'], $vector['secret'] );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $out['nonce'] );
		$this->assertEqualsWithDelta( time(), $out['timestamp'], 5 );

		$expected_canonical = "POST\n" . $vector['event'] . "\n" . hash( 'sha256', $vector['body'] ) . "\n" . $out['timestamp'] . "\n" . $out['nonce'];
		$this->assertSame( self::b64url( hash_hmac( 'sha256', $expected_canonical, $vector['secret'], true ) ), $out['signature'] );
		$this->assertSame( array( 'timestamp', 'nonce', 'signature' ), array_keys( $out ) );
	}

	// -----------------------------------------------------------------------
	// Release (firma k2 su "release\n<tag>\n<sha256>")
	// -----------------------------------------------------------------------

	/**
	 * La release e' firmata con k2 e verificata dal plugin con scopo "release".
	 *
	 * @dataProvider provide_release
	 *
	 * @param array $vector Vettore.
	 */
	public function test_release_signature( array $vector ) {
		// Lo SHA-256 del contenuto fisso e' quello del vettore.
		$this->assertSame( $vector['sha256'], hash( 'sha256', $vector['content'] ) );

		// Messaggio: "release\n<tag>\n<sha256>" (stesso formato costruito da wphc_perform_self_update).
		$message = "release\n" . $vector['tag'] . "\n" . $vector['sha256'];
		$this->assertSame( $vector['canonical'], $message );
		$this->assertSame( $vector['canonical'], self::message_signed( 'release', array( $vector['tag'], $vector['sha256'] ) ) );

		// Il plugin accetta la firma con scopo release e kid k2...
		$this->assertTrue( self::call( 'wphc_verify_central_signature', $message, $vector['signature'], $vector['kid'], 'release' ) );
		// ...e la firma PHP coincide byte per byte con quella Python.
		$this->assertSame( $vector['signature'], self::sign_message( $message, 'k2' ) );

		// Separazione delle chiavi: una firma di release NON vale come busta (scopo envelope),
		// ne' con kid k2 ne' con k1; e k1 non puo' firmare release.
		$this->assertFalse( self::call( 'wphc_verify_central_signature', $message, $vector['signature'], 'k2' ) );
		$this->assertFalse( self::call( 'wphc_verify_central_signature', $message, $vector['signature'], 'k1' ) );
		$this->assertFalse( self::call( 'wphc_verify_central_signature', $message, $vector['signature'], 'k1', 'release' ) );
		$this->assertFalse( self::call( 'wphc_verify_central_signature', $message, self::sign_message( $message, 'k1' ), 'k2', 'release' ) );

		// Tag o hash diversi: firma non valida.
		$this->assertFalse( self::call( 'wphc_verify_central_signature', "release\nv0.0.1\n" . $vector['sha256'], $vector['signature'], 'k2', 'release' ) );

		// Il JSON .sig e' esattamente quello che scrive il firmatario reale dell'hub (tag, sha256, kid, signature).
		$sig = json_decode( $vector['sig_json'], true );
		$this->assertSame( array( 'tag', 'sha256', 'kid', 'signature' ), array_keys( $sig ) );
		$this->assertSame( $vector['sig'], $sig );
		$this->assertSame( $vector['tag'], $sig['tag'] );
		$this->assertSame( $vector['sha256'], $sig['sha256'] );
		$this->assertSame( $vector['signature'], $sig['signature'] );
		// Sia "v1.2.3" sia "1.2.3" sono tag ammessi; il tag finisce nel messaggio firmato cosi' com'e'.
		$this->assertTrue( self::call( 'wphc_is_release_tag', $vector['tag'] ), $vector['tag'] );
	}

	/**
	 * Il builder del messaggio firmato DEL PLUGIN ("release\n<tag>\n<sha256>" dentro wphc_install_release)
	 * accetta la firma del firmatario reale dell'hub, senza scrivere nulla: si pone una DIRECTORY al
	 * posto del .bak, cosi' il backup (passo successivo alla verifica della firma) fallisce e la
	 * pipeline si ferma con wphc_update_backup_failed PRIMA di toccare il plugin. Quindi:
	 *  - firma valida (dell'hub)         -> 'error' / wphc_update_backup_failed (firma e integrita' superate);
	 *  - firma di un altro tag, o assente -> 'integrity_check_failed'.
	 * Le versioni inferiori a quella del plugin sotto test sono "up_to_date" (la pipeline non fa downgrade):
	 * per quelle il builder non e' raggiunto, e restano i vettori 9.9.9 (con e senza "v").
	 *
	 * @dataProvider provide_release
	 *
	 * @param array $vector Vettore.
	 */
	public function test_release_message_builder_accepts_the_hub_signature( array $vector ) {
		$bak = WPHC_PLUGIN_FILE . '.bak';
		$this->assertFalse( file_exists( $bak ), 'precondizione: nessun .bak accanto al plugin sotto test' );

		$install = function ( $tag, $sig ) use ( $vector ) {
			return self::call( 'wphc_install_release', $tag, $vector['content'], $vector['sha256'], $sig, true );
		};

		// Una directory al posto del .bak fa fallire copy() del backup, dopo la verifica della firma.
		$this->assertTrue( mkdir( $bak ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		try {
			if ( version_compare( ltrim( $vector['tag'], 'v' ), WP_HEALTH_CHECK_VERSION, '<' ) ) {
				$this->assertSame( 'up_to_date', $install( $vector['tag'], $vector['sig'] )['result'], 'nessun downgrade' );

				return;
			}

			$result = $install( $vector['tag'], $vector['sig'] );
			$this->assertSame( 'error', $result['result'], wp_json_encode( $result ) );
			$this->assertSame( 'wphc_update_backup_failed', $result['code'], 'firma e integrita\' superate, si ferma al backup' );

			// Firma di un tag diverso (stesso sha): rifiutata dal builder del plugin.
			$other = $vector['sig'];
			$other['signature'] = self::sign_message( "release\naltro-tag\n" . $vector['sha256'], 'k2' );
			$this->assertSame( 'integrity_check_failed', $install( $vector['tag'], $other )['result'] );

			// Firma assente.
			$this->assertSame( 'integrity_check_failed', $install( $vector['tag'], array() )['result'] );
		} finally {
			rmdir( $bak ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		$this->assertFalse( file_exists( $bak ) );
	}

	// -----------------------------------------------------------------------
	// Guardie di wphc_verify_central_signature (fail closed)
	// -----------------------------------------------------------------------

	/**
	 * Input malformati: la verifica risponde false senza eccezioni.
	 */
	public function test_verify_central_signature_fails_closed_on_malformed_input() {
		$message = 'qualunque';

		$this->assertFalse( self::call( 'wphc_verify_central_signature', $message, '', 'k1' ), 'firma vuota' );
		$this->assertFalse( self::call( 'wphc_verify_central_signature', $message, '!!non-base64!!', 'k1' ), 'base64 non valido (strict)' );
		$this->assertFalse( self::call( 'wphc_verify_central_signature', $message, base64_encode( 'corta' ), 'k1' ), 'lunghezza firma errata' );
		$this->assertFalse( self::call( 'wphc_verify_central_signature', $message, self::sign_message( $message ), 'k9' ), 'kid sconosciuto' );
		$this->assertFalse( self::call( 'wphc_verify_central_signature', $message, self::sign_message( $message ), '' ), 'kid vuoto' );
	}

	/**
	 * Funzioni base64url del plugin: round trip, senza padding e con l'alfabeto URL-safe.
	 */
	public function test_base64url_helpers() {
		$binary = "\xfb\xff\xfe" . 'abc'; // Produce '+' e '/' in base64 standard.

		$encoded = self::call( 'wphc_base64url_encode', $binary );
		$this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $encoded );
		$this->assertSame( $binary, self::call( 'wphc_base64url_decode', $encoded ) );
		$this->assertFalse( self::call( 'wphc_base64url_decode', '*not valid*' ) );
	}
}
