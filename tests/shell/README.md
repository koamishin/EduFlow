# Shell tests

Behavioural tests for the self-hosting scripts at the repository root.

## install.test.sh

Exercises `install.sh` end-to-end without touching Docker, the network or any
container: every command the installer shells out to (`docker`, `curl`, `sudo`,
`sh`, `systemctl`, `apt-mark`) is replaced by a stub that logs its calls, and
each scenario runs in a throwaway temp directory.

```bash
bash tests/shell/install.test.sh
```

Covered flows:

- fresh install with `--yes` — image-pull and local-build variants, and a
  pull failure that must stop before any service is created
- Swarm objects: the overlay network, the database secret, the three services,
  the published port, health flags and the PostgreSQL readiness wait
- interactive input under a pty for both `bash install.sh` and `curl … | bash`,
  piped answers, `curl … | bash` with no terminal, and the fish shell pipe form
- `--update` (service roll plus PostgreSQL backup) and `--update` without an
  existing service
- re-running the installer on an existing stack: services are removed and
  recreated while the password, secret and network are kept
- SQLite (no database service, no secret), an LXC host
  (`--endpoint-mode dnsrr`), and a host where Docker requires `sudo`
- Docker Engine auto-install on a host without Docker, executed against a
  minimal `PATH` so the real Docker on the machine stays invisible:
  the get.docker.com path for Debian-family hosts (pinned `DOCKER_VERSION`,
  `apt-mark hold`), the `pacman` path for Arch-family hosts, the distribution
  fallback when the convenience script fails, and the clear error on a
  distro the installer cannot recognize
- the container entrypoint's `DB_PASSWORD_FILE` handling, used by the Swarm
  secret

Tests needing util-linux `script`/`setsid`, or `timeout`/`gtimeout`, skip
themselves when those tools are missing (macOS without `gtimeout`, for
example). Likewise the fish test skips when `fish` is not installed, and the
Docker auto-install test is Linux-only.
