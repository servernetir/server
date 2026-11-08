# Jupyter Server Proxy startup candidate

`startup-command.json` is an operator-reviewed command override for the pinned
Docker Stacks image. Its exact linux/amd64 OCI configuration was checked: the
original entrypoint is `tini -g -- start.sh`, with CMD `start-notebook.py`.
Salad replaces both ENTRYPOINT and CMD, so the override explicitly preserves
that entrypoint before running the bootstrap. It ensures `jupyter-server-proxy==4.6.0` is installed,
registers the server extension **before** Jupyter starts, then replaces the
wrapper with the image's normal `start-notebook.py` launcher. Existing token,
network, user and launcher settings are inherited. Authentication is not disabled.

This candidate is **not deployed** and is not automatically selected by the
service factory. Validate the exact image/entrypoint before applying it to a
stopped deployment; a local test is not production acceptance.

Do not execute the command in a running customer's Jupyter terminal, kill its
main process, or assume that stop/start preserves the filesystem. Confirm the
deployment identity, file/backup situation, startup behavior and authorized
runtime budget first. Preserve the previous image, command and other settings
privately for rollback. No OCR model or application package is installed here.

The conditional install leaves an already matching proxy version alone. A failed
install or registration exits before Jupyter is launched. Installation needs
ordinary package-index access and can consume startup time; validate it offline
before starting a billable deployment when possible.

## Local integration test

In a disposable virtual environment:

```sh
python -m pip install jupyter-server==2.21.1 jupyter-server-proxy==4.6.0
python relay/jupyter/test_startup.py
```

The test reproduces a missing handler with the installed extension unloaded,
executes the actual candidate command with local entrypoint/launcher shims, and uses real
Jupyter HTTP proxy/authentication. It checks GET, Persian JSON POST, invalid-token
rejection, entrypoint argument forwarding and preservation of an application's own 404. It has no GPU, provider
credentials or customer files. Docker image entrypoint, public TLS, gateway,
relay, billing, timeouts and effective upload limits still require a separately
authorized production test followed by verified service stop.

References:

- https://docs.salad.com/container-engine/how-to-guides/specifying-a-command
- https://jupyter-server-proxy.readthedocs.io/en/latest/arbitrary-ports-hosts.html
- https://jupyter-docker-stacks.readthedocs.io/en/latest/using/common.html
