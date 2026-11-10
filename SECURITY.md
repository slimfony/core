# Security policy

## Reporting a vulnerability

Report vulnerabilities privately via GitHub Security Advisories ("Report a vulnerability" on the repository's Security tab). Do not open public issues for security problems. You get an answer within a few days; a confirmed problem is fixed in a release and published as an advisory with credit, if you want it.

## Supported versions

| Version | Security fixes |
|---|---|
| the latest release | yes |
| older releases | no: upgrade (see [UPGRADE.md](UPGRADE.md)) |

Before 1.0 only the latest minor release is supported. [STABILITY.md](STABILITY.md) lists the PHP, Symfony and Doctrine versions that are supported.

## Verifying a release

Every release carries a source archive and a CycloneDX SBOM of the runtime dependencies, with a signed build provenance attestation (GitHub's OIDC identity) for both:

```
gh attestation verify slimfony-core-v0.7.0.zip --repo slimfony/core
```

The tags are made by the maintainer; the CI actions are pinned to commit SHAs and kept current by Dependabot, and `composer audit` runs on every change.
