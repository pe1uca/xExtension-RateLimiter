# Rate limit extension for FreshRSS  

This extension keeps track of how many times FreshRSS has requested information for per site.  
Based on this it prevents making too many requests to each site in a short period of time.  

# Requirements  

- SQLite3 module for PHP (If you're using the docker deploy this should be already sorted out for you)  
- FreshRSS 1.25.0

# Configuration  

- Rate limit window: How many seconds since the last update for each site before the requests counter resets.  
- Max hits: How many requests FreshRSS can make to each site within the window.  

These settings are for all sites. Each site has its own count.  
If a sites returns headers or a response known to be related to rate limiting this extension will use it.  