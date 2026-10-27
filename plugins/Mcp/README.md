# mcp

An MCP server at `/admin/mcp`, for agents such as Claude Code. Enable it with `SATISFY_PLUGINS=mcp`.

It is served by Symfony's MCP bundle (`symfony/mcp-bundle`, on the official PHP MCP SDK) over Streamable HTTP, and the plugin enables that bundle with it. The bundle is a beta and outside Symfony's backward-compatibility promise.

## Tools

| Tool | What it answers |
|---|---|
| `repositories` | The repositories in `satis.json`, in its order: each one's id, type and URL. User information in a URL, such as `https://token@host/`, is replaced with `***`; an scp-style `git@host:path` is left as it is |
| `build_status` | Whether the worker is running a build, how many requests wait in the queue, and the end of the last build's output (`logBytes`, default 8192, at most 65536) |

Both are read-only. Nothing the plugin exposes writes `satis.json`, queues a build, or touches `authz`.

## Configuration

| Variable | Meaning |
|---|---|
| `SATISFY_MCP_TOKEN` | Required. A client sends it as `Authorization: Bearer <token>`. While it is unset or empty, every request to `/admin/mcp` is answered 401 |

The token is checked by a `kernel.request` listener before Satisfy's firewall, whatever `admin.auth` says, because the admin sign-in is a form that an MCP client cannot use.

The endpoint is under `/admin`, so with `authz` enabled nginx passes it to PHP without the catalogue's check.

The bundle accepts only requests addressed to `localhost` until its `allowed_hosts` lists the public hostname. The plugin does not set it yet, so this is a local-only server for now.

## Connecting Claude Code

With the development container from `docker-compose.yml`:

```
SATISFY_PLUGINS=mcp SATISFY_MCP_TOKEN=<token> docker compose up -d
claude mcp add --transport http --scope local satisfy http://localhost:8000/admin/mcp --header "Authorization: Bearer <token>"
```

A session started after that has the two tools.

## Data

None. The MCP session store is the bundle's default, files under the kernel's cache directory.
