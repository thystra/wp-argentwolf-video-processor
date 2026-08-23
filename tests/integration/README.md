# Development integration smokes

These repository-only smokes validate focused development checkpoints. They do
not replace `tests/release-validation/run.sh`, exact-package installation,
Plugin Check, upgrade coverage, or the release compatibility matrix.

## PeerTube read-only HTTP checkpoint

Run from a clean Git checkout on a disposable Docker host:

```bash
bash tests/integration/peertube-http-smoke.sh
```

The runner pulls the reviewed digest-pinned WordPress 7.1/PHP 8.3 image
(`7.1.0-php8.3-apache`), WP-CLI PHP 8.3, and MariaDB 10.11.18 images before
creating an internal-only network.
The runner requires a clean checkout, exports the exact committed Git tree, and
mounts that export read-only as the plugin. No ignored or untracked checkout
file enters the container, and no container port is published. The test
activates the plugin and calls the real WordPress safe HTTP API against an
isolated PHP fixture at the one explicitly allowed development origin,
`http://peertube.test:9000`.

The WordPress and WP-CLI containers receive the same debug and isolated-network
configuration. The runner verifies the exact WordPress version and configuration
before activating the plugin so configuration failures remain distinguishable
from product failures.

By default, the preserved report is created under `/tmp`. To choose another
location outside the repository, set an absolute directory:

```bash
AWVP_R33_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-http-smoke.sh
```

The test requires Docker access for the invoking account and network access to
pull the pinned images. Runtime containers themselves have no external route.
It covers only the R33 public `/api/v1/config` detection boundary. It does not
test TLS, credentials, tokens, refresh/revocation, uploads, a real PeerTube
server, a built plugin ZIP, or release/upgrade behavior.
