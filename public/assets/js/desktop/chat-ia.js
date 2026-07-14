if (!window.WMS_MODULES) window.WMS_MODULES = {};

WMS_MODULES['chat-ia'] = (function () {
  let _historial = [];
  let _moduloCtx = 'general';
  let _pensando  = false;

  const SUGERENCIAS = [
    '¿Cuántas órdenes de picking están en proceso?',
    '¿Qué productos tienen stock bajo?',
    '¿Cuántos faltantes hay actualmente?',
    '¿Cuál es el resumen de movimientos de hoy?',
    '¿Qué devoluciones están pendientes?',
    '¿Cómo está el inventario disponible vs reservado?',
    '¿Qué lotes están próximos a vencer?',
    'Dame un resumen ejecutivo de la operación',
  ];

  function _md(text) {
    return text
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
      .replace(/\*(.+?)\*/g, '<i>$1</i>')
      .replace(/`(.+?)`/g, '<code style="background:#f1f5f9;padding:1px 4px;border-radius:3px;font-size:11px;font-family:monospace;">$1</code>')
      .replace(/^### (.+)$/gm, '<div style="font-weight:800;font-size:13px;margin-top:10px;color:#0f172a;">$1</div>')
      .replace(/^## (.+)$/gm,  '<div style="font-weight:800;font-size:14px;margin-top:12px;color:#0f172a;">$1</div>')
      .replace(/^# (.+)$/gm,   '<div style="font-weight:800;font-size:15px;margin-top:14px;color:#0f172a;">$1</div>')
      .replace(/^[-*] (.+)$/gm,'<div style="margin-left:14px;margin-top:3px;">• $1</div>')
      .replace(/^(\d+)\. (.+)$/gm,'<div style="margin-left:14px;margin-top:3px;"><b>$1.</b> $2</div>')
      .replace(/\n\n/g, '<div style="height:8px;"></div>')
      .replace(/\n/g, '<br>');
  }

  function _scrollBottom() {
    const el = document.getElementById('cia-messages');
    if (el) el.scrollTop = el.scrollHeight;
  }

  function _addMessage(role, content, typing) {
    const el = document.getElementById('cia-messages');
    if (!el) return null;
    const isUser = role === 'user';
    const id     = 'msg-' + Date.now() + '-' + Math.floor(Math.random() * 9999);
    const hora   = new Date().toLocaleTimeString('es', {hour:'2-digit', minute:'2-digit'});
    el.insertAdjacentHTML('beforeend', `
      <div id="${id}" style="display:flex;gap:10px;margin-bottom:16px;align-items:flex-start;${isUser ? 'flex-direction:row-reverse' : ''}">
        <div style="width:34px;height:34px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;
          background:${isUser ? '#1d4ed8' : 'linear-gradient(135deg,#7c3aed,#1d4ed8)'};color:#fff;font-size:14px;">
          <i class="fa-solid ${isUser ? 'fa-user' : 'fa-robot'}"></i>
        </div>
        <div style="max-width:78%;min-width:60px;">
          <div style="font-size:10px;color:#94a3b8;margin-bottom:4px;${isUser ? 'text-align:right' : ''}">
            ${isUser ? 'Tú' : 'FENIX IA'} · ${hora}
          </div>
          <div class="cia-bubble" style="background:${isUser ? '#1d4ed8' : '#fff'};color:${isUser ? '#fff' : '#1e293b'};
            padding:12px 15px;border-radius:${isUser ? '16px 4px 16px 16px' : '4px 16px 16px 16px'};
            font-size:13px;line-height:1.6;box-shadow:0 2px 8px rgba(0,0,0,.08);
            border:${isUser ? 'none' : '1px solid #f1f5f9'};">
            ${typing
              ? '<span class="cia-typing-dots"><span class="cia-dot"></span><span class="cia-dot"></span><span class="cia-dot"></span></span>'
              : (isUser ? WMS.esc(content) : _md(content))}
          </div>
        </div>
      </div>`);
    _scrollBottom();
    return id;
  }

  async function _enviar() {
    if (_pensando) return;
    const input = document.getElementById('cia-input');
    if (!input) return;
    const texto = input.value.trim();
    if (!texto) return;
    input.value = '';
    input.style.height = '40px';

    _historial.push({ role: 'user', content: texto });
    _addMessage('user', texto);
    _pensando = true;

    const btnSend = document.getElementById('cia-send');
    if (btnSend) { btnSend.disabled = true; btnSend.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; }

    const typingId = _addMessage('assistant', '', true);

    try {
      const resp = await fetch(API.BASE_URL + '/chat-ia/mensaje', {
        method:  'POST',
        headers: {
          'Content-Type':  'application/json',
          'Authorization': 'Bearer ' + (localStorage.getItem('wms_token') || ''),
        },
        body:   JSON.stringify({ mensaje: texto, historial: _historial.slice(-12), modulo: _moduloCtx }),
        signal: AbortSignal.timeout(50000),
      });
      const json = await resp.json();
      const respuesta = json?.data?.respuesta || json?.respuesta || json?.message || 'Sin respuesta';
      const isErr = resp.status >= 400;

      const msgEl = document.getElementById(typingId);
      if (msgEl) {
        const bubble = msgEl.querySelector('.cia-bubble');
        if (bubble) {
          if (isErr) {
            bubble.style.background = '#fef2f2';
            bubble.style.color      = '#dc2626';
            bubble.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + WMS.esc(respuesta);
          } else {
            bubble.innerHTML = _md(respuesta);
            _historial.push({ role: 'assistant', content: respuesta });
          }
        }
      }
    } catch (e) {
      const msgEl = document.getElementById(typingId);
      if (msgEl) {
        const bubble = msgEl.querySelector('.cia-bubble');
        if (bubble) {
          bubble.style.background = '#fef2f2';
          bubble.style.color      = '#dc2626';
          bubble.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + WMS.esc(e.message || 'Error de conexión con la IA');
        }
      }
    } finally {
      _pensando = false;
      if (btnSend) { btnSend.disabled = false; btnSend.innerHTML = '<i class="fa-solid fa-paper-plane"></i>'; }
      _scrollBottom();
    }
  }

  return {
    load(sub) {
      _moduloCtx = sub || 'general';
      const sugs = [...SUGERENCIAS].sort(() => Math.random() - .5).slice(0, 4);

      WMS.setContent(`
        <style>
          .cia-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#94a3b8;margin:0 2px;
            animation:cia-bounce .9s infinite ease-in-out}
          .cia-dot:nth-child(2){animation-delay:.15s}
          .cia-dot:nth-child(3){animation-delay:.30s}
          @keyframes cia-bounce{0%,80%,100%{transform:scale(.6)}40%{transform:scale(1)}}
          #cia-input{outline:none}
          #cia-input:focus{box-shadow:0 0 0 2px #1d4ed830}
          .cia-sug{cursor:pointer;background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:6px 13px;
            font-size:11px;color:#475569;transition:all .2s;white-space:nowrap}
          .cia-sug:hover{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
          .cia-ctx-chip{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;cursor:pointer;
            color:#fff;text-transform:uppercase;transition:all .2s;user-select:none}
        </style>
        <div style="display:flex;flex-direction:column;height:calc(100vh - 118px);background:#f8fafc;border-radius:8px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06);">
          <!-- Header -->
          <div style="background:linear-gradient(135deg,#1d4ed8,#7c3aed);padding:16px 20px;color:#fff;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:12px;">
              <div style="width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;">
                <i class="fa-solid fa-robot" style="font-size:20px;"></i>
              </div>
              <div>
                <div style="font-size:17px;font-weight:800;letter-spacing:.3px;">FENIX IA</div>
                <div style="font-size:11px;opacity:.8;">Asistente operativo · Consulta todo sobre tu operación logística</div>
              </div>
              <div style="margin-left:auto;">
                <button onclick="WMS_MODULES['chat-ia']._limpiar()"
                  style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:11px;">
                  <i class="fa-solid fa-trash-can"></i> Limpiar
                </button>
              </div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;">
              ${['general','inventario','picking','despacho','devoluciones','trazabilidad'].map(m => `
                <span class="cia-ctx-chip" id="ctx-${m}" data-ctx="${m}"
                  onclick="WMS_MODULES['chat-ia']._setCtx('${m}')"
                  style="background:${m === _moduloCtx ? 'rgba(255,255,255,.35)' : 'rgba(255,255,255,.1)'};
                         border:1px solid rgba(255,255,255,${m === _moduloCtx ? '.5' : '.2'});">
                  ${m}
                </span>`).join('')}
            </div>
          </div>

          <!-- Messages -->
          <div id="cia-messages" style="flex:1;overflow-y:auto;padding:20px;background:#f8fafc;">
            <div style="text-align:center;margin:20px 0 28px;">
              <div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#1d4ed8);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                <i class="fa-solid fa-robot" style="font-size:28px;color:#fff;"></i>
              </div>
              <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:6px;">¡Hola! Soy FENIX IA</div>
              <div style="font-size:12px;color:#64748b;max-width:400px;margin:0 auto;">
                Puedo ayudarte a consultar inventarios, picking, despachos, devoluciones, trazabilidad y toda la operación logística en tiempo real.
              </div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-bottom:20px;">
              ${sugs.map(s => `<button class="cia-sug" onclick="WMS_MODULES['chat-ia']._usarSug(this)">${WMS.esc(s)}</button>`).join('')}
            </div>
          </div>

          <!-- Input -->
          <div style="border-top:1px solid #e2e8f0;background:#fff;padding:12px 16px;flex-shrink:0;">
            <div style="display:flex;gap:10px;align-items:flex-end;">
              <textarea id="cia-input" placeholder="Escribe tu consulta sobre la operación..."
                style="flex:1;border:1px solid #e2e8f0;border-radius:12px;padding:10px 14px;font-size:13px;
                  font-family:inherit;resize:none;height:40px;max-height:120px;overflow-y:auto;
                  line-height:1.5;color:#0f172a;background:#f8fafc;transition:box-shadow .2s;"
                onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();WMS_MODULES['chat-ia']._enviar();}"
                oninput="this.style.height='40px';this.style.height=Math.min(this.scrollHeight,120)+'px'"></textarea>
              <button id="cia-send" onclick="WMS_MODULES['chat-ia']._enviar()"
                style="width:42px;height:42px;border-radius:50%;background:#1d4ed8;border:none;color:#fff;
                  cursor:pointer;flex-shrink:0;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s;"
                onmouseover="this.style.background='#1e40af'" onmouseout="this.style.background='#1d4ed8'">
                <i class="fa-solid fa-paper-plane"></i>
              </button>
            </div>
            <div style="font-size:10px;color:#94a3b8;margin-top:5px;text-align:center;">
              Enter para enviar &nbsp;·&nbsp; Shift+Enter para nueva línea
            </div>
          </div>
        </div>`);

      WMS.setToolbar(`
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES['chat-ia']._limpiar()">
          <i class="fa-solid fa-rotate"></i> Nueva conversación
        </button>`);

      setTimeout(() => document.getElementById('cia-input')?.focus(), 200);
    },

    _enviar,

    _limpiar() {
      _historial = [];
      this.load(_moduloCtx);
    },

    _setCtx(modulo) {
      _moduloCtx = modulo;
      document.querySelectorAll('.cia-ctx-chip').forEach(el => {
        const m = el.dataset.ctx;
        el.style.background   = m === modulo ? 'rgba(255,255,255,.35)' : 'rgba(255,255,255,.1)';
        el.style.borderColor  = m === modulo ? 'rgba(255,255,255,.5)'  : 'rgba(255,255,255,.2)';
      });
    },

    _usarSug(btn) {
      const input = document.getElementById('cia-input');
      if (input) { input.value = btn.textContent.trim(); _enviar(); }
    },
  };
})();
