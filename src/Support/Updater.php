<?php

namespace Zanbug\Sdk\Support;

use Zanbug\Sdk\Zanbug;
use Zanbug\Sdk\Config;
use Zanbug\Sdk\Transport\CurlTransport;
use Zanbug\Sdk\Transport\StreamTransport;
use Zanbug\Sdk\Transport\Transport;

/**
 * Keeps the SDK current.
 *
 *  - check()  asks the panel for the newest published version (GET /api/ingest/latest)
 *  - update() upgrades the install in place: `composer require zanbug/*:^X` for a
 *             composer install, a GitHub release zip swapped atomically for a
 *             manual (autoload.php) install
 *  - maybeAutoUpdate() is the opt-in daily hook (`auto_update` / ZANBUG_AUTO_UPDATE):
 *             at most once per 24h, CLI only, runs `bin/zanbug update` detached so
 *             the customer's cron/queue process is never slowed down by it.
 *
 * Everything here is best-effort and never throws: monitoring must not become
 * the outage, and neither must its updater. PHP 7.0 compatible.
 */
class Updater
{
    const RELEASE_ZIP = 'https://github.com/zanbug/php-sdk/archive/refs/tags/v%s.zip';
    const CHECK_INTERVAL = 86400;
    const COMPOSER_TIMEOUT = 600;

    /**
     * @return array current, latest, update_available, error
     */
    public static function check(Config $config, $transport = null)
    {
        $out = array('current' => Zanbug::VERSION, 'latest' => null, 'update_available' => false, 'error' => null);
        try {
            if (!$config->isUsable()) {
                $out['error'] = 'no api_key configured (ZANBUG_API_KEY)';

                return $out;
            }
            $transport = $transport instanceof Transport ? $transport : self::transport();
            $data = $transport->fetch($config->latestUrl(), $config->apiKey);
            $latest = (is_array($data) && isset($data['latest']['php'])) ? trim((string) $data['latest']['php']) : '';
            if ($latest === '' || !preg_match('/^\d+\.\d+\.\d+/', $latest)) {
                $out['error'] = 'could not read the latest version from ' . $config->host;

                return $out;
            }
            $out['latest'] = $latest;
            $out['update_available'] = version_compare($latest, Zanbug::VERSION, '>');
        } catch (\Exception $e) {
            $out['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /**
     * @param callable|null $log receives progress lines (composer output etc.)
     * @return array ok, mode (composer|manual|none), from, to, message, output
     */
    public static function update(Config $config, $transport = null, $dryRun = false, $log = null)
    {
        $res = array('ok' => false, 'mode' => 'none', 'from' => Zanbug::VERSION, 'to' => null, 'message' => '', 'output' => '');
        try {
            $check = self::check($config, $transport);
            if ($check['error'] !== null) {
                $res['message'] = $check['error'];

                return $res;
            }
            $res['to'] = $check['latest'];
            if (!$check['update_available']) {
                $res['ok'] = true;
                $res['message'] = 'already up to date (' . Zanbug::VERSION . ')';

                return $res;
            }
            $install = self::detectInstall();
            $res['mode'] = $install['mode'];
            if ($install['mode'] === 'composer') {
                return self::composerUpdate($install, $check['latest'], $dryRun, $res, $log);
            }
            if ($install['mode'] === 'manual') {
                return self::manualUpdate($install, $check['latest'], $dryRun, $res, $log);
            }
            $res['message'] = 'cannot tell how the SDK was installed; update it by hand (composer require zanbug/php-sdk:^' . $check['latest'] . ')';
        } catch (\Exception $e) {
            $res['message'] = $e->getMessage();
        } catch (\Throwable $e) {
            $res['message'] = $e->getMessage();
        }

        return $res;
    }

    /**
     * Opt-in daily hook. Called from the CLI run tracker on shutdown, and —
     * via registerWebHook() — after a web response has been flushed, so a
     * manual install on shared hosting with no cron still updates itself.
     * Never more than once per 24h per install; the actual work runs in a
     * detached process so the calling request/command finishes at normal speed.
     */
    public static function maybeAutoUpdate(Config $config, $transport = null, $allowWeb = false)
    {
        try {
            if (!$config->autoUpdate || (PHP_SAPI !== 'cli' && !$allowWeb) || !$config->isUsable()) {
                return false;
            }
            if (!self::checkDue($config)) {
                return false;
            }
            // No writable marker → no once-a-day guarantee → do not check at
            // all (otherwise every request after 24h would hit the network).
            if (@file_put_contents(self::markerPath($config), (string) time()) === false) {
                return false;
            }

            $check = self::check($config, $transport);
            if ($check['error'] !== null || !$check['update_available']) {
                return false;
            }

            $bin = dirname(dirname(__DIR__)) . '/bin/zanbug';
            $logFile = self::logPath();
            $install = self::detectInstall();
            $mode = isset($install['mode']) ? $install['mode'] : 'none';
            if (!empty($install['path_repo'])) {
                // linked from a local path repository (monorepo/dev box):
                // composer cannot replace it, so do not spawn a failing run.
                @file_put_contents($logFile, '[' . date('c') . '] update ' . Zanbug::VERSION . ' -> ' . $check['latest']
                    . ' skipped: SDK is linked from a local path repository (' . $install['path_repo'] . ")\n", FILE_APPEND);

                return false;
            }
            $target = $mode === 'manual' && !empty($install['root']) ? $install['root']
                : ($mode === 'composer' && !empty($install['vendor'])
                    ? (is_dir($install['vendor'] . '/zanbug') ? $install['vendor'] . '/zanbug' : $install['vendor'] . '/bugban') // legacy: bugban → zanbug (2026-10-07)
                    : null);
            if ($target !== null && (!@is_writable($target) || ($mode === 'manual' && !@is_writable($target . '/src')))) {
                // e.g. php-fpm as www-data, libs/ owned by root: leave it to
                // the CLI side (cron / artisan as the owner) — say so once a day.
                @file_put_contents($logFile, '[' . date('c') . '] update ' . Zanbug::VERSION . ' -> ' . $check['latest']
                    . ' skipped: ' . $target . ' is not writable by ' . self::whoami()
                    . ' (the owner\'s cron/CLI run will update it, or run: php ' . $bin . " update --yes)\n", FILE_APPEND);

                return false;
            }
            $php = self::cliPhp();
            if (DIRECTORY_SEPARATOR === '/' && is_file($bin) && function_exists('proc_open') && $php !== null) {
                $cmd = escapeshellarg($php) . ' ' . escapeshellarg($bin) . ' update --yes';
                $cmd = '(echo ' . escapeshellarg('[' . date('c') . '] auto-update ' . Zanbug::VERSION . ' -> ' . $check['latest']) . '; ' . $cmd . ') >> ' . escapeshellarg($logFile) . ' 2>&1 &';
                $env = self::childEnv($config);
                $p = @proc_open($cmd, array(), $pipes, null, $env);
                if (is_resource($p)) {
                    @proc_close($p);

                    return true;
                }
            }
            // No detached shell (Windows / proc_open disabled): do it inline.
            $res = self::update($config, $transport, false, null);
            @file_put_contents($logFile, date('c') . ' ' . ($res['ok'] ? 'ok' : 'failed') . ' ' . $res['message'] . "\n", FILE_APPEND);

            return $res['ok'];
        } catch (\Exception $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Web-request side of the daily check. Registered once from Zanbug::init()
     * when auto_update is on and we are not in CLI. Runs at shutdown, AFTER
     * the response has gone out (fastcgi_finish_request when available), so
     * the visitor never waits for the network. Cheap when not due: one stat().
     */
    public static function registerWebHook(Config $config, $transport = null)
    {
        if (!$config->autoUpdate || PHP_SAPI === 'cli' || !$config->isUsable()) {
            return;
        }
        register_shutdown_function(function () use ($config, $transport) {
            try {
                if (!self::checkDue($config)) {
                    return;
                }
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                self::maybeAutoUpdate($config, $transport, true);
            } catch (\Exception $e) {
                // never let the updater surface in the app
            } catch (\Throwable $e) {
                // ditto
            }
        });
    }

    /** True when the 24h marker is missing or older than CHECK_INTERVAL. */
    private static function checkDue(Config $config)
    {
        $marker = self::markerPath($config);
        if ($marker === null) {
            return false;
        }
        $stamp = @filemtime($marker);

        return $stamp === false || (time() - (int) $stamp) >= self::CHECK_INTERVAL;
    }

    /** Where maybeAutoUpdate() writes what it did. */
    public static function logPath()
    {
        $dir = @sys_get_temp_dir();

        return (is_string($dir) && $dir !== '' ? $dir : '/tmp') . '/zanbug-update.log';
    }

    // ---- install detection ---------------------------------------------------

    /**
     * @return array mode (composer|manual|none), root, sdk_root, packages[]
     */
    public static function detectInstall()
    {
        $sdkRoot = dirname(dirname(__DIR__)); // .../src/Support -> package root
        $info = array('mode' => 'none', 'root' => null, 'sdk_root' => $sdkRoot, 'packages' => array());

        // vendor/zanbug/php-sdk  -> vendor -> project root
        $vendor = dirname(dirname($sdkRoot));
        // legacy: bugban → zanbug (2026-10-07): a copy living under the old vendor/bugban/ dir still counts as composer mode.
        if (in_array(basename(dirname($sdkRoot)), array('zanbug', 'bugban'), true) && is_file($vendor . '/composer/installed.json')) {
            $info['mode'] = 'composer';
            $info['root'] = dirname($vendor);
            $info['vendor'] = $vendor;
            $info['packages'] = self::installedZanbugPackages($vendor . '/composer/installed.json');
            if (!$info['packages']) {
                $info['packages'] = array('zanbug/php-sdk');
            }
            self::describeComposer($info, $vendor . '/composer/installed.json', $sdkRoot);

            return $info;
        }

        // Composer "path" repositories (and some deploy tools) SYMLINK
        // vendor/zanbug/php-sdk to a source folder, so __DIR__ resolves outside
        // vendor/ and the check above misses. Composer 2 knows the truth
        // directly; its class file sits at vendor/composer/InstalledVersions.php.
        if (class_exists('\Composer\InstalledVersions') && method_exists('\Composer\InstalledVersions', 'isInstalled')) {
            try {
                if (\Composer\InstalledVersions::isInstalled('zanbug/php-sdk')
                    || \Composer\InstalledVersions::isInstalled('bugban/php-sdk')) { // legacy: bugban → zanbug (2026-10-07)
                    $ref = new \ReflectionClass('\Composer\InstalledVersions');
                    $composerDir = dirname((string) $ref->getFileName());
                    if (is_file($composerDir . '/installed.json')) {
                        $info['mode'] = 'composer';
                        $info['vendor'] = dirname($composerDir);
                        $info['root'] = dirname($info['vendor']);
                        $info['packages'] = self::installedZanbugPackages($composerDir . '/installed.json');
                        if (!$info['packages']) {
                            $info['packages'] = array('zanbug/php-sdk');
                        }
                        self::describeComposer($info, $composerDir . '/installed.json', $sdkRoot);

                        return $info;
                    }
                }
            } catch (\Exception $e) {
            } catch (\Throwable $e) {
            }
        }

        if (is_file($sdkRoot . '/autoload.php') && is_file($sdkRoot . '/src/Zanbug.php')) {
            $info['mode'] = 'manual';
            $info['root'] = $sdkRoot;
            $info['packages'] = array('zanbug/php-sdk');
        }

        return $info;
    }

    /**
     * Adds path_repo (string|null) and no_dev (bool) to a composer-mode $info.
     *
     * path_repo: the SDK is linked from a local "path" repository (composer
     * symlinks or copies a folder of the app itself). `composer require
     * zanbug/php-sdk:^X` can never upgrade it — composer refuses to take the
     * Packagist release over the canonical path repo — so update() reports it
     * instead of running composer and failing with exit 2.
     * no_dev: the install was made with --no-dev; keep it that way so an
     * update does not pull every dev dependency onto a production box.
     */
    private static function describeComposer(array &$info, $installedJson, $sdkRoot)
    {
        $info['path_repo'] = null;
        $info['no_dev'] = false;
        $data = json_decode((string) @file_get_contents($installedJson), true);
        if (is_array($data)) {
            if (isset($data['dev']) && $data['dev'] === false) {
                $info['no_dev'] = true;
            }
            $list = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data;
            foreach ($list as $pkg) {
                if (is_array($pkg) && isset($pkg['name']) && strpos((string) $pkg['name'], 'zanbug/') === 0
                    && isset($pkg['dist']['type']) && $pkg['dist']['type'] === 'path') {
                    $info['path_repo'] = isset($pkg['dist']['url']) ? (string) $pkg['dist']['url'] : (string) $pkg['name'];
                    break;
                }
            }
        }
        if ($info['path_repo'] === null && !empty($info['vendor'])) {
            // installed.json may be missing the dist block; a package root that
            // resolves outside vendor/ is the same situation.
            $real = @realpath($sdkRoot);
            $vend = @realpath($info['vendor']);
            if (is_string($real) && is_string($vend) && strpos($real . '/', rtrim($vend, '/') . '/') !== 0) {
                $info['path_repo'] = $real;
            }
        }
    }

    private static function installedZanbugPackages($installedJson)
    {
        $out = array();
        $data = json_decode((string) @file_get_contents($installedJson), true);
        if (!is_array($data)) {
            return $out;
        }
        $list = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data; // composer 2 vs 1
        foreach ($list as $pkg) {
            if (is_array($pkg) && isset($pkg['name'])
                && (strpos((string) $pkg['name'], 'zanbug/') === 0 || strpos((string) $pkg['name'], 'bugban/') === 0)) { // legacy: bugban → zanbug (2026-10-07)
                $out[] = (string) $pkg['name'];
            }
        }
        // Core first: a stale lock leaving an old core behind is what took a
        // customer's site down in v1.5.2.
        usort($out, function ($a, $b) {
            if ($a === 'zanbug/php-sdk') {
                return -1;
            }
            if ($b === 'zanbug/php-sdk') {
                return 1;
            }

            return strcmp($a, $b);
        });

        return array_values(array_unique($out));
    }

    // ---- composer mode --------------------------------------------------------

    private static function composerUpdate(array $install, $latest, $dryRun, array $res, $log)
    {
        if (!empty($install['path_repo'])) {
            $res['message'] = 'the SDK is linked from a local composer "path" repository (' . $install['path_repo'] . '), so composer will not replace it with the published ^'
                . $latest . ' release. Update the files in that folder (and its "version" in composer.json), or drop the path repository and run: composer require zanbug/php-sdk:^' . $latest;

            return $res;
        }
        $composer = self::findComposer($install['root']);
        if ($composer === null) {
            $res['message'] = 'composer executable not found (set COMPOSER_BIN=/path/to/composer)';

            return $res;
        }
        $parts = $composer;
        $parts[] = 'require';
        foreach ($install['packages'] as $name) {
            if (strpos($name, 'bugban/') === 0) { // legacy: bugban → zanbug (2026-10-07): old packages have no 2.x release; migrate by hand (composer remove bugban/* && composer require zanbug/...)
                continue;
            }
            $parts[] = $name . ':^' . $latest;
        }
        if (count($parts) === count($composer) + 1) { // legacy: bugban → zanbug (2026-10-07): only old bugban/* packages found
            $res['message'] = 'only legacy bugban/* packages are installed; migrate by hand: composer remove bugban/* && composer require zanbug/php-sdk:^' . $latest;

            return $res;
        }
        $parts[] = '--update-with-dependencies';
        if (!empty($install['no_dev'])) {
            $parts[] = '--update-no-dev';
        }
        $parts[] = '--no-interaction';
        $parts[] = '--no-progress';
        if ($dryRun) {
            $parts[] = '--dry-run';
        }
        $cmd = implode(' ', array_map('escapeshellarg', $parts));
        self::say($log, '$ ' . $cmd);

        $env = self::childEnv(null);
        $env['COMPOSER_ALLOW_SUPERUSER'] = '1';
        $env['COMPOSER_NO_INTERACTION'] = '1';
        $env['COMPOSER_MEMORY_LIMIT'] = '-1';

        $exit = self::run($cmd, $install['root'], $env, self::COMPOSER_TIMEOUT, $output, $log);
        $res['output'] = $output;
        if ($exit === 0) {
            $res['ok'] = true;
            $res['message'] = ($dryRun ? '[dry-run] would update ' : 'updated ') . implode(', ', $install['packages']) . ' to ^' . $latest;
        } else {
            $res['message'] = 'composer exited with code ' . var_export($exit, true) . '; nothing was changed by us — see the output above';
        }

        return $res;
    }

    /** @return array|null argv prefix, e.g. ['composer'] or ['/usr/bin/php','/srv/app/composer.phar'] */
    private static function findComposer($root)
    {
        $envBin = getenv('COMPOSER_BIN');
        if (is_string($envBin) && $envBin !== '' && is_file($envBin)) {
            return substr($envBin, -5) === '.phar' ? array(self::cliPhpOr(), $envBin) : array($envBin);
        }
        if (is_file($root . '/composer.phar')) {
            return array(self::cliPhpOr(), $root . '/composer.phar');
        }
        $path = (string) getenv('PATH');
        $dirs = array_filter(explode(PATH_SEPARATOR, $path));
        $dirs[] = '/usr/local/bin';
        $dirs[] = '/usr/bin';
        $home = (string) getenv('HOME');
        if ($home !== '') {
            $dirs[] = $home . '/.local/bin';
            $dirs[] = $home . '/.composer/vendor/bin';
            $dirs[] = $home . '/.config/composer/vendor/bin';
        }
        foreach (array_unique($dirs) as $dir) {
            foreach (array('composer', 'composer.phar') as $name) {
                $bin = rtrim($dir, '/') . '/' . $name;
                if (is_file($bin) && (is_executable($bin) || $name === 'composer.phar')) {
                    return $name === 'composer.phar' ? array(self::cliPhpOr(), $bin) : array($bin);
                }
            }
        }

        return null;
    }

    // ---- manual (autoload.php) mode ---------------------------------------------

    private static function manualUpdate(array $install, $latest, $dryRun, array $res, $log)
    {
        $root = $install['root'];
        if (!class_exists('ZipArchive')) {
            $res['message'] = 'ext-zip is missing; download ' . sprintf(self::RELEASE_ZIP, $latest) . ' and replace ' . $root . '/src by hand';

            return $res;
        }
        if (!is_writable($root) || !is_writable($root . '/src')) {
            $res['message'] = $root . ' is not writable by ' . self::whoami();

            return $res;
        }
        $url = sprintf(self::RELEASE_ZIP, $latest);
        if ($dryRun) {
            $res['ok'] = true;
            $res['message'] = '[dry-run] would download ' . $url . ' and replace ' . $root . '/src';

            return $res;
        }
        self::say($log, 'downloading ' . $url);
        $zip = self::download($url);
        if ($zip === null) {
            $res['message'] = 'download failed: ' . $url;

            return $res;
        }
        $tmp = $zip . '.d';
        @mkdir($tmp, 0755, true);
        $za = new \ZipArchive();
        $opened = $za->open($zip);
        if ($opened !== true || !$za->extractTo($tmp)) {
            @unlink($zip);
            self::rmdir($tmp);
            $res['message'] = 'could not extract the release archive';

            return $res;
        }
        $za->close();
        @unlink($zip);

        $src = self::findExtracted($tmp);
        if ($src === null || !is_file($src . '/src/Zanbug.php')) {
            self::rmdir($tmp);
            $res['message'] = 'release archive has an unexpected layout';

            return $res;
        }
        $shipped = (string) @file_get_contents($src . '/src/Zanbug.php');
        if (strpos($shipped, "VERSION = '" . $latest . "'") === false) {
            self::rmdir($tmp);
            $res['message'] = 'archive does not contain version ' . $latest;

            return $res;
        }

        // Atomic swap: rename old src aside, move new src in, roll back on failure.
        $backup = $root . '/src.bak-' . Zanbug::VERSION;
        self::rmdir($backup);
        if (!@rename($root . '/src', $backup)) {
            self::rmdir($tmp);
            $res['message'] = 'could not move the current src/ aside';

            return $res;
        }
        if (!@rename($src . '/src', $root . '/src')) {
            @rename($backup, $root . '/src');
            self::rmdir($tmp);
            $res['message'] = 'could not move the new src/ into place; old version restored';

            return $res;
        }
        foreach (array('autoload.php', 'README.md') as $f) {
            if (is_file($src . '/' . $f)) {
                @copy($src . '/' . $f, $root . '/' . $f);
            }
        }
        if (is_dir($src . '/bin')) {
            @mkdir($root . '/bin', 0755, true);
            if (is_file($src . '/bin/zanbug')) {
                @copy($src . '/bin/zanbug', $root . '/bin/zanbug');
                @chmod($root . '/bin/zanbug', 0755);
            }
        }
        self::rmdir($tmp);
        self::say($log, 'old files kept in ' . $backup);
        $res['ok'] = true;
        $res['message'] = 'updated ' . Zanbug::VERSION . ' -> ' . $latest . ' in ' . $root . ' (backup: ' . basename($backup) . ')';
        // Adapters installed next to the core (libs/zanbug-laravel, ...) ship
        // the same version — keep them in step. Best effort: a failed adapter
        // swap leaves the old adapter working and never undoes the core update.
        $done = self::updateSiblingAdapters(dirname($root), $latest, $log);
        if ($done) {
            $res['message'] .= '; adapters: ' . implode(', ', $done);
        }

        return $res;
    }

    /**
     * @return string[] adapter dirs that were updated
     */
    private static function updateSiblingAdapters($libs, $latest, $log)
    {
        $out = array();
        $repos = array(
            'zanbug-laravel' => 'laravel',
            'zanbug-symfony' => 'symfony',
            'zanbug-codeigniter' => 'codeigniter',
            'zanbug-yii2' => 'yii2',
            // legacy: bugban → zanbug (2026-10-07) — adapters still sitting in the old libs/bugban-* dirs are updated too.
            'bugban-laravel' => 'laravel',
            'bugban-symfony' => 'symfony',
            'bugban-codeigniter' => 'codeigniter',
            'bugban-yii2' => 'yii2',
        );
        foreach ($repos as $dir => $repo) {
            $root = $libs . '/' . $dir;
            if (!is_dir($root . '/src') || !is_writable($root)) {
                continue;
            }
            try {
                $url = 'https://github.com/zanbug/' . $repo . '/archive/refs/tags/v' . $latest . '.zip';
                self::say($log, 'downloading ' . $url);
                $zip = self::download($url);
                if ($zip === null) {
                    continue;
                }
                $tmp = $zip . '.d';
                @mkdir($tmp, 0755, true);
                $za = new \ZipArchive();
                if ($za->open($zip) !== true || !$za->extractTo($tmp)) {
                    @unlink($zip);
                    self::rmdir($tmp);
                    continue;
                }
                $za->close();
                @unlink($zip);
                $src = self::findExtracted($tmp);
                if ($src === null || !is_dir($src . '/src')) {
                    self::rmdir($tmp);
                    continue;
                }
                $backup = $root . '/src.bak-' . Zanbug::VERSION;
                self::rmdir($backup);
                if (@rename($root . '/src', $backup)) {
                    if (@rename($src . '/src', $root . '/src')) {
                        foreach (array('config', 'composer.json', 'README.md') as $f) {
                            if (is_dir($src . '/' . $f)) {
                                self::rmdir($root . '/' . $f);
                                @rename($src . '/' . $f, $root . '/' . $f);
                            } elseif (is_file($src . '/' . $f)) {
                                @copy($src . '/' . $f, $root . '/' . $f);
                            }
                        }
                        $out[] = $dir;
                    } else {
                        @rename($backup, $root . '/src');
                    }
                }
                self::rmdir($tmp);
            } catch (\Exception $e) {
            } catch (\Throwable $e) {
            }
        }

        return $out;
    }

    private static function download($url)
    {
        $dir = @sys_get_temp_dir();
        $file = (is_string($dir) && $dir !== '' ? $dir : '/tmp') . '/zanbug-sdk-' . substr(md5($url . microtime(true)), 0, 10) . '.zip';
        $body = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => 'zanbug-php-sdk/' . Zanbug::VERSION,
            ));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            Compat::closeCurl($ch);
            if ($code !== 200) {
                $body = null;
            }
        } elseif (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(array('http' => array('timeout' => 120, 'follow_location' => 1, 'user_agent' => 'zanbug-php-sdk/' . Zanbug::VERSION)));
            $body = @file_get_contents($url, false, $ctx);
        }
        if (!is_string($body) || strlen($body) < 1000) {
            return null;
        }

        return @file_put_contents($file, $body) ? $file : null;
    }

    private static function findExtracted($tmp)
    {
        if (is_file($tmp . '/src/Zanbug.php')) {
            return $tmp;
        }
        foreach ((array) @scandir($tmp) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($tmp . '/' . $entry) && is_file($tmp . '/' . $entry . '/src/Zanbug.php')) {
                return $tmp . '/' . $entry;
            }
        }

        return null;
    }

    private static function rmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) @scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            if (is_dir($p) && !is_link($p)) {
                self::rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    // ---- process helpers ----------------------------------------------------------

    private static function run($cmd, $cwd, array $env, $timeout, &$output, $log)
    {
        $output = '';
        if (!function_exists('proc_open')) {
            $output = 'proc_open() is disabled';

            return null;
        }
        $spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $p = @proc_open($cmd, $spec, $pipes, $cwd, $env);
        if (!is_resource($p)) {
            $output = 'could not start the process';

            return null;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = time() + $timeout;
        $open = true;
        while ($open) {
            $chunk = (string) @fread($pipes[1], 8192) . (string) @fread($pipes[2], 8192);
            if ($chunk !== '') {
                $output .= $chunk;
                self::say($log, rtrim($chunk, "\n"));
            }
            $status = proc_get_status($p);
            if (!$status['running']) {
                $open = false;
                break;
            }
            if (time() > $deadline) {
                @proc_terminate($p);
                $output .= "\n(timed out after {$timeout}s)";
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);

                return null;
            }
            usleep(100000);
        }
        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($p);
        if (isset($status['exitcode']) && $status['exitcode'] !== -1) {
            $exit = $status['exitcode'];
        }

        return (int) $exit;
    }

    /** Environment for child processes: current env + Zanbug key/host (never on argv). */
    private static function childEnv($config)
    {
        $env = array();
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && is_string($v) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $k)) {
                $env[$k] = $v;
            }
        }
        foreach (array('PATH', 'HOME', 'LANG', 'TMPDIR', 'COMPOSER_HOME', 'COMPOSER_BIN', 'APPDATA', 'SystemRoot') as $k) {
            $v = getenv($k);
            if (is_string($v) && $v !== '' && !isset($env[$k])) {
                $env[$k] = $v;
            }
        }
        if (!isset($env['HOME']) && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && !empty($pw['dir'])) {
                $env['HOME'] = $pw['dir'];
            }
        }
        if ($config instanceof Config) {
            $env['ZANBUG_API_KEY'] = $config->apiKey;
            $env['ZANBUG_HOST'] = $config->host;
        }

        return $env;
    }

    private static function say($log, $line)
    {
        if (is_callable($log) && $line !== '') {
            try {
                call_user_func($log, $line);
            } catch (\Exception $e) {
            } catch (\Throwable $e) {
            }
        }
    }

    private static function markerPath(Config $config)
    {
        $dir = @sys_get_temp_dir();
        if (!is_string($dir) || $dir === '' || !@is_dir($dir) || !@is_writable($dir)) {
            return null;
        }
        // Per OS user: a root-owned marker must not block (or be un-touchable
        // for) the www-data web workers, and vice versa.
        $uid = function_exists('posix_geteuid') ? (string) @posix_geteuid() : self::whoami();

        return $dir . '/.zanbug-update-check-' . substr(md5($config->apiKey . '|' . dirname(dirname(__DIR__)) . '|' . $uid), 0, 16);
    }

    /** Effective OS user name (not the script owner get_current_user() reports). */
    private static function whoami()
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(@posix_geteuid());
            if (is_array($pw) && !empty($pw['name'])) {
                return $pw['name'];
            }
        }
        $u = getenv('USER');

        return is_string($u) && $u !== '' ? $u : 'this user';
    }

    /**
     * A php CLI binary for the detached `bin/zanbug update`. Under php-fpm
     * PHP_BINARY is empty or the fpm daemon itself — running that gave
     * "sh: 1: : Permission denied". Prefer the same PHP version.
     *
     * @return string|null
     */
    public static function cliPhp()
    {
        if (PHP_SAPI === 'cli' && is_string(PHP_BINARY) && PHP_BINARY !== '' && @is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }
        $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $dirs = array_unique(array_filter(array(defined('PHP_BINDIR') ? PHP_BINDIR : null, '/usr/bin', '/usr/local/bin', '/opt/php/bin')));
        foreach ($dirs as $d) {
            foreach (array('php' . $ver, 'php' . str_replace('.', '', $ver), 'php') as $name) {
                $f = rtrim($d, '/') . '/' . $name;
                if (@is_file($f) && @is_executable($f)) {
                    return $f;
                }
            }
        }
        if (is_string(PHP_BINARY) && PHP_BINARY !== '' && strpos(basename(PHP_BINARY), 'fpm') === false
            && strpos(basename(PHP_BINARY), 'cgi') === false && @is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }

        return null;
    }

    private static function cliPhpOr()
    {
        $p = self::cliPhp();

        return $p !== null ? $p : 'php';
    }

    private static function transport()
    {
        return function_exists('curl_init') ? new CurlTransport(8) : new StreamTransport(8);
    }
}
