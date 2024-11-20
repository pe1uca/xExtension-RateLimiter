<?php

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

            // Only get `count` if we're still within the window. Otherwise we can stay at 0.  
            if (time() - $lastUpdate <= $this->rateLimitWindow) {
                $count = $data['count'];
            }
        }

        // If there have been more than the configured count of recent requests we stop processing feeds
        if ($count >= $this->maxRateLimitCount) {
            return null;
        }

        $lastUpdate = time();
        $count += 1;

        $this->updateDomainData($host, $lastUpdate, $count);

        return $feed;
    }

    private function getDomainData(string $domain) {
        try {
            $stmt = $this->db->prepare("SELECT `lastUpdate`, `count` FROM `sites` WHERE `domain`=:domain");
            $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
            $result = $stmt->execute();

            return $result->fetchArray();
        } finally {
            $result->finalize();
            $stmt->close();
        }
    }

    private function updateDomainData(string $domain, int $lastUpdate, int $count) {
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
}

function extensionLog(string $data) {
    syslog(LOG_INFO, "pe1uca: " . $data);
}