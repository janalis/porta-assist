# Assist: working together, live

The bundled `assist` plugin lets several people work in one project at
the same time, the way VS Code Live Share and PhpStorm Code With Me do.
One person, the **host**, shares the project they have open. Others, the
**guests**, join from their own Porta. Everyone edits the same files at once
and sees each other's carets and selections. Anyone can follow anyone.

The host's copy is the one that counts:

- A guest needs no checkout of their own.
- A guest's saves and file operations are asked of the host, who carries them out.

Enable it under **Settings ▸ Plugins**, or add `"assist"` to `enabledPlugins`
in `.porta.json`.

## Sharing a project

1. Run **Assist: Share This Project** from the palette or the Plugins menu.
2. Porta starts listening and copies the invite to the clipboard, for example
   `porta assist 127.0.0.1:52814#4f3a….9c1e…`. Send it to the people you want in.
3. When someone joins, the host is asked: **Let them edit**, **Let them read
   along**, or **Turn them away**.

While sharing, the status bar shows `⇄` and how many are in. Click it to:

- follow someone;
- copy the invite again;
- open **Participants**, where the host can change what a guest may do or
  remove them;
- stop sharing.

**Assist: Stop Sharing** ends the session for everyone.

**The session is encrypted.** Each share makes a fresh TLS certificate, and
the invite carries its fingerprint after the dot. A guest only talks to the
host whose certificate matches, so nobody on the way can read the session or
stand in for the host. The token before the dot is what gets a guest asked
in at all, so share the invite only with the people you mean to let in.

By default the host still only accepts connections **from its own machine**
(`127.0.0.1`), so opening it to a network is a choice you make. To work across
machines, use one of these:

- **An SSH tunnel.** The guest runs `ssh -L 52814:127.0.0.1:52814 host`, then
  joins `127.0.0.1:52814#…`.
- **A VPN such as Tailscale.** Set **Accept guests from** to `0.0.0.0` and
  share the host's VPN address.
- **A relay.** When neither side can reach the other (both behind a router,
  say), anyone can run `porta assist-relay` on a machine both can reach. It
  listens on port 7420 (`--port` changes it, `--bind` the address). Set
  **Relay to share through** to that machine's `address:port`, then share.
  The invite then names the relay and the session, as
  `relay.example.com:7420/6b1f…#…`, and guests join it the usual way.

  The relay only joins the two connections and passes bytes between them.
  The encryption runs from the guest all the way to the host, checked against
  the fingerprint in the invite, so the relay sees neither the code nor the
  token, and it cannot stand in for the host. If the relay stops, everyone
  who came in through it is cut off and the host is told. Share again for a
  new invite once it is back.

## Joining

Run the invite in a terminal:

```
porta assist 127.0.0.1:52814#4f3a….9c1e…
```

If the host on the other end presents a different certificate, Porta refuses
to join and says so.

Add `--name` to choose the name the others see. Without it, Porta uses your
login name.

1. The host is asked to let you in.
2. Once you are in, a copy of the host's project is brought up to date under
   `~/.porta/assist/`.
3. Porta opens on that copy, connected to the session.

What the copy holds:

- **Which files.** What git shows (tracked files, and untracked ones that are
  not ignored), plus `vendor/` so your own language server can resolve the
  dependencies. Outside a git repository, every file except `node_modules/`.
- **What stays with the host.** The host's `.git/`, `.porta/` and `.porta.json`
  are never sent.
- **Joining again.** It fetches only what changed since last time.

**Assist: Join a Session…** inside Porta copies the command for you to run.
A session opens in its own Porta window because it is its own project.

In a session, as a guest:

- **Opening a file** asks the host for it. The host's text, unsaved edits
  included, replaces what your copy had.
- **What you type** reaches everyone who has the file open, and theirs
  reaches you.
- **Saving** asks the host to save, so the file lands on the host's disk.
  Everyone with the same text is marked saved too.
- **Creating, renaming or deleting a file** asks the host to do it. Files that
  change on the host, through a save, a checkout or a pull, are fetched again.
- **Reading along** (when the host chose read-only) makes the shared files
  refuse your keystrokes, with a message saying why. The caret, selection and
  copy still work.
- **If the connection drops**, the shared files go read-only and Porta keeps
  trying to reconnect. When it gets back in, it carries on from the host's copy.
- **Assist: Leave the Session** ends it for you. The copy stays on disk for you
  to keep.
- **Closing Porta** keeps the session. Opening the copy again rejoins it, as
  long as the host is still sharing.

## Language servers

A guest needs no language server of their own. Each language the host has
a server for is served to the guest by the host. When the guest's editor
starts one, Porta runs `porta assist-lsp <language>` in its place. That
bridge asks the host, which starts its own server for the language, with its
own settings, on its own files.

Go-to-definition, hover, completion and diagnostics all answer from the
host's project. The file names are moved between the guest's copy and the
host's project on the way, so a jump lands in the guest's copy.

A guest reading along may not run the server's commands, because what they
do, they do to the host's files. A guest's servers stop when they leave or are
removed, and when sharing stops.

## Sharing a terminal

**Assist: Share a Terminal** lets the host pick one of their terminal tabs:
a shell, or a run. Every guest gets a tab for it, marked `⇄`, showing what it
shows now and then what it prints. Guests who join later get it too. Picking
the same terminal again stops sharing it. The guests' tabs stay open, marked
"(ended)".

**A shared terminal is read-only for guests by default.** Typing into it is
the host's shell running whatever is typed, so the host lets each guest type
separately: in **Participants**, pick the guest, then **Let them type in
shared terminals** (or **Stop them typing**). Until then, a key typed in the
tab is refused with a message saying so.

## Running the host's configurations

**Assist: Run on the Host…** lists the host's run configurations: the current
file, the composer scripts, the Makefile targets. A guest picks one and it
runs on the host, in the host's terminal panel. Its terminal is shared, so
everyone sees what it prints, and the host is told who ran it.

**Guests cannot run anything by default.** A run executes on the host's
machine, so the host lets each guest separately: in **Participants**, pick the
guest, then **Let them run the run configurations** (or **Stop them running**).
A guest only ever picks a configuration the host offers. It cannot send a
command of its own.

## Debugging together

When the host debugs, every guest is told where the debugger stopped and gets
a toast. Guests who join while it is paused are told too. **Assist: Host's
Debugger** shows the host's stack, with **Resume Program**, **Step Over**,
**Step Into** and **Step Out** above it. While the program runs, it offers
**Pause Program** instead. Picking a frame opens its file at its
line and lists its variables. A variable that holds more, an array or an
object, is marked `…`, and picking it lists what it holds. Picking any other
variable copies its value.

Any guest can look. **Stepping is off by default**, because it drives a
program on the host's machine. The host lets each guest separately: in
**Participants**, pick the guest, then **Let them step the debugger** (or
**Stop them stepping**). A guest following the host already sees each stop,
since the host's editor goes to the stopped line.

## Sharing a server

A project's dev server (`php -S localhost:8000`, `symfony serve`) runs on
the host. **Assist: Share a Server** asks the host for its port. Every guest
can then reach it on their own machine: a toast gives the address, for
example `http://127.0.0.1:8000`. The guest gets the same port number when it
is free on their machine, and another one when it is not. **Assist: Shared
Servers** lists the shared servers and copies an address.

Each connection a guest opens there is carried through the session, so it is
encrypted like the rest. It reaches only the port the host shared, on the
host's own machine: a guest cannot name another port or another address.
Guests who join later get the shared servers too. Running **Assist: Share a
Server** again lists what is shared: pick one to stop sharing it, which closes
its connections. Any guest, reading along or editing, can use a shared
server, so share only what you would let them use.

## Carets, undo and following

- **Carets and selections.** Everyone's caret shows in their own colour, with
  their name at the end of the line and their selection tinted. A file someone
  else is in gets a `✦` on its tab and tree row.
- **Agents count as participants.** An agent at work on either side, such as
  one connected through the [mcp plugin](../../mcp/docs/README.md), shows the same way.
  **Participants** lists it, and you can follow it.
- **Undo is your own.** <kbd>Ctrl+Z</kbd> takes back only your own changes,
  even when others have typed since.
- **Following someone.** **Assist: Follow Someone**, or a name in the status
  bar's menu, makes your editor go wherever that person's caret goes, across
  files too, scrolled to show what they see. Moving your own caret stops
  following.
- **Asking everyone to follow you.** **Assist: Ask Everyone to Follow Me**
  (also in the status bar's menu) works from the host or any guest. Everyone
  else starts following you and is taken to where you are straight away,
  with a toast saying who asked. Each of them stops following the same way:
  by moving their own caret.

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| Your name in a session | your login name | The name the others see beside your caret |
| Accept guests from | `127.0.0.1` | `127.0.0.1`: this machine only (reach it through a tunnel or VPN). `0.0.0.0`: the whole network |
| Port to share on | `0` | `0` picks a free port each time |
| Relay to share through | empty | `host:port` of a `porta assist-relay` for guests who cannot reach this machine. Empty: guests connect directly |
| Most guests at once | `8` | More can join than there are caret colours. Past eight, the colours repeat |
| Largest file to share (MB) | `4` | Bigger files stay on the host |

## Limits

- **Each share is a new invite.** Its certificate and token last as long as
  that share: after **Assist: Stop Sharing**, an old invite no longer works.
- **Eight guests by default.** **Most guests at once** raises it. The editor
  has eight caret colours, so past eight the colours repeat. The name beside
  each caret still tells people apart.
