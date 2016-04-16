PPM - PHP Process Manager
====================================================

<p align="center">
<img src="https://avatars3.githubusercontent.com/u/11821812?v=3&s=200" />
</p>

PHP-PM is a process manager, supercharger and load balancer for PHP applications.

[![Build Status](https://travis-ci.org/php-pm/php-pm.svg?branch=master)](https://travis-ci.org/php-pm/php-pm)
[![Gitter](https://badges.gitter.im/php-pm/php-pm.svg)](https://gitter.im/php-pm/php-pm?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge)

It's based on ReactPHP and works best with applications that use request-response frameworks like Symfony's HTTPKernel.
The approach of this is to kill the expensive bootstrap of PHP (declaring symbols, loading/parsing files) and the bootstrap of feature-rich frameworks. See Performance section for a quick hint.
PHP-PM basically spawns several PHP instances as worker bootstraping your application (eg. the whole Symfony Kernel) and hold it in the memory to be prepared for every
incoming request: This is why PHP-PM makes your application so fast.

More information can be found in the article: [Bring High Performance Into Your PHP App (with ReactPHP)](http://marcjschmidt.de/blog/2014/02/08/php-high-performance.html)

### Features

* Performance boost up to 15x (compared to PHP-FPM, Symfony applications).
* Integrated load balancer.
* Hot-Code reload (when PHP files change).
* Static file serving for easy development procedures.
* Support for HttpKernel (Symfony/Laravel), Drupal (experimental), Zend (experimental).

### Why using PPM as development server instead of vagrant, nginx or apache?

* No hassle with file permissions (www-data vs local user ids).
* No painful slow virtual-box file sync.
* Faster response times of your PHP app.
* No fighting with vagrant / virtual machine settings. 
* Checkout a new project, run `ppm start` - done. (if configured with `ppm config`)
* No hassle with domain names (/etc/hosts), just use different ports for your app without root access.


### Badge all the things

Does your app/library support PPM? Show it!

[![PPM Compatible](https://raw.githubusercontent.com/php-pm/ppm-badge/master/ppm-badge.png)](https://github.com/php-pm/php-pm)

```
[![PPM Compatible](https://raw.githubusercontent.com/php-pm/ppm-badge/master/ppm-badge.png)](https://github.com/php-pm/php-pm)
```


### Installation

To get PHP-PM you need beside the php binary also php-cgi, which comes often with php. If not availabe try to install it:

**Debian/Ubuntu** (https://www.digitalocean.com/community/tutorials/how-to-upgrade-to-php-7-on-ubuntu-14-04)

`apt-get install php7.0-cgi`

By default cgi bin is in  `/usr/lib/cgi-bin/php`, so you need to run:

`sudo ln -s /usr/lib/cgi-bin/php /usr/bin/php7.0-cgi`

**Red Hat/Centos (RHEL-7, 6)** (https://webtatic.com/packages/php70/)

install webtatic first

`yum install php70w`

**Mac OS X - Homebrew** (https://github.com/Homebrew/homebrew-php)

`brew install php70`

**Mac OS X - Macports**

`port install php70-cgi`

By default, PPM looks for a binary named `php-cgi`. If your PHP installation uses
a different binary name, you can specify the full path to that binary with the `php-cgi`
configuration option (for example: `ppm config --php-cgi=/opt/local/bin/php-cgi70`).

On Ubuntu for example per default `pcntl_*` functions are disabled.
If you get `Warning: pcntl_signal() has been disabled for security reasons`, you should activate these functions:

Open `/etc/php5/cgi/php.ini`, find line `disable_functions = pcntl_alarm,pcntl_fork, ...` and place a `;` in front of it:

```
; This directive allows you to disable certain functions for security reasons.
; It receives a comma-delimited list of function names.
; http://php.net/disable-functions
;disable_functions = pcntl_alarm,pcntl_fork, ...
```


#### Global

```bash
$ git clone git@github.com:php-pm/php-pm.git
$ cd php-pm
$ composer install
$ ln -s `pwd`/bin/ppm /usr/local/bin/ppm
$ ppm --help
```

#### Per project

```bash
# change minimum-stability to dev in your composer.json (until we have a version tagged): "minimum-stability": "dev"
composer require php-pm/php-pm:dev-master
composer require php-pm/httpkernel-adapter:dev-master #if you have httpkernel (laravel, symfony)
./vendor/bin/ppm config --bootstrap=symfony #places a ppm.json in your directory
./vendor/bin/ppm start #reads ppm.json and starts the server like you want
```

Once configured (composer and ppm.json) you can start your app on your development machine or server instantly:

```bash
composer install
./vendor/bin/ppm start
```

When `debug` is enabled, PHP-PM detects file changes and restarts its worker automatically.

#### Performance

To get the maximum performance you should usually use `--app-env=prod` with disabled
debug `--debug=0`. Also make sure xdebug is disabled. Try with different amount of workers.
Usually a 10% over your cpu core count is good. Example: If you have 8 real cores (excl. hyper-threading) use `--workers=9`.

If your applications supports it, try enabled concurrent requests per worker: `--concurrent-requests=1`.

To get even more performance (for static file serving or for rather fast applications) try a different event loop (see https://github.com/reactphp/event-loop).

#### Debugging

If you get strange issues in your application and you have no idea where they are coming from try
using only one worker `--workers=1` and enable `-v` or `-vv`. 

When debugging you should use xdebug as you're used to. If you set a break point and hold the application, then only one
worker is stopped until you release the break point. All other workers are fully functional. 

**Note for XDebug and PHPStorm**: Since php-pm uses at least two processes, there are two xdebug instances as well. PHPStorm is per default configured to only accept one connection at a time. You need to increase that. You won't get xdebug working with your application if you don't increase that count.

![Xdebug and PHPStorm](https://dl.dropboxusercontent.com/u/54069263/ppm-github/xdebug-phpstorm.png)

In all workers the STDOUT is redirected to the connected client. So take care, `var_dump`, `echo` are not displayed on the console.
STDERR is not redirected to the client, but to the console. So, for very simple debugging you could use `error_log('hi')` and you'll see it on the console.
Per default exceptions and errors are only displayed on the console, prettified with Symfony/Debug component.

### Adapter

**HttpKernel for Symfony/Laravel** - https://github.com/php-pm/php-pm-httpkernel

**Drupal** - https://github.com/php-pm/php-pm-drupal

**Zend** - https://github.com/php-pm/php-pm-zend

### Command

![ppm-help](https://dl.dropboxusercontent.com/u/54069263/ppm-github/help-screenshot.png)

Start

```bash
cd ~/my/path/to/symfony/
ppm start

ppm start ~/my/path/to/symfony/ --bootstrap=Symfony --bridge=HttpKernel

cd ~/my/path/to/symfony/
./vendor/bin/ppm start
```

![ppm-start](https://dl.dropboxusercontent.com/u/54069263/ppm-github/start-command.png)


#### Symfony

```bash
cd my-project
composer require php-pm/httpkernel-adapter:dev-master
$ ./bin/ppm start --bootstrap=symfony
```

#### Laravel

```bash
cd my-project
composer require php-pm/httpkernel-adapter:dev-master
$ ./vendor/bin/ppm start --bootstrap=laravel
```

#### Drupal

```bash
cd my-project
composer require php-pm/httpkernel-adapter:dev-master
$ ./bin/ppm start --bootstrap=drupal
```

#### Zend

```bash
cd my-project
composer require php-pm/zend-adapter:dev-master
$ ./bin/ppm start --bridge=Zf2 --bootstrap=Zf2
```

Each worker starts its own HTTP Server which listens on port 5501, 5502, 5503 etc. Range is `5501 -> 5500+<workersCount>`.
You can integrate those workers directly in a load balancer like NGINX or use http://127.0.0.1:8080 directly.

### Performance (requests/s)

6x4GHz Intel i7, 16GB RAM. 10 concurrent, 1000 total request: `ab -c 10 -n 1000 http://127.0.0.1:8080/`

#### Symfony, CMS application

`ppm start --bootstrap=symfony --app-env=prod --logging=0 --debug=0 --workers=20`

http://jarves.io

| PHP Version              | Dynamic at Jarves | Static file |
|--------------------------|-------------------|-------------|
| 7.0.3, StreamSelectLoop  | 2387,67           | 3944,52     |
| 5.6.18, StreamSelectLoop | 1663,56           | 2636,09     |
| 5.6.18, LibEventLoop     | 1811,76           | 3441,72     |

#### Laravel, example package

https://github.com/bestmomo/laravel5-example

`ppm start --bootstrap=laravel --app-env=prod --debug=0 --logging=0 --workers=20`


<p align="center">
<img src="https://dl.dropboxusercontent.com/u/54069263/ppm-github/laravel.png" />
</p>

## Issues

* Not production ready yet, as it's in development and still needs some work in the bootstrap classes of supported frameworks. Some people are currently trying to use it in production. Stay tuned :)
* Memory leaks, memory leaks and memory leaks. You will also find leaks in your application. :) But no big issue since workers restart automatically.
* Does not work with ExtEventLoop. (So don't install `php70-event`, but you can try LibEventLoop `php56-libevent`)
* Drupal and Zend is very experimental and not fully working. Try using https://github.com/php-pm/php-pm-drupal.
* Laravel's debugger isn't working perfectly yet since it's still needed to reset some stuff after each request.
* Streamed responses are not streamed yet
* File upload is experimental
* No windows support due to signal handling
* Doesn't fully implement HTTP/1.1, but [reactphp/http](https://github.com/reactphp/http) is working on it.

Please help us fix these issues by creating pull requests. :)

### Setup 1. Use NGINX

Example config for NGINX:

```nginx
server {
    root /path/to/symfony/web/;
    server_name servername.com;
    location / {
        try_files $uri @ppm;
    }
    location @ppm {
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_pass http://127.0.0.1:8080;
    }
}
```

To get the real remote IP in your Symfony application for example, don't forget to add ppm (default `127.0.0.1`)
as trusted reverse proxy.

```yml
# app/config/config.yml
# ...
framework:
    trusted_proxies:  [127.0.0.1]
```

More information at http://symfony.com/doc/current/cookbook/request/load_balancer_reverse_proxy.html.

### Setup 2. Use PPM directly

Since PPM has also a static file server (which isn't quite as fast as nginx, but works for basic usage, see Performance section),
you can use PPM directly on your server or local. Do not run ppm as root (to get port like 80 working), as it does not set a new UID of the current process
and would run all the time as root, which is highly unrecommended.
