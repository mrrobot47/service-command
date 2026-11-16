<?php

use EE\ExitException;
use EE\Model\Option;
use EE\Tests\TestCase;

/**
 * Base for service-command tests: an empty EE_SERVICE_DIR, allocated global subnets, no config.yml, and a dummy docker
 * on PATH for EE_DOCKER::container_status().
 */
abstract class ServiceTestCase extends TestCase {

	private $path_backup;
	private $error_log_backup;

	protected function setUp(): void {
		parent::setUp();
		$this->reset_service_dir();
		$this->set_config( [ 'custom-compose' => null, 'skip-tty' => true, 'sysctl' => false ] );
		Option::set( 'frontend_subnet_ip', '10.1.0.0/16' );
		Option::set( 'backend_subnet_ip', '10.2.0.0/16' );
		$this->path_backup = getenv( 'PATH' );
		$this->docker_on_path();
		// EE::exec( …, echo_stderr ) error_log()s the fake stderr.
		$this->error_log_backup = ini_set( 'error_log', '/dev/null' );
	}

	protected function tearDown(): void {
		putenv( 'PATH=' . $this->path_backup );
		ini_set( 'error_log', (string) $this->error_log_backup );
		$config = getenv( 'EE_CONFIG_PATH' );
		if ( file_exists( $config ) ) {
			unlink( $config );
		}
		$this->reset_service_dir();
		parent::tearDown();
	}

	private function reset_service_dir() {
		exec( 'rm -rf ' . escapeshellarg( EE_SERVICE_DIR ) );
		mkdir( EE_SERVICE_DIR, 0700, true );
	}

	/**
	 * EE_DOCKER::container_status() first runs a real `which docker`; put a dummy docker on PATH so it passes.
	 * The dummy is never run: EE::exec()/launch() go to the fake runner.
	 */
	protected function docker_on_path() {
		$bin = $this->sandbox() . '/bin';
		mkdir( $bin );
		file_put_contents( "$bin/docker", "#!/bin/sh\nexit 1\n" );
		chmod( "$bin/docker", 0755 );
		putenv( "PATH=$bin:" . $this->path_backup );
	}

	/** Write config.yml verbatim (YAML lines), as a user would hand-edit it. */
	protected function write_config( $yaml ) {
		file_put_contents( getenv( 'EE_CONFIG_PATH' ), $yaml );
	}

	/**
	 * Script `docker inspect -f '{{.State.Running}}' <container>`.
	 *
	 * @param string   $container Container name.
	 * @param string   $state     'running', 'exited' or 'missing'.
	 * @param int|null $times     Answer this many times only.
	 */
	protected function container( $container, $state, $times = null ) {
		$pattern = "/^docker inspect -f '\\{\\{\\.State\\.Running\\}\\}' " . preg_quote( $container, '/' ) . '$/';
		if ( 'missing' === $state ) {
			$this->runner->on( $pattern, '', 1, "Error: No such object: $container", $times );
		} else {
			$this->runner->on( $pattern, 'running' === $state ? "true\n" : "false\n", 0, '', $times );
		}
	}

	/** Script the host ports the running proxy publishes. */
	protected function proxy_ports( $http, $https ) {
		$this->runner->on( '/^docker inspect --format .*"80\/tcp"/', "$http\n" );
		$this->runner->on( '/^docker inspect --format .*"443\/tcp"/', "$https\n" );
	}

	/** Existing global volumes, so generate_global_docker_compose_yml() creates none. */
	protected function existing_volumes() {
		$this->runner->on( '/^docker volume ls /', "some_volume\n" );
	}

	/** @return array The generated services/docker-compose.yml, parsed. */
	protected function generate_compose() {
		\EE\Service\Utils\generate_global_docker_compose_yml( new Symfony\Component\Filesystem\Filesystem() );

		return Symfony\Component\Yaml\Yaml::parse( file_get_contents( EE_SERVICE_DIR . '/docker-compose.yml' ) );
	}

	/** Run $fn and fail with the EE error message if it exits. */
	protected function run_ok( callable $fn ) {
		try {
			return $fn();
		} catch ( ExitException $e ) {
			$this->fail( 'Unexpected EE::error(): ' . trim( $this->stderr() ) );
		}
	}
}
