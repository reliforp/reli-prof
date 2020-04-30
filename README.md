# About
This is a software intended to be a PHP profiler written in PHP.
It can read information from running PHP process, by parsing ELF binary of the interpreter and reading memory map from /proc/<pid>/maps and using ptrace(2) and process_vm_readv(2) with FFI.

# Status
- WIP
- Currently all it can do is finding the address of EG from another PHP process
    - ZTS is also supported.

# Usage
## Get the address of EG
- sudo php ./php-profiler inspector:eg -p <pid of the target process or thread>

# Supported PHP version
## Execution
- PHP-7.4 64bit Linux x86_64 (NTS)
- PHP-7.4 64bit Linux x86_64 (ZTS)

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