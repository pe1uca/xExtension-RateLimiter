# Rate limit extension for FreshRSS  

This extension keeps track of how many times FreshRSS has requested information for per site.  
Based on this it prevents making too many requests to each site in a short period of time.  

# Requirements  

- SQLite3 module for PHP (If you're using the docker deploy this should be already sorted out for you)  
- FreshRSS 1.25.0 (for older versions check the branch `pre-1.25`)  
- User running FreshRSS should have write permissions to this extension's folder  
  `chown -R www-data:www-data /var/www/FreshRSS/extensions/xExtension-RateLimiter/`  

It is adviced to configure [automatic feed updating](https://freshrss.github.io/FreshRSS/en/admins/08_FeedUpdates.html) with a frequency of at most the configured rate limit window.  
For a docker deploy you'll need to use [CRON_MIN](https://github.com/FreshRSS/FreshRSS/blob/edge/Docker/README.md#cron-job-to-automatically-refresh-feeds) environment variable.  

# Configuration  

- Rate limit window: How many seconds since the last update for each site before the requests counter resets.  
- Max hits: How many requests FreshRSS can make to each site within the window.  

These settings are for all sites. Each site has its own count.  
If a sites returns headers or a response known to be related to rate limiting this extension will use it.  