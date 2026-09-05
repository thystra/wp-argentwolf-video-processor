# WordPress plugin development guidance

This document captures WordPress-specific development and release rules for
ArgentWolf plugins intended for WordPress.org. It supplements each project's
`AGENTS.md`.

WordPress.org policies and WordPress APIs change. Before a submission, corrected
review upload, or release, re-check the current official documentation rather
than treating this file as an immutable substitute for upstream rules.

## Primary official references

Review these sources when the corresponding area changes:

- Plugin Directory and detailed guidelines:
  https://developer.wordpress.org/plugins/wordpress-org/
  https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Common Plugin Directory review issues:
  https://developer.wordpress.org/plugins/wordpress-org/common-issues/
- Planning, submission, review, and maintenance:
  https://developer.wordpress.org/plugins/wordpress-org/planning-submitting-and-maintaining-plugins/
- WordPress.org `readme.txt`:
  https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- WordPress.org SVN:
  https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/
- WordPress.org icons, banners, and screenshots:
  https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
- Plugin/content directory APIs:
  https://developer.wordpress.org/plugins/plugin-basics/determining-plugin-and-content-directories/
- Security:
  https://developer.wordpress.org/apis/security/
  https://developer.wordpress.org/apis/security/data-validation/
  https://developer.wordpress.org/apis/security/sanitizing/
  https://developer.wordpress.org/apis/security/escaping/
  https://developer.wordpress.org/apis/security/nonces/
  https://developer.wordpress.org/apis/security/user-roles-and-capabilities/
- WordPress coding standards:
  https://developer.wordpress.org/coding-standards/wordpress-coding-standards/

## Directory and data ownership

Never assume a stock WordPress filesystem layout.

Use WordPress APIs to discover paths and URLs. Do not hard-code `wp-content`,
`wp-content/plugins`, or `wp-content/uploads`. WordPress permits custom content
and uploads locations, multisite layouts, and symlinked installations.

Plugin configuration belongs in WordPress-managed storage such as the Options
or Settings APIs unless the data is inherently file content. User-uploaded
media should use WordPress's media/upload APIs.

When a plugin must generate or manage files that are not ordinary Media Library
uploads, use `wp_upload_dir()` at runtime and create a clearly plugin-owned
directory below the uploads `basedir`, normally named for the plugin slug.

A plugin must not write generated state into:

- WordPress core directories;
- its own installed plugin directory;
- another plugin or theme directory;
- a hard-coded `wp-content` path;
- an arbitrary location outside the WordPress installation;
- a path derived from metadata or user input without an explicit ownership
  boundary check.

If generated data is not meant to be public, protect it from direct access or
choose an appropriate non-public storage design.

## Runtime diagnostics and temporary-file lifecycle

Classify plugin-created runtime data before selecting storage:

- persistent application state belongs in WordPress-managed database/storage APIs appropriate to the data;
- persistent private diagnostics require an explicitly non-public design and bounded retention;
- short-lived process/bootstrap capture may use WordPress-resolved temporary storage only when cleanup is explicit on success and failure.

For temporary files, prefer WordPress temporary-file facilities such as `get_temp_dir()` and `wp_tempnam()` rather than directly selecting a PHP/system temporary directory. A temporary file is not a retention mechanism. Import any diagnostic data that must persist into its authoritative store, bound the stored size, then delete the scratch file. Design stale-file reconciliation for abrupt process termination so a later run does not truncate the only remaining evidence before importing it.

Every retained diagnostic facility needs a finite per-record size bound and a finite record-count or time-based retention policy. Error records are not exempt from retention limits. If administrators can tune retention, sanitize the setting to safe bounds and provide a generous but finite default.

Do not put private runtime logs under uploads merely to satisfy a writable-path requirement unless direct web access is separately and portably prevented. Apache-only `.htaccess` protection is not a cross-server privacy boundary.

`ABSPATH` is not categorically forbidden. Use it only when the semantic requirement is the WordPress installation root or a WordPress core include. Do not use it as a substitute for plugin-directory, content-directory, uploads, or writable-storage APIs.

## Filesystem confinement

Treat filesystem destinations as security-sensitive inputs.

Define one managed root for plugin-created files and centralize path creation
and validation. Callers should request paths from that storage component rather
than assemble paths themselves.

Validate confinement before every filesystem mutation, including:

- directory creation;
- encoder/transcoder output;
- `file_put_contents()` or equivalent writes;
- `rename()` or promotion of temporary output;
- `wp_delete_file()`;
- recursive deletion;
- cleanup during uninstall or attachment deletion.

Do not make URL conversion the first confinement check after a write has already
happened.

Boundary tests must cover traversal (`..`), sibling-prefix paths, path separator
edge cases, custom upload locations, nonexistent final paths, and symlinks.
Remember that `realpath()` fails for paths that do not yet exist, so validation
of a future destination normally needs a validated existing parent plus a
carefully constructed child path.

Destructive cleanup must fail closed when the path cannot be proven to belong to
the plugin.

## Security model

Do not trust request data, stored database data, attachment metadata, remote API
data, filenames, paths, or administrator-configured executable paths merely
because they came through WordPress.

For privileged actions:

1. check the specific capability with `current_user_can()` or an equivalent
   capability-aware API;
2. verify the action nonce for CSRF protection;
3. validate values against the narrowest accepted domain;
4. sanitize when strict validation is not possible;
5. perform the action only after authorization and validation;
6. escape output for its final HTML/attribute/URL/JavaScript context as late as
   practical.

A WordPress nonce is not authentication or authorization. Do not use nonce
success instead of a capability check.

Prefer rejection/validation to silently transforming an invalid
security-sensitive value.

Use WordPress database and HTTP APIs rather than bypassing them without a
reviewed technical reason. Parameterize database queries and keep SQL,
filesystem, and shell boundaries explicit.

For large outbound request bodies, do not assume the WordPress HTTP API requires
materializing the complete body as a PHP string. When a reviewed transport needs
streaming, keep `wp_safe_remote_request()` (or the appropriate WordPress HTTP
entry point) as the URL/policy boundary and scope any lower-level transport hook
to the exact request. A cURL read callback must read only from an already-proven
plugin-owned descriptor, set an exact content length/range, be removed after the
request on success or failure, and fail closed when the required transport is
unavailable. Do not silently fall back from a reviewed streaming contract to a
large in-memory body.

For shell execution, prefer a small reviewed command builder. Keep executable
selection constrained to administrator-authorized configuration, validate
values before use, pass fixed arguments where possible, quote each shell
argument safely, and never concatenate untrusted request data into a shell
command.

## Naming and global compatibility

WordPress installations run many plugins and themes in one PHP process.

Use a unique, sufficiently long project prefix or namespace for functions,
classes/namespaces, constants, option names, post-meta keys, cron hooks,
action/filter hooks, database tables, REST routes, and other global identifiers.

Do not invent identifiers beginning with WordPress-reserved `wp_`, `_`, or
double-underscore prefixes. Avoid `function_exists()` wrappers as a substitute
for proper unique naming.

Guard executable PHP entry points against unintended direct web execution where
appropriate.

When a public product rename deliberately preserves established persisted identifiers for upgrade compatibility, keep those existing identifiers stable. Newly introduced global identifiers should use the current canonical plugin-specific prefix or slug rather than extending a legacy abbreviation or old product name.

## Third-party libraries and build inputs

The plugin author is responsible for every file shipped in the WordPress.org
package.

Before distributing a third-party library:

- verify its exact version and origin;
- verify a GPL-compatible license for WordPress.org distribution;
- preserve required license notices;
- prefer WordPress-provided/default libraries where Directory rules require
  them;
- keep source/build instructions available when distributed code is generated
  or minified;
- do not use remote code execution or download executable runtime code as an
  update mechanism.

Build-time integrity records are useful, but do not ship internal checksums,
version marker files, package-manager caches, source maps, tests, or build
metadata unless they have a genuine runtime/user purpose.

If the plugin contacts an external service, document the service and its terms
and privacy behavior. Do not add telemetry, tracking, or unrelated remote asset
loads without explicit authorized consent and required disclosure.

## Package composition

The submitted ZIP is a complete installable plugin, not a source-repository
archive.

Use a deterministic allowlist or an equally strong distribution mechanism.
Inspect the final package manifest.

A normal WordPress.org plugin ZIP should contain the main plugin PHP file,
runtime PHP/JS/CSS/assets, `readme.txt`, required license files, and
runtime-required third-party libraries/notices.

Keep repository-only material out of the plugin ZIP, including:

- `.git` or Forgejo/GitHub metadata;
- CI workflow files;
- development tests;
- build tooling;
- AGENTS or agent-profile documents;
- local operations/runbooks;
- migration/testing utilities not intended as supported runtime features;
- backups, evidence, reports, and logs;
- WordPress.org directory icons/banners source files;
- transient build integrity records that are not runtime requirements.

Run `unzip -Z1` or an equivalent manifest inspection against the exact
submission artifact.

## Plugin headers and readme

Keep the main plugin header and `readme.txt` synchronized.

The main plugin file is authoritative for installed Version metadata and current
WordPress requirement parsing. WordPress.org `readme.txt` controls the public
directory description and its Stable Tag selects the release tag.

For every code release:

- increment the plugin Version;
- update `Stable Tag` to the same release;
- update changelog/release notes;
- keep license declarations compatible and consistent;
- set `Tested up to` only to a WordPress version actually tested;
- keep the short description concise and directory-appropriate.

Do not use `Stable Tag: trunk` for a new plugin.

## Plugin Check and manual review

Plugin Check is a required project gate when preparing a WordPress.org package,
but it is not proof that the Plugins Team will find no issue.

After Plugin Check passes, perform an independent manual review for filesystem
writes/destructive paths, capabilities/nonces, input validation/sanitization,
contextual output escaping, unique prefixes/namespaces, direct file access,
database queries, remote requests/privacy, third-party provenance/licenses,
unnecessary package files, version consistency, custom update mechanisms,
external executable/hosting assumptions, and uninstall behavior.

When a reviewer reports one occurrence, search the whole source tree for the
same class of problem.

## Test and submission gate

Before submitting or replying to a review correction:

1. validate PHP/JavaScript syntax and project tests;
2. run focused security/data/filesystem regression tests;
3. build the exact deterministic ZIP;
4. inspect its manifest and checksum;
5. install that exact ZIP on a clean WordPress installation;
6. enable `WP_DEBUG` during clean-install testing;
7. exercise activation, settings, primary functionality, deactivation, and
   uninstall behavior;
8. test supported upgrades from relevant previously released/submitted versions;
9. run Plugin Check against the exact ZIP;
10. preserve that artifact unchanged after it passes;
11. upload that same artifact to WordPress.org.

A rebuild after the final Plugin Check or clean-install test creates a new
artifact and must be revalidated.

During the initial review process, upload a complete corrected package through
the WordPress.org submission interface and reply to the existing Plugins Team
email thread. Keep the reply concise and focused on information useful to the
next review.

## WordPress.org SVN discipline

WordPress.org SVN is a release/distribution surface, not the authoritative
development repository.

After approval:

- keep current code in `trunk`;
- create a versioned tag for every release;
- set trunk `readme.txt` Stable Tag to the intended version;
- create the tag from the reviewed trunk state;
- do not modify a released tag to change code;
- make a new plugin version for code changes.

Directory artwork belongs in top-level SVN `assets/`, alongside `trunk` and
`tags`. Do not put WordPress.org icons/banners in `trunk/assets`,
`tags/<version>/assets`, or the runtime plugin's own `assets/` directory unless
the runtime genuinely uses those files.

Git/Forgejo tags, release pages, WordPress.org SVN publication, staging
installation, and production deployment are distinct operations.

## Release provenance

For projects using Forgejo as authority:

- commit and review development in Forgejo;
- use GitHub as a downstream public mirror when desired;
- treat public issue intake separately from source authority;
- build canonical release bytes once from an exact reviewed Forgejo commit;
- preserve checksums and promote the same bytes to downstream release surfaces;
- never rebuild a supposedly identical package independently for another host.

If a release package changes for any reason, it is a different artifact and
must pass the release gates again.

## AWVP 1.0 release closure

The first stable WordPress.org release is `1.0.0`, promoted from the approved
`0.3.3` codebase with release metadata changes only.

Release authority:

* stable source commit:
  `f656cdaba54fa63771187ca8b4fa6e19a20989f6`;
* canonical/public Forgejo ZIP SHA-256:
  `7bbafd11c4d1f2805cfe66bb448ddac656eecc8bb2d2d12adf23a7173225468e`;
* package size: 227964 bytes;
* installable file count: 30;
* release validator report:
  `awvp-100-wp71-pcp-r1-20260823T020938Z-14923.txt`;
* exact published base `0.3.3` SHA-256:
  `0249a1e481c5335e7dd57d6c0fcc1885d42425b9862e08cf559b112ecc04c094`.

The 1.0 validator proved the intended two-file metadata package delta, exact
published 0.3.3 -> exact 1.0.0 in-place upgrade, installed-package byte
identity, Plugin Check 2.1.0 static/new + runtime/new + runtime/update,
WordPress 7.1, DB version 2, and the AWVP `WP_DEBUG` gate.

The canonical release workflow is a pre-tag builder. Build canonical bytes from
the exact reviewed commit before creating the corresponding tag when the
workflow refuses post-tag rebuilds. Preserve and promote those exact bytes,
then independently redownload the public Forgejo asset and verify its checksum.

WordPress.org SVN identity is case-sensitive. The successful committer identity
for this plugin is `Thystra`; lowercase `thystra` reached the pre-commit hook
but lacked authorization. The rejected transaction did not invalidate the
staged tree or release artifact; correcting account case and retrying the same
commit was sufficient.

WordPress.org SVN remains a distribution surface. `trunk/` and `tags/1.0.0/`
were derived from the canonical plugin tree, while directory artwork belongs in
top-level SVN `assets/`.
