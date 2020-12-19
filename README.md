# php-profiler
![Minimum PHP version: 7.4.0](https://img.shields.io/badge/php-7.4.0%2B-blue.svg)
[![Packagist](https://img.shields.io/packagist/v/sj-i/php-profiler.svg)](https://packagist.org/packages/sj-i/php-profiler)
[![Packagist](https://img.shields.io/packagist/dt/sj-i/php-profiler.svg)](https://packagist.org/packages/sj-i/php-profiler)
[![Github Actions](https://github.com/sj-i/php-profiler/workflows/build/badge.svg)](https://github.com/sj-i/php-profiler/actions)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/sj-i/php-profiler/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/sj-i/php-profiler/?branch=master)
[![Coverage Status](https://coveralls.io/repos/github/sj-i/php-profiler/badge.svg?branch=master)](https://coveralls.io/github/sj-i/php-profiler?branch=master)
![Psalm coverage](https://shepherd.dev/github/sj-i/php-profiler/coverage.svg?)

php-profiler is a sampling profiler (or a VM state inspector) written in PHP. It can read information about running PHP script from outside of the process. It's a stand alone CLI tool, so target programs don't need any modifications.

It's implemented by using following techniques:

- parsing ELF binary of the interpreter
- reading memory map from /proc/\<pid\>/maps
- reading memory of outer process by using ptrace(2) and process_vm_readv(2) via FFI
- analyzing internal data structure in the PHP VM (aka Zend Engine)

If you have a bit of extra CPU resource, the overhead of this software would be negligible.

## Differences to phpspy, when to use php-profiler
php-profiler is heavily inspired by [adsr/phpspy](https://github.com/adsr/phpspy).

The main difference between the two is that php-profiler is written in almost pure PHP while phpspy is written in C.
In profiling, there are cases you want to customize how and what information to get.
If customizabilities for PHP developers matters, you can use this software at the cost of performance. (Although, I hope the cost is not too big.)

Additionally, php-profiler can find VM state from ZTS interpreters. Currently this cannot be done with phpspy only.
php-profiler also provides functionality to only get the address of EG from targets, so you can use actual profiling with phpspy if you want, even when the target is ZTS.

## Requirements
### Supported PHP versions
#### Execution
- PHP-7.4 64bit Linux x86_64 (NTS / ZTS)
- FFI extension must be enabled.
- If the target process is ZTS, PCNTL extension must be enabled.

#### Target
- PHP-7.0 64bit Linux x86_64 (NTS / ZTS)
- PHP-7.1 64bit Linux x86_64 (NTS / ZTS)
- PHP-7.2 64bit Linux x86_64 (NTS / ZTS)
- PHP-7.3 64bit Linux x86_64 (NTS / ZTS)
- PHP-7.4 64bit Linux x86_64 (NTS / ZTS)
- PHP-8.0 64bit Linux x86_64 (NTS / ZTS)

On targeting ZTS, the target process must load libpthread.so, and also you must have unstripped binary of the interpreter and the libpthread.so, to find EG from the TLS.

## Installation
### From Git
```bash
git clone git@github.com:sj-i/php-profiler.git
cd php-profiler
composer install
./php-profiler
```

### From Composer
```bash
composer require sj-i/php-profiler
./vendor/bin/php-profiler
```

## Usage
### Get current function names
```bash
 % ./php-profiler inspector:current_function --help
Description:
  periodically get running function name from an outer process or thread

Usage:
  inspector:current_function [options]

Options:
  -p, --pid=PID                              process id (required)
  -s, --sleep-ns[=SLEEP-NS]                  nanoseconds between traces (default: 1000 * 1000 * 10)
  -r, --max-retries[=MAX-RETRIES]            max retries on contiguous errors of read (default: 10)
      --php-regex[=PHP-REGEX]                regex to find the php binary loaded in the target process
      --libpthread-regex[=LIBPTHREAD-REGEX]  regex to find the libpthread.so loaded in the target process
      --php-version[=PHP-VERSION]            php version of the target (default: v74)
      --php-path[=PHP-PATH]                  path to the php binary (only needed in tracing chrooted ZTS target)
      --libpthread-path[=LIBPTHREAD-PATH]    path to the libpthread.so (only needed in tracing chrooted ZTS target)
  -h, --help                                 Display this help message
  -q, --quiet                                Do not output any message
  -V, --version                              Display this application version
      --ansi                                 Force ANSI output
      --no-ansi                              Disable ANSI output
  -n, --no-interaction                       Do not ask any interactive question
  -v|vv|vvv, --verbose                       Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

```

### Get call traces
```bash
 % ./php-profiler inspector:trace --help           
Description:
  periodically get call trace from an outer process or thread

Usage:
  inspector:trace [options]

Options:
  -p, --pid=PID                              process id (required)
  -d, --depth[=DEPTH]                        max depth
  -s, --sleep-ns[=SLEEP-NS]                  nanoseconds between traces (default: 1000 * 1000 * 10)
  -r, --max-retries[=MAX-RETRIES]            max retries on contiguous errors of read (default: 10)
      --php-regex[=PHP-REGEX]                regex to find the php binary loaded in the target process
      --libpthread-regex[=LIBPTHREAD-REGEX]  regex to find the libpthread.so loaded in the target process
      --php-version[=PHP-VERSION]            php version of the target (default: v74)
      --php-path[=PHP-PATH]                  path to the php binary (only needed in tracing chrooted ZTS target)
      --libpthread-path[=LIBPTHREAD-PATH]    path to the libpthread.so (only needed in tracing chrooted ZTS target)
  -h, --help                                 Display this help message
  -q, --quiet                                Do not output any message
  -V, --version                              Display this application version
      --ansi                                 Force ANSI output
      --no-ansi                              Disable ANSI output
  -n, --no-interaction                       Do not ask any interactive question
  -v|vv|vvv, --verbose                       Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

### Daemon mode
```bash
 % ./php-profiler inspector:daemon --help
Description:
  concurrently get call traces from processes whose command-lines match a given regex

Usage:
  inspector:daemon [options]

Options:
  -P, --target-regex=TARGET-REGEX            regex to find target processes which have matching command-line (required)
  -T, --threads[=THREADS]                    number of workers (default: 8)
  -d, --depth[=DEPTH]                        max depth
  -s, --sleep-ns[=SLEEP-NS]                  nanoseconds between traces (default: 1000 * 1000 * 10)
  -r, --max-retries[=MAX-RETRIES]            max retries on contiguous errors of read (default: 10)
      --php-regex[=PHP-REGEX]                regex to find the php binary loaded in the target process
      --libpthread-regex[=LIBPTHREAD-REGEX]  regex to find the libpthread.so loaded in the target process
      --php-version[=PHP-VERSION]            php version of the target (default: v74)
      --php-path[=PHP-PATH]                  path to the php binary (only needed in tracing chrooted ZTS target)
      --libpthread-path[=LIBPTHREAD-PATH]    path to the libpthread.so (only needed in tracing chrooted ZTS target)
  -h, --help                                 Display this help message
  -q, --quiet                                Do not output any message
  -V, --version                              Display this application version
      --ansi                                 Force ANSI output
      --no-ansi                              Disable ANSI output
  -n, --no-interaction                       Do not ask any interactive question
  -v|vv|vvv, --verbose                       Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

### Get the address of EG
```bash
 % ./php-profiler inspector:eg --help   
Description:
  get EG address from an outer process or thread

Usage:
  inspector:eg_address [options]

Options:
  -p, --pid=PID                              process id (required)
      --php-regex[=PHP-REGEX]                regex to find the php binary loaded in the target process
      --libpthread-regex[=LIBPTHREAD-REGEX]  regex to find the libpthread.so loaded in the target process
      --php-version[=PHP-VERSION]            php version of the target (default: v74)
      --php-path[=PHP-PATH]                  path to the php binary (only needed in tracing chrooted ZTS target)
      --libpthread-path[=LIBPTHREAD-PATH]    path to the libpthread.so (only needed in tracing chrooted ZTS target)
  -h, --help                                 Display this help message
  -q, --quiet                                Do not output any message
  -V, --version                              Display this application version
      --ansi                                 Force ANSI output
      --no-ansi                              Disable ANSI output
  -n, --no-interaction                       Do not ask any interactive question
  -v|vv|vvv, --verbose                       Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

## Examples
### Periodically read current function name
```bash
sudo php ./php-profiler inspector:current_function -p <pid of the target process or thread>
```

### Periodically read call trace
```bash
sudo php ./php-profiler inspector:trace -p <pid of the target process or thread>
```

### Daemon mode
```bash
sudo php ./php-profiler inspector:daemon -P <regex to find target processes>
``` 

### Get the address of EG
```bash
sudo php ./php-profiler inspector:eg -p <pid of the target process or thread>
``` 

### Use in a docker container and target a process on host
```bash
docker build -t php-profiler .
docker run -it --security-opt="apparmor=unconfined" --cap-add=SYS_PTRACE --pid=host php-profiler:latest vendor/bin/php-profiler inspector:trace -p <pid of the target process or thread>
```

# LICENSE
- MIT (mostly)
- Some C headers defining internal structures are extracted from php-src. They are licensed under the zend engine license. See src/Lib/PhpInternals/Headers . So here are the words required by the zend engine license.
```
This product includes the Zend Engine, freely available at
     http://www.zend.com
```

# See also
- [adsr/phpspy](https://github.com/adsr/phpspy)
    - php-profiler is heavily inspired by phpspy.
