<?php

const DEBUG = false;

final class RateLimiterExtension extends Minz_Extension {

    private const DB_PATH = __DIR__ . '/ratelimit.sqlite';

    private const DEFAULT_WINDOW = 300;

    private const DEFAULT_RATE_LIMIT = 50;

    private $db;

    public $rateLimitWindow;

    public $maxRateLimitCount = self::DEFAULT_RATE_LIMIT;

    public function init() {
        parent::init();

        $this->registerHook('feed_before_actualize', [
            $this,
            'feedUpdate',
        ]);
        $this->registerHook('simplepie_after_init', [
            $this,
            'afterDataFetch',
        ]);

        $this->db = new SQLite3(self::DB_PATH);
        $this->db->busyTimeout(1000);
        $this->loadConfig();
    }

    public function install() {
        if (!class_exists('SQLite3')) {
            return 'SQLite3 extension not found';
        }

        try {
            $this->db = new SQLite3(self::DB_PATH);
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS `sites` 
                (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    `domain` TEXT UNIQUE, 
                    `lastUpdate` BIGINT DEFAULT 0,
                    `count` INTEGER DEFAULT 0,
                    `remaining` INTEGER DEFAULT 0,
                    `countStartTime` BIGINT DEFAULT -1,
                    `rateLimited` INTEGER DEFAULT FALSE,
                    `retryAfter` INTEGER
                )'
            );
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS `config` 
                (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    `name` TEXT UNIQUE, 
                    `value` TEXT
                )'
            );
            $this->resetDomainRateLimit('localhost', true);
        } catch (\Throwable $th) {
            return $th->getMessage();
        }

        return true;
    }

    private function loadConfig() {
        $this->rateLimitWindow = $this->db->querySingle('SELECT `value` FROM `config` WHERE `name`="window"');
        if (!$this->rateLimitWindow) {
            $this->rateLimitWindow = self::DEFAULT_WINDOW;
        }
        $this->rateLimitWindow = (int)$this->rateLimitWindow;
        $this->maxRateLimitCount = $this->db->querySingle('SELECT `value` FROM `config` WHERE `name`="limit"');
        if (!$this->maxRateLimitCount) {
            $this->maxRateLimitCount = self::DEFAULT_RATE_LIMIT;
        }
        $this->maxRateLimitCount = (int)$this->maxRateLimitCount;
    }

    public function handleConfigureAction() {
        if (!Minz_Request::isPost()) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO `config`(`name`, `value`)
                    VALUES(:setting, :value) ON CONFLICT(`name`) 
                    DO UPDATE SET `value`=:value'
        );

        $setting = '';
        $value = null;
        $stmt->bindParam(':setting', $setting, SQLITE3_TEXT);
        $stmt->bindParam(':value', $value, SQLITE3_TEXT);
        
        $setting = 'window';
        $value = Minz_Request::paramInt('rate_limit_window');
        $stmt->execute();

        $setting = 'limit';
        $value = Minz_Request::paramInt('rate_limit_count');
        $stmt->execute();
        $stmt->close();
    }

    public function feedUpdate(FreshRSS_Feed $feed) {
        $host = parse_url($feed->url(), PHP_URL_HOST);
        $data = $this->getDomainData($host);

        if (!$data) {
            return $feed;
        }

        $countStartTime = $data['countStartTime'];
        $rateLimited = $data['rateLimited'];
        $retryAfter = $data['retryAfter'];
        $remaining = $data['remaining'];
        $resetCount = false;

        // Check if `remaining` started to count before the window (-1 means unset)
        if ($countStartTime > 0 && time() - $countStartTime >= $this->rateLimitWindow) {
            extensionLog("$host's remaining can be reset");
            $resetCount = true;
        }

        // Check if the site has been rate limited by headers and the time hasn't yet expired.  
        if ($rateLimited && $retryAfter > time()) {
            extensionLog("Rate limited by $host and retry after is still in the future");
            return null;
        }
        $this->resetDomainRateLimit($host, $resetCount);

        // If there have been more than the configured count of recent requests we stop processing feeds
        if ($remaining <= 0) {
            extensionLog("Custom rate limit reached");
            return null;
        }

        return $feed;
    }

    public function afterDataFetch(
        \SimplePie\SimplePie $simplePie, 
        FreshRSS_Feed $feed, 
        bool $simplePieResult
    ) {
        $host = parse_url($feed->url(), PHP_URL_HOST);
        // Check if there has been a request to the site
        // Simplepie returns code=0 and the cached data.
        if ($simplePie->status_code == 0 && $simplePie->data) {
            extensionLog("Cache has been used");
            return;
        }
        // Simplepie has a bug, it's unable to report http error codes (https://github.com/FreshRSS/FreshRSS/issues/7038)
        // We assume code=0 and no data means the site was hit but an HTTP error was returned.
        if ($simplePie->status_code == 0 && !$simplePie->data) {
            extensionLog("HTTP error");
            // Assume we can't make any more requests
            $this->updateDomainCount($host, 0);
            return;
        }

        extensionLog("Site '$host' has been hit");

        [$rateLimited, $retryAfter, $remaining] = $this->analizeRequest($simplePie);
        if ($rateLimited) {
            extensionLog("The site '$host' rate limited us until $retryAfter");
            $this->updateDomainRateLimit($host, $rateLimited, $retryAfter);
        }
        $this->updateDomainCount($host, $remaining);
    }

    private function getDomainData(string $domain) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM `sites` WHERE `domain`=:domain");
            if ($stmt === false) return false;
            $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
            $result = $stmt->execute();

            return $result ? $result->fetchArray() : false;
        } finally {
            if ($result)
                $result->finalize();
            if ($stmt)
                $stmt->close();
        }
    }

    private function updateDomainCount(string $domain, int $remaining = null) {
        $prev = $this->getDomainData($domain);
        $newRemaining = $this->maxRateLimitCount - 1;
        $countStartTime = time();
        if ($prev) {
            $dbRemaining = $prev['remaining'] - 1;
            $dbStartTime = $prev['countStartTime'];
            // If the start time is within the window we take DB data.
            if ($dbStartTime > 0 && time() - $dbStartTime <= $this->rateLimitWindow) {
                $newRemaining = $dbRemaining;
                $countStartTime = $dbStartTime;
            }
        }

        // If $remaining was provided it's preferred
        if ($remaining !== null) {
            $newRemaining = $remaining;
        }
        extensionLog("New remaining: $newRemaining");
        extensionLog("Start time: $countStartTime");

        $stmt = $this->db->prepare(
            'INSERT INTO `sites`(`domain`, `lastUpdate`, `count`, `remaining`, `countStartTime`)
                    VALUES(:domain, :lastUpdate, 1, :remaining, :countStartTime) ON CONFLICT(`domain`) 
                    DO UPDATE SET `lastUpdate`=:lastUpdate, `count`=`count`+1, `remaining`=:remaining, `countStartTime`=:countStartTime'
        );
        $stmt->bindValue(':lastUpdate', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
        $stmt->bindValue(':remaining', $newRemaining, SQLITE3_INTEGER);
        $stmt->bindValue(':countStartTime', $countStartTime, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();
    }

    private function resetDomainRateLimit(string $domain, bool $resetCount = false) {
        $this->updateDomainRateLimit($domain, false, 0);
        if ($resetCount) {
            extensionLog("Reset count for $domain");
            $this->updateDomainData($domain, time(), 0, $this->maxRateLimitCount);
        }
    }

    private function updateDomainRateLimit(
        string $domain,
        bool $rateLimited,
        int $retryAfter
    ) {
        $stmt = $this->db->prepare(
            'INSERT INTO `sites`(`domain`, `rateLimited`, `retryAfter`)
                    VALUES(:domain, :rateLimited, :retryAfter) ON CONFLICT(`domain`) 
                    DO UPDATE SET `rateLimited`=:rateLimited, `retryAfter`=:retryAfter'
        );
        $stmt->bindValue(':rateLimited', $rateLimited, SQLITE3_INTEGER);
        $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
        $stmt->bindValue(':retryAfter', $retryAfter, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();
    }

    private function updateDomainData(
        string $domain,
        int $lastUpdate,
        int $count,
        int $remaining
    ) {
        $stmt = $this->db->prepare(
            'INSERT INTO `sites`(`domain`, `lastUpdate`, `count`, `remaining`, `countStartTime`)
                    VALUES(:domain, :lastUpdate, :count, :remaining, :countStartTime) ON CONFLICT(`domain`) 
                    DO UPDATE SET `lastUpdate`=:lastUpdate, `count`=:count, `remaining`=:remaining, `countStartTime`=:countStartTime'
        );
        $stmt->bindValue(':lastUpdate', $lastUpdate, SQLITE3_INTEGER);
        $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
        $stmt->bindValue(':count', $count, SQLITE3_INTEGER);
        $stmt->bindValue(':remaining', $remaining, SQLITE3_INTEGER);
        $stmt->bindValue(':countStartTime', -1, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();
    }

    function analizeRequest(\SimplePie\SimplePie $simplePie) {
        $headers = $simplePie->data['headers'] ?? [];
        $statusCode = $simplePie->status_code;
        $rateLimited = false;
        $retryAfter = 0;
        $remaining = null;

        if (isset($headers['x-ratelimit-remaining'])) {
            $remaining = (int)$headers['x-ratelimit-remaining'];
            extensionLog("Header: $remaining");
            $rateLimited = $remaining <= 0;
        }
        if (isset($headers['x-ratelimit-reset'])) {
            $retryAfter = time() + ((int)$headers['x-ratelimit-reset']);
        }
        if (isset($headers['Retry-After'])) {
            $retryAfter = time() + ((int)$headers['Retry-After']);
        }

        extensionLog("Code: $statusCode");
        if ($statusCode == 429) {
            $rateLimited = true;
        }

        // Check if the site has rate limited us but we don't know when to retry.  
        if ($rateLimited && !$retryAfter) {
            // Default to use the rate limit window the user set
            $retryAfter = time() + $this->rateLimitWindow;
        }

        return [
            $rateLimited,
            $retryAfter,
            $remaining
        ];
    }
}

function extensionLog(string $data) {
    if (!DEBUG) return;
    syslog(LOG_INFO, "pe1uca: " . $data);
}