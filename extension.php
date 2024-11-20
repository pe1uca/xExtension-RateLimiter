<?php

const DEBUG = false;

final class RateLimiterExtension extends Minz_Extension {

    private const DB_PATH = __DIR__ . '/ratelimit.sqlite';

    private const DEFAULT_WINDOW = 300;

    private const DEFAULT_RATE_LIMIT = 50;

    private $db;

    public $rateLimitWindow;

    public $maxRateLimitCount;

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
        $this->loadConfig();
    }

    public function install() {
        if (!class_exists('SQLite3')) {
            return 'SQLite3 extension not found';
        }

        $this->db = new SQLite3(self::DB_PATH);
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS `sites` 
            (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                `domain` TEXT UNIQUE, 
                `lastUpdate` BIGINT DEFAULT 0,
                `count` INTEGER DEFAULT 0,
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
        $count = 0;
        $lastUpdate = -1;
        if ($data) {
            $lastUpdate = $data['lastUpdate'];
            $rateLimited = $data['rateLimited'];
            $retryAfter = $data['retryAfter'];
            $resetCount = true;

            // Only get `count` if we're still within the window. Otherwise we can stay at 0.  
            if (time() - $lastUpdate <= $this->rateLimitWindow) {
                extensionLog("We need to use count");
                $count = $data['count'];
                $resetCount = false;
            }

            // Check if the site has been rate limited by headers and the time hasn't yet expired.  
            if ($rateLimited && $retryAfter > time()) {
                extensionLog("Rate limited by domain and retry after is still in the future");
                return null;
            }
            $this->resetDomainRateLimit($host, $resetCount);
        }

        // If there have been more than the configured count of recent requests we stop processing feeds
        if ($count >= $this->maxRateLimitCount) {
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
        // Check if there has been a request to the site
        if ($simplePie->status_code == 0) {
            extensionLog("Cache has been used");
            return;
        }

        $host = parse_url($feed->url(), PHP_URL_HOST);
        extensionLog("Site '$host' has been hit");
        $this->bumpDomainCount($host);

        [$rateLimited, $retryAfter] = $this->analizeRequest($simplePie);
        if ($rateLimited) {
            extensionLog("The site '$host' rate limited us until $retryAfter");
            $this->updateDomainRateLimit($host, $rateLimited, $retryAfter);
        }
    }

    private function getDomainData(string $domain) {
        try {
            $stmt = $this->db->prepare("SELECT `lastUpdate`, `count`, `rateLimited`, `retryAfter` FROM `sites` WHERE `domain`=:domain");
            $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
            $result = $stmt->execute();

            return $result->fetchArray();
        } finally {
            $result->finalize();
            $stmt->close();
        }
    }

    private function bumpDomainCount(string $domain) {
        $stmt = $this->db->prepare(
            'INSERT INTO `sites`(`domain`, `lastUpdate`, `count`)
                    VALUES(:domain, :lastUpdate, 1) ON CONFLICT(`domain`) 
                    DO UPDATE SET `lastUpdate`=:lastUpdate, `count`=`count`+1'
        );
        $stmt->bindValue(':lastUpdate', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();
    }

    private function resetDomainRateLimit(string $domain, bool $resetCount = false) {
        $this->updateDomainRateLimit($domain, false, 0);
        if ($resetCount) $this->updateDomainData($domain, time(), 0);
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
        int $count
    ) {
        $stmt = $this->db->prepare(
            'INSERT INTO `sites`(`domain`, `lastUpdate`, `count`)
                    VALUES(:domain, :lastUpdate, :count) ON CONFLICT(`domain`) 
                    DO UPDATE SET `lastUpdate`=:lastUpdate, `count`=:count'
        );
        $stmt->bindValue(':lastUpdate', $lastUpdate, SQLITE3_INTEGER);
        $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
        $stmt->bindValue(':count', $count, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();
    }

    function analizeRequest(\SimplePie\SimplePie $simplePie) {
        $headers = $simplePie->data['headers'] ?? [];
        $statusCode = $simplePie->status_code;
        $rateLimited = false;
        $retryAfter = 0;

        if (isset($headers['x-ratelimit-remaining'])) {
            $rateLimited = ((int)$headers['x-ratelimit-remaining']) <= 0;
        }
        if (isset($headers['x-ratelimit-reset'])) {
            $retryAfter = time() + ((int)$headers['x-ratelimit-reset']);
        }
        if (isset($headers['Retry-After'])) {
            $retryAfter = time() + ((int)$headers['Retry-After']);
        }

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
            $retryAfter
        ];
    }
}

function extensionLog(string $data) {
    if (!DEBUG) return;
    syslog(LOG_INFO, "pe1uca: " . $data);
}