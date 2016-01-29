<?php
/**
 * @link      http://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   http://craftcms.com/license
 */

namespace craft\app\base;

use Craft;
use craft\app\dates\DateTime;
use craft\app\db\Connection;
use craft\app\db\MigrationManager;
use craft\app\db\Query;
use craft\app\enums\ConfigCategory;
use craft\app\errors\DbConnectException;
use craft\app\errors\Exception;
use craft\app\events\EditionChangeEvent;
use craft\app\helpers\App;
use craft\app\helpers\DateTimeHelper;
use craft\app\helpers\Db;
use craft\app\helpers\StringHelper;
use craft\app\helpers\Url;
use craft\app\i18n\Locale;
use craft\app\log\FileTarget;
use craft\app\mail\Mailer;
use craft\app\models\Info;
use craft\app\web\Application as WebApplication;
use yii\base\InvalidConfigException;
use yii\log\Logger;

/**
 * ApplicationTrait
 *
 * @property \craft\app\web\AssetManager          $assetManager     The asset manager component
 * @property \craft\app\services\Assets           $assets           The assets service
 * @property \craft\app\services\AssetIndexer     $assetIndexing    The asset indexer service
 * @property \craft\app\services\AssetTransforms  $assetTransforms  The asset transforms service
 * @property \craft\app\services\Categories       $categories       The categories service
 * @property \craft\app\services\Config           $config           The config service
 * @property \craft\app\services\Content          $content          The content service
 * @property \craft\app\services\Dashboard        $dashboard        The dashboard service
 * @property \craft\app\db\Connection             $db               The database connection component
 * @property \craft\app\services\Deprecator       $deprecator       The deprecator service
 * @property \craft\app\services\Elements         $elements         The elements service
 * @property \craft\app\services\EmailMessages    $emailMessages    The email messages service
 * @property \craft\app\services\Entries          $entries          The entries service
 * @property \craft\app\services\EntryRevisions   $entryRevisions   The entry revisions service
 * @property \craft\app\services\Et               $et               The E.T. service
 * @property \craft\app\services\Feeds            $feeds            The feeds service
 * @property \craft\app\services\Fields           $fields           The fields service
 * @property \craft\app\i18n\Formatter            $formatter        The formatter component
 * @property \craft\app\services\Globals          $globals          The globals service
 * @property \craft\app\i18n\I18N                 $i18n             The internationalization (i18n) component
 * @property \craft\app\services\Images           $images           The images service
 * @property \craft\app\i18n\Locale               $locale           The Locale object for the target language
 * @property \craft\app\mail\Mailer               $mailer           The mailer component
 * @property \craft\app\services\Matrix           $matrix           The matrix service
 * @property \craft\app\db\MigrationManager       $migrator         The application’s migration manager
 * @property \craft\app\services\Path             $path             The path service
 * @property \craft\app\services\Plugins          $plugins          The plugins service
 * @property \craft\app\services\Relations        $relations        The relations service
 * @property \craft\app\services\Resources        $resources        The resources service
 * @property \craft\app\services\Routes           $routes           The routes service
 * @property \craft\app\services\Search           $search           The search service
 * @property \craft\app\services\Security         $security         The security component
 * @property \craft\app\services\Sections         $sections         The sections service
 * @property \craft\app\services\Structures       $structures       The structures service
 * @property \craft\app\services\SystemSettings   $systemSettings   The system settings service
 * @property \craft\app\services\Tags             $tags             The tags service
 * @property \craft\app\services\Tasks            $tasks            The tasks service
 * @property \craft\app\services\TemplateCaches   $templateCaches   The template caches service
 * @property \craft\app\services\Tokens           $tokens           The tokens service
 * @property \craft\app\services\Updates          $updates          The updates service
 * @property \craft\app\services\UserGroups       $userGroups       The user groups service
 * @property \craft\app\services\UserPermissions  $userPermissions  The user permissions service
 * @property \craft\app\services\Users            $users            The users service
 * @property \craft\app\web\View                  $view             The view component
 * @property \craft\app\services\Volumes          $volumes          The volumes service
 *
 * @method \craft\app\web\AssetManager            getAssetManager() Returns the asset manager component.
 * @method \craft\app\db\Connection               getDb()           Returns the database connection component.
 * @method \craft\app\i18n\Formatter              getFormatter()    Returns the formatter component.
 * @method \craft\app\i18n\I18N                   getI18n()         Returns the internationalization (i18n) component.
 * @method \craft\app\services\Security           getSecurity()     Returns the security component.
 * @method \craft\app\web\View                    getView()         Returns the view component.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
trait ApplicationTrait
{
    // Properties
    // =========================================================================

    /**
     * @var
     */
    private $_isInstalled;

    /**
     * @var
     */
    private $_isLocalized;

    /**
     * @var
     */
    private $_info;

    /**
     * @var
     */
    private $_siteName;

    /**
     * @var
     */
    private $_siteUrl;

    /**
     * @var bool
     */
    private $_isDbConfigValid = false;

    /**
     * @var bool
     */
    private $_isDbConnectionValid = false;

    /**
     * @var bool
     */
    private $_gettingLanguage = false;

    /**
     * @var string Craft’s build number.
     */
    public $build;

    /**
     * @var string Craft’s schema version number.
     */
    public $schemaVersion;

    /**
     * @var DateTime Craft’s release date.
     */
    public $releaseDate;

    /**
     * @var string The minimum Craft build number required to update to this build.
     */
    public $minBuildRequired;

    /**
     * @var string The URL to download the minimum Craft version.
     * @see $minBuildRequired
     */
    public $minBuildUrl;

    /**
     * @var string The release track Craft is running on.
     */
    public $track;

    /**
     * @var string The stored version
     * @todo Remove this after the next breakpoint
     */
    private $_storedVersion;

    // Public Methods
    // =========================================================================

    /**
     * Returns the target app language.
     *
     * @param boolean $useUserLanguage Whether the user's preferred language should be used.
     *
     * @return string|null
     */
    public function getTargetLanguage($useUserLanguage = true)
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        if ($this->isInstalled()) {
            // Will any locale validation be necessary here?
            $request = $this->getRequest();

            if ($useUserLanguage || defined('CRAFT_LOCALE')) {
                if ($useUserLanguage) {
                    $locale = 'auto';
                } else {
                    /** @noinspection PhpUndefinedConstantInspection */
                    $locale = StringHelper::toLowerCase(CRAFT_LOCALE);
                }

                // Get the list of actual site locale IDs
                $siteLocaleIds = $this->getI18n()->getSiteLocaleIds();

                // Is it set to "auto"?
                if ($locale == 'auto') {
                    // Place this within a try/catch in case userSession is being fussy.
                    try {
                        // If the user is logged in *and* has a primary language set, use that
                        $user = $this->getUser()->getIdentity();

                        if ($user && ($preferredLocale = $user->getPreferredLocale()) !== null) {
                            return $preferredLocale;
                        }
                    } catch (\Exception $e) {
                        Craft::error('Tried to determine the user’s preferred locale, but got this exception: '.$e->getMessage(), __METHOD__);
                    }

                    // Is there a default CP languge?
                    if ($defaultCpLanguage = Craft::$app->getConfig()->get('defaultCpLanguage')) {
                        // Make sure it's one of the site locales
                        $defaultCpLanguage = StringHelper::toLowerCase($defaultCpLanguage);

                        if (in_array($defaultCpLanguage, $siteLocaleIds)) {
                            return $defaultCpLanguage;
                        }
                    }

                    // Otherwise check if the browser's preferred language matches any of the site locales
                    if (!$request->getIsConsoleRequest()) {
                        $browserLanguages = $request->getAcceptableLanguages();

                        if ($browserLanguages) {
                            foreach ($browserLanguages as $language) {
                                if (in_array($language, $siteLocaleIds)) {
                                    return $language;
                                }
                            }
                        }
                    }
                } // Is it set to a valid site locale?
                else if (in_array($locale, $siteLocaleIds)) {
                    return $locale;
                }
            }

            // Use the primary site locale by default
            return $this->getI18n()->getPrimarySiteLocaleId();
        } else {
            return $this->_getFallbackLanguage();
        }
    }

    /**
     * Determines if Craft is installed by checking if the info table exists.
     *
     * @return boolean
     */
    public function isInstalled()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        if (!isset($this->_isInstalled)) {
            try {
                // Initialize the DB connection
                $this->getDb();

                // If the db config isn't valid, then we'll assume it's not installed.
                if (!$this->getIsDbConnectionValid()) {
                    return false;
                }
            } catch (DbConnectException $e) {
                return false;
            }

            $this->_isInstalled = (bool)($this->getRequest()->getIsConsoleRequest() || $this->getDb()->tableExists('{{%info}}',
                    false));
        }

        return $this->_isInstalled;
    }

    /**
     * Tells Craft that it's installed now.
     *
     * @return void
     */
    public function setIsInstalled()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        // If you say so!
        $this->_isInstalled = true;
    }

    /**
     * Returns whether this site has multiple locales.
     *
     * @return boolean
     */
    public function isLocalized()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        if (!isset($this->_isLocalized)) {
            $this->_isLocalized = ($this->getEdition() == Craft::Pro && count($this->getI18n()->getSiteLocales()) > 1);
        }

        return $this->_isLocalized;
    }

    /**
     * Returns the Craft edition.
     *
     * @return integer
     */
    public function getEdition()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->getInfo('edition');
    }

    /**
     * Returns the name of the Craft edition.
     *
     * @return string
     */
    public function getEditionName()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return App::getEditionName($this->getEdition());
    }

    /**
     * Returns the edition Craft is actually licensed to run in.
     *
     * @return integer|null
     */
    public function getLicensedEdition()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $licensedEdition = $this->getCache()->get('licensedEdition');

        if ($licensedEdition !== false) {
            return $licensedEdition;
        }
    }

    /**
     * Returns the name of the edition Craft is actually licensed to run in.
     *
     * @return string|null
     */
    public function getLicensedEditionName()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $licensedEdition = $this->getLicensedEdition();

        if ($licensedEdition !== null) {
            return App::getEditionName($licensedEdition);
        }
    }

    /**
     * Returns whether Craft is running with the wrong edition.
     *
     * @return boolean
     */
    public function hasWrongEdition()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $licensedEdition = $this->getLicensedEdition();

        return ($licensedEdition !== null && $licensedEdition != $this->getEdition() && !$this->canTestEditions());
    }

    /**
     * Sets the Craft edition.
     *
     * @param integer $edition The edition to set.
     *
     * @return boolean
     */
    public function setEdition($edition)
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $info = $this->getInfo();
        $oldEdition = $info->edition;
        $info->edition = $edition;

        $success = $this->saveInfo($info);

        if ($success === true && !$this->getRequest()->getIsConsoleRequest()) {
            $this->trigger(WebApplication::EVENT_AFTER_EDITION_CHANGE,
                new EditionChangeEvent([
                    'oldEdition' => $oldEdition,
                    'newEdition' => $edition
                ]));
        }

        return $success;
    }

    /**
     * Requires that Craft is running an equal or better edition than what's passed in
     *
     * @param integer $edition  The Craft edition to require.
     * @param boolean $orBetter If true, makes $edition the minimum edition required.
     *
     * @throws Exception
     */
    public function requireEdition($edition, $orBetter = true)
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        if ($this->isInstalled()) {
            $installedEdition = $this->getEdition();

            if (($orBetter && $installedEdition < $edition) || (!$orBetter && $installedEdition !== $edition)) {
                throw new Exception(Craft::t('app', 'Craft {edition} is required to perform this action.',
                    [
                        'edition' => App::getEditionName($edition)
                    ]));
            }
        }
    }

    /**
     * Returns whether Craft is eligible to be upgraded to a different edition.
     *
     * @return boolean
     */
    public function canUpgradeEdition()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        // Only admins can upgrade Craft
        if ($this->getUser()->getIsAdmin()) {
            // Are they either *using* or *licensed to use* something < Craft Pro?
            $activeEdition = $this->getEdition();
            $licensedEdition = $this->getLicensedEdition();

            return (
                ($activeEdition < Craft::Pro) ||
                ($licensedEdition !== null && $licensedEdition < Craft::Pro)
            );
        } else {
            return false;
        }
    }

    /**
     * Returns whether Craft is running on a domain that is eligible to test out the editions.
     *
     * @return boolean
     */
    public function canTestEditions()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $request = $this->getRequest();

        return (!$request->getIsConsoleRequest() && $this->getCache()->get('editionTestableDomain@'.$request->getHostName()) == 1);
    }

    /**
     * Returns the site name.
     *
     * @return string
     */
    public function getSiteName()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        if (!isset($this->_siteName)) {
            // Start by checking the config
            $siteName = $this->getConfig()->getLocalized('siteName');

            if (!$siteName) {
                $siteName = $this->getInfo('siteName');

                // Parse it for environment variables
                $siteName = $this->getConfig()->parseEnvironmentString($siteName);
            }

            $this->_siteName = $siteName;
        }

        return $this->_siteName;
    }

    /**
     * Returns the site URL (with a trailing slash).
     *
     * @param string|null $protocol The protocol to use (http or https). If none is specified, it will default to
     *                              whatever's in the Site URL setting.
     *
     * @return string
     */
    public function getSiteUrl($protocol = null)
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        if (!isset($this->_siteUrl)) {
            // Start by checking the config
            $siteUrl = $this->getConfig()->getLocalized('siteUrl');

            if (!$siteUrl) {
                if (defined('CRAFT_SITE_URL')) {
                    $siteUrl = CRAFT_SITE_URL;
                } else {
                    $siteUrl = $this->getInfo('siteUrl');
                }

                if ($siteUrl) {
                    // Parse it for environment variables
                    $siteUrl = $this->getConfig()->parseEnvironmentString($siteUrl);
                } else {
                    // Figure it out for ourselves, then
                    $siteUrl = $this->getRequest()->getBaseUrl(true);
                }
            }

            $this->setSiteUrl($siteUrl);
        }

        return Url::getUrlWithProtocol($this->_siteUrl, $protocol);
    }

    /**
     * Sets the site URL, while ensuring that the given URL ends with a trailing slash.
     *
     * @param string $siteUrl
     *
     * @return void
     */
    public function setSiteUrl($siteUrl)
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $this->_siteUrl = rtrim($siteUrl, '/').'/';
    }

    /**
     * Returns the site UID.
     *
     * @return string
     */
    public function getSiteUid()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->getInfo('uid');
    }

    /**
     * Returns whether the system is on.
     *
     * @return boolean
     */
    public function isSystemOn()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        if (is_bool($on = $this->getConfig()->get('isSystemOn'))) {
            return $on;
        }

        return (bool)$this->getInfo('on');
    }

    /**
     * Returns whether the system is in maintenance mode.
     *
     * @return boolean
     */
    public function isInMaintenanceMode()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return (bool)$this->getInfo('maintenance');
    }

    /**
     * Enables Maintenance Mode.
     *
     * @return boolean
     */
    public function enableMaintenanceMode()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->_setMaintenanceMode(1);
    }

    /**
     * Disables Maintenance Mode.
     *
     * @return boolean
     */
    public function disableMaintenanceMode()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->_setMaintenanceMode(0);
    }

    /**
     * Returns the info model, or just a particular attribute.
     *
     * @param string|null $attribute
     *
     * @throws Exception
     * @return Info|string
     */
    public function getInfo($attribute = null)
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        if (!isset($this->_info)) {
            if ($this->isInstalled()) {
                $row = (new Query())
                    ->from('{{%info}}')
                    ->one();

                if (!$row) {
                    throw new Exception(Craft::t('app', 'Craft appears to be installed but the info table is empty.'));
                }

                // TODO: Remove this after the next breakpoint
                $this->_storedVersion = $row['version'];

                // Prevent an infinite loop in toDateTime.
                $row['releaseDate'] = DateTimeHelper::toDateTime($row['releaseDate'], false, false);

                $this->_info = Info::create($row);
            } else {
                $this->_info = new Info();
            }
        }

        if ($attribute) {
            return $this->_info->$attribute;
        } else {
            return $this->_info;
        }
    }

    /**
     * Updates the info row.
     *
     * @param Info $info
     *
     * @return boolean
     */
    public function saveInfo(Info $info)
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        if ($info->validate()) {
            $attributes = Db::prepareValuesForDb($info);

            if ($this->isInstalled()) {
                // TODO: Remove this after the next breakpoint
                if (version_compare($this->_storedVersion, '3.0', '<')) {
                    unset($attributes['fieldVersion']);
                }

                $this->getDb()->createCommand()->update('{{%info}}', $attributes)->execute();
            } else {
                $this->getDb()->createCommand()->insert('{{%info}}', $attributes)->execute();

                // Set the new id
                $info->id = $this->getDb()->getLastInsertID();
            }

            // Use this as the new cached Info
            $this->_info = $info;

            return true;
        } else {
            return false;
        }
    }

    /**
     * Returns the Yii framework version.
     *
     * @return mixed
     */
    public function getYiiVersion()
    {
        return \Yii::getVersion();
    }

    /**
     * Make sure the basics are in place in the db connection file before we
     * actually try to connect later on.
     *
     * @throws DbConnectException
     * @return void
     */
    public function validateDbConfigFile()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        if ($this->_isDbConfigValid === null) {
            $messages = [];

            $databaseServerName = $this->getConfig()->get('server', ConfigCategory::Db);
            $databaseAuthName = $this->getConfig()->get('user', ConfigCategory::Db);
            $databaseName = $this->getConfig()->get('database', ConfigCategory::Db);
            $databasePort = $this->getConfig()->get('port', ConfigCategory::Db);
            $databaseCharset = $this->getConfig()->get('charset', ConfigCategory::Db);
            $databaseCollation = $this->getConfig()->get('collation', ConfigCategory::Db);

            if (!$databaseServerName) {
                $messages[] = Craft::t('app', 'The database server name isn’t set in your db config file.');
            }

            if (!$databaseAuthName) {
                $messages[] = Craft::t('app', 'The database user name isn’t set in your db config file.');
            }

            if (!$databaseName) {
                $messages[] = Craft::t('app', 'The database name isn’t set in your db config file.');
            }

            if (!$databasePort) {
                $messages[] = Craft::t('app', 'The database port isn’t set in your db config file.');
            }

            if (!$databaseCharset) {
                $messages[] = Craft::t('app', 'The database charset isn’t set in your db config file.');
            }

            if (!$databaseCollation) {
                $messages[] = Craft::t('app', 'The database collation isn’t set in your db config file.');
            }

            if (!empty($messages)) {
                $this->_isDbConfigValid = false;
                throw new DbConnectException(Craft::t('app', 'Database configuration errors: {errors}', ['errors' => implode(PHP_EOL, $messages)]));
            }

            $this->_isDbConfigValid = true;
        }

        return $this->_isDbConfigValid;
    }

    /**
     * Don't even think of moving this check into Connection->init().
     *
     * @return boolean
     */
    public function getIsDbConnectionValid()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->_isDbConnectionValid;
    }

    /**
     * Don't even think of moving this check into Connection->init().
     *
     * @param $value
     */
    public function setIsDbConnectionValid($value)
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $this->_isDbConnectionValid = $value;
    }

    // Service Getters
    // -------------------------------------------------------------------------

    /**
     * Returns the assets service.
     *
     * @return \craft\app\services\Assets The assets service
     */
    public function getAssets()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('assets');
    }

    /**
     * Returns the asset indexing service.
     *
     * @return \craft\app\services\AssetIndexer The asset indexing service
     */
    public function getAssetIndexer()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('assetIndexer');
    }

    /**
     * Returns the asset transforms service.
     *
     * @return \craft\app\services\AssetTransforms The asset transforms service
     */
    public function getAssetTransforms()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('assetTransforms');
    }

    /**
     * Returns the categories service.
     *
     * @return \craft\app\services\Categories The categories service
     */
    public function getCategories()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('categories');
    }

    /**
     * Returns the config service.
     *
     * @return \craft\app\services\Config The config service
     */
    public function getConfig()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('config');
    }

    /**
     * Returns the content service.
     *
     * @return \craft\app\services\Content The content service
     */
    public function getContent()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('content');
    }

    /**
     * Returns the dashboard service.
     *
     * @return \craft\app\services\Dashboard The dashboard service
     */
    public function getDashboard()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('dashboard');
    }

    /**
     * Returns the deprecator service.
     *
     * @return \craft\app\services\Deprecator The deprecator service
     */
    public function getDeprecator()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('deprecator');
    }

    /**
     * Returns the elements service.
     *
     * @return \craft\app\services\Elements The elements service
     */
    public function getElements()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('elements');
    }

    /**
     * Returns the email messages service.
     *
     * @return \craft\app\services\EmailMessages The email messages service
     */
    public function getEmailMessages()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('emailMessages');
    }

    /**
     * Returns the entries service.
     *
     * @return \craft\app\services\Entries The entries service
     */
    public function getEntries()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('entries');
    }

    /**
     * Returns the entry revisions service.
     *
     * @return \craft\app\services\EntryRevisions The entry revisions service
     */
    public function getEntryRevisions()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('entryRevisions');
    }

    /**
     * Returns the E.T. service.
     *
     * @return \craft\app\services\Et The E.T. service
     */
    public function getET()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('et');
    }

    /**
     * Returns the feeds service.
     *
     * @return \craft\app\services\Feeds The feeds service
     */
    public function getFeeds()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('feeds');
    }

    /**
     * Returns the fields service.
     *
     * @return \craft\app\services\Fields The fields service
     */
    public function getFields()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('fields');
    }

    /**
     * Returns the globals service.
     *
     * @return \craft\app\services\Globals The globals service
     */
    public function getGlobals()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('globals');
    }

    /**
     * Returns the images service.
     *
     * @return \craft\app\services\Images The images service
     */
    public function getImages()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('images');
    }

    /**
     * Returns a Locale object for the target language.
     *
     * @return Locale The Locale object for the target language
     */
    public function getLocale()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('locale');
    }

    /**
     * Returns the email service.
     *
     * @return \craft\app\mail\Mailer The mailer component
     */
    public function getMailer()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('mailer');
    }

    /**
     * Returns the matrix service.
     *
     * @return \craft\app\services\Matrix The matrix service
     */
    public function getMatrix()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('matrix');
    }

    /**
     * Returns the application’s migration manager.
     *
     * @return MigrationManager The application’s migration manager
     */
    public function getMigrator()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('migrator');
    }

    /**
     * Returns the path service.
     *
     * @return \craft\app\services\Path The path service
     */
    public function getPath()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('path');
    }

    /**
     * Returns the plugins service.
     *
     * @return \craft\app\services\Plugins The plugins service
     */
    public function getPlugins()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('plugins');
    }

    /**
     * Returns the relations service.
     *
     * @return \craft\app\services\Relations The relations service
     */
    public function getRelations()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('relations');
    }

    /**
     * Returns the resources service.
     *
     * @return \craft\app\services\Resources The resources service
     */
    public function getResources()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('resources');
    }

    /**
     * Returns the routes service.
     *
     * @return \craft\app\services\Routes The routes service
     */
    public function getRoutes()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('routes');
    }

    /**
     * Returns the search service.
     *
     * @return \craft\app\services\Search The search service
     */
    public function getSearch()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('search');
    }

    /**
     * Returns the sections service.
     *
     * @return \craft\app\services\Sections The sections service
     */
    public function getSections()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('sections');
    }

    /**
     * Returns the structures service.
     *
     * @return \craft\app\services\Structures The structures service
     */
    public function getStructures()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('structures');
    }

    /**
     * Returns the system settings service.
     *
     * @return \craft\app\services\SystemSettings The system settings service
     */
    public function getSystemSettings()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('systemSettings');
    }

    /**
     * Returns the tags service.
     *
     * @return \craft\app\services\Tags The tags service
     */
    public function getTags()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('tags');
    }

    /**
     * Returns the tasks service.
     *
     * @return \craft\app\services\Tasks The tasks service
     */
    public function getTasks()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('tasks');
    }

    /**
     * Returns the template cache service.
     *
     * @return \craft\app\services\TemplateCaches The template caches service
     */
    public function getTemplateCaches()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('templateCaches');
    }

    /**
     * Returns the tokens service.
     *
     * @return \craft\app\services\Tokens The tokens service
     */
    public function getTokens()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('tokens');
    }

    /**
     * Returns the updates service.
     *
     * @return \craft\app\services\Updates The updates service
     */
    public function getUpdates()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('updates');
    }

    /**
     * Returns the user groups service.
     *
     * @return \craft\app\services\UserGroups The user groups service
     */
    public function getUserGroups()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('userGroups');
    }

    /**
     * Returns the user permissions service.
     *
     * @return \craft\app\services\UserPermissions The user permissions service
     */
    public function getUserPermissions()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('userPermissions');
    }

    /**
     * Returns the users service.
     *
     * @return \craft\app\services\Users The users service
     */
    public function getUsers()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('users');
    }

    /**
     * Returns the volumes service.
     *
     * @return \craft\app\services\Volumes The volumes service
     */
    public function getVolumes()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->get('volumes');
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the definition for a given application component ID, in which we need to take special care on.
     *
     * @param string $id
     *
     * @return mixed
     */
    private function _getComponentDefinition($id)
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        switch ($id) {
            case 'assetManager':
                return $this->_getAssetManagerDefinition();
            case 'cache':
                return $this->_getCacheDefinition();
            case 'db':
                return $this->_getDbDefinition();
            case 'mailer':
                return $this->_getMailerDefinition();
            case 'formatter':
                return $this->getLocale()->getFormatter();
            case 'locale':
                return $this->_getLocaleDefinition();
            case 'log':
                return $this->_getLogDispatcherDefinition();
        }
    }

    /**
     * Returns the definition for the [[\yii\web\AssetManager]] object that will be available from Craft::$app->assetManager.
     *
     * @return array
     */
    private function _getAssetManagerDefinition()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $configService = Craft::$app->getConfig();

        return [
            'class' => 'craft\app\web\AssetManager',
            'basePath' => $configService->get('resourceBasePath'),
            'baseUrl' => $configService->get('resourceBaseUrl')
        ];
    }

    /**
     * Returns the definition for the [[\yii\caching\Cache]] object that will be available from Craft::$app->cache.
     *
     * @return string|array
     * @throws InvalidConfigException
     */
    private function _getCacheDefinition()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $configService = Craft::$app->getConfig();
        $cacheMethod = $configService->get('cacheMethod');

        switch ($cacheMethod) {
            case 'apc': {
                return 'craft\app\cache\ApcCache';
            }

            case 'db': {
                return [
                    'class' => 'craft\app\cache\DbCache',
                    'gcProbability' => $configService->get('gcProbability', ConfigCategory::DbCache),
                    'cacheTableName' => $this->_getNormalizedTablePrefix().$configService->get('cacheTableName',
                            ConfigCategory::DbCache),
                    'autoCreateCacheTable' => true,
                ];
            }

            case 'file': {
                return [
                    'class' => 'craft\app\cache\FileCache',
                    'cachePath' => $configService->get('cachePath', ConfigCategory::FileCache),
                    'gcProbability' => $configService->get('gcProbability', ConfigCategory::FileCache),
                ];
            }

            case 'memcache': {
                return [
                    'class' => 'craft\app\cache\MemCache',
                    'servers' => $configService->get('servers', ConfigCategory::Memcache),
                    'useMemcached' => $configService->get('useMemcached', ConfigCategory::Memcache),
                ];
            }

            case 'wincache': {
                return 'craft\app\cache\WinCache';
            }

            case 'xcache': {
                return 'craft\app\cache\XCache';
            }

            case 'zenddata': {
                return 'craft\app\cache\ZendDataCache';
            }

            default: {
                throw new InvalidConfigException('Unsupported cacheMethod config setting value: '.$cacheMethod);
            }
        }
    }

    /**
     * Returns the definition for the [[Command]] object that will be available from Craft::$app->db.
     *
     * @return Connection
     * @throws DbConnectException
     */
    private function _getDbDefinition()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $configService = $this->getConfig();

        try {
            $config = [
                'class' => 'craft\app\db\Connection',
                'dsn' => $this->_processConnectionString(),
                'emulatePrepare' => true,
                'username' => $configService->get('user', ConfigCategory::Db),
                'password' => $configService->get('password', ConfigCategory::Db),
                'charset' => $configService->get('charset', ConfigCategory::Db),
                'tablePrefix' => $this->_getNormalizedTablePrefix(),
                'schemaMap' => [
                    'mysql' => '\\craft\\app\\db\\mysql\\Schema',
                ],
            ];

            $db = Craft::createObject($config);
            $db->open();
        } // Most likely missing PDO in general or the specific database PDO driver.
        catch (\yii\db\Exception $e) {
            Craft::error($e->getMessage(), __METHOD__);

            // TODO: Multi-db driver check.
            if (!extension_loaded('pdo')) {
                throw new DbConnectException(Craft::t('app', 'Craft requires the PDO extension to operate.'));
            } else if (!extension_loaded('pdo_mysql')) {
                throw new DbConnectException(Craft::t('app', 'Craft requires the PDO_MYSQL driver to operate.'));
            } else {
                Craft::error($e->getMessage(), __METHOD__);
                throw new DbConnectException(Craft::t('app', 'Craft can’t connect to the database with the credentials in craft/config/db.php.'));
            }
        } catch (\Exception $e) {
            Craft::error($e->getMessage(), __METHOD__);
            throw new DbConnectException(Craft::t('app', 'Craft can’t connect to the database with the credentials in craft/config/db.php.'));
        }

        $this->setIsDbConnectionValid(true);

        return $db;
    }

    /**
     * Returns the definition for the Mailer object that will be available from Craft::$app->getMailer().
     *
     * @return Mailer
     */
    private function _getMailerDefinition()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return $this->getSystemSettings()->getSettings('mailer');
    }

    /**
     * Returns the definition for the Locale object that will be available from Craft::$app->getLocale().
     *
     * @return Locale
     */
    private function _getLocaleDefinition()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        return new Locale($this->language);
    }

    /**
     * Returns the definition for the Dispatcher object that will be available from Craft::$app->getLog().
     *
     * @return array|null
     */
    private function _getLogDispatcherDefinition()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $isConsoleRequest = $this->getRequest()->getIsConsoleRequest();

        // Don't log getAuthTimeout requests
        if ($isConsoleRequest || $this->getUser()->enableSession) {
            $configService = Craft::$app->getConfig();
            $fileTarget = new FileTarget();

            if ($isConsoleRequest) {
                $fileTarget->logFile = Craft::getAlias('@storage/logs/console.log');
            } else {
                $fileTarget->logFile = Craft::getAlias('@storage/logs/web.log');

                // Only log errors and warnings, unless Craft is running in Dev Mode or it's being updated
                if (!$configService->get('devMode') || !$this->_isCraftUpdating()) {
                    $fileTarget->setLevels(Logger::LEVEL_ERROR | Logger::LEVEL_WARNING);
                }
            }

            $fileTarget->fileMode = $configService->get('defaultFilePermissions');
            $fileTarget->dirMode = $configService->get('defaultFolderPermissions');

            return [
                'class' => '\yii\log\Dispatcher',
                'targets' => [
                    $fileTarget
                ]
            ];
        } else {
            return null;
        }
    }

    /**
     * Returns the application’s configured DB table prefix.
     *
     * @return string
     */
    private function _getNormalizedTablePrefix()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        // Table prefixes cannot be longer than 5 characters
        $tablePrefix = rtrim($this->getConfig()->get('tablePrefix', ConfigCategory::Db), '_');

        if ($tablePrefix) {
            if (StringHelper::length($tablePrefix) > 5) {
                $tablePrefix = substr($tablePrefix, 0, 5);
            }

            $tablePrefix .= '_';
        } else {
            $tablePrefix = '';
        }

        return $tablePrefix;
    }

    /**
     * Sets the target application language.
     */
    private function _setLanguage()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        // Defend against an infinite _setLanguage() loop
        if (!$this->_gettingLanguage) {
            $this->_gettingLanguage = true;
            $request = $this->getRequest();
            $useUserLanguage = $request->getIsConsoleRequest() || $request->getIsCpRequest();
            $targetLanguage = $this->getTargetLanguage($useUserLanguage);
            $this->language = $targetLanguage;
        } else {
            // We tried to get the language, but something went wrong. Use fallback to prevent infinite loop.
            $fallbackLanguage = $this->_getFallbackLanguage();
            $this->_gettingLanguage = false;
            $this->language = $fallbackLanguage;
        }
    }

    /**
     * Sets the system timezone.
     */
    private function _setTimeZone()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $timezone = $this->getConfig()->get('timezone');

        if (!$timezone) {
            $timezone = $this->getInfo('timezone');
        }

        if ($timezone) {
            $this->setTimeZone($timezone);
        }
    }

    /**
     * Enables or disables Maintenance Mode
     *
     * @param boolean $value
     *
     * @return boolean
     */
    private function _setMaintenanceMode($value)
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $info = $this->getInfo();
        $info->maintenance = $value;

        return $this->saveInfo($info);
    }

    /**
     * Returns the correct connection string depending on whether a unixSocket is specified or not in the db config.
     *
     * @return string
     */
    private function _processConnectionString()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $unixSocket = $this->getConfig()->get('unixSocket', ConfigCategory::Db);

        if (!empty($unixSocket)) {
            return strtolower('mysql:unix_socket='.$unixSocket.';dbname=').$this->getConfig()->get('database', ConfigCategory::Db).';';
        } else {
            return strtolower('mysql:host='.$this->getConfig()->get('server',
                    ConfigCategory::Db).';dbname=').$this->getConfig()->get('database',
                ConfigCategory::Db).strtolower(';port='.$this->getConfig()->get('port', ConfigCategory::Db).';');
        }
    }

    /**
     * Tries to find a language match with the user's browser's preferred language(s).
     * If not uses the app's sourceLanguage.
     *
     * @return string
     */
    private function _getFallbackLanguage()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        // See if we have the CP translated in one of the user's browsers preferred language(s)
        $language = $this->getTranslatedBrowserLanguage();

        // Default to the source language.
        if (!$language) {
            $language = $this->sourceLanguage;
        }

        return $language;
    }

    /**
     * Sets the edition components.
     *
     * @return void
     */
    private function _setEditionComponents()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        // Set the appropriate edition components
        $edition = $this->getEdition();

        if ($edition == Craft::Client || $edition == Craft::Pro) {
            $pathService = $this->getPath();

            $this->setComponents(require $pathService->getAppPath().'/config/components/client.php');

            if ($edition == Craft::Pro) {
                $this->setComponents(require $pathService->getAppPath().'/config/components/pro.php');
            }
        }
    }

    /**
     * Returns whether Craft is in the middle of an update.
     *
     * @return boolean
     */
    private function _isCraftUpdating()
    {
        /** @var $this \craft\app\web\Application|\craft\app\console\Application */
        $request = $this->getRequest();

        if ($this->getUpdates()->isCraftDbMigrationNeeded() ||
            ($this->isInMaintenanceMode() && $request->getIsCpRequest()) ||
            $request->getActionSegments() == ['update', 'cleanUp'] ||
            $request->getActionSegments() == ['update', 'rollback']
        ) {
            return true;
        }

        return false;
    }
}