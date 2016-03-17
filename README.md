PPM - PHP Process Manager
====================================================

<p align="center">
<img src="https://avatars3.githubusercontent.com/u/11821812?v=3&s=200" />
</p>

PHP-PM is a process manager, supercharger and load balancer for PHP applications.

It's based on ReactPHP and works best with applications that use request-response frameworks like Symfony's HTTPKernel.
The approach of this is to kill the expensive bootstrap of PHP (declaring symbols) and the bootstrap of feature-rich frameworks. See Performance section for quick hint.
PHP-PM basically spawns several PHP instances as worker, bootstraping your application (eg. the whole Symfony Kernel) and hold it in the memory for every incoming request: This it why PHP-PM makes your application so fast.
 

More information can be found in the article: [Bring High Performance Into Your PHP App (with ReactPHP)](http://marcjschmidt.de/blog/2014/02/08/php-high-performance.html)


### Features

* Performance boost of over 15x (compared to PHP-FPM, Symfony applications).
* Integrated load balancer.
* Hot-Code reload (when PHP files changes).
* Static file serving for easy development procedures.
* Support for HttpKernel (Symfony/Laravel), Drupal, Zend.

### Installation

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
composer require php-pm/php-pm:^0.1.0
composer require php-pm/httpkernel-adapter:^0.1.0 #if you have httpkernel
./vendor/bin/ppm config #places a ppm.json in your directory
./vendor/bin/ppm start #reads ppm.json and starts the server like you want
```

Once configured (composer and ppm.json) you can start your app on your development machine or server instantly:

```bash
composer install
./vendor/bin/ppm start
```

When `debug` is enabled, PHP-PM detects file changes and restarts its worker automatically.

### Adapter

**HttpKernel for Symfony/Laravel** - https://github.com/php-pm/php-pm-httpkernel

**Drupal** - https://github.com/php-pm/php-pm-drupal

**Zend** - https://github.com/php-pm/php-pm-zend

### Command

![ppm-help](https://dl.dropboxusercontent.com/u/54069263/ppm-github/help-screenshot.png)

```bash
ppm start ~/my/path/to/symfony/ #default is symfony with httpKernel
```

![ppm-start](https://dl.dropboxusercontent.com/u/54069263/ppm-github/start-command.png)

### Example for Symfony's HTTPKernel

```bash
cd php-pm
composer require php-pm/httpkernel-adapter:dev-master
$ ./bin/ppm start ~/my/path/to/symfony/ --bridge=httpKernel --bootstrap=PHPPM\Bootstraps\Symfony
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
