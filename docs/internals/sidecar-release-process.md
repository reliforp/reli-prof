# Sidecar Release Process

How to ship a `reliforp/reli-prof` release that involves the
`Reli\Sidecar\Client\` namespace, given the two-repository topology
(upstream + auto-generated read-only mirror on Packagist).

The process is small but order-sensitive: doing the upstream tag
before the mirror tag leaves the upstream release notes pointing at
a Packagist version that does not exist yet, and `composer require
reliforp/reli-prof-sidecar-client:^0.x` fails for everyone who
copies the install snippet during that window.

## Repositories

| Repo | Role | Branch you tag |
|---|---|---|
| `reliforp/reli-prof` | Upstream — owns the full source under `src/Sidecar/Client/`, the build script (`tools/build-sidecar-client.sh`), the Rector config (`rector-sidecar-client.php`), the docs in `docs/monitoring/sidecar.md`, and this file. | the maintenance branch (`0.12.x`, `0.13.x`, …) |
| `reliforp/reli-prof-sidecar-client` | Read-only mirror published on Packagist as the standalone client package. Files are produced by the Rector downgrade pipeline; nobody opens PRs here. | `main` |

The mirror's `main` branch tracks upstream's current maintenance
branch. Each commit on the mirror has the form
`Sync from upstream 0.12.x@<sha>`, where `<sha>` is the upstream
commit whose tree was downgraded.

## Versioning policy

**Mirror tag = upstream tag, exactly.** When upstream tags `0.12.0`,
the mirror tags `0.12.0`. Patch releases follow the same rule:
upstream `0.12.1` ⇒ mirror `0.12.1`, even when `src/Sidecar/Client/`
did not change in that patch (the Rector tooling, `composer.json`
metadata, or PHP-version handling may have shifted, and a fresh tag
captures that state).

This is deliberately stricter than "tag the mirror only when the
client API changes", because:

- it gives users a single mental model (`reli 0.12 → client ^0.12`)
  rather than a lookup table,
- empty bumps cost nothing (a tag is one git push), and
- it removes a class of "did the mirror also need a tag this time?"
  ambiguity from every patch.

## Tag-day playbook

> **Order matters: mirror first, upstream second.** The upstream
> release notes / docs reference the mirror tag, so the mirror tag
> has to exist by the time the upstream tag is pushed.

### 1. Pre-flight (do this once per release)

```bash
# In the upstream checkout, on the maintenance branch:
git checkout 0.12.x
git pull --ff-only
composer install               # picks up the matching rector pin
tools/build-sidecar-client.sh  # regenerates build/sidecar-client/

# Smoke-check the artifact against a fresh consumer project:
mkdir /tmp/sidecar-smoke && cd /tmp/sidecar-smoke
cat > composer.json <<EOF
{
  "require": { "reliforp/reli-prof-sidecar-client": "*" },
  "repositories": [
    {"type": "path",
     "url": "$OLDPWD/build/sidecar-client",
     "options": {"symlink": false}}
  ],
  "minimum-stability": "dev"
}
EOF
composer install --no-progress
php -r 'require "vendor/autoload.php";
        new Reli\Sidecar\Client\SidecarClient("/dev/null");'
```

If the smoke test fails, fix it on the upstream branch first — never
hand-edit the build artifact, and never commit divergent code on the
mirror. The mirror is a function of upstream + the Rector pipeline.

### 2. Stage the upstream docs change (do **not** commit yet)

In `docs/monitoring/sidecar.md`, swap the install snippet from the
`dev-main` form to a tagged constraint:

```diff
-  composer require reliforp/reli-prof-sidecar-client:dev-main
+  composer require 'reliforp/reli-prof-sidecar-client:^0.12'
```

Drop the `Until the first tagged release …` paragraph and the
`minimum-stability: dev` requirement. Keep the patch in your working
tree — it is committed in step 5 once the mirror tag is live.

### 3. Push the regenerated mirror, then tag it

In a checkout of `reliforp/reli-prof-sidecar-client`:

```bash
# Replace the working tree with the freshly built artifact.
rsync -a --delete --exclude=.git \
  /path/to/reli-prof/build/sidecar-client/ ./

git add -A
git commit -m "Sync from upstream 0.12.x@$(cd /path/to/reli-prof && git rev-parse --short HEAD)"
git push origin main

# Tag it. The tag name must match the upstream tag we are about to push.
git tag -a 0.12.0 -m "Mirror sync for reliforp/reli-prof 0.12.0"
git push origin 0.12.0
```

### 4. Wait for Packagist to ingest the tag

The Packagist webhook usually picks up new tags within ~30 seconds.
Verify before moving on:

```bash
curl -sS https://packagist.org/p2/reliforp/reli-prof-sidecar-client.json \
  | python3 -c '
import json, sys
data = json.load(sys.stdin)
versions = [v["version"] for v in data["packages"]["reliforp/reli-prof-sidecar-client"]]
print(versions[:5])
'
# Expect: ['0.12.0', 'dev-main', ...]
```

If the new tag is missing for more than a couple of minutes, log in
to Packagist and click **Update** on the package page (requires
maintainer permission). The webhook may have been throttled or the
mirror push may not have triggered it.

### 5. Land the upstream docs change

Commit the docs patch you staged in step 2 onto the upstream
maintenance branch, push, and verify CI is green. This is what
`composer require reliforp/reli-prof-sidecar-client:^0.12` users
will read on the tagged release page.

### 6. Tag the upstream release

```bash
# In the upstream checkout, on 0.12.x with the docs commit landed:
git tag -a 0.12.0 -m "..."
git push origin 0.12.0
```

### 7. Release notes

In the GitHub release notes, name the matching client version
explicitly:

> Pairs with `reliforp/reli-prof-sidecar-client` **v0.12.0** on
> Packagist. Install with `composer require
> reliforp/reli-prof-sidecar-client:^0.12`.

This serves two purposes: it lets readers paste the exact constraint
without copying from the upstream docs, and it gives future
maintainers a clear precedent for the "mirror tag = upstream tag"
convention.

## Patch releases (`0.12.1`, `0.12.2`, …)

Same playbook, no exceptions: the mirror gets a tag with the same
version as the upstream patch, regardless of whether
`src/Sidecar/Client/` changed.

| `src/Sidecar/Client/` changed in the patch? | Action |
|---|---|
| **Yes** (any client-facing change, however small) | Regenerate, push to mirror, tag `0.12.x` on the mirror first, then upstream. |
| **No** | Still tag the mirror with the same version. Re-run the build script so the mirror tree reflects the current Rector tooling, push as a fresh sync commit, and tag — even when the resulting tree is byte-identical to the previous tag. The tag itself preserves the "reli `0.12.x` pairs with client `0.12.x`" mental model and removes a class of "is there a matching mirror tag for this upstream patch?" support questions. Tags are cheap; ambiguity is not. |

The single-rule version, kept short for the runbook reader: **every
upstream tag in the `Reli\Sidecar\Client\` namespace's lifetime gets
a matching mirror tag. No exceptions, no conditional language in
release notes.**

## Recovery

### "I tagged upstream first by mistake."

The release page now points at a non-existent client version. Fix
forward:

1. Push the mirror tag (`0.12.0`).
2. Cut a docs-only `0.12.1` upstream patch that re-asserts the
   correct install snippet (or, if step 5 did land correctly, do
   nothing — the only damage is the brief window).

Do **not** delete-and-retag the upstream tag. Packagist caches
deleted tags aggressively and the resulting "version exists but is
empty" state is worse than the original mistake.

### "The Rector output is wrong."

Fix on upstream, regenerate, push to mirror as a fresh sync commit,
re-tag with the next patch number. Treat the bad mirror tag as
released — that is what users on `^0.12` may have already pulled.

### "The webhook is silent."

Manual `Update` on the Packagist package page is the supported
escape hatch. If that also fails, the package's API key needs
rotating; a maintainer with Packagist account access has to do
that.

## One-time setup verification

Before the very first tagged release on a new mirror repository:

- [ ] The `reliforp/reli-prof-sidecar-client` Packagist package
      has a maintainer account that matches the release engineer
      (or a service account they can use).
- [ ] The mirror repository on GitHub has a webhook to
      `https://packagist.org/api/github` configured (Packagist's
      "How to update packages" page has the canonical instructions).
- [ ] `tools/build-sidecar-client.sh` runs cleanly on a fresh
      `composer install` of the upstream maintenance branch.
- [ ] The mirror's `composer.json` (regenerated by the script) has
      no leftover `version` field — Packagist must derive versions
      from git tags.

## Future automation

The whole sequence above is mechanical and a good fit for a CI
workflow. The shape of that automation is sketched in
`docs/internals/sidecar-improvements.md` § F2, and is on the 0.12.x
roadmap rather than the launch scope. Until it lands, this page is
the source of truth for the manual procedure.
