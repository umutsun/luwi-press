"""
Luwi Agent Gateway — LuwiPress Agentic plugin bridge to OpenAI.

Serves the wire format the WP `luwipress-agentic` plugin's HTTP adapter speaks
(`POST /agent`), proxying to OpenAI Chat Completions with tool-calling.

Deployed at hermes.luwi.dev (nginx → 127.0.0.1:3140) via the
`luwi-agent-gateway.service` systemd unit. Env comes from
/etc/luwi/agentic.env (OPENAI_API_KEY, LUWI_AGENT_GATEWAY_TOKEN, LUWI_AGENT_MODEL).

Wire contract (must match includes/adapters/class-http-adapter.php):

  REQUEST  (POST /agent, Bearer LUWI_AGENT_GATEWAY_TOKEN):
    { "messages": [ {role,content,(tool_call_id),(tool_calls)} ],
      "context":  { site_name, products, ... },
      "tools":    [ {type:"function", function:{name,description,parameters}} ] }

  RESPONSE (HTTP 200):
    { "response": "<assistant text>",
      "tool_calls": [ {action:"<tool>", params:{...}, id:"call_..."} ] }

The PHP adapter FILTERS returned tool_calls to those with a non-empty `action`,
then call_ai() reads action/params — so we MUST emit `action` (not
`function_name`). On the next turn the plugin echoes the assistant message back
with its `tool_calls:[{action,params,id}]` plus `tool` messages; we translate
those back into OpenAI's native shape so the model keeps its tool-call state.
"""

import json
import os

import requests
from fastapi import FastAPI, Header, HTTPException, Request

GATEWAY_TOKEN = os.environ.get("LUWI_AGENT_GATEWAY_TOKEN", "").strip()
OPENAI_API_KEY = os.environ.get("OPENAI_API_KEY", "").strip()
MODEL = os.environ.get("LUWI_AGENT_MODEL", "gpt-4o-mini").strip() or "gpt-4o-mini"
OPENAI_URL = "https://api.openai.com/v1/chat/completions"
REQUEST_TIMEOUT = 90

app = FastAPI(title="Luwi Agent Gateway", version="1.0.0")


def build_system_prompt(context: dict) -> str:
    """Ground the assistant in the store. Brand as 'Luwi' (never expose the
    underlying model name in user-facing replies)."""
    site = ""
    if isinstance(context, dict):
        site = str(context.get("site_name") or context.get("name") or "").strip()
    lines = [
        "You are Luwi, an AI assistant that helps the store owner operate their "
        "WooCommerce store conversationally. Be concise, friendly and concrete.",
        "When the operator asks for store data or an action, prefer calling one "
        "of the provided tools over guessing. Only call read-only tools "
        "autonomously. Summarise tool results in plain language.",
        "Never reveal internal model, vendor or tool implementation names — you "
        "are simply 'Luwi'.",
    ]
    if site:
        lines.append(f"The store is '{site}'.")
    if isinstance(context, dict) and context:
        try:
            ctx_json = json.dumps(context, ensure_ascii=False)[:4000]
            lines.append("Store context (JSON): " + ctx_json)
        except Exception:
            pass
    return "\n".join(lines)


def to_openai_messages(messages, context) -> list:
    """Translate the plugin's message list into OpenAI's chat format, including
    bidirectional tool-call mapping for multi-turn tool use."""
    out = [{"role": "system", "content": build_system_prompt(context)}]
    for m in messages if isinstance(messages, list) else []:
        if not isinstance(m, dict):
            continue
        role = m.get("role", "")
        content = m.get("content", "") or ""

        if role == "assistant" and m.get("tool_calls"):
            oai_tcs = []
            for tc in m["tool_calls"]:
                if not isinstance(tc, dict):
                    continue
                name = tc.get("action") or tc.get("function_name") or ""
                args = tc.get("params", tc.get("arguments", {}))
                args_str = args if isinstance(args, str) else json.dumps(args or {})
                oai_tcs.append({
                    "id": tc.get("id") or "call_x",
                    "type": "function",
                    "function": {"name": name, "arguments": args_str},
                })
            msg = {"role": "assistant", "content": content}
            if oai_tcs:
                msg["tool_calls"] = oai_tcs
            out.append(msg)
        elif role == "tool":
            out.append({
                "role": "tool",
                "tool_call_id": m.get("tool_call_id") or "call_x",
                "content": content if isinstance(content, str) else json.dumps(content),
            })
        elif role in ("user", "assistant", "system"):
            out.append({"role": role, "content": content})
    return out


@app.get("/health")
def health():
    return {
        "status": "ok",
        "service": "luwi-agent-gateway",
        "model": MODEL,
        "openai_configured": bool(OPENAI_API_KEY),
        "auth_required": bool(GATEWAY_TOKEN),
    }


@app.post("/agent")
async def agent(request: Request, authorization: str = Header(None)):
    if GATEWAY_TOKEN:
        if authorization != f"Bearer {GATEWAY_TOKEN}":
            raise HTTPException(status_code=401, detail="invalid or missing token")
    if not OPENAI_API_KEY:
        raise HTTPException(status_code=503, detail="OPENAI_API_KEY not configured on gateway")

    try:
        body = await request.json()
    except Exception:
        raise HTTPException(status_code=400, detail="invalid JSON body")

    # Cheap connectivity+auth probe: the plugin's "Test connection" button POSTs
    # {"ping": true} so it can confirm the endpoint + token are live WITHOUT
    # spending an OpenAI call. Auth was already enforced above, so reaching here
    # means the token is valid.
    if body.get("ping") is True:
        return {"pong": True, "model": MODEL, "openai_configured": bool(OPENAI_API_KEY)}

    messages = body.get("messages", [])
    context = body.get("context", {})
    tools = body.get("tools", [])

    payload = {
        "model": MODEL,
        "messages": to_openai_messages(messages, context),
        "temperature": 0.3,
    }
    if isinstance(tools, list) and tools:
        payload["tools"] = tools  # already OpenAI function-tool format
        payload["tool_choice"] = "auto"

    try:
        r = requests.post(
            OPENAI_URL,
            headers={
                "Authorization": f"Bearer {OPENAI_API_KEY}",
                "Content-Type": "application/json",
            },
            json=payload,
            timeout=REQUEST_TIMEOUT,
        )
    except requests.RequestException as exc:
        return {"response": f"(Luwi gateway could not reach the model: {exc})", "tool_calls": []}

    if r.status_code != 200:
        snippet = (r.text or "")[:300]
        return {
            "response": f"(Luwi gateway: model returned HTTP {r.status_code})",
            "tool_calls": [],
            "error": snippet,
        }

    data = r.json()
    try:
        choice = data["choices"][0]["message"]
    except (KeyError, IndexError, TypeError):
        return {"response": "(Luwi gateway: empty model response)", "tool_calls": []}

    response_text = choice.get("content") or ""
    tool_calls = []
    for tc in (choice.get("tool_calls") or []):
        fn = tc.get("function", {}) if isinstance(tc, dict) else {}
        raw_args = fn.get("arguments") or "{}"
        try:
            params = json.loads(raw_args)
        except Exception:
            params = {}
        tool_calls.append({
            "action": fn.get("name", ""),
            "params": params,
            "id": tc.get("id", ""),
        })

    return {"response": response_text, "tool_calls": tool_calls}
