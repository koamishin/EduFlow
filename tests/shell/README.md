# Shell tests

Behavioural tests for the self-hosting scripts at the repository root.

## install.test.sh

Exercises `install.sh` end-to-end without touching Docker, the network or any
container: `docker` and `curl` are replaced by stubs that log their calls, and
every scenario runs in a throwaway temp directory.

```bash
bash tests/shell/install.test.sh
```

Covered flows: fresh install with `--yes` (image-pull and local-build
variants), interactive input under a pty for both `bash install.sh` and
`curl … | bash`, piped answers, `curl … | bash` with no terminal, `--update`,
daemon-unreachable, compose-download failure, and `--help`.

Tests needing util-linux `script`/`setsid`, or `timeout`/`gtimeout`, skip
themselves when those tools are missing (macOS without `gtimeout`, for
example).
