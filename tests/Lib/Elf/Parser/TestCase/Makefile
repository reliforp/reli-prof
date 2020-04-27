CC = gcc
LD = ld

target = test000.so
CFLAGS = -Wall -Wextra -fno-builtin -fno-common -nostdlib -nostdinc -nostartfiles -nodefaultlibs -mno-red-zone -ffreestanding -fno-stack-protector
LDFLAGS = -shared --entry=main

all: $(target)

%.so: %.o
	$(LD) $(LDFLAGS) $^ -o $@

%.o: %.c
	$(CC) $(CFLAGS) -c $< -o $@

