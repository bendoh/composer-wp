<?php

/**
 * TODO
 * - switch out new filtering
 * - add version fixer to svn repo
 * - recognize package passed as CLI arg
 * - how are the non-ascii package names handled?
 * - allow directories of plugin zips to be specified !
 * - ssh into a server to get at zipped plugins
 * - github hosted plugins possibly missing composer.json
 * - composer autoload order
 */

namespace BalBuf\ComposerWP;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Plugin\PluginEvents;
use Composer\Config;
use Composer\Installer\InstallerEvent;
use Composer\Plugin\CommandEvent;
use BalBuf\ComposerWP\Repository\Config\RepositoryConfigInterface;


class Plugin implements PluginInterface, EventSubscriberInterface {

	// the plugin properties are defined in this 'extra' field
	const extra_field = 'composer-wp';
	protected $composer;
	protected $io;
	protected $extra;
	// these are builtin repos that are named and can be enabled/disabled in composer.json
	// the name maps to a class in Repository\Config\Builtin which defines its config options
	protected $builtinRepos = array(
		'plugins' => 'WordPressPlugins',
		'themes' => 'WordPressThemes',
		'core' => 'WordPressCore',
		'develop' => 'WordPressDevelop',
		'wpcom-themes' => 'WordPressComThemes',
		'vip-plugins' => 'WordPressVIP',
	);
	// these repos are enabled by default, unless otherwise disabled
	protected $defaultRepos = array( 'plugins', 'core' );
	// these repos are auto-enabled if their vendor names are found in the root package, unless otherwise disabled
	protected $autoLoadRepos = array( 'themes', 'wpcom-themes', 'vip-plugins' );
	// repo config classes by type
	protected $configClass = array(
		'wp-svn' => 'SVNRepositoryConfig',
		'wp-zip' => 'ZipRepositoryConfig',
	);

	/**
	 * Instruct the plugin manager to subscribe us to these events.
	 */
	public static function getSubscribedEvents() {
		return array(
			PluginEvents::COMMAND => array(
				array('onCommand', 0),
			),
		);
	}

	/**
	 * Set up our repos.
	 */
	public function activate( Composer $composer, IOInterface $io ) {
		// store composer and io objects for reuse
		$this->composer = $composer;
		$this->io = $io;
		// the extra data from the composer.json
		$extra = $composer->getPackage()->getExtra();
		// drill down to only our options
		$this->extra = !empty( $extra[ self::extra_field ] ) ? $extra[ self::extra_field ] : array();

		// these will be all the repos we try to enable
		$repos = array();
		// get the user-defined repos first
		if ( !empty( $this->extra['repositories'] ) ) {
			if ( !is_array( $this->extra['repositories'] ) ) {
				throw new \Exception( '[extra][' . self::extra_field . '][repositories] should be an array of repository definitions.' );
			}
			foreach ( $this->extra['repositories'] as $repo ) {
				// see if this includes definition(s) about the builtin repos
				$defaults = array_intersect_key( $repo, $this->builtinRepos );
				// note: this means repo property names should not overlap with builtin repo names
				if ( count( $defaults ) ) {
					// add these to the repos array, only if not defined already
					$repos += $defaults;
				} else {
					// custom repo - add as-is, to be parsed later
					$repos[] = $repo;
				}
			}
		}
		// add the default repos - they will only be added if not previously defined
		$repos += array_fill_keys( $this->defaultRepos, true );

		// get the vendors of the root requirements
		// @todo - capture the package name if this is any command where a package name is passed, e.g. 'require'
		$rootRequires = array_merge( $composer->getPackage()->getRequires(), $composer->getPackage()->getDevRequires() );
		$rootVendors = array();
		foreach ( $rootRequires as $link ) {
			$rootVendors[ strstr( $link->getTarget(), '/', true ) ] = true;
		}

		// get the configs for all the builtin repos and add vendor aliases
		$builtin = array();
		foreach ( $this->builtinRepos as $name => $class ) {
			// add the fully qualified namespace to the class name
			$class = '\\' . __NAMESPACE__ . '\\Repository\\Config\\Builtin\\' . $class;
			// instantiate the config class
			$builtin[ $name ] = new $class();
			// resolve aliases, disabled vendors, etc.
			$this->resolveVendors( $builtin[ $name ] );
			// check if the vendor is represented in the root composer.json
			// and enable this repo if it is one of the auto-load ones
			if ( in_array( $name, $this->autoLoadRepos ) && count( array_intersect_key( $builtin[ $name ]->get( 'vendors' ), $rootVendors ) ) ) {
				// this ensures that a repo is not enabled if it has been explicitly disabled by the user
				$repos += [ $name => true ];
			}
		}

		// the repo manager
		$rm = $this->composer->getRepositoryManager();
		// add our repo classes as available ones to use
		$rm->setRepositoryClass( 'wp-svn', '\\' . __NAMESPACE__ . '\\Repository\\SVNRepository' );
		$rm->setRepositoryClass( 'wp-zip', '\\' . __NAMESPACE__ . '\\Repository\\ZipRepository' );

		// create the repos!
		foreach ( $repos as $name => $definition ) {
			// is this a builtin repo?
			if ( isset( $builtin[ $name ] ) ) {
				// a falsey value means we will not use this repo
				if ( !$definition ) {
					continue;
				}
				$repoConfig = $builtin[ $name ];
				// allow config properties to be overridden by composer.json
				if ( is_array( $definition ) ) {
					// replace out these values
					foreach ( $definition as $key => $value ) {
						$repoConfig->set( $key, $value );
					}
				}
			} else {
				if ( !is_array( $definition ) ) {
					throw new \UnexpectedValueException( 'Custom repository definition must be a JSON object.' );
				}
				if ( !isset( $definition['type'] ) ) {
					throw new \UnexpectedValueException( 'Custom repository definition must declare a type.' );
				}
				if ( !isset( $this->configClass[ $definition['type'] ] ) ) {
					throw new \UnexpectedValueException( 'Unknown repository type ' . $definition['type'] );
				}
				$configClass = '\\' . __NAMESPACE__ . '\\Repository\\Config\\' . $this->configClass[ $definition['type'] ];
				$repoConfig = new $configClass( $definition );
				$this->resolveVendors( $repoConfig );
			}
			// provide a reference to this plugin
			$repoConfig->set( 'plugin', $this );
			// add the repo!
			$rm->addRepository( $rm->createRepository( $repoConfig->getRepositoryType(), $repoConfig ) );
		}
	}

	/**
	 * Take a repo config and add/remove vendors based on extra.
	 * @param  RepositoryConfigInterface  $repoConfig
	 */
	protected function resolveVendors( RepositoryConfigInterface $repoConfig ) {
		static $filterDisable, $vendorAliases, $vendorDisable;
		// grab the vendor info from extra (only the first time)
		if ( !isset( $filterDisable, $vendorAliases, $vendorDisable ) ) {
			// get the user-defined mapping of vendor aliases
			$vendorAliases = !empty( $this->extra['vendors'] ) ? $this->extra['vendors'] : array();
			// get all of the keys that point to a falsey value - these vendors will be disabled
			$vendorDisable = array_keys( $vendorAliases, false );
			// now remove those falsey values
			$vendorAliases = array_filter( $vendorAliases );
			// a filter used to remove disabled vendors from an array of vendors
			$filterDisable = function( $value ) use ( $vendorDisable ) {
				return !in_array( $value, $vendorDisable );
			};
		}
		$types = $repoConfig->get( 'package-types' ) ?: array();
		// all vendors this repo recognizes, keyed on vendor
		$allVendors = array();
		// get the factory-set types
		// add user-defined vendor aliases
		foreach ( $types as $type => &$vendors ) {
			// vendors could be a string
			$vendors = (array) $vendors;
			// get the vendor aliases for any vendors that match those in this type
			$aliases = array_keys( array_intersect( $vendorAliases, $vendors ) );
			// combine, and remove duplicates and disabled aliases
			$vendors = array_filter( array_unique( array_merge( $vendors, $aliases ) ), $filterDisable );
			// add the recognized vendors for this type for handy retrieval
			foreach ( $vendors as $vendor ) {
				$allVendors[ $vendor ] = $type;
			}
		}
		$repoConfig->set( 'package-types', $types );
		$repoConfig->set( 'vendors', $allVendors );
	}

	public function onCommand( CommandEvent $event ) {

	}

	/**
	 * Get the extra data pertinent to this plugin.
	 * @return array extra data properties
	 */
	public function getExtra() {
		return $this->extra;
	}

	/**
	 * Get the composer object that this plugin is loaded in.
	 * @return Composer composer instance
	 */
	public function getComposer() {
		return $this->composer;
	}

}
