# ArgentWolf Video Processor 1.0 Release Retrospective

Status: **released and live on WordPress.org**

## Final authority

* Version: `1.0.0`
* Stable source commit:
  `f656cdaba54fa63771187ca8b4fa6e19a20989f6`
* Canonical ZIP SHA-256:
  `7bbafd11c4d1f2805cfe66bb448ddac656eecc8bb2d2d12adf23a7173225468e`
* ZIP size: 227964 bytes
* Installable files: 30
* Validation report:
  `awvp-100-wp71-pcp-r1-20260823T020938Z-14923.txt`
* Exact 0.3.3 base SHA-256:
  `0249a1e481c5335e7dd57d6c0fcc1885d42425b9862e08cf559b112ecc04c094`

## Release sequence that worked

1. Complete WordPress.org review fixes on `release/1.x`.
2. Validate and publish 0.3.3 for review.
3. Receive WordPress.org approval.
4. Promote the approved codebase to metadata-only 1.0.0.
5. Run native CI.
6. Dispatch the canonical Forgejo builder before creating `v1.0.0`.
7. Preserve the workflow-produced ZIP/checksum/provenance.
8. Validate that exact ZIP on WordPress 7.1.
9. Create `v1.0.0` at the exact reviewed source commit.
10. Publish the preserved ZIP as the Forgejo release asset.
11. Redownload the public asset and verify its canonical SHA-256.
12. Stage the exact plugin tree into WordPress.org `trunk` and `tags/1.0.0`.
13. Stage directory artwork separately in top-level SVN `assets/`.
14. Commit SVN using the exact case-sensitive committer identity.

## Validation lesson

Once exact candidate identity is proved, an environment or verifier failure does
not justify silently rebuilding the candidate. The first 1.0 validator attempt
stopped because the normal shared-host account lacked direct Docker-socket
access. The artifact remained authoritative; the host capability was corrected
independently and the unchanged ZIP then passed.

The successful remote harness emitted an exact `scp` report-copy command. Keep
that behavior in the durable 2.0 harness.

## WordPress.org SVN lesson

The SVN username is case-sensitive. For this plugin the successful committer
identity is `Thystra`, not lowercase `thystra`. The lowercase attempt was denied
by the pre-commit authorization hook without partially publishing the release.
The staged working copy was preserved, identity was corrected, and the same
commit then succeeded.

## Branch closure

`release/1.x` is the completed stable/review line. Merge it to `main`, then
deliberately forward-port stable `main` into `develop-2.0`. Do not mix unfinished
2.0 work back into the stable 1.x line.
