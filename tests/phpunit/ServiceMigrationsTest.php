<?php

use EE\Migration\AddNewrelicVolume;
use EE\Migration\ChangeGlobalServiceContainerNames;
use EE\Migration\GlobalCron;
use EE\Migration\RefreshCompose;
use EE\Migration\RemoveSysctl;
use EE\Migration\SaveGLobalDbPass;
use EE\Migration\SaveGLobalDbPassOne;
use EE\Model\Option;

/**
 * The container migrations in migrations/container/: skip conditions, the global DB password recovery and the
 * container-rename helpers.
 */
class ServiceMigrationsTest extends ServiceTestCase {

	public static function setUpBeforeClass(): void {
		foreach ( glob( dirname( __DIR__, 2 ) . '/migrations/container/*.php' ) as $file ) {
			require_once $file;
		}
	}

	private function upgrade() {
		Option::set( 'version', '4.12.0' );
	}

	/** Run `cat | grep | cut` pipelines for real: they only read the compose file. */
	private function real_pipelines() {
		$this->runner->on(
			'/^cat \S+ \| grep MYSQL_ROOT_PASSWORD \| cut -d"=" -f2$/',
			function ( $command ) {
				exec( $command, $out, $code );

				return [ implode( "\n", $out ) . "\n", $code ];
			}
		);
	}

	/**
	 * @dataProvider password_migrations
	 */
	public function test_db_password_is_recovered_from_the_compose_file( $class ) {
		$this->upgrade();
		$this->existing_volumes();
		Option::set( GLOBAL_DB, 'Recover3dPassw0rd' );
		$this->generate_compose();
		Option::set( GLOBAL_DB, '' );
		$this->real_pipelines();
		$this->runner->calls = [];

		( new $class() )->up();

		$this->assertSame( [ sprintf( 'cat %s/docker-compose.yml | grep MYSQL_ROOT_PASSWORD | cut -d"=" -f2', EE_SERVICE_DIR ) ], $this->runner->commands() );
		$this->assertSame( 'Recover3dPassw0rd', Option::get( GLOBAL_DB ) );
	}

	public function password_migrations() {
		return [
			'SaveGLobalDbPass'    => [ SaveGLobalDbPass::class ],
			'SaveGLobalDbPassOne' => [ SaveGLobalDbPassOne::class ],
		];
	}

	/**
	 * @dataProvider password_migrations
	 */
	public function test_db_password_recovery_skips_without_a_compose_file_on_first_execution( $class ) {
		( new $class() )->up();

		$this->assertSame( [], $this->runner->commands() );
		$this->assertFalse( Option::get( GLOBAL_DB ) );
	}

	public function test_db_password_recovery_one_keeps_a_stored_password() {
		$this->upgrade();
		file_put_contents( EE_SERVICE_DIR . '/docker-compose.yml', "      - MYSQL_ROOT_PASSWORD=other\n" );
		Option::set( GLOBAL_DB, 'kept' );

		( new SaveGLobalDbPassOne() )->up();

		$this->assertSame( [], $this->runner->commands() );
		$this->assertSame( 'kept', Option::get( GLOBAL_DB ) );
	}

	public function test_restore_yml_file_restores_and_starts_the_old_services() {
		$backup = $this->sandbox() . '/docker-compose.yml.backup';
		file_put_contents( $backup, "services: {old: {}}\n" );

		ChangeGlobalServiceContainerNames::restore_yml_file( $backup, EE_SERVICE_DIR . '/docker-compose.yml', [ 'ee-global-nginx-proxy', 'ee-global-redis', 'ee-global-db' ] );

		$this->assertStringEqualsFile( EE_SERVICE_DIR . '/docker-compose.yml', "services: {old: {}}\n" );
		$this->assertSame( [ 'docker-compose up -d global-nginx-proxy global-redis global-db ' ], $this->runner->commands() );
		$this->assertSame( realpath( EE_SERVICE_DIR ), getcwd() );
	}

	public function test_restore_yml_file_fails_when_the_old_services_do_not_start() {
		$backup = $this->sandbox() . '/docker-compose.yml.backup';
		touch( $backup );
		$this->runner->on( '/^docker-compose up /', '', 1 );

		$this->expectExceptionMessage( 'Unable to start ee-containers' );
		GlobalCron::restore_yml_file( $backup, EE_SERVICE_DIR . '/docker-compose.yml', [ 'ee-cron-scheduler' ] );
	}

	public function test_removing_old_containers() {
		ChangeGlobalServiceContainerNames::remove_global_ee_containers( [] );
		GlobalCron::remove_old_cron_containers( [] );
		$this->assertSame( [], $this->runner->commands() );

		ChangeGlobalServiceContainerNames::remove_global_ee_containers( [ 'ee-global-db', 'ee-global-redis' ] );
		GlobalCron::remove_old_cron_containers( [ 'ee-cron-scheduler' ] );
		$this->assertSame( [ 'docker rm -f ee-global-db ee-global-redis', 'docker rm -f ee-cron-scheduler' ], $this->runner->commands() );

		$this->runner->on( '/^docker rm /', '', 1 );
		$this->expectExceptionMessage( 'Unable to remove global service containers' );
		GlobalCron::remove_old_cron_containers( [ 'ee-cron-scheduler' ] );
	}

	public function test_update_cache_host_points_wordpress_at_global_redis() {
		$dir = $this->sandbox();

		ChangeGlobalServiceContainerNames::update_cache_host( [ 'site_url' => 'example.test', 'site_fs_path' => $dir ] );

		$this->assertSame(
			[
				"docker-compose exec --user='www-data' php wp config set RT_WP_NGINX_HELPER_REDIS_HOSTNAME global-redis --add=true --type=constant",
				"docker-compose exec --user='www-data' php wp config set --type=variable redis_server \"array('host'=> 'global-redis','port'=> 6379,)\" --raw",
			],
			$this->runner->commands()
		);
		$this->assertSame( $dir, getcwd() );
	}

	public function test_update_cache_host_stops_at_the_first_failure() {
		$this->runner->on( '/RT_WP_NGINX_HELPER_REDIS_HOSTNAME/', '', 1 );

		try {
			ChangeGlobalServiceContainerNames::update_cache_host( [ 'site_url' => 'example.test', 'site_fs_path' => $this->sandbox() ] );
			$this->fail( 'No exception' );
		} catch ( Exception $e ) {
			$this->assertSame( 'Unable to update cache host of example.test', $e->getMessage() );
		}
		$this->assertCount( 1, $this->runner->commands() );
	}

	public function test_global_cron_stops_only_the_cron_service() {
		GlobalCron::stop_default_containers();
		ChangeGlobalServiceContainerNames::stop_default_containers();

		$this->assertSame( [ 'docker-compose stop global-cron', 'docker-compose stop && docker-compose rm -f' ], $this->runner->commands() );
	}

	public function test_add_newrelic_volume_creates_the_labelled_volume_and_regenerates_compose() {
		$this->upgrade();
		$this->existing_volumes();

		( new AddNewrelicVolume() )->up();

		$create = $this->runner->matching( '/^docker volume create /' );
		$this->assertCount( 1, $create );
		$this->assertMatchesRegularExpression( '/--label "org\.label-schema\.vendor=EasyEngine"\s+\\\\?\s*--label "io\.easyengine\.site=global-newrelic-daemon"\s+\\\\?\s*global-newrelic-daemon_newrelic_sock$/', $create[0] );
		$this->assertFileExists( EE_SERVICE_DIR . '/docker-compose.yml' );
	}

	/**
	 * @dataProvider refresh_migrations
	 */
	public function test_refresh_migrations_recreate_the_running_services( $class ) {
		$this->upgrade();
		$this->existing_volumes();
		$this->runner->on( '/^docker-compose ps -q global-redis$/', "9a8b7c\n" );

		( new $class() )->up();

		$this->assertSame(
			[ 'docker-compose ps -q global-nginx-proxy', 'docker-compose ps -q global-db', 'docker-compose ps -q global-redis', 'docker-compose ps -q global-newrelic-daemon' ],
			$this->runner->matching( '/^docker-compose ps /' )
		);
		$this->assertSame( [ 'docker-compose -f docker-compose.yml  up -d global-redis' ], $this->runner->matching( '/ up -d /' ) );
		$this->assertFileExists( EE_SERVICE_DIR . '/docker-compose.yml' );
	}

	/**
	 * @dataProvider refresh_migrations
	 */
	public function test_refresh_migrations_skip_the_first_execution( $class ) {
		( new $class() )->up();

		$this->assertSame( [], $this->runner->commands() );
		$this->assertFileDoesNotExist( EE_SERVICE_DIR . '/docker-compose.yml' );
	}

	public function refresh_migrations() {
		return [
			'RemoveSysctl'   => [ RemoveSysctl::class ],
			'RefreshCompose' => [ RefreshCompose::class ],
		];
	}

	/**
	 * SVC-12: `"${…}"` interpolation is deprecated since PHP 8.2 and removed in PHP 9.
	 *
	 * @group known-bug
	 * @group SVC-12
	 */
	public function test_sources_do_not_use_dollar_brace_interpolation() {
		$root  = dirname( __DIR__, 2 );
		$found = [];
		foreach ( array_merge( glob( "$root/src/*.php" ), glob( "$root/src/helper/*.php" ), glob( "$root/migrations/container/*.php" ) ) as $file ) {
			foreach ( token_get_all( file_get_contents( $file ) ) as $token ) {
				if ( is_array( $token ) && T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) {
					$found[] = substr( $file, strlen( $root ) + 1 ) . ':' . $token[2];
				}
			}
		}
		$this->assertSame( [], $found );
	}
}
