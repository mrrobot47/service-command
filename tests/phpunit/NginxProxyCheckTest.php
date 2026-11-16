<?php

/**
 * nginx_proxy_check() and set_nginx_proxy_version_conf() (src/helper/service-utils.php), directly and through
 * `ee service enable nginx-proxy`. Nothing listens in the test container, so the free-port check always passes.
 */
class NginxProxyCheckTest extends ServiceTestCase {

	const VERSION_CONF = 'docker-compose -f docker-compose.yml  exec global-nginx-proxy bash -c \'echo "add_header X-Powered-By \"EasyEngine v%s\";" > /version.conf\'';
	const RELOAD       = 'docker-compose -f docker-compose.yml  exec global-nginx-proxy bash -c "nginx -t && nginx -s reload"';

	private function enable() {
		( new Service_Command() )->enable( [ 'nginx-proxy' ], [] );
	}

	/** A stopped proxy whose compose file and default cert already exist. */
	private function stopped_proxy( $compose = "services: {}\n" ) {
		file_put_contents( EE_SERVICE_DIR . '/docker-compose.yml', $compose );
		mkdir( EE_SERVICE_DIR . '/nginx-proxy/certs', 0755, true );
		touch( EE_SERVICE_DIR . '/nginx-proxy/certs/default.key' );
		touch( EE_SERVICE_DIR . '/nginx-proxy/certs/default.crt' );
		// Stopped until `up -d` starts it.
		$this->container( GLOBAL_PROXY_CONTAINER, 'running' );
		$this->container( GLOBAL_PROXY_CONTAINER, 'exited', 1 );
	}

	/**
	 * @dataProvider matching_ports
	 */
	public function test_running_proxy_with_the_configured_ports_is_already_enabled( $config, $http, $https ) {
		if ( $config ) {
			$this->write_config( $config );
		}
		$this->container( GLOBAL_PROXY_CONTAINER, 'running' );
		$this->proxy_ports( $http, $https );

		$this->assertFalse( $this->run_ok( '\EE\Service\Utils\nginx_proxy_check' ) );
		$this->assertSame( [], $this->runner->matching( '/^docker-compose /' ) );

		$this->enable();
		$this->assertSame( "Notice: Service nginx_proxy already enabled.\n", $this->stdout() );
	}

	public function matching_ports() {
		return [
			'defaults'     => [ null, '80', '443' ],
			'quoted ports' => [ "proxy_80_port: \"8080\"\nproxy_443_port: \"8443\"\n", '8080', '8443' ],
		];
	}

	public function test_running_proxy_inspects_the_published_ports() {
		$this->container( GLOBAL_PROXY_CONTAINER, 'running' );
		$this->proxy_ports( '80', '443' );

		\EE\Service\Utils\nginx_proxy_check();

		$this->assertSame(
			[
				'docker inspect --format \'{{ (index (index .NetworkSettings.Ports "80/tcp") 0).HostPort }}\' services_global-nginx-proxy_1',
				'docker inspect --format \'{{ (index (index .NetworkSettings.Ports "443/tcp") 0).HostPort }}\' services_global-nginx-proxy_1',
			],
			$this->runner->matching( '/^docker inspect --format /' )
		);
	}

	/**
	 * @dataProvider mismatching_ports
	 */
	public function test_running_proxy_on_other_ports_is_an_error( $config, $http, $https ) {
		if ( $config ) {
			$this->write_config( $config );
		}
		$this->container( GLOBAL_PROXY_CONTAINER, 'running' );
		$this->proxy_ports( $http, $https );

		$this->assert_ee_error(
			'\EE\Service\Utils\nginx_proxy_check',
			"Ports of current running nginx-proxy and ports specified in EasyEngine config file don't match."
		);
	}

	public function mismatching_ports() {
		return [
			'http port changed'  => [ "proxy_80_port: \"8080\"\n", '80', '443' ],
			'https port changed' => [ "proxy_443_port: \"8443\"\n", '80', '443' ],
			'no published port'  => [ null, '', '' ],
		];
	}

	public function test_stopped_proxy_is_started_with_compose() {
		$this->stopped_proxy();

		$this->run_ok( '\EE\Service\Utils\nginx_proxy_check' );

		$this->assertSame( [ 'docker-compose -f docker-compose.yml  up -d global-nginx-proxy' ], $this->runner->matching( '/^docker-compose /' ) );
		$this->assertSame( realpath( EE_SERVICE_DIR ), getcwd() );
	}

	public function test_failed_proxy_start_is_an_error() {
		$this->stopped_proxy();
		$this->runner->on( '/ up -d global-nginx-proxy$/', '', 1 );

		$this->assert_ee_error( '\EE\Service\Utils\nginx_proxy_check', 'There was some error in starting services_global-nginx-proxy_1 container. Please check logs.' );
	}

	public function test_stopped_proxy_without_compose_file_gets_one() {
		$this->stopped_proxy();
		unlink( EE_SERVICE_DIR . '/docker-compose.yml' );
		$this->existing_volumes();

		$this->run_ok( '\EE\Service\Utils\nginx_proxy_check' );

		$this->assertStringContainsString( '      - "80:80"', file_get_contents( EE_SERVICE_DIR . '/docker-compose.yml' ) );
	}

	public function test_version_conf_is_written_into_a_running_proxy_and_nginx_reloaded() {
		$this->container( GLOBAL_PROXY_CONTAINER, 'running' );

		\EE\Service\Utils\set_nginx_proxy_version_conf();

		$this->assertSame( [ sprintf( self::VERSION_CONF, EE_VERSION ), self::RELOAD ], $this->runner->matching( '/^docker-compose /' ) );
		$this->assertSame( realpath( EE_SERVICE_DIR ), getcwd() );
	}

	public function test_version_conf_failure_skips_the_reload() {
		$this->container( GLOBAL_PROXY_CONTAINER, 'running' );
		$this->runner->on( '/> \/version\.conf\'$/', '', 1 );

		\EE\Service\Utils\set_nginx_proxy_version_conf();

		$this->assertSame( [ sprintf( self::VERSION_CONF, EE_VERSION ) ], $this->runner->matching( '/^docker-compose /' ) );
	}

	public function test_version_conf_is_skipped_for_a_stopped_proxy() {
		$this->container( GLOBAL_PROXY_CONTAINER, 'exited' );

		\EE\Service\Utils\set_nginx_proxy_version_conf();

		$this->assertSame( [], $this->runner->matching( '/^docker-compose /' ) );
	}

	/**
	 * The version line survives both shells: sh keeps the single-quoted part, bash unescapes the inner quotes.
	 */
	public function test_version_conf_command_writes_the_header_line() {
		$command = sprintf( self::VERSION_CONF, '4.99.0' );
		$inner   = substr( $command, strpos( $command, "bash -c '" ) + 9, -1 );
		$dir     = $this->sandbox();
		$inner   = str_replace( '/version.conf', "$dir/version.conf", $inner );
		exec( 'sh -c ' . escapeshellarg( $inner ) );
		$this->assertStringEqualsFile( "$dir/version.conf", "add_header X-Powered-By \"EasyEngine v4.99.0\";\n" );
	}

	/**
	 * SVC-5: an unquoted `proxy_80_port: 80` is loaded as int(80) and compared with `!==` to the string '80'.
	 *
	 * @group known-bug
	 * @group SVC-5
	 */
	public function test_unquoted_config_ports_match_the_running_proxy() {
		$this->write_config( "proxy_80_port: 80\nproxy_443_port: 443\n" );
		$this->container( GLOBAL_PROXY_CONTAINER, 'running' );
		$this->proxy_ports( '80', '443' );

		$this->assertFalse( $this->run_ok( '\EE\Service\Utils\nginx_proxy_check' ) );
	}

	/**
	 * SVC-1: when the default cert already exists, nginx_proxy_check() returns null after starting a stopped proxy,
	 * so enable says "already enabled" and the version.conf is never written.
	 *
	 * @group known-bug
	 * @group SVC-1
	 */
	public function test_starting_a_stopped_proxy_reports_enabled_and_sets_the_version() {
		$this->stopped_proxy();

		$this->run_ok(
			function () {
				$this->enable();
			}
		);

		$this->assertStringContainsString( 'Success: Service nginx_proxy enabled.', $this->stdout() );
		$this->assertContains( sprintf( self::VERSION_CONF, EE_VERSION ), $this->runner->commands() );
	}

	/**
	 * SVC-10: a stopped proxy is started from the old compose file, so changed proxy ports aren't applied.
	 *
	 * @group known-bug
	 * @group SVC-10
	 */
	public function test_starting_a_stopped_proxy_applies_changed_ports() {
		$this->existing_volumes();
		$this->generate_compose();
		$this->stopped_proxy( file_get_contents( EE_SERVICE_DIR . '/docker-compose.yml' ) );
		$this->write_config( "proxy_80_port: \"18080\"\nproxy_443_port: \"18443\"\n" );

		$this->run_ok( '\EE\Service\Utils\nginx_proxy_check' );

		$compose = Symfony\Component\Yaml\Yaml::parse( file_get_contents( EE_SERVICE_DIR . '/docker-compose.yml' ) );
		$this->assertSame( [ '18080:80', '18443:443' ], $compose['services']['global-nginx-proxy']['ports'] );
	}
}
