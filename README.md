# About
This is a software intended to be a PHP profiler written in PHP.
It can read information from running PHP process, by parsing ELF binary of the interpreter and reading memory map from /proc/\<pid>/maps and using ptrace(2) and process_vm_readv(2) with FFI.

# Status
- WIP
- It can periodically read and output the current running function name from another PHP process
- Additionally, it can find the address of EG from another PHP process
    - ZTS is also supported.
    - So it can also be used with [adsr/phpspy](https://github.com/adsr/phpspy) to profile in ZTS

# Usage
## Basic usage
```
sudo php ./php-profiler inspector:current_function -p <pid of the target process or thread>
```

## Get the address of EG
```
sudo php ./php-profiler inspector:eg -p <pid of the target process or thread>
``` 

# Supported PHP version
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