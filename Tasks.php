<?php declare(strict_types=1);
////////////////////////////////////////////////////////////////////////////////
//             ___________                     __   __  _____
//             \_   _____/______ __ __   _____/  |_|__|/ ____\__ __
//              |    __) \_  __ \  |  \_/ ___\   __\  \   __<   |  |
//              |     \   |  | \/  |  /\  \___|  | |  ||  |  \___  |
//              \___  /   |__|  |____/  \___  >__| |__||__|  / ____|
//                  \/                      \/               \/
// -----------------------------------------------------------------------------
//                          https://github.com/fructify
//
//          Designed and Developed by Brad Jones <brad @="bjc.id.au" />
// -----------------------------------------------------------------------------
////////////////////////////////////////////////////////////////////////////////

namespace Fructify\Robo;

use Gears\String\Str;
use Composer\Semver\Semver;
use GuzzleHttp\Client as Http;
use YaLinqo\Enumerable as Linq;
use Symfony\Component\Finder\Finder;

class Tasks extends \Robo\Tasks
{
    /** @var string */
    private static $WP_SALTS_URL = 'https://api.wordpress.org/secret-key/1.1/salt/';

    /** @var string */
    private static $WP_VERSION_URL = 'https://api.wordpress.org/core/version-check/1.7/';

    /** @var string */
    private static $WP_RELEASES_URL = 'https://wordpress.org/download/release-archive/';

    /**
     * Installs Wordpress.
     *
     * This task will download the core wordpress files for you. It is
     * automatically run by composer as a post-install-cmd so you really
     * shouldn't need to worry about it.
     *
     * But if you do want to call it, usage would look like:
     *
     * ```
     * 	php vendor/bin/robo fructify:install v4.*
     * ```
     *
     * @param  string $versionContraint A semantic version contraint.
     * @return void
     */
    public function fructifyInstall(string $versionContraint = '*')
    {
        // Lets check if wordpress actually exists
        if (!file_exists('./wp-includes/version.php'))
        {
            // Grab the resolved version number
            $version = $this->wpResolveVersionNo($versionContraint);

            // Download the core wordpress files
            $this->_exec('./vendor/bin/wp core download --version='.$version);

            // Remove a few things we don't need
            @unlink('./license.txt');
            @unlink('./readme.html');
            @unlink('./wp-config-sample.php');
            @unlink('./wp-content/plugins/hello.php');

            // Read in the composer file and remove some of the bundled plugins
            // and themes unless of course they are actually referenced in the
            // composer requirements.
            $composer = Str::s(file_get_contents('./composer.json'));

            if (!$composer->contains('wpackagist-plugin/akismet'))
            {
                $this->_deleteDir(['./wp-content/plugins/akismet']);
            }

            if (!$composer->contains('wpackagist-theme/twentyseventeen'))
            {
                $this->_deleteDir(['./wp-content/themes/twentyseventeen']);
            }

            if (!$composer->contains('wpackagist-theme/twentysixteen'))
            {
                $this->_deleteDir(['./wp-content/themes/twentysixteen']);
            }

            if (!$composer->contains('wpackagist-theme/twentyfifteen'))
            {
                $this->_deleteDir(['./wp-content/themes/twentyfifteen']);
            }

            if (!$composer->contains('wpackagist-theme/twentyfourteen'))
            {
                $this->_deleteDir(['./wp-content/themes/twentyfourteen']);
            }
        }
    }

    /**
     * Updates Wordpress.
     *
     * This task will update the core wordpress files for you. It is
     * automatically run by composer as a post-update-cmd so you really
     * shouldn't need to worry about it.
     *
     * But if you do want to call it, usage would look like:
     *
     * ```
     * 	php vendor/bin/robo fructify:update v4.*
     * ```
     *
     * @param  string $versionContraint A semantic version contraint.
     * @return void
     */
    public function fructifyUpdate(string $versionContraint = '*')
    {
        // Lets attempt to update wordpress
        if (file_exists('./wp-includes/version.php'))
        {
            // Grab the version of wordpress that is installed
            require('./wp-includes/version.php');
            $installed_version = $wp_version;

            // Get the version we want to update to
            $new_version = $this->wpResolveVersionNo($versionContraint);

            // Nothing to do, same version.
            if ($installed_version == $new_version) return;

            // Now lets download the version of wordpress that we already have,
            // sounds silly I know but it will make sense soon I promise.
            $temp = sys_get_temp_dir().'/'.md5(microtime());
            $this->_mkdir($temp);
            $this->_exec
            (
                './vendor/bin/wp core download'.
                ' --version='.$installed_version.
                ' --path='.$temp
            );

            // Now lets delete all the files that are stock wordpress files
            $finder = new Finder();
            $finder->files()->in($temp);
            foreach ($finder as $file)
            {
                if (file_exists($file->getRelativePathname()))
                {
                    unlink($file->getRelativePathname());
                }
            }

            // Clean up
            $this->_deleteDir(['./wp-admin', './wp-includes', $temp]);
        }

        // Either we just deleted the old wordpress files or it didn't exist.
        // Regardless lets run the install functionality.
        $this->fructifyInstall($versionContraint);
    }

    /**
     * Creates New Salts, used for encryption by Wordpress.
     *
     * This task will create a new set of salts and write them to the
     * .salts.php file for you. Again this is tied into composer as a
     * post-install-cmd so you really shouldn't need to worry about it.
     *
     * But if you do want to call it, usage would look like:
     *
     * ```
     * 	php vendor/bin/robo fructify:salts
     * ```
     *
     * @return void
     */
    public function fructifySalts()
    {
        $this->taskWriteToFile('./.salts.php')
            ->line('<?php')
            ->text((new Http)->request('GET', self::$WP_SALTS_URL)->getBody())
            ->run();
    }

    /**
     * Sets permissions on "special" Wordpress folders.
     *
     * This task simply loops through some folders and ensures they exist and
     * have the correct permissions. It is automatically run by composer as a
     * post-install-cmd so you really shouldn't need to worry about it.
     *
     * But if you do want to call it, usage would look like:
     *
     * ```
     * 	php vendor/bin/robo fructify:permissions
     * ```
     *
     * @return void
     */
    public function fructifyPermissions()
    {
        // These folders will be given full write permissions
        $folders =
        [
            './wp-content/uploads'
        ];

        // Loop through each folder
        foreach ($folders as $folder)
        {
            $this->taskFileSystemStack()
                ->mkdir($folder)
                ->chmod($folder, 0777)
            ->run();
        }
    }

    /**
     * Resolves a Wordpress Version Contraint.
     *
     * This is a private helper method. It takes a semantic version contraint,
     * parsable by [Composer's Semver](https://github.com/composer/semver) and
     * resolves an actual wordpress version number.
     *
     * We use this page: http://wordpress.org/download/release-archive/
     * As an offical list of released versions.
     *
     * @param  string $versionContraint A semantic version contraint.
     * @return string 					A semantic version number.
     */
    private function wpResolveVersionNo(string $versionContraint)
    {
        // Remove a v at the start if it exists
        $versionContraint = str_replace('v', '', $versionContraint);

        // If the constraint it a single wildcard, lets just
        // return the latest stable release of wordpress.
        if ($versionContraint == '*')
        {
            $json = (new Http)->request('GET', self::$WP_VERSION_URL)->getBody();
            return json_decode($json, true)['offers'][0]['version'];
        }

        // Download the releases from the wordpress site.
        $html = (new Http)->request('GET', self::$WP_RELEASES_URL)->getBody()->getContents();

        // Extract a list of download links, these contain the versions.
        preg_match_all("#><a href='https://wordpress\.org/wordpress-[^>]+#",
            $html, $matches
        );

        // Filter the links to obtain a list of just versions
        $versions = Linq::from($matches[0])
        ->select(function(string $v){ return Str::s($v); })
        ->where(function(Str $v){ return $v->endsWith(".zip'"); })
        ->where(function(Str $v){ return !$v->contains('IIS'); })
        ->where(function(Str $v){ return !$v->contains('mu'); })
        ->select(function(Str $v){ return $v->between('wordpress-', '.zip'); })
        ->where(function(Str $v)
        {
            if ($v->contains('-'))
            {
                return preg_match("#.*-(dev|beta|alpha|rc).*#i", (string)$v) === 1;
            }

            return true;
        })
        ->toArray();

        // Let semver take over and work it's magic
        return (string) Semver::satisfiedBy($versions, $versionContraint)[0];
    }
}
