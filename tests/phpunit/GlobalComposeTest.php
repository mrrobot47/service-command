<?php

use EE\Model\Option;

/**
 * generate_global_docker_compose_yml() and ensure_global_network_initialized() (src/helper/service-utils.php):
 * the rendered services/docker-compose.yml, parsed.
 */
class GlobalComposeTest extends ServiceTestCase {

	const SERVICES = [ 'global-nginx-proxy', 'global-db', 'global-redis', 'global-newrelic-daemon', 'global-cron' ];

	public function test_services_containers_images_and_restart_policy() {
		$this->existing_volumes();
		Option::set( GLOBAL_DB, 'dbroot' );
		$tags = json_decode( file_get_contents( EE_ROOT . '/img-versions.json' ), true );

		$compose = $this->generate_compose();

		$this->assertSame( self::SERVICES, array_keys( $compose['services'] ) );
		$images = [ 'nginx-proxy', 'mariadb', 'redis', 'newrelic-daemon', 'cron' ];
		foreach ( self::SERVICES as $i => $service ) {
			$this->assertSame( "services_{$service}_1", $compose['services'][ $service ]['container_name'] );
			$this->assertSame( "easyengine/{$images[ $i ]}:" . $tags[ "easyengine/{$images[ $i ]}" ], $compose['services'][ $service ]['image'], $service );
			$this->assertSame( 'always', $compose['services'][ $service ]['restart'], $service );
		}
		$this->assertSame( [ 'redis-server', '/usr/local/etc/redis/redis.conf' ], $compose['services']['global-redis']['command'] );
		$this->assertSame( [ 'LOCAL_USER_ID=' . posix_geteuid(), 'LOCAL_GROUP_ID=' . posix_getegid() ], $compose['services']['global-nginx-proxy']['environment'] );
		$this->assertContains( 'MYSQL_ROOT_PASSWORD=dbroot', $compose['services']['global-db']['environment'] );
	}

	/**
	 * @dataProvider port_configs
	 */
	public function test_proxy_ports_come_from_config( $config, $ports ) {
		$this->existing_volumes();
		if ( $config ) {
			$this->write_config( $config );
		}

		$compose = $this->generate_compose();

		$this->assertSame( $ports, $compose['services']['global-nginx-proxy']['ports'] );
		foreach ( array_diff( self::SERVICES, [ 'global-nginx-proxy' ] ) as $service ) {
			$this->assertArrayNotHasKey( 'ports', $compose['services'][ $service ], $service );
		}
	}

	public function port_configs() {
		return [
			'defaults'      => [ null, [ '80:80', '443:443' ] ],
			'quoted ports'  => [ "proxy_80_port: \"8080\"\nproxy_443_port: \"8443\"\n", [ '8080:80', '8443:443' ] ],
			'unquoted port' => [ "proxy_80_port: 8081\n", [ '8081:80', '443:443' ] ],
		];
	}

	public function test_volume_mounts_per_service() {
		$this->existing_volumes();

		$compose = $this->generate_compose();

		$this->assertSame(
			[
				'global-nginx-proxy'     => [ 'certs:/etc/nginx/certs', 'dhparam:/etc/nginx/dhparam', 'confd:/etc/nginx/conf.d', 'htpasswd:/etc/nginx/htpasswd', 'vhostd:/etc/nginx/vhost.d', 'html:/usr/share/nginx/html', 'nginx_proxy_logs:/var/log/nginx', '/var/run/docker.sock:/tmp/docker.sock:ro' ],
				'global-db'              => [ 'db_data:/var/lib/mysql', 'db_conf:/etc/mysql', 'db_logs:/var/log/mysql' ],
				'global-redis'           => [ 'redis_data:/data', 'redis_conf:/usr/local/etc/redis', 'redis_logs:/var/log/redis' ],
				'global-newrelic-daemon' => [ 'newrelic_sock:/run/newrelic' ],
				'global-cron'            => [ 'cron_conf:/etc/ofelia:ro', '/var/run/docker.sock:/var/run/docker.sock:ro' ],
			],
			array_map(
				function ( $service ) {
					return $service['volumes'];
				},
				$compose['services']
			)
		);
	}

	public function test_every_named_volume_is_external_and_created_with_its_label() {
		$this->runner->on( "/^docker info /", "/var/lib/docker\n" );
		$this->runner->on( '/^docker volume inspect /', '', 1 );

		$compose = $this->generate_compose();

		$external = [];
		foreach ( $compose['volumes'] as $key => $volume ) {
			$this->assertSame( true, $volume['external'], $key );
			$external[ $key ] = $volume['name'];
		}
		$mounted = [];
		foreach ( $compose['services'] as $service ) {
			foreach ( $service['volumes'] as $mount ) {
				if ( '/' !== $mount[0] ) {
					$mounted[] = explode( ':', $mount )[0];
				}
			}
		}
		$this->assertEqualsCanonicalizing( array_keys( $external ), array_unique( $mounted ) );

		$created = [];
		foreach ( $this->runner->matching( '/^docker volume create /' ) as $command ) {
			$this->assertMatchesRegularExpression( '/--label "org\.label-schema\.vendor=EasyEngine"/', $command );
			preg_match( '/--label ["\']io\.easyengine\.site=([^"\']+)["\']\s+\\\\?\s*\'?([^\s\']+)\'?$/', $command, $m );
			$this->assertStringStartsWith( $m[1] . '_', $m[2] );
			$created[] = $m[2];
		}
		$this->assertEqualsCanonicalizing( array_values( $external ), $created );
		$this->assertSame( 'global-db_db_data', $external['db_data'] );
		$this->assertSame( '/var/lib/docker/volumes/global-nginx-proxy_certs/_data', readlink( EE_SERVICE_DIR . '/nginx-proxy/certs' ) );
	}

	public function test_existing_global_volumes_are_not_recreated() {
		$this->existing_volumes();

		$this->generate_compose();

		$this->assertSame( [], $this->runner->matching( '/^docker volume (create|inspect) /' ) );
		$this->assertCount( 5, $this->runner->matching( '/^docker volume ls .*--filter=["\']label=io\.easyengine\.site=global-(nginx-proxy|db|redis|newrelic-daemon|cron)["\'] -q$/' ) );
	}

	public function test_networks_use_the_allocated_subnets() {
		$this->existing_volumes();

		$compose = $this->generate_compose();

		$this->assertSame(
			[
				'ee-global-frontend-network' => [
					'name'   => 'ee-global-frontend-network',
					'labels' => [ 'org.label-schema.vendor=EasyEngine' ],
					'ipam'   => [ 'config' => [ [ 'subnet' => '10.1.0.0/16' ] ] ],
				],
				'ee-global-backend-network'  => [
					'name'   => 'ee-global-backend-network',
					'labels' => [ 'org.label-schema.vendor=EasyEngine' ],
					'ipam'   => [ 'config' => [ [ 'subnet' => '10.2.0.0/16' ] ] ],
				],
			],
			$compose['networks']
		);
		$this->assertSame( [ 'ee-global-frontend-network' ], $compose['services']['global-nginx-proxy']['networks'] );
		foreach ( [ 'global-db', 'global-redis', 'global-newrelic-daemon' ] as $service ) {
			$this->assertSame( [ 'ee-global-backend-network' ], $compose['services'][ $service ]['networks'], $service );
		}
		$this->assertArrayNotHasKey( 'networks', $compose['services']['global-cron'] );
	}

	public function test_a_missing_db_password_is_generated_and_saved() {
		$this->existing_volumes();

		$compose = $this->generate_compose();

		$password = Option::get( GLOBAL_DB );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{18}$/', $password );
		$this->assertContains( "MYSQL_ROOT_PASSWORD=$password", $compose['services']['global-db']['environment'] );
	}

	public function test_a_stored_db_password_is_kept() {
		$this->existing_volumes();
		Option::set( GLOBAL_DB, 'Stored1Password' );

		$this->generate_compose();
		$this->generate_compose();

		$this->assertSame( 'Stored1Password', Option::get( GLOBAL_DB ) );
		$this->assertStringContainsString( "      - MYSQL_ROOT_PASSWORD=Stored1Password\n", file_get_contents( EE_SERVICE_DIR . '/docker-compose.yml' ) );
	}

	/**
	 * @dataProvider sysctl_configs
	 */
	public function test_sysctls_are_added_only_when_enabled( $sysctl, $count ) {
		$this->existing_volumes();
		$this->set_config( [ 'sysctl' => $sysctl ] );

		$compose = $this->generate_compose();

		foreach ( [ 'global-nginx-proxy', 'global-db', 'global-redis' ] as $service ) {
			$this->assertCount( $count, isset( $compose['services'][ $service ]['sysctls'] ) ? $compose['services'][ $service ]['sysctls'] : [], $service );
		}
		$this->assertArrayNotHasKey( 'sysctls', $compose['services']['global-cron'] );
		if ( $count ) {
			$this->assertContains( 'net.ipv4.ip_local_port_range=2000 65535', $compose['services']['global-db']['sysctls'] );
		}
	}

	public function sysctl_configs() {
		return [
			'off'         => [ false, 0 ],
			'on'          => [ true, 9 ],
			'string true' => [ 'true', 9 ],
		];
	}

	public function test_network_init_allocates_distinct_free_subnets() {
		Option::set( 'frontend_subnet_ip', '' );
		Option::set( 'backend_subnet_ip', '' );
		$this->runner->on( "/^ip route show \| cut -d ' ' -f1 \| grep \^10$/", "10.0.0.0/16\n10.1.0.0/16\n" );

		\EE\Service\Utils\ensure_global_network_initialized();

		$this->assertSame( '10.2.0.0/16', Option::get( 'frontend_subnet_ip' ) );
		$this->assertSame( '10.3.0.0/16', Option::get( 'backend_subnet_ip' ) );
	}

	public function test_network_init_keeps_allocated_subnets() {
		\EE\Service\Utils\ensure_global_network_initialized();

		$this->assertSame( '10.1.0.0/16', Option::get( 'frontend_subnet_ip' ) );
		$this->assertSame( '10.2.0.0/16', Option::get( 'backend_subnet_ip' ) );
		$this->assertSame( [], $this->runner->commands() );
	}

	/**
	 * MIG-8: global-db has no stop_grace_period, so compose SIGKILLs MariaDB after 10 s, possibly mid-shutdown.
	 *
	 * @group known-bug
	 * @group MIG-8
	 */
	public function test_db_gets_time_to_shut_down_cleanly() {
		$this->existing_volumes();

		$compose = $this->generate_compose();

		$this->assertArrayHasKey( 'stop_grace_period', $compose['services']['global-db'] );
	}
}
