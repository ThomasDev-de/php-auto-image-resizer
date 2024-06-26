# php-auto-image-resizer
A PHP file that scales and delivers images based on the screen width.

## Requirements
You must have at least PHP 8.2 with the PHP extension ext-imagick.
## Preparation
First, the HTACCESS file must be adapted or created in the document root.

Add the following lines:

```apacheconf
<IfModule mod_rewrite.c>
    # Turning on the apache rewrite engine
    RewriteEngine On
     # Specifies the base URL for per-directory rewrites
    RewriteBase /
    # First rule: If the requested path is "image-resizer.php", stop processing further rules.
    # ^image-resizer\.php$ - is a regular expression that exactly matches "image-resizer.php"
    # [L] is a flag that tells mod_rewrite to stop processing the rule set
    RewriteRule ^image-resizer\.php$ - [L]

    # Second rule: If the requested URI is any(.) file that ends (anchors the end position $)
    # with jpeg, jpg, gif, png, or webp, rewrite the request to "/image-resizer.php"
    # \.(?:jpeg|jpg|gif|png|webp)$ - is a regular expression that matches jpeg, jpg, gif, png, or webp files.
    # /image-resizer.php is the target where the matched request will be rewritten to
    # [QSA,L] are flags. QSA, or 'QueryString Append', forces the rewrite engine to append
    # a query string part in the substitution string to the existing one. L, or 'last',
    # stops mod_rewrite from processing any more rules.
    RewriteRule \.(?:jpeg|jpg|gif|png|webp)$ /image-resizer.php [QSA,L]
</IfModule>
```
The lines cause all images with the extension jpeg, jpg, gif, png or webp to be sent to a PHP file
called image-resizer.php.
The name of the PHP file can be changed, but must then also be in
the htaccess can be adjusted.

Make sure your web server has the rewrite module active. I describe it using Apache2.

For Apache2, it might look like this:

```shell
sudo a2enmod rewrite
```
If not already done, set AllowOverride to All: Apache does not allow `.htaccess` overrides by default.
Edit your apache2.conf file (usually found in /etc/apache2/apache2.conf) and change AllowOverride None to
AllowOverride All for the corresponding directory(s).

```apacheconf
<Directory /var/www/>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

Restart the web server:
```shell
sudo service apache2 restart
```

## Installation

### via composer

```shell
composer install webcito/php-auto-image-resizer dev-main
composer dump-autoload
```

### manually
Load the PHP class `/src/ImageCache.php` into your project.

## Use
Create a PHP file called image-resizer.php in the DocumentRoot

The contents of the file should look something like this:

```php
<?php
use Webcito\ImageCache;

require_once "vendor/autoload.php"; // via composer
// require_once "path/to/ImageCache.php"; // or manual

$options = [
    "cache" => "/cache/", // the folder from documentRoot
    "resolution" => null, // the max screen width
    "breakpoints" => [1200, 992, 768, 480, 320], // the breakpoints
    "compressionQuality" => 85 // quality
];

new ImageCache($options);
```
Make sure there are enough rights on the documentRoot
to create the cache folder (e.g., 0755).

This should lay the foundation.

## Passing the maximum screen width or height
Since there is no direct way to measure screen width in PHP,
I use Javascript.

On each of my public pages I set a cookie in the head and pass it on
into the options.

```html
<script>
    document.cookie = "resolution=" + Math.max(screen.width, screen.height)+"; path=/; SameSite=None";
</script>
```
```php
$options = [
    'resolution' => $_COOKIE['resolution'] ?? zero
];
new ImageCache($options);
```

That's it!

