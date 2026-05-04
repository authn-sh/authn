<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Laravel\Dusk\TestCase as BaseTestCase;

abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Dusk runs against the live `make dev` stack at http://app:8080 (inside
     * the compose network). The docker-compose.dusk.yml layer ships a
     * `selenium/standalone-chromium` container; we point Dusk at it via
     * DUSK_DRIVER_URL. ChromeDriver is owned by Selenium — we don't start
     * one locally.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments([
            '--window-size=1280,800',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            // Modern Chrome auto-upgrades single-label hostnames (e.g.
            // `app`) to HTTPS via the HttpsUpgrades feature. The dev stack
            // serves plaintext, so the upgrade trips ERR_SSL_PROTOCOL_ERROR.
            // Disable both feature flags so http://app:8080 stays http.
            '--disable-features=HttpsUpgrades,HttpsFirstBalancedMode,SingleLetterHostnameRedirect,HttpsFirstModeForTypicallySecureUsers,HttpsFirstModeIncognito',
            '--ignore-certificate-errors',
        ]);

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:4444/wd/hub',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }
}
