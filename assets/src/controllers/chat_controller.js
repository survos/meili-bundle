import { Controller } from "@hotwired/stimulus"

export default class extends Controller {

  static targets = ["messages", "input", "detailBody", "detailHeader", "typing", "sendBtn"]

  static values = {
    streamUrl:   String,
    templateUrl: String,
    schemaUrl:   String,
    indexName:   String,
    primaryKey:  { type: String, default: "id" },
    meiliHost:   String,
    meiliApiKey: String,
  }

  connect() {
    this.history      = []
    this.marked       = null
    this._engine      = null
    this._engineReady = null
    this._twigBlock   = null
    this._twigSource  = null
    this._renderTimer = null

    this._loadMarked()
    this._ensureTwigEngine().then(() => this._ensureTemplate())

    console.log("chat controller connected")
  }

  disconnect() {
    clearTimeout(this._renderTimer)
  }

  // ── Auto-resize textarea ──────────────────────────────────────────────────
  autoResize() {
    const ta = this.inputTarget
    ta.style.height = "auto"
    ta.style.height = Math.min(ta.scrollHeight, 120) + "px"
  }

  suggest(event) {
    this.inputTarget.value = event.currentTarget.innerText.trim()
    this.autoResize()
    this.send()
  }

  keydown(event) {
    if (event.key === "Enter" && !event.shiftKey) {
      event.preventDefault()
      this.send()
    }
  }

  clearHistory() {
    this.history = []
    Array.from(this.messagesTarget.querySelectorAll(".chat-bubble, .chat-sources, .chat-search-note"))
      .forEach(el => el.remove())
  }

  // ── Marked ───────────────────────────────────────────────────────────────
  async _loadMarked() {
    try {
      const mod = await import("marked")
      this.marked = mod.marked
      this.marked.use({ breaks: true, gfm: true })
    } catch (e) {
      console.warn("[chat] marked not available:", e.message)
    }
  }

  // ── JS-Twig engine ────────────────────────────────────────────────────────
  async _ensureTwigEngine() {
    if (this._engine) return this._engine
    try {
      const { createEngine } = await import("@tacman1123/twig-browser")
      this._engine = createEngine()
      this._engine.registerFunction("sais_encode", (url) =>
        btoa(url).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "")
      )
      this._engineReady = import("@survos/js-twig/generated/fos_routes.js")
        .then(({ path }) => this._engine.registerFunction("path", path))
        .catch(() => {})
    } catch (e) {
      console.warn("[chat] JS-Twig engine unavailable:", e)
    }
    return this._engine
  }

  async _ensureTemplate() {
    if (this._twigBlock !== null && this._twigBlock !== undefined) return this._twigBlock
    const engine = await this._ensureTwigEngine()
    if (!engine) return null
    try {
      const { loadTemplateFromUrl } = await import("@tacman1123/twig-browser")
      const result = await loadTemplateFromUrl(engine, this.templateUrlValue)
      this._twigBlock  = result.blockName
      this._twigSource = result.source
    } catch (e) {
      console.error("[chat] template load failed:", e)
      this._twigBlock = null
    }
    return this._twigBlock
  }

  // ── Detail panel ──────────────────────────────────────────────────────────
  async showDetail(docId, label) {
    if (!this.hasDetailHeaderTarget || !this.hasDetailBodyTarget) return

    this.detailHeaderTarget.textContent = label || String(docId)
    this.detailBodyTarget.innerHTML = '<div class="text-muted small p-3">Loading…</div>'

    let doc
    try {
      const headers = { "Content-Type": "application/json" }
      if (this.meiliApiKeyValue) headers["Authorization"] = "Bearer " + this.meiliApiKeyValue
      const r = await fetch(
        `${this.meiliHostValue}/indexes/${this.indexNameValue}/documents/${encodeURIComponent(docId)}`,
        { headers }
      )
      if (!r.ok) throw new Error(`HTTP ${r.status}`)
      doc = await r.json()
    } catch (e) {
      this.detailBodyTarget.innerHTML =
        `<div class="alert alert-danger m-3">Could not fetch document <code>${docId}</code>: ${e.message}</div>`
      return
    }

    const engine    = await this._ensureTwigEngine()
    const blockName = await this._ensureTemplate()

    if (!engine || !blockName) {
      this.detailBodyTarget.innerHTML = this._renderFallback(null, doc, null)
      return
    }

    try {
      const globals = {
        serverUrl:    this.meiliHostValue,
        serverApiKey: this.meiliApiKeyValue,
        indexName:    this.indexNameValue,
        _sc_modal:    "@survos/meili-bundle/json",
        debug:        false,
        details:      true,
        chatBaseUrl:  "",
        searchBaseUrl: "",
      }
      const ctx = {
        hit:           doc,
        globals,
        _config:       { primaryKey: this.primaryKeyValue, details: true },
        _score:        doc._rankingScore ?? null,
        _scoreDetails: doc._rankingScoreDetails ?? null,
        _isSemantic:   false,
        icons:         {},
        _sc_modal:     "@survos/meili-bundle/json",
        hints:         {},
        view:          {},
      }
      this.detailBodyTarget.innerHTML = engine.renderBlock(blockName, ctx)
    } catch (e) {
      console.error("[chat] render error:", e)
      this.detailBodyTarget.innerHTML = this._renderFallback(e, doc, this._twigSource)
    }
  }

  _renderFallback(err, doc, tmplSource) {
    const esc = s => String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    let html = ""
    if (err) {
      html += `<div style="background:#dc3545;color:#fff;padding:.75rem 1rem;font-weight:700;font-size:.9rem;">
        ⚠ JS-Twig render error: ${esc(err.message)}</div>
        <pre style="background:#fff3f3;color:#7a0000;font-size:.7rem;padding:.75rem;margin:0;white-space:pre-wrap">${esc(err.stack ?? "")}</pre>`
    } else {
      html += `<div class="alert alert-warning m-2 mb-0">JS-Twig engine unavailable — showing raw JSON.</div>`
    }
    html += `<details open style="margin:0">
      <summary style="padding:.5rem .75rem;font-size:.75rem;font-weight:600;cursor:pointer;background:#f8f9fa">Raw JSON</summary>
      <pre style="font-size:.7rem;padding:.75rem;margin:0;white-space:pre-wrap;max-height:300px;overflow:auto">${esc(JSON.stringify(doc, null, 2))}</pre>
    </details>`
    if (tmplSource) {
      html += `<details style="margin:0;border-top:1px solid #dee2e6">
        <summary style="padding:.5rem .75rem;font-size:.75rem;font-weight:600;cursor:pointer;background:#f8f9fa">Raw Twig template</summary>
        <pre style="font-size:.7rem;padding:.75rem;margin:0;white-space:pre-wrap;max-height:300px;overflow:auto">${esc(tmplSource)}</pre>
      </details>`
    }
    return html
  }

  // ── Bubble helpers ────────────────────────────────────────────────────────
  _scrollBottom() {
    this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight
  }

  _addBubble(role, text) {
    const div = document.createElement("div")
    div.className = "chat-bubble " + role
    div.textContent = text
    this.messagesTarget.appendChild(div)
    this._scrollBottom()
    return div
  }

  _setTyping(on) {
    if (this.hasTypingTarget) this.typingTarget.classList.toggle("d-none", !on)
  }

  _setBusy(on) {
    if (this.hasSendBtnTarget) this.sendBtnTarget.disabled = on
    this.inputTarget.readOnly = on
  }

  // ── Markdown ──────────────────────────────────────────────────────────────
  _injectDetailButtons(markdown) {
    return markdown.replace(/\[id:([^\]]+)\]/g, (_, docId) => {
      const id = docId.trim()
      return `<button class="btn btn-sm btn-outline-primary ms-1 py-0 px-2 chat-show-detail" data-doc-id="${id}" style="font-size:.75rem;vertical-align:middle">Show details</button>`
    })
  }

  _renderMarkdown(bubble, markdown) {
    bubble.classList.remove("streaming")
    if (!this.marked) {
      bubble.textContent = markdown
      return
    }
    const processed = this._injectDetailButtons(markdown)
    try {
      bubble.innerHTML = this.marked.parse(processed)
    } catch (e) {
      bubble.textContent = markdown
      return
    }
    bubble.querySelectorAll("a").forEach(a => {
      a.target = "_blank"
      a.rel = "noopener noreferrer"
    })
  }

  _scheduleRender(bubble, text) {
    clearTimeout(this._renderTimer)
    this._renderTimer = setTimeout(() => {
      bubble.classList.add("streaming")
      this._renderMarkdown(bubble, text)
    }, 120)
  }

  // ── Sources block ─────────────────────────────────────────────────────────
  _addSourcesBlock(documents) {
    const docs = Array.isArray(documents) ? documents : Object.values(documents)
    if (!docs.length) return

    const details = document.createElement("details")
    details.className = "chat-sources"

    const summary = document.createElement("summary")
    summary.textContent = `${docs.length} source${docs.length > 1 ? "s" : ""} from Meilisearch`
    details.appendChild(summary)

    const list = document.createElement("div")
    list.className = "chat-sources-list"

    docs.forEach((doc, i) => {
      const pk    = doc[this.primaryKeyValue] ?? doc.meili_id ?? doc.id ?? doc.sku ?? doc.code ?? doc._id ?? Object.values(doc)[0] ?? i
      const title = doc.title ?? doc.name ?? doc.label ?? `#${pk}`
      const btn   = document.createElement("button")
      btn.className = "btn btn-sm btn-outline-primary btn-show-detail"
      btn.textContent = String(title).slice(0, 32)
      btn.title = `Show details for: ${title}`
      btn.dataset.docId    = String(pk)
      btn.dataset.docTitle = String(title).slice(0, 50)
      btn.addEventListener("click", () => this.showDetail(btn.dataset.docId, btn.dataset.docTitle))
      list.appendChild(btn)
    })
    details.appendChild(list)

    const jsonDetails = document.createElement("details")
    jsonDetails.style.marginTop = ".35rem"
    const jsonSummary = document.createElement("summary")
    jsonSummary.style.cssText = "font-size:.68rem;color:#6c757d;cursor:pointer"
    jsonSummary.textContent = "Raw JSON"
    const pre = document.createElement("pre")
    pre.className = "chat-sources-json"
    pre.textContent = JSON.stringify(docs, null, 2)
    jsonDetails.appendChild(jsonSummary)
    jsonDetails.appendChild(pre)
    details.appendChild(jsonDetails)

    this.messagesTarget.appendChild(details)

    // Auto-show first doc in detail panel
    if (docs.length > 0) {
      const first = docs[0]
      const pk    = first[this.primaryKeyValue] ?? first.meili_id ?? first.id ?? first.sku ?? first.code ?? first._id ?? Object.values(first)[0]
      const title = first.title ?? first.name ?? first.label ?? `#${pk}`
      this.showDetail(String(pk), String(title).slice(0, 50))
    }

    this._scrollBottom()
  }

  // ── Send ──────────────────────────────────────────────────────────────────
  async send() {
    const text = this.inputTarget.value.trim()
    if (!text) return

    this.inputTarget.value = ""
    this.autoResize()
    this._setBusy(true)
    this._setTyping(true)

    this._addBubble("user", text)
    this.history.push({ role: "user", content: text })

    const bubble    = this._addBubble("assistant", "")
    bubble.classList.add("streaming")
    let reply     = ""
    const toolCalls = {}

    try {
      const resp = await fetch(this.streamUrlValue, {
        method:  "POST",
        headers: { "Content-Type": "application/json" },
        body:    JSON.stringify({ messages: this.history, schemaUrl: this.schemaUrlValue }),
      })

      this._setTyping(false)

      if (!resp.ok) {
        bubble.classList.remove("streaming")
        bubble.classList.add("error")
        bubble.textContent = `Error: ${resp.status} ${resp.statusText}`
        return
      }

      const reader  = resp.body.getReader()
      const decoder = new TextDecoder()
      let buffer  = ""

      while (true) {
        const { done, value } = await reader.read()
        if (done) break
        buffer += decoder.decode(value, { stream: true })

        const parts = buffer.split("\n\n")
        buffer = parts.pop()

        for (const part of parts) {
          for (const line of part.split("\n")) {
            if (!line.startsWith("data:")) continue
            const data = line.slice(5).trim()
            if (data === "[DONE]") continue
            try {
              const parsed = JSON.parse(data)

              // Accumulate tool call fragments
              for (const tc of parsed?.choices?.[0]?.delta?.tool_calls ?? []) {
                const idx = tc.index ?? 0
                if (!toolCalls[idx]) toolCalls[idx] = { id: "", name: "", args: "" }
                if (tc.id)                  toolCalls[idx].id   = tc.id
                if (tc.function?.name)      toolCalls[idx].name = tc.function.name
                if (tc.function?.arguments) toolCalls[idx].args += tc.function.arguments
              }

              // Handle tool_calls finish
              if (parsed?.choices?.[0]?.finish_reason === "tool_calls") {
                for (const tc of Object.values(toolCalls)) {
                  let args = {}
                  try { args = JSON.parse(tc.args) } catch (_) {}

                  if (tc.name === "_meiliSearchProgress") {
                    try {
                      const params = JSON.parse(args.function_parameters ?? "{}")
                      if (params.index_uid) {
                        const note = document.createElement("div")
                        note.className = "chat-search-note"
                        note.textContent = `${params.index_uid}${params.q ? ' · q: "' + params.q + '"' : ""}`
                        this.messagesTarget.appendChild(note)
                        this._scrollBottom()
                      }
                    } catch (_) {}

                  } else if (tc.name === "_meiliSearchSources") {
                    this._addSourcesBlock(args.documents ?? args)

                  } else if (tc.name === "_meiliAppendConversationMessage") {
                    const msg = { role: args.role }
                    if (args.tool_calls)   msg.tool_calls   = args.tool_calls
                    if (args.content)      msg.content      = args.content
                    if (args.tool_call_id) msg.tool_call_id = args.tool_call_id
                    this.history.push(msg)
                  }
                }
              }

              // Content delta
              const rawDelta = parsed?.choices?.[0]?.delta?.content ?? ""
              const delta = typeof rawDelta === "string" ? rawDelta
                : (rawDelta !== null && rawDelta !== undefined ? JSON.stringify(rawDelta) : "")
              if (delta) {
                reply += delta
                bubble.classList.add("streaming")
                this._scheduleRender(bubble, reply)
                this._scrollBottom()
              }

              if (parsed?.error) {
                bubble.classList.add("error")
                const errObj = parsed.error
                bubble.textContent = typeof errObj === "string" ? errObj : (errObj?.message ?? JSON.stringify(errObj))
              }
            } catch (_) {}
          }
        }
      }
    } catch (err) {
      this._setTyping(false)
      bubble.classList.add("error")
      bubble.textContent = "Connection error: " + err.message
    } finally {
      clearTimeout(this._renderTimer)
      bubble.classList.remove("streaming")
      if (reply) {
        this._renderMarkdown(bubble, reply)
        this.history.push({ role: "assistant", content: reply })
      }
      this._setBusy(false)
      this.inputTarget.focus()
      this._scrollBottom()
    }
  }

  // Delegate clicks on injected "Show details" buttons inside markdown bubbles
  messagesTargetConnected(el) {
    el.addEventListener("click", (e) => {
      const btn = e.target.closest(".chat-show-detail, [data-show-detail]")
      if (!btn) return
      const docId = btn.dataset.docId ?? btn.dataset.hitId
      if (docId) this.showDetail(docId, docId)
    })
  }
}