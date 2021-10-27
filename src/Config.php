<?php

declare(strict_types=1);

/*
 * This file is part of the package "typo3-config" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13;

use TYPO3\CMS\Core\Cache\Backend\RedisBackend;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Log\LogLevel;

/**
 * Class to use in your configuration files of a TYPO3 project.
 */
class Config
{
    /**
     * @var ApplicationContext
     */
    protected $context;

    /**
     * @var Typo3Version
     */
    protected $version;
    /**
     * @var string
     */
    protected $configPath;
    /**
     * @var string
     */
    protected $varPath;
    /**
     * @var bool
     */
    protected $ddevEnvironment = false;
    /**
     * @var Config
     */
    protected static $instance;

    private function __construct()
    {
        $this->context = Environment::getContext();
        $this->version = new Typo3Version();
        $this->configPath = Environment::getConfigPath();
        $this->varPath = Environment::getVarPath();
        $this->ddevEnvironment = getenv('IS_DDEV_PROJECT') == 'true';
    }

    /**
     * @param bool $includeAutomatedLoader
     * @return static
     */
    public static function initialize(bool $includeAutomatedLoader = true): self
    {
        // Late static binding
        self::$instance = new static();
        if ($includeAutomatedLoader === false) {
            return self::$instance;
        }
        self::$instance
            // use sensible default based on Context
            ->applyDefaults()
            ->appendContextToSiteName();
    }

    /**
     * @return static
     */
    public static function get(): self
    {
        return self::$instance;
    }

    public function applyDefaults(): self
    {
        // Include presets by default
        self::$instance
            ->forbidInvalidCacheHashQueryParameter()
            ->forbidNoCacheQueryParameter();

        if (self::$instance->context->isDevelopment()) {
            self::$instance->useDevelopmentPreset();
            if (self::$instance->ddevEnvironment) {
                self::$instance->useDDEVConfiguration();
            }
        } elseif (self::$instance->context->isProduction()) {
            self::$instance->useProductionPreset();
        }
        return $this;
    }

    /**
     * Include additional configurations by TYPO3_CONTEXT server variable
     *
     * Example:
     * - TYPO3_CONTEXT: Production/Qa
     * - Possible configuration files:
     *   1. config/production.php
     *   2. config/production/qa.php (higher priority)
     *
     * Allowed base TYPO3_CONTEXT values:
     * - Development
     * - Testing
     * - Production
     */
    public function includeContextDependentConfigurationFiles(): self
    {
        $orderedListOfContextNames = [];
        $currentContext = $this->context;
        do {
            $orderedListOfContextNames[] = (string)$currentContext;
        } while (($currentContext = $currentContext->getParent()));
        $orderedListOfContextNames = array_reverse($orderedListOfContextNames);
        foreach ($orderedListOfContextNames as $contextName) {
            $contextConfigFilePath = $this->configPath . '/' . strtolower($contextName) . '.php';
            if (file_exists($contextConfigFilePath)) {
                require($contextConfigFilePath);
            }
        }
        return $this;
    }

    /**
     * Append TYPO3_CONTEXT to site name in the TYPO3 backend
     */
    public function appendContextToSiteName(): self
    {
        if ($this->context->isProduction() === false) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] .= ' - ' . (string)$this->context;
        }
        return $this;
    }

    public function initializeDatabaseConnection(array $options = null, $connectionName = 'Default'): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$connectionName] = array_replace_recursive(
            $options,
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$connectionName]
        );
        return $this;
    }

    public function useProductionPreset(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = false;
        $GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = false;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = -1;
        return $this;
    }

    public function useDevelopmentPreset(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '*';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = 1;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*.*';
        $this->enableDeprecationLogging();
        return $this;
    }

    public function useDDEVConfiguration(): self
    {
        $this
            ->initializeDatabaseConnection(
                [
                    'dbname' => 'db',
                    'host' => 'db',
                    'password' => 'db',
                    'port' => '3306',
                    'user' => 'db',
                ]
            )
            ->useImageMagick()
            ->useMailhog(getenv('MH_SMTP_BIND_ADDR'));
        return $this;
    }

    public function useImageMagick(string $path = '/usr/bin/'): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'ImageMagick';
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path'] = $path;
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path_lzw'] = $path;
        return $this;
    }

    public function useGraphicsMagick(string $path = '/usr/bin/'): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'GraphicsMagick';
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path'] = $path;
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path_lzw'] = $path;
        return $this;
    }

    public function useMailhog(string $host = 'localhost', int $port = null): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_encrypt'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] = $host . ($port ? ':' . (string)$port : '');
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_username'] = '';
        return $this;
    }

    public function allowNoCacheQueryParameter(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['disableNoCacheParameter'] = false;
        return $this;
    }

    public function forbidNoCacheQueryParameter(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['disableNoCacheParameter'] = true;
        return $this;
    }

    public function allowInvalidCacheHashQueryParameter(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['pageNotFoundOnCHashError'] = false;
        return $this;
    }

    public function forbidInvalidCacheHashQueryParameter(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['disableNoCacheParameter'] = true;
        return $this;
    }

    public function addQueryParameterForCacheHashCalculation(string $queryParameter): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = $queryParameter;
        return $this;
    }

    public function enableDeprecationLogging(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['deprecations']['writerConfiguration'][LogLevel::NOTICE]['TYPO3\CMS\Core\Log\Writer\FileWriter']['disabled'] = false;
        return $this;
    }

    public function disableDeprecationLogging(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['deprecations']['writerConfiguration'][LogLevel::NOTICE]['TYPO3\CMS\Core\Log\Writer\FileWriter']['disabled'] = true;
        return $this;
    }

    /**
     * Additional Project-specific methods
     */
    public function configureExceptionHandlers(string $productionExceptionHandlerClassName, string $debugExceptionHandlerClassName): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['productionExceptionHandler'] = $productionExceptionHandlerClassName;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['debugExceptionHandler'] = $debugExceptionHandlerClassName;
        return $this;
    }

    /**
     * Activates caching for Redis, if used in its environment.
     *
     * @param array|null $caches an associative array of [cache_name => default lifetime], if null, then we rely on best practices
     * @param string $redisHost alternative redis host
     * @param int $redisStartDb the start DB for the redis caches
     * @param int $redisPort alternative port for redis, usually 6379
     * @param null $alternativeCacheBackend alternative cache backend, useful if you use b13/graceful-caches
     * @return $this
     */
    public function initializeRedisCaching(array $caches = null, string $redisHost = '127.0.0.1', int $redisStartDb = 0, int $redisPort = 6379, $alternativeCacheBackend = null): self
    {
        $isVersion9 = $this->version->getMajorVersion() === 9;
        $cacheBackend = $alternativeCacheBackend ?? RedisBackend::class;
        $redisDb = $redisStartDb;
        $caches = $caches ?? [
                ($isVersion9 ? 'cache_pages' : 'pages') => 86400*30,
                ($isVersion9 ? 'cache_pagesection' : 'pagesection') => 86400*30,
                ($isVersion9 ? 'cache_hash' : 'hash') => 86400*30,
                ($isVersion9 ? 'cache_rootline' : 'rootline') => 86400*30,
                ($isVersion9 ? 'cache_extbase' : 'extbase') => 0,
        ];
        foreach ($caches as $key => $lifetime) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$key]['backend'] = $cacheBackend;
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$key]['options'] = [
                'database' => $redisDb++,
                'hostname' => $redisHost,
                'port' => $redisPort,
                'defaultLifetime' => $lifetime
            ];
        }
        return $this;
    }

    public function setAlternativeCachePath(string $path, array $applyForCaches = null): self
    {
        $applyForCaches = $applyForCaches ?? [
                'cache_core',
                'fluid_template',
                'assets',
                'l10n'
            ];
        foreach ($applyForCaches as $cacheName) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheName]['options']['cacheDirectory'] = $path;
        }
    }
}