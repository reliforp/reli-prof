[![Packagist](https://img.shields.io/packagist/v/sj-i/php-profiler.svg)](https://packagist.org/packages/sj-i/php-profiler)
[![Packagist](https://img.shields.io/packagist/dt/sj-i/php-profiler.svg)](https://packagist.org/packages/sj-i/php-profiler)
[![Github Actions](https://github.com/sj-i/php-profiler/workflows/build/badge.svg)](https://github.com/sj-i/php-profiler/actions)
[![Coverage Status](https://coveralls.io/repos/github/sj-i/php-profiler/badge.svg?branch=master)](https://coveralls.io/github/sj-i/php-profiler?branch=master)
![Psalm coverage](https://shepherd.dev/github/sj-i/php-profiler/coverage.svg?)
# About
This is a software intended to be a sampling PHP profiler written in PHP.
It can read information from running PHP process, by parsing ELF binary of the interpreter and reading memory map from /proc/\<pid>/maps and using ptrace(2) and process_vm_readv(2) with FFI.

# Status
- WIP
- It can periodically read and output the current running function name or call trace from another PHP process
- Additionally, it can find the address of EG from another PHP process
    - ZTS is also supported.
    - So it can also be used with [adsr/phpspy](https://github.com/adsr/phpspy) to profile in ZTS
- It runs only on PHP 7.4

# Installation
## From Git
```
git clone git@github.com:sj-i/php-profiler.git
cd php-profiler
composer install
./php-profiler
```

## From Composer
```
composer require sj-i/php-profiler
./vendor/bin/php-profiler
```

# Usage
## Periodically read current function name
```
sudo php ./php-profiler inspector:current_function -p <pid of the target process or thread>
```

## Periodically read call trace
```
sudo php ./php-profiler inspector:trace -p <pid of the target process or thread>
```

## Get the address of EG
```
sudo php ./php-profiler inspector:eg -p <pid of the target process or thread>
``` 

# Supported PHP versions
## Execution
- PHP-7.4 64bit Linux x86_64 (NTS)
- PHP-7.4 64bit Linux x86_64 (ZTS)
- FFI extension must be enabled.
- If the target process is ZTS, PCNTL extension must be enabled.

## Target
- PHP-7.4 64bit Linux x86_64 (NTS)
- PHP-7.4 64bit Linux x86_64 (ZTS)
    - The target process has to load libpthread.so on ZTS to find EG from TLS,

# LICENSE
- MIT (mostly)
- Some C headers defining internal structures are extracted from php-src. They are licensed under the zend engine license. See src/Lib/PhpInternals/Headers . So here are the words required by the zend engine license.
```
This product includes the Zend Engine, freely available at
     http://www.zend.com
```

# Credits
- php-profiler is heavily inspired by [adsr/phpspy](https://github.com/adsr/phpspy).