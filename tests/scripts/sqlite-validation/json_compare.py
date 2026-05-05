#!/usr/bin/env python3
"""Compare two reli memory JSON dumps structurally."""
import json
import sys
from collections import defaultdict


def shape(obj, max_depth=12, _depth=0):
    """Recursive shape descriptor: keys → child shapes for dicts,
    type counts for arrays. Truncates at max_depth so symmetric
    shapes collapse."""
    if _depth >= max_depth:
        return type(obj).__name__
    if isinstance(obj, dict):
        return {k: shape(v, max_depth, _depth + 1) for k, v in obj.items()}
    if isinstance(obj, list):
        if not obj:
            return []
        seen = {}
        for item in obj[:5]:
            k = json.dumps(shape(item, max_depth, _depth + 1), sort_keys=True, default=str)
            seen[k] = seen.get(k, 0) + 1
        return {"_array_len": len(obj), "_sample_shapes": list(seen.keys())}
    return type(obj).__name__


def array_lengths(obj, prefix=""):
    out = []
    if isinstance(obj, dict):
        for k, v in obj.items():
            out.extend(array_lengths(v, f"{prefix}.{k}" if prefix else k))
    elif isinstance(obj, list):
        out.append((prefix, len(obj)))
        for i, item in enumerate(obj[:1]):
            out.extend(array_lengths(item, f"{prefix}[0]"))
    return out


def first_dict_keys(obj, path=""):
    """For each array of dicts, the keys in dict[0]."""
    out = {}
    if isinstance(obj, dict):
        for k, v in obj.items():
            out.update(first_dict_keys(v, f"{path}.{k}" if path else k))
    elif isinstance(obj, list) and obj and isinstance(obj[0], dict):
        out[path] = sorted(obj[0].keys())
        out.update(first_dict_keys(obj[0], f"{path}[0]"))
    return out


def main():
    a_path, b_path = sys.argv[1], sys.argv[2]
    with open(a_path) as f:
        a = json.load(f)
    with open(b_path) as f:
        b = json.load(f)

    print(f"== top-level keys ==")
    print(f"  a: {sorted(a.keys()) if isinstance(a, dict) else type(a).__name__}")
    print(f"  b: {sorted(b.keys()) if isinstance(b, dict) else type(b).__name__}")
    if isinstance(a, dict) and isinstance(b, dict):
        only_a = set(a) - set(b)
        only_b = set(b) - set(a)
        if only_a:
            print(f"  only in a: {sorted(only_a)}")
        if only_b:
            print(f"  only in b: {sorted(only_b)}")

    print()
    print(f"== array lengths along the tree ==")
    al_a = dict(array_lengths(a))
    al_b = dict(array_lengths(b))
    all_paths = sorted(set(al_a) | set(al_b))
    for p in all_paths:
        la = al_a.get(p, "-")
        lb = al_b.get(p, "-")
        marker = "" if la == lb else "  ≠"
        print(f"  {p:50s} a={la}  b={lb}{marker}")

    print()
    print(f"== first-dict keys per array-of-dicts ==")
    fk_a = first_dict_keys(a)
    fk_b = first_dict_keys(b)
    all_paths = sorted(set(fk_a) | set(fk_b))
    for p in all_paths:
        ka = fk_a.get(p, [])
        kb = fk_b.get(p, [])
        if ka == kb:
            print(f"  {p:50s} OK ({len(ka)} keys: {ka[:6]}{'...' if len(ka)>6 else ''})")
        else:
            print(f"  {p:50s} ≠")
            print(f"    only in a: {sorted(set(ka) - set(kb))}")
            print(f"    only in b: {sorted(set(kb) - set(ka))}")

    # Summary, if it's a list of {key, value}
    print()
    print(f"== summary key-set diff ==")
    s_a = a.get("summary") if isinstance(a, dict) else None
    s_b = b.get("summary") if isinstance(b, dict) else None

    def summary_keys(s):
        if isinstance(s, list) and s and isinstance(s[0], dict) and "key" in s[0]:
            return sorted({item["key"] for item in s})
        if isinstance(s, dict):
            return sorted(s.keys())
        return None

    ks_a = summary_keys(s_a)
    ks_b = summary_keys(s_b)
    if ks_a is None or ks_b is None:
        print(f"  summary shape: a={type(s_a).__name__} b={type(s_b).__name__} — skipping")
    else:
        only_a = set(ks_a) - set(ks_b)
        only_b = set(ks_b) - set(ks_a)
        if not only_a and not only_b:
            print(f"  summary keys identical ({len(ks_a)} keys)")
        else:
            print(f"  only in a: {sorted(only_a)}")
            print(f"  only in b: {sorted(only_b)}")


if __name__ == "__main__":
    main()
