PPM - PHP Process Manager
====================================================

<p align="center">
<img src="https://avatars3.githubusercontent.com/u/11821812?v=3&s=200" />
</p>

PHP-PM is a process manager, supercharger and load balancer for PHP applications.

It's based on ReactPHP and works best with applications that use request-response frameworks like Symfony's HTTPKernel.
The approach of this is to kill the expensive bootstrap of PHP (declaring symbols, loading/parsing files) and the bootstrap of feature-rich frameworks. See Performance section for a quick hint.
PHP-PM basically spawns several PHP instances as worker bootstraping your application (eg. the whole Symfony Kernel) and hold it in the memory to be prepared for every
incoming request: This is why PHP-PM makes your application so fast.
 

More information can be found in the article: [Bring High Performance Into Your PHP App (with ReactPHP)](http://marcjschmidt.de/blog/2014/02/08/php-high-performance.html)


### Features

* Performance boost up to 15x (compared to PHP-FPM, Symfony applications).
* Integrated load balancer.
* Hot-Code reload (when PHP files changes).
* Static file serving for easy development procedures.
* Support for HttpKernel (Symfony/Laravel), Drupal (experimental), Zend (experimental).

### Why using PPM as development server instead of vagrant, nginx or apache?

* No hassle with file permissions (www-data vs local user ids).
* No painful slow virtual-box file sync.
* Faster response times of your PHP app.
* No fighting with vagrant / virtual machine settings. 
* Checkout a new project, run `ppm start` - done. (if configured with `ppm config`)
* No hassle with domain names (/etc/hosts), just use different ports for your app without root access.

### Installation

To get PHP-PM you need beside the php binary also php-cgi, which comes often with php. If not availabe try to install it:

**Debian/Ubuntu** (https://www.digitalocean.com/community/tutorials/how-to-upgrade-to-php-7-on-ubuntu-14-04)

`apt-get install php7.0-cgi`

**Mac OSX** (https://github.com/Homebrew/homebrew-php)

`brew install php70`

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
composer require php-pm/php-pm:dev-master #if you have httpkernel (laravel, symfony)
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

#### Performance & Debugging tips

To get the maximum performance you should usually use `--app-env=prod` with disabled
debug `--debug=0`. Also make sure xdebug is disabled. Try with different amount of workers.
Usually a 10% over your cpu core count is good. Example: If you have 8 cores (incl. hyper-threading) use `--workers=9`.

To get even more performance (for static file serving or for rather fast applications) try a different event loop:


If you get strange issues in your application and you have no idea where they are coming from try
using only one worker `--workers=1`. 

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

### Performance

6x3,2 GHz Intel, 16GB RAM. 20 concurrent, 1000 total request: `ab -c 20 -n 1000 http://127.0.0.1:8080/`

#### PHP 7, StreamSelectLoop

```
/usr/local/bin/php7 ./bin/ppm start ~/www/symfony--bridge=httpKernel --app-env=prod --logging=0 --debug=0 --workers=8

Static file: 2371.93 requests/s
Dynamic CMS application: 1685.80 request/s (http://jarves.io)
```

#### PHP 5.6.18, StreamSelectLoop

```
/usr/local/bin/php5 ./bin/ppm start ~/www/symfony --bridge=httpKernel --app-env=prod --logging=0 --debug=0 --workers=8

Static file: 1818.52 requests/s
Dynamic CMS application: 1270.30 request/s (http://jarves.io)
```

### Issues

* Memory leaks, memory leaks and memory leaks. You will find also leaks in your application. :)
* Does not work with ExtEventLoop. (So don't install `php70-event`)
* Drupal is very experimental and not fully working. Try using https://github.com/php-pm/php-pm-drupal.
* Symfony's and Laravel's profiler aren't working yet perfectly since it's still needed to reset some stuff after each request.
* Streamed responses are not streamed yet
* File upload is experimental
* No windows support due to signal handling

Please help us to fix those issues by creating pull requests. :)

### Setup 1. Use external Load-Balancer

![ReactPHP with external Load-Balancer](doc/reactphp-external-balancer.jpg)

Example config for NGiNX:

```nginx
upstream backend  {
    server 127.0.0.1:5501;
    server 127.0.0.1:5502;
    server 127.0.0.1:5503;
    server 127.0.0.1:5504;
    server 127.0.0.1:5505;
    server 127.0.0.1:5506;
}

server {
    root /path/to/symfony/web/;
    server_name servername.com;
    location / {
        try_files $uri @backend;
    }
    location @backend {
        proxy_pass http://backend;
    }
}
```

### Setup 2. Use internal Load-Balancer

This setup is slower as we can't load balance incoming connections as fast as NGiNX it does,
but it's perfect for testing purposes.

![ReactPHP with internal Load-Balancer](doc/reactphp-internal-balancer.jpg)
