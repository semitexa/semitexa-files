# Semitexa Files

`semitexa/files`

An in-OS file manager for Semitexa OS: the **Files** app browses and opens real files and folders on the machine through the local bridge that ships with `semitexa/os`.

## Install

Not included by the installer. Add it to an existing project from the project root:

```bash
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" app composer require semitexa/files
bin/semitexa server:restart
```

It depends on `semitexa/os` (not in the installer's set); Composer installs it with it.

## What it provides

- The `Files` assistant skill, which opens the file manager dialog at `/os/app/files`.
- The dialog talks to the local bridge at `http://127.0.0.1:8777` from the browser (`/list`, `/read`, `/open`). The bridge is the desktop daemon in `semitexa/os` (`resources/os-desktop/bridge/bridged.py`); without it running, the file list cannot load.

No console commands, attributes or tables.

## License

MIT, see [LICENSE](LICENSE).
