# Overrides the drupal11 recipe vhost. The recipe ships Drupal's stock rule
# (location ~ '\.php$|^/update.php'), which hands every .php path to php-fpm and
# leaves /autoload.php reachable. The allow-list below is the project's security
# decision and has to survive the move to Lando.

server {
  listen 80 default_server;
  listen 443 ssl;
  server_name localhost;

  ssl_certificate           /certs/cert.crt;
  ssl_certificate_key       /certs/cert.key;
  ssl_verify_client         off;
  ssl_session_cache         shared:SSL:1m;
  ssl_session_timeout       5m;
  ssl_ciphers               HIGH:!aNULL:!MD5;
  ssl_prefer_server_ciphers on;

  port_in_redirect off;
  client_max_body_size 64M;

  root "{{LANDO_WEBROOT}}";
  index index.php;

  gzip on;
  gzip_vary on;
  gzip_proxied any;
  gzip_comp_level 5;
  gzip_min_length 256;
  gzip_types
      application/atom+xml
      application/javascript
      application/json
      application/ld+json
      application/manifest+json
      application/rss+xml
      application/vnd.ms-fontobject
      application/wasm
      application/xhtml+xml
      application/xml
      font/otf
      font/ttf
      image/svg+xml
      image/vnd.microsoft.icon
      text/cache-manifest
      text/css
      text/javascript
      text/plain
      text/xml;

  location = /favicon.ico {
      log_not_found off;
      access_log off;
  }

  location = /robots.txt {
      allow all;
      log_not_found off;
      access_log off;
  }

  # Prefix match with ^~, so the dotfile regex below never sees these URIs.
  location ^~ /.well-known/ {
      allow all;
  }

  location ~ /\. {
      return 404;
  }

  location ~ ^/vendor/ {
      return 404;
  }

  location ~ ^/sites/[^/]+/private/ {
      return 404;
  }

  location ~ ^/sites/[^/]+/files/.+\.php$ {
      return 404;
  }

  location ~* \.(engine|inc|install|make|module|profile|po|sh|sql|theme|twig|tpl|xtmpl|yml|bak|backup|config|dist|fla|ini|log|orig|psd|save|sw[op])$|~$ {
      return 404;
  }

  location ~ ^/sites/.*/files/styles/ {
      try_files $uri @rewrite;
  }

  location ~ ^/sites/.*/files/(css|js)/ {
      expires max;
      add_header Cache-Control "public, immutable";
      try_files $uri @rewrite;
  }

  # Private files can arrive with a language prefix.
  location ~ ^(/[a-z\-]+)?/system/files/ {
      try_files $uri /index.php?$query_string;
  }

  # Front-controller allow-list: everything Drupal's .htaccess would otherwise have to
  # protect (/autoload.php, /core/scripts/*.php, stray vendor entry points) is unreachable
  # because no other .php path is ever handed to php-fpm.
  location ~ ^/(index|update|core/authorize|core/install|core/rebuild)\.php(/|$) {
      include fastcgi_params;

      fastcgi_split_path_info ^(.+?\.php)(|/.*)$;
      fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      fastcgi_param PATH_INFO $fastcgi_path_info;
      fastcgi_param QUERY_STRING $query_string;
      # Block httpoxy attacks. See https://httpoxy.org/.
      fastcgi_param HTTP_PROXY "";
      fastcgi_intercept_errors on;

      # Step debugging holds a request open for as long as the developer stays paused.
      fastcgi_read_timeout 600s;

      fastcgi_buffer_size 128k;
      fastcgi_buffers 16 128k;
      fastcgi_busy_buffers_size 256k;
      fastcgi_temp_file_write_size 256k;

      fastcgi_pass fpm:9000;
  }

  location ~ \.php$ {
      return 404;
  }

  location ~* \.(css|js|mjs|gif|jpe?g|png|webp|avif|svg|svgz|ico|woff|woff2|ttf|otf|eot|mp3|mp4|m4a|ogg|ogv|webm|wav|pdf|zip)$ {
      expires 30d;
      add_header Cache-Control "public";
      try_files $uri @rewrite;
  }

  location / {
      try_files $uri /index.php?$query_string;
  }

  location @rewrite {
      rewrite ^ /index.php;
  }
}
