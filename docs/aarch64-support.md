# AArch64 (ARM64) Support

## Overview

Reli supports AArch64 Linux as an experimental platform. This enables profiling on ARM-based servers (e.g., AWS Graviton) and Apple Silicon Macs running Linux VMs or Docker containers. Both NTS and ZTS targets are supported.

## Implementation differences from x86_64

### ptrace register access

AArch64 Linux does not support `PTRACE_GETREGS` or `PTRACE_PEEKUSER`. Instead, reli uses `PTRACE_GETREGSET` with an `iovec` structure to read registers:

- **General-purpose registers**: `PTRACE_GETREGSET` with `NT_PRSTATUS` reads the `user_pt_regs` struct (X0-X30, SP, PC, PSTATE).
- **Thread pointer (TLS)**: `PTRACE_GETREGSET` with `NT_ARM_TLS` (0x401) reads the `TPIDR_EL0` register, which is the AArch64 equivalent of x86_64's `FS_BASE`.

### DWARF register numbering

The DWARF register numbers differ between architectures:

| Role | x86_64 | AArch64 |
|------|--------|---------|
| Instruction pointer | 16 (RIP) | 32 (PC) |
| Stack pointer | 7 (RSP) | 31 (SP) |
| Frame pointer | 6 (RBP) | 29 (X29/FP) |
| Return address | 16 (RIP) | 30 (X30/LR) |
| Callee-saved | RBX, RBP, R12-R15 | X19-X30 |

### TLS Variant I vs Variant II

This is the most significant architectural difference for ZTS support.

- **x86_64 (TLS Variant II)**: `FS_BASE` points directly to `struct pthread`. The `_thread_db_pthread_dtvp` offset (from libpthread debug symbols) gives the position of the DTV pointer within the struct. So `DTV = *(FS_BASE + dtvp_offset)`.

- **AArch64 (TLS Variant I)**: `TPIDR_EL0` points to `tcbhead_t`, which is located **after** `struct pthread` in memory (glibc defines `THREAD_SELF` as `(struct pthread *)tp - 1`). The DTV pointer is the first field of `tcbhead_t` at offset 0. So `DTV = *(TPIDR_EL0 + 0)`.

The `_thread_db_pthread_dtvp` offset cannot be used directly with `TPIDR_EL0` on AArch64 because it's an offset within `struct pthread`, not from the thread pointer.

### .eh_frame differences

AArch64 ELF binaries use different CIE (Common Information Entry) defaults:

| Field | x86_64 | AArch64 |
|-------|--------|---------|
| Code alignment factor | 1 | 4 |
| Data alignment factor | -8 | -8 |
| Return address register | 16 (RIP) | 30 (LR) |

The frame pointer unwinding layout is identical on both architectures: `[FP+0]` = saved FP, `[FP+8]` = return address.
