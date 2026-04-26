// Source for AtomicCodeRegion::CODE_BYTES — DO NOT change at runtime.
//
// To regenerate the bytes after edits:
//
//   gcc -O2 -fno-pie -fno-asynchronous-unwind-tables -fcf-protection=none \
//       -ffreestanding -nostdlib -c -o loi2.o loi2.c
//   objcopy -O binary --only-section=.text loi2.o loi2.bin
//   xxd -i loi2.bin     # or hexdump+sed; paste into AtomicCodeRegion.php
//
// If function offsets shift (verify with `objdump -d loi2.o`), update the
// OFFSET_GET_OR_INSERT / OFFSET_LOOKUP_ONLY constants in AtomicCodeRegion.php.

typedef unsigned long u64;

#define HIGH_BIT (1UL << 63)

u64 get_or_insert(u64* buckets, u64 mask, u64 key, u64 value)
{
    u64 h = key * 0x9E3779B97F4A7C15UL;
    u64 slot = (h >> 17) & mask;

    for (;;) {
        u64* kp = &buckets[slot * 2];
        u64* vp = kp + 1;
        u64 cur = __atomic_load_n(kp, __ATOMIC_ACQUIRE);

        if (cur == key) {
            return __atomic_load_n(vp, __ATOMIC_ACQUIRE);
        }
        if (cur == 0) {
            u64 expected = 0;
            if (__atomic_compare_exchange_n(kp, &expected, key, 0,
                    __ATOMIC_ACQ_REL, __ATOMIC_ACQUIRE)) {
                __atomic_store_n(vp, value, __ATOMIC_RELEASE);
                return value | HIGH_BIT;
            }
            if (expected == key) {
                return __atomic_load_n(vp, __ATOMIC_ACQUIRE);
            }
        }
        slot = (slot + 1) & mask;
    }
}

u64 lookup_only(u64* buckets, u64 mask, u64 key)
{
    u64 h = key * 0x9E3779B97F4A7C15UL;
    u64 slot = (h >> 17) & mask;

    for (;;) {
        u64* kp = &buckets[slot * 2];
        u64 cur = __atomic_load_n(kp, __ATOMIC_ACQUIRE);

        if (cur == key) {
            return __atomic_load_n(kp + 1, __ATOMIC_ACQUIRE) | HIGH_BIT;
        }
        if (cur == 0) {
            return 0;
        }
        slot = (slot + 1) & mask;
    }
}
