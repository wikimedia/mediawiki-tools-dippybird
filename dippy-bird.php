<?php

$dippy_bird = new DippyBird();
$dippy_bird->run();


class DippyBird {
	protected $configOpts = array(
		'port' => array(
			'required' => true,
			'value' => null,
		),
		'server' => array(
			'required' => true,
			'value' => null,
		),
		'username' => array(
			'required' => true,
			'value' => null,
		),
		'query' => array(
			'required' => true,
			'value' => null,
		),
		'action' => array(
			'required' => false,
			'value' => null,
		),
		'pretend' => array(
			'required' => false,
			'value' => false,
		),
		'verbose' => array(
			'required' => false,
			'value' => false,
		),
		'debug' => array(
			'required' => false,
			'value' => false,
		),
	);

	protected $validActions = array(
		//'approve' => 'executeApprove',
		//'verify' => 'executeVerify',
		'submit' => 'executeSubmit',
		//'abandon' => 'executeAbandon',
	);

	public function __construct() {
		// if a config file is present, load it
		$config_file = dirname( __FILE__ ) . "/" . "config.ini";
		if ( file_exists( $config_file ) ) {
			$this->loadConfiguration( $config_file );
		}
	}

	public function run() {
		$this->handleOpts();
		if ( !$this->isConfigSane() ) {
			// bail and fail
			$msg = "There is something wrong in your arguments or configuration.";
			$this->bail( 1, $msg );
		}

		// determine the 'action' to take
		$action = $this->getConfigOpt( 'action' );
		$action_method = $this->getActionMethod( $action );

		// fetch the results of the query
		$results = $this->executeQuery();

		// execute the action to take on the query results
		$this->{$action_method}( $results );

		echo "Thanks for playing!" . PHP_EOL;
		echo "<3," . PHP_EOL;
		echo "Dippy bird" . PHP_EOL;
	}

	public function executeQuery() {
		// config options we need
		$opts = array( 'port', 'server', 'username', 'query' );
		$config_opts = $this->getConfigOptsByArray( $opts );
		$cmd = "ssh -p {$config_opts['port']} {$config_opts['username']}@{$config_opts['server']} gerrit query {$config_opts['query']} --format=JSON --current-patch-set";
		if ( $this->getConfigOpt( 'verbose' ) ) {
			echo "Executing query: " . $cmd . PHP_EOL;
		}
		$results = array();
		exec( escapeshellcmd( $cmd ), $results, $status );
		if ( $status === 0 ) {
			if ( $this->getConfigOpt( 'debug' ) ) {
				echo "Query results: " . print_r( $results, true ) . PHP_EOL;
			}
			return $results;
		} else {
			$msg = "There was a problem executing your query." . PHP_EOL;
			$msg .= "Exiting with status: $status";
			$this->bail( $status, $msg );
		}
	}

	/**
	 * @param array $results
	 * @return mixed
	 */
	public function executeSubmit( $results ) {
		// If there are less than two items in the array, there are no changesets on which to operate
		if ( count( $results ) < 2 ) {
			// nothing to process
			return;
		}

		// prepare to do... stuff
		$submitted = 0;
		$opts = array( 'port', 'server', 'username' );
		$config_opts = $this->getConfigOptsByArray( $opts );

		// get the patchset ids form the result set
		$patchset_ids = self::extractPatchSetIds( $results );

		// loop through patchsets and submit them one by one
		foreach ( $patchset_ids as $patchset_id ) {
			// prepare command to execute
			$cmd = "ssh -p {$config_opts['port']} {$config_opts['username']}@{$config_opts['server']} gerrit approve --verified 1 --code-review 2 --submit $patchset_id";

			if ( $this->getConfigOpt( 'verbose' ) ) {
				echo "Executing: " . $cmd . PHP_EOL;
			}

			// should we do this for reals?!
			if ( !$this->getConfigOpt( 'pretend' ) ) {
				exec( escapeshellcmd( $cmd ), $cmd_results, $status );
				if ( $status !== 0 ) {
					$msg = "Problem executing submit" . PHP_EOL;
					$this->bail( 1, $msg );
				}
			}
			$submitted++;
		}
		echo "$submitted changesets submitted." . PHP_EOL;
	}

	/**
	 * Extract patchset ids
	 * @param array $results JSON representations of gerrit changesets
	 * @return array
	 */
	public static function extractPatchSetIds( $results ) {
		$patchset_ids = array();
		foreach ( $results as $result ) {
			$patchset_id = self::extractPatchSetId( $result );
			if ( !is_null( $patchset_id )) {
				$patchset_ids[] = $patchset_id;
			}
		}
		return $patchset_ids;
	}

	/**
	 * Extract patchset id
	 * @param string $result JSON representation of gerrit changeset
	 * @return mixed patchset id or null
	 */
	public static function extractPatchSetId( $result ) {
		$changeset = json_decode( $result, true );
		if( isset( $changeset['currentPatchSet']['revision'] ) ) {
			return $changeset['currentPatchSet']['revision'];
		}
		return null;
	}

	/**
	 * Set $this->configOpts by array of key -> value pairs
	 * @param array
	 */
	public function setConfigByArray( array $config ) {
		// don't bother if the array is empty
		if ( empty( $config ) ) {
			return;
		}

		// only set valid config opts
		foreach ( $config as $key => $value ) {
			if ( isset( $this->configOpts[ $key ] ) ) {
				$this->configOpts[ $key ]['value'] = $value;
			}
		}
	}

	/**
	 * Load configuration options from an ini file
	 * @param string $config_file Path to configuration file
	 */
	protected function loadConfiguration( $config_file ) {
		$config = parse_ini_file( $config_file );

		// do some option clean up....

		$this->setConfigByArray( $config );
	}

	/**
	 * Handle runtime command line options
	 */
	protected function handleOpts() {
		$user_opts = getopt( $this->getShortOpts(), $this->getLongOpts() );

		// handle 'help' message
		if ( isset( $user_opts['help'] ) || isset( $user_opts['h'] ) ) {
			$this->bail();
		}

		$config = array();

		foreach ( $user_opts as $key => $value ) {
			switch ( $key ) {
				case 'port':
				case 'P':
					$config['port'] = $value;
					break;
				case 'server':
				case 's':
					$config['server'] = $value;
					break;
				case 'username':
				case 'u':
					$config['username'] = $value;
					break;
				case 'query':
				case 'q':
					$config['query'] = $value;
					break;
				case 'action':
				case 'a':
					$config['action'] = $value;
					break;
				case 'pretend':
				case 'p':
					$config['pretend'] = true;
					break;
				case 'verbose':
				case 'v':
					$config['verbose'] = true;
					break;
				case 'debug':
				case 'd':
					// if debug, also set verbose to true
					$config['verbose'] = true;
					$config['debug'] = true;
					break;
				default:
					break;
			}
		}

		$this->setConfigByArray( $config );
	}

	/**
	 * Check sanity of configuration values
	 *
	 * If a value is required but unset, config is not sane.
	 */
	protected function isConfigSane() {
		foreach ( $this->configOpts as $opt => $info ) {
			if ( $info['required'] === true && is_null( $info['value'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Get the 'long' options available
	 */
	public function getLongOpts() {
		$long_opts = array(
			"port:",
			"server:",
			"username:",
			"query:",
			"action:",
			"pretend",
			"help",
			"verbose",
			"debug",
		);
		return $long_opts;
	}

	/**
	 * Get the 'short' options available
	 */
	public function getShortOpts() {
		$short_opts = 	"P:"; 	// port
		$short_opts .= 	"s:"; 	// server
		$short_opts .= 	"u:"; 	// username
		$short_opts .= 	"q:"; 	// gerrit query
		$short_opts .= 	"a:";	// action
		$short_opts .= 	"p";	// pretend
		$short_opts .= "h"; 	// help
		$short_opts .= "v"; 	// verbose
		$short_opts .= "d";		// debug
		return $short_opts;
	}

	/**
	 * Fetch usage message
	 */
	public function getUsage() {
		$usage = <<<USAGE

Purpose: Perform bulk actions on Gerrit changesets

Description: Given a gerrit search query, perform a selected 'aciton'. Valid
actions currently include:
	submit: Verify, approve, and submit changeset

Usage: php dippy-bird.php --username=<username> --server=<gerrit servername>
		--port=<gerrit port> [--verbose] [--debug] [--help]
		--action=<action> --query=<query>

Required parameters:
	--username, -u		Username you use to log in to Gerrit
	--server, -s		Hostname of the Gerrit server
	--port, -P		Port where Gerrit is running
	--query, -q		Gerrit query (See docs: http://bit.ly/H9bYiq)
	--action, -a		Action to take after running query

Optional options:
	--pretend, -p		Executes query but not action
	--verbose, -v		Run in 'verbose' mode
	--debug, -d		Run in 'debug' mode
	--help, -h		Display this help message

Configuration options can also be set using the longoption names placed in
a 'config.ini' file in the same directory as this script.

USAGE;
		return $usage;
	}

	/**
	 * Print usage message and die
	 * @param int $status Status code with which to exit
	 * @param string $msg
	 */
	public function bail( $status = 0, $msg = null ) {
		if ( !is_null( $msg ) ) {
			echo $msg . PHP_EOL;
		}
		echo $this->getUsage();
		echo PHP_EOL;
		exit( intval( $status ) );
	}

	/**
	 * Fetch $this->configOpts
	 * @return array
	 */
	public function getConfigOpts() {
		return $this->configOpts;
	}

	/**
	 * Fetch a specified configuration option
	 *
	 * @param $opt string The name of the option
	 * @param $verbose bool If true, returns full config opt array, otherwise
	 *		just returns the config opt's value
	 * @return mixed string|array|null Returns null if the config opt does
	 * 		not exist.
	 */
	public function getConfigOpt( $opt, $verbose = false ) {
		if ( isset( $this->configOpts[ $opt ] ) ) {
			if ( $verbose === false ) {
				return $this->configOpts[ $opt ]['value'];
			} else {
				return $this->configOpts[ $opt ];
			}
		}
		return null;
	}

	/**
	 * Fetch multiple config option values
	 * @param array $opts Config option names to fetch
	 * @return array Option name => value
	 */
	public function getConfigOptsByArray( array $opts ) {
		$config_opts = array();
		foreach ( $opts as $opt ) {
			$config_opts[ $opt ] = $this->getConfigOpt( $opt );
		}
		return $config_opts;
	}

	/**
	 * Determine whether or not requested action is valid to take
	 *
	 * Valid actions are defined in $this->validActions
	 * @param string
	 * @return bool
	 */
	public function isValidAction( $action ) {
		if ( isset( $this->validActions[ $action ] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Fetch the method name corresponding to a specific 'action'
	 *
	 * If the action is not valid, fail and bail.
	 * @param string
	 * @return string
	 */
	public function getActionMethod( $action ) {
		if ( !$this->isValidAction( $action ) ) {
			$msg = "Invalid action requested" . PHP_EOL;
			$msg .= "Valid actions include:" . PHP_EOL;
			foreach( $this->validActions as $action => $method ) {
				$msg .= "\t$action" . PHP_EOL;
			}
			$this->bail( 1, $msg );
		}
		return $this->validActions[ $action ];
	}

}