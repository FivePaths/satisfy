# Demo: an MCP server and build diagnosis in Satisfy

Satisfy with the [`mcp`](../plugins/Mcp/README.md) and [`diagnosis`](../plugins/Diagnosis/README.md) plugins enabled, and a `satis.json` with a mistake in it. The first build fails, a Symfony AI agent works out why from the redacted build log, and an MCP client reads the answer from Satisfy's MCP server. Fixing the mistake and building again publishes two packages.

## What you need

- Docker with Compose v2.
- An MCP client: Claude Code, or the MCP Inspector (`npx @modelcontextprotocol/inspector`, which needs Node.js).
- A model that can call tools, behind an OpenAI-compatible endpoint. A Hugging Face token works with the settings in `.env.example`: create a fine-grained token at huggingface.co, under Settings, Access Tokens, with "Make calls to Inference Providers" ticked. An Ollama on the same machine should work too, with no key, but has not been tried.

## Run it

1. Clone the branch and configure the demo:

   ```bash
   git clone --branch 4.x-mcp https://github.com/FivePaths/satisfy.git
   cd satisfy/demo
   cp .env.example .env
   ```

   In `.env`, set `SATISFY_MCP_TOKEN` to a random string (`openssl rand -hex 32` makes one) and `SATISFY_DIAGNOSIS_API_KEY` to your Hugging Face token, or switch the model settings to Ollama.

2. Start Satisfy:

   ```bash
   docker compose up -d
   ```

   This pulls `ghcr.io/fivepaths/satisfy:4.x-mcp`. `docker compose up -d --build` builds the same image from your checkout instead, which takes a few minutes.

3. Build. Open http://localhost:8000/admin/satis/build and queue a full build. It fails within seconds, and the diagnosis follows a few seconds later.

4. Read the diagnosis over MCP. The server is at `http://localhost:8000/admin/mcp`, and every request carries `Authorization: Bearer <your SATISFY_MCP_TOKEN>`.

   - Claude Code:

     ```bash
     claude mcp add --transport http satisfy-demo http://localhost:8000/admin/mcp --header "Authorization: Bearer <your SATISFY_MCP_TOKEN>"
     ```

     Then, in a new Claude Code session, ask it to read the resource `satisfy://build/diagnosis` and to call the `build_status` tool.
   - MCP Inspector: run `npx @modelcontextprotocol/inspector`, choose the Streamable HTTP transport, enter the server's URL, and add the `Authorization` header. The diagnosis is under Resources; `repositories` and `build_status` are under Tools.
   - Without a client, the same JSON is in the container: `docker compose exec satisfy cat var/diagnosis/last.json`.

5. Fix and build again. Correct the line the diagnosis points to in `demo/config/satis.json`, which the container reads on every build, and queue another build from the same page. It succeeds, and http://localhost:8000 lists `psr/container` and `psr/log`.

6. Stop it, and remove its data:

   ```bash
   docker compose down -v
   ```

## What is in it

| File | What it holds |
|---|---|
| `compose.yaml` | One service: the image, its port, `config/` mounted with `SATIS_CONFIG` pointing into it, and the plugins' settings from `.env` |
| `config/satis.json` | Two public repositories from PHP-FIG, `psr/container` and `psr/log`, and a requirement Satis cannot parse |
| `.env.example` | The MCP token and the model settings, for Hugging Face, Ollama or OpenRouter |

Nothing here needs credentials for the repositories: both are public, and Satis clones them over HTTPS.

## What to look at

- The diagnosis names the requirement at fault, quotes the lines of the log that show it, and says what to change. It was produced by an agent with three tools over the redacted log and `satis.json`, in a process that had no Composer credentials. Tested with `openai/gpt-oss-120b` on Hugging Face's router, where it answered in a few seconds.
- The MCP server runs inside Satisfy, from Symfony's MCP bundle, with read-only tools; its token is checked before Satisfy's own firewall.
- `var/diagnosis/` in the container keeps each failed build's redacted log, the diagnosis, and the output of the process that asked.
