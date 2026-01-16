# === BLOKADA BOTÓW ===
<IfModule mod_rewrite.c>
RewriteEngine On
# Blokuj Facebook botów
RewriteCond %{HTTP_USER_AGENT} (facebookexternalhit|meta-externalagent|Facebot) [NC]
RewriteRule .* - [F,L]

# Blokuj SEO botów (MJ12bot, SemrushBot, AhrefsBot, DotBot)
RewriteCond %{HTTP_USER_AGENT} (MJ12bot|SemrushBot|AhrefsBot|DotBot) [NC]
RewriteRule .* - [F,L]
</IfModule>
# === /BLOKADA BOTÓW ===

# BEGIN Really Simple Security Redirect

<IfModule mod_rewrite.c>
RewriteEngine on
RewriteCond %{HTTP:CF-Visitor} '"scheme":"http"'
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
</IfModule>

# END Really Simple Security Redirect
# --- HARD ALLOWLIST (przebija wszystkie późniejsze Deny) ---
# Oznaczamy ruch z whitelisty zmienną środowiskową WL=1
SetEnvIf Remote_Addr "^3\.74\.135\.112$|^3\.74\.1\.39$|^51\.77\.52\.107$|^3\.68\.36\.131$|^3\.68\.91\.166$|^18\.157\.125\.26$|^3\.126\.202\.245$|^3\.74\.7\.74$|^3\.126\.190\.98$|^3\.66\.117\.228$|^3\.66\.179\.55$|^13\.49\.121\.244$|^13\.49\.185\.62$|^13\.51\.143\.164$|^18\.194\.204\.155$|^3\.125\.51\.17$" WL=1

# Kolejność 2.2-kompatybilna: najpierw Deny, potem Allow; Allow z env=WL NADPISZE każdy Deny
Order Deny,Allow
Allow from env=WL
# --- /HARD ALLOWLIST ---

# === ALLOWLIST (ma pierwszeństwo) ===
<RequireAny>
  # IP, których nie wolno blokować (BaseLinker)
  Require ip 3.74.135.112
  Require ip 3.74.1.39
  Require ip 51.77.52.107
  Require ip 3.68.36.131
  Require ip 3.68.91.166
  Require ip 18.157.125.26
  Require ip 3.126.202.245
  Require ip 3.74.7.74
  Require ip 3.126.190.98
  Require ip 3.66.117.228
  Require ip 3.66.179.55
  Require ip 13.49.121.244
  Require ip 13.49.185.62
  Require ip 13.51.143.164
  Require ip 18.194.204.155
  Require ip 3.125.51.17

# === /ALLOWLIST ===

# BEGIN WP Rocket

Deny from 66.249.79.173
Deny from 66.249.79.160
Deny from 66.249.79.161
Deny from 66.249.79.162
Deny from 66.249.79.163
Deny from 66.249.79.164
Deny from 20.171.207.0/24
Deny from 57.141.0.0/24
Deny from 57.141.4.0/24
Deny from 20.171.207.108
Deny from 79.137.165.179

Deny from 216.73.216.0/24
Deny from 20.171.207.0/24

Deny from 195.201.199.0/24
Deny from 185.191.171.0/24
Deny from 192.99.36.0/24
Deny from 144.76.19.0/24

# END WP Rocket

# Boty niech nie dotykają /wp-token
RewriteEngine On
RewriteCond %{REQUEST_URI} ^/ecommerce/user/wp-token$ [NC]
RewriteCond %{HTTP_USER_AGENT} (bot|crawler|spider|lscache_runner|BLEXBot|facebookexternalhit|meta-externalagent) [NC]
RewriteRule ^ - [F,L]

#<IfModule Litespeed>
#CacheRoot /home/klient.dhosting.pl/pomocgecomm/sklep.plantis.app-xo7n/lscache/
#</IfModule>

# BEGIN LSCACHE
## LITESPEED WP CACHE PLUGIN - Do not edit the contents of this block! ##
<IfModule LiteSpeed>
RewriteEngine on
CacheLookup on
RewriteRule .* - [E=Cache-Control:no-autoflush]
RewriteRule litespeed/debug/.*\.log$ - [F,L]
RewriteRule \.litespeed_conf\.dat - [F,L]

### marker ASYNC start ###
RewriteCond %{REQUEST_URI} /wp-admin/admin-ajax\.php
RewriteCond %{QUERY_STRING} action=async_litespeed
RewriteRule .* - [E=noabort:1]
### marker ASYNC end ###

### marker MOBILE start ###
RewriteCond %{HTTP_USER_AGENT} Mobile|Android|Silk/|Kindle|BlackBerry|Opera\ Mini|Opera\ Mobi [NC]
RewriteRule .* - [E=Cache-Control:vary=%{ENV:LSCACHE_VARY_VALUE}+ismobile]
### marker MOBILE end ###

### marker WEBP start ###
RewriteCond %{HTTP_ACCEPT} image/webp [OR]
RewriteCond %{HTTP_USER_AGENT} iPhone\ OS\ (1[4-9]|[2-9][0-9]) [OR]
RewriteCond %{HTTP_USER_AGENT} Firefox/([6-9][0-9]|[1-9][0-9]{2,})
RewriteRule .* - [E=Cache-Control:vary=%{ENV:LSCACHE_VARY_VALUE}+webp]
### marker WEBP end ###

### marker DROPQS start ###
CacheKeyModify -qs:fbclid
CacheKeyModify -qs:gclid
CacheKeyModify -qs:utm*
CacheKeyModify -qs:_ga
### marker DROPQS end ###

</IfModule>
## LITESPEED WP CACHE PLUGIN - Do not edit the contents of this block! ##
# END LSCACHE
# BEGIN NON_LSCACHE
## LITESPEED WP CACHE PLUGIN - Do not edit the contents of this block! ##
### marker BROWSER CACHE start ###
<IfModule mod_expires.c>
ExpiresActive on
ExpiresByType application/pdf A31557600
ExpiresByType image/x-icon A31557600
ExpiresByType image/vnd.microsoft.icon A31557600
ExpiresByType image/svg+xml A31557600

ExpiresByType image/jpg A31557600
ExpiresByType image/jpeg A31557600
ExpiresByType image/png A31557600
ExpiresByType image/gif A31557600
ExpiresByType image/webp A31557600
ExpiresByType image/avif A31557600

ExpiresByType video/ogg A31557600
ExpiresByType audio/ogg A31557600
ExpiresByType video/mp4 A31557600
ExpiresByType video/webm A31557600

ExpiresByType text/css A31557600
ExpiresByType text/javascript A31557600
ExpiresByType application/javascript A31557600
ExpiresByType application/x-javascript A31557600

ExpiresByType application/x-font-ttf A31557600
ExpiresByType application/x-font-woff A31557600
ExpiresByType application/font-woff A31557600
ExpiresByType application/font-woff2 A31557600
ExpiresByType application/vnd.ms-fontobject A31557600
ExpiresByType font/ttf A31557600
ExpiresByType font/otf A31557600
ExpiresByType font/woff A31557600
ExpiresByType font/woff2 A31557600

</IfModule>
### marker BROWSER CACHE end ###

## LITESPEED WP CACHE PLUGIN - Do not edit the contents of this block! ##
# END NON_LSCACHE

# BEGIN WordPress
# Dyrektywy zawarte między „BEGIN WordPress” oraz „END WordPress” są generowane dynamicznie i powinny być modyfikowane tylko za pomocą
# filtrów WordPressa. Zmiany dokonane bezpośrednio tutaj będą nadpisywane.
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>

# END WordPress

# BEGIN LiteSpeed
# Dyrektywy zawarte między „BEGIN LiteSpeed” oraz „END LiteSpeed” są generowane dynamicznie i powinny być modyfikowane tylko za pomocą
# filtrów WordPressa. Zmiany dokonane bezpośrednio tutaj będą nadpisywane.
<IfModule Litespeed>
	SetEnv noabort 1
</IfModule>
# END LiteSpeed
# BEGIN Really Simple Security No Index
# Dyrektywy zawarte między „BEGIN Really Simple Security No Index” oraz „END Really Simple Security No Index” są generowane dynamicznie i powinny być modyfikowane tylko za pomocą
# filtrów WordPressa. Zmiany dokonane bezpośrednio tutaj będą nadpisywane.
Options -Indexes
# END Really Simple Security No Index