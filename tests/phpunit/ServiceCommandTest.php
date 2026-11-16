<?php

use EE\Model\Option;

/**
 * Service_Command: service-name mapping, the compose commands of enable/disable/restart/reload/refresh and their results.
 */
class ServiceCommandTest extends ServiceTestCase {

	private function command() {
		return new Service_Command();
	}

	private function compose_file() {
		file_put_contents( EE_SERVICE_DIR . '/docker-compose.yml', "services: {}\n" );
	}

	/**
	 * @dataProvider service_names
	 */
	public function test_filter_service_maps_names_to_compose_services( $args, $service ) {
		$this->assertSame( $service, self::call_private( $this->command(), 'filter_service', $args ) );
	}

	public function service_names() {
		return [
			'nginx-proxy'           => [ [ 'nginx-proxy' ], 'global-nginx-proxy' ],
			'db'                    => [ [ 'db' ], 'global-db' ],
			'redis'                 => [ [ 'redis' ], 'global-redis' ],
			'newrelic-daemon'       => [ [ 'newrelic-daemon' ], 'global-newrelic-daemon' ],
			'cron'                  => [ [ 'cron' ], 'global-cron' ],
			'first known name wins' => [ [ 'nope', 'redis', 'db' ], 'global-db' ],
		];
	}

	/**
	 * @dataProvider unknown_services
	 */
	public function test_unknown_services_are_rejected_before_any_command( $name ) {
		foreach ( [ 'enable', 'disable', 'restart', 'reload' ] as $subcommand ) {
			$this->assert_ee_error(
				function () use ( $subcommand, $name ) {
					$this->command()->$subcommand( [ $name ], [] );
				},
				"Unable to find global EasyEngine service $name"
			);
		}
		$this->assertSame( [], $this->runner->commands() );
	}

	public function unknown_services() {
		return [
			'compose name'   => [ 'global-db' ],
			'container name' => [ 'services_global-db_1' ],
			'unknown'        => [ 'mysql' ],
			'shell payload'  => [ 'db; touch /tmp/x' ],
		];
	}

	public function test_constructor_works_in_the_service_dir() {
		chdir( $this->sandbox() );
		$this->command();
		$this->assertSame( realpath( EE_SERVICE_DIR ), getcwd() );
	}

	/**
	 * @dataProvider compose_actions
	 */
	public function test_disable_and_restart_run_compose_on_the_service( $subcommand, $name, $command, $message ) {
		$this->run_ok(
			function () use ( $subcommand, $name ) {
				$this->command()->$subcommand( [ $name ], [] );
			}
		);
		$this->assertSame( [ $command ], $this->runner->commands() );
		$this->assertStringContainsString( "Success: $message", $this->stdout() );
	}

	public function compose_actions() {
		return [
			'disable db'           => [ 'disable', 'db', 'docker-compose -f docker-compose.yml  stop global-db', 'Service global-db disabled.' ],
			'disable nginx-proxy'  => [ 'disable', 'nginx-proxy', 'docker-compose -f docker-compose.yml  stop global-nginx-proxy', 'Service global-nginx-proxy disabled.' ],
			'restart redis'        => [ 'restart', 'redis', 'docker-compose -f docker-compose.yml  restart global-redis', 'Service global-redis restarted.' ],
			'restart cron'         => [ 'restart', 'cron', 'docker-compose -f docker-compose.yml  restart global-cron', 'Service global-cron restarted.' ],
		];
	}

	public function test_reload_regenerates_the_proxy_config_and_reloads_nginx() {
		$this->run_ok(
			function () {
				$this->command()->reload( [ 'nginx-proxy' ], [] );
			}
		);
		$this->assertSame(
			[ 'docker-compose -f docker-compose.yml  exec global-nginx-proxy sh -c "/app/docker-entrypoint.sh /usr/local/bin/docker-gen /app/nginx.tmpl /etc/nginx/conf.d/default.conf; /usr/sbin/nginx -t; /usr/sbin/nginx -s reload"' ],
			$this->runner->commands()
		);
		$this->assertStringContainsString( 'Success: Reloaded global-nginx-proxy.', $this->stdout() );
	}

	/**
	 * @dataProvider not_reloadable
	 */
	public function test_reload_of_other_services_only_warns( $name ) {
		$this->command()->reload( [ $name ], [] );
		$this->assertSame( [], $this->runner->commands() );
		$this->assertStringContainsString( "Warning: global-$name can not be reloaded.", $this->stderr() );
	}

	public function not_reloadable() {
		return [ [ 'db' ], [ 'redis' ], [ 'newrelic-daemon' ], [ 'cron' ] ];
	}

	public function test_enable_boots_a_stopped_service() {
		$this->compose_file();
		$this->container( 'services_global-db_1', 'missing' );

		$this->run_ok(
			function () {
				$this->command()->enable( [ 'db' ], [] );
			}
		);

		$this->assertSame( [ 'docker-compose -f docker-compose.yml  up -d global-db' ], $this->runner->matching( '/^docker-compose /' ) );
		$this->assertSame( realpath( EE_SERVICE_DIR ), getcwd() );
		$this->assertStringContainsString( 'Success: Service global-db enabled.', $this->stdout() );
	}

	public function test_enable_starts_an_exited_container() {
		$this->compose_file();
		$this->container( 'services_global-redis_1', 'exited' );

		$this->command()->enable( [ 'redis' ], [] );

		$this->assertSame( [ 'docker start services_global-redis_1' ], $this->runner->matching( '/^docker (start|-compose)/' ) );
		$this->assertStringContainsString( 'Success: Service global-redis enabled.', $this->stdout() );
	}

	public function test_enable_leaves_a_running_service_alone() {
		$this->compose_file();
		$this->container( 'services_global-cron_1', 'running' );

		$this->command()->enable( [ 'cron' ], [] );

		$this->assertSame( [], $this->runner->matching( '/^docker (start|-compose)/' ) );
		$this->assertSame( "Notice: Service global-cron already enabled.\n", $this->stdout() );
	}

	public function test_enable_generates_the_global_compose_file_when_missing() {
		$this->existing_volumes();
		$this->container( 'services_global-redis_1', 'running' );

		$this->command()->enable( [ 'redis' ], [] );

		$this->assertFileExists( EE_SERVICE_DIR . '/docker-compose.yml' );
		$this->assertStringContainsString( '  global-redis:', file_get_contents( EE_SERVICE_DIR . '/docker-compose.yml' ) );
	}

	public function test_init_global_container_accepts_a_container_name() {
		$this->compose_file();
		$this->container( 'custom_db', 'missing' );

		$this->assertTrue( \EE\Service\Utils\init_global_container( GLOBAL_DB, 'custom_db' ) );
		$this->assertSame( array_fill( 0, 2, "docker inspect -f '{{.State.Running}}' custom_db" ), $this->runner->matching( '/^docker inspect /' ) );
		$this->assertSame( [ 'docker-compose -f docker-compose.yml  up -d global-db' ], $this->runner->matching( '/^docker-compose /' ) );
	}

	public function test_refresh_regenerates_compose_and_recreates_only_running_services() {
		$this->compose_file();
		$this->existing_volumes();
		Option::set( GLOBAL_DB, 'dbroot' );
		$this->runner->on( '/^docker-compose ps -q global-nginx-proxy$/', "8c1d0e\n" );
		$this->runner->on( '/^docker-compose ps -q global-db$/', "2b7f3a\n" );

		$this->run_ok(
			function () {
				$this->command()->refresh( [], [] );
			}
		);

		$this->assertSame(
			[ 'docker-compose ps -q global-nginx-proxy', 'docker-compose ps -q global-db', 'docker-compose ps -q global-redis', 'docker-compose ps -q global-newrelic-daemon', 'docker-compose ps -q global-cron' ],
			$this->runner->matching( '/^docker-compose ps /' )
		);
		$this->assertSame(
			[ 'docker-compose -f docker-compose.yml  up -d global-nginx-proxy', 'docker-compose -f docker-compose.yml  up -d global-db' ],
			$this->runner->matching( '/ up -d /' )
		);
		$this->assertStringContainsString( '  global-cron:', file_get_contents( EE_SERVICE_DIR . '/docker-compose.yml' ) );
		$this->assertStringContainsString( 'Success: Global services refreshed.', $this->stdout() );
	}

	/**
	 * SVC-2: disable/restart/reload ignore the exec result and print Success on failure.
	 *
	 * @group known-bug
	 * @group SVC-2
	 * @dataProvider failing_actions
	 */
	public function test_failed_compose_actions_are_errors( $subcommand, $name ) {
		// compose exits 14 when services/docker-compose.yml is missing.
		$this->runner->on( '/^docker-compose /', '', 14, 'no configuration file provided: not found' );

		$this->assert_ee_error(
			function () use ( $subcommand, $name ) {
				$this->command()->$subcommand( [ $name ], [] );
			}
		);
		$this->assertStringNotContainsString( 'Success', $this->stdout() );
	}

	public function failing_actions() {
		return [
			'disable' => [ 'disable', 'db' ],
			'restart' => [ 'restart', 'redis' ],
			'reload'  => [ 'reload', 'nginx-proxy' ],
		];
	}

	/**
	 * SVC-3: `docker-compose restart` of a service with no container exits 0, and restart reports it as restarted.
	 *
	 * @group known-bug
	 * @group SVC-3
	 */
	public function test_restart_of_a_service_without_container_is_not_a_success() {
		$this->compose_file();
		$this->container( 'services_global-redis_1', 'missing' );
		$this->runner->on( '/^docker-compose .*ps /', '' );

		try {
			$this->command()->restart( [ 'redis' ], [] );
		} catch ( EE\ExitException $e ) {
			// Refusing is fine; claiming success is not.
		}
		$this->assertStringNotContainsString( 'Service global-redis restarted.', $this->stdout() );
	}

	/**
	 * SVC-4 (MIG-2): enable ignores boot_container()'s result and reports success when `up -d` fails.
	 *
	 * @group known-bug
	 * @group SVC-4
	 */
	public function test_enable_fails_when_the_service_does_not_start() {
		$this->compose_file();
		$this->container( 'services_global-db_1', 'missing' );
		$this->runner->on( '/ up -d global-db$/', '', 1, 'Error response from daemon: driver failed' );

		$this->assert_ee_error(
			function () {
				$this->command()->enable( [ 'db' ], [] );
			}
		);
		$this->assertStringNotContainsString( 'Service global-db enabled.', $this->stdout() );
	}

	/**
	 * SVC-8: refresh detects running services with `docker-compose ps`, which finds none without a compose file, so
	 * nothing is recreated and it still reports success.
	 *
	 * @group known-bug
	 * @group SVC-8
	 */
	public function test_refresh_without_a_compose_file_keeps_running_services_up() {
		$this->existing_volumes();
		$this->runner->on( '/^docker-compose ps /', '', 14, 'no configuration file provided: not found' );
		foreach ( [ 'nginx-proxy', 'db', 'redis', 'newrelic-daemon', 'cron' ] as $service ) {
			$this->container( "services_global-{$service}_1", 'running' );
		}

		try {
			$this->command()->refresh( [], [] );
		} catch ( EE\ExitException $e ) {
			$this->assertStringNotContainsString( 'Global services refreshed.', $this->stdout() );

			return;
		}
		$this->assertCount( 5, $this->runner->matching( '/ up -d global-/' ), 'Reported success without recreating the running services.' );
	}
}
