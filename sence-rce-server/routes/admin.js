const express = require('express');
const router = express.Router();
const crypto = require('crypto');
const { supabase } = require('../lib/supabase');
const { PLANS } = require('../lib/plans');

/**
 * Middleware para validar Master Key en endpoints API del admin
 */
function checkMasterKey(req, res, next) {
  const masterKey = req.headers['x-master-key'] || req.query.master_key || (req.body && req.body.master_key);
  const expectedKey = process.env.MASTER_ADMIN_KEY;

  if (!expectedKey) {
    return res.status(500).json({ error: 'MASTER_ADMIN_KEY no configurada en las variables de entorno del servidor.' });
  }

  if (!masterKey || masterKey !== expectedKey) {
    return res.status(401).json({ error: 'Master Admin Key inválida.' });
  }

  next();
}

/**
 * POST /admin/api/login
 * Comprueba que la master key sea correcta
 */
router.post('/api/login', (req, res) => {
  const { master_key } = req.body;
  const expectedKey = process.env.MASTER_ADMIN_KEY;

  if (!expectedKey) {
    return res.status(500).json({ error: 'MASTER_ADMIN_KEY no configurada en el servidor.' });
  }

  if (master_key === expectedKey) {
    return res.json({ success: true, message: 'Autenticación exitosa' });
  }

  return res.status(401).json({ error: 'Contraseña maestra incorrecta.' });
});

/**
 * GET /admin/api/otecs
 * Lista todas las OTECs con estadísticas de cursos y sesiones
 */
router.get('/api/otecs', checkMasterKey, async (req, res) => {
  try {
    const { data: otecs, error: otecsErr } = await supabase
      .from('otecs')
      .select('*')
      .order('created_at', { ascending: false });

    if (otecsErr) {
      console.error('Error al listar OTECs:', otecsErr);
      return res.status(500).json({ error: 'Error al consultar la base de datos.' });
    }

    // Obtener estadísticas del mes actual
    const now = new Date();
    const startOfMonth = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1)).toISOString();

    const { data: coursesCount } = await supabase
      .from('courses')
      .select('otec_id');

    const { data: monthSessions } = await supabase
      .from('sessions')
      .select('otec_id')
      .gte('session_opened_at', startOfMonth);

    // Mapear conteos por OTEC
    const coursesMap = {};
    (coursesCount || []).forEach(c => {
      coursesMap[c.otec_id] = (coursesMap[c.otec_id] || 0) + 1;
    });

    const sessionsMap = {};
    (monthSessions || []).forEach(s => {
      sessionsMap[s.otec_id] = (sessionsMap[s.otec_id] || 0) + 1;
    });

    const enriched = (otecs || []).map(o => ({
      ...o,
      courses_count: coursesMap[o.id] || 0,
      sessions_this_month: sessionsMap[o.id] || 0,
      plan_details: PLANS[o.plan] || PLANS.starter
    }));

    res.json({
      otecs: enriched,
      stats: {
        total_otecs: enriched.length,
        active_otecs: enriched.filter(o => o.is_active).length,
        total_courses: coursesCount ? coursesCount.length : 0,
        total_sessions_month: monthSessions ? monthSessions.length : 0
      }
    });
  } catch (err) {
    console.error('Error en /admin/api/otecs:', err);
    res.status(500).json({ error: 'Error interno del servidor.' });
  }
});

/**
 * POST /admin/api/otecs
 * Crea una nueva OTEC
 */
router.post('/api/otecs', checkMasterKey, async (req, res) => {
  try {
    const {
      nombre,
      rut_otec,
      email,
      telefono,
      plan = 'starter',
      token_sence = '',
      test_env = true,
      notes = '',
      api_key
    } = req.body;

    if (!nombre || !rut_otec || !email) {
      return res.status(400).json({ error: 'Nombre, RUT y Email son obligatorios.' });
    }

    // Normalizar RUT: sin puntos
    const cleanRut = rut_otec.replace(/\./g, '').trim();

    // Generar o usar API key
    const generatedKey = api_key && api_key.trim().length > 0
      ? api_key.trim()
      : 'sk-live-' + crypto.randomBytes(16).toString('hex');

    const payload = {
      nombre: nombre.trim(),
      rut_otec: cleanRut,
      email: email.trim().toLowerCase(),
      telefono: telefono ? telefono.trim() : null,
      plan: ['starter', 'pro', 'enterprise'].includes(plan) ? plan : 'starter',
      token_sence: token_sence ? token_sence.trim() : null,
      test_env: Boolean(test_env),
      notes: notes ? notes.trim() : null,
      api_key: generatedKey,
      is_active: true
    };

    const { data: newOtec, error } = await supabase
      .from('otecs')
      .insert(payload)
      .select()
      .single();

    if (error) {
      console.error('Error al insertar OTEC:', error);
      if (error.code === '23505') {
        return res.status(400).json({ error: 'Ya existe una OTEC con ese RUT, Email o API Key.' });
      }
      return res.status(500).json({ error: 'Error al registrar en base de datos: ' + error.message });
    }

    res.json({ success: true, otec: newOtec });
  } catch (err) {
    console.error('Error en creación de OTEC:', err);
    res.status(500).json({ error: 'Error interno del servidor.' });
  }
});

/**
 * PATCH /admin/api/otecs/:id/toggle
 * Activa o suspende una OTEC
 */
router.patch('/api/otecs/:id/toggle', checkMasterKey, async (req, res) => {
  try {
    const { id } = req.params;

    const { data: otec, error: getErr } = await supabase
      .from('otecs')
      .select('is_active')
      .eq('id', id)
      .single();

    if (getErr || !otec) {
      return res.status(404).json({ error: 'OTEC no encontrada.' });
    }

    const nextState = !otec.is_active;

    const { data: updated, error: updErr } = await supabase
      .from('otecs')
      .update({ is_active: nextState })
      .eq('id', id)
      .select()
      .single();

    if (updErr) {
      return res.status(500).json({ error: 'Error al actualizar estado.' });
    }

    res.json({ success: true, is_active: nextState, otec: updated });
  } catch (err) {
    console.error('Error en toggle OTEC:', err);
    res.status(500).json({ error: 'Error interno.' });
  }
});

/**
 * PATCH /admin/api/otecs/:id/plan
 * Cambia el plan de una OTEC
 */
router.patch('/api/otecs/:id/plan', checkMasterKey, async (req, res) => {
  try {
    const { id } = req.params;
    const { plan } = req.body;

    if (!['starter', 'pro', 'enterprise'].includes(plan)) {
      return res.status(400).json({ error: 'Plan inválido.' });
    }

    const { data: updated, error } = await supabase
      .from('otecs')
      .update({ plan })
      .eq('id', id)
      .select()
      .single();

    if (error) {
      return res.status(500).json({ error: 'Error al cambiar plan.' });
    }

    res.json({ success: true, otec: updated });
  } catch (err) {
    console.error('Error al cambiar plan:', err);
    res.status(500).json({ error: 'Error interno.' });
  }
});

/**
 * POST /admin/api/otecs/:id/regenerate-key
 * Genera una nueva API Key para la OTEC
 */
router.post('/api/otecs/:id/regenerate-key', checkMasterKey, async (req, res) => {
  try {
    const { id } = req.params;
    const newKey = 'sk-live-' + crypto.randomBytes(16).toString('hex');

    const { data: updated, error } = await supabase
      .from('otecs')
      .update({ api_key: newKey })
      .eq('id', id)
      .select()
      .single();

    if (error) {
      return res.status(500).json({ error: 'Error al regenerar API Key.' });
    }

    res.json({ success: true, api_key: newKey, otec: updated });
  } catch (err) {
    console.error('Error al regenerar key:', err);
    res.status(500).json({ error: 'Error interno.' });
  }
});

/**
 * DELETE /admin/api/otecs/:id
 * Elimina una OTEC
 */
router.delete('/api/otecs/:id', checkMasterKey, async (req, res) => {
  try {
    const { id } = req.params;

    const { error } = await supabase
      .from('otecs')
      .delete()
      .eq('id', id);

    if (error) {
      return res.status(500).json({ error: 'Error al eliminar OTEC: ' + error.message });
    }

    res.json({ success: true, message: 'OTEC eliminada correctamente.' });
  } catch (err) {
    console.error('Error al eliminar OTEC:', err);
    res.status(500).json({ error: 'Error interno.' });
  }
});

/**
 * GET /admin
 * Renderiza la interfaz web completa (HTML/CSS/JS)
 */
router.get('/', (req, res) => {
  res.send(`<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SENCE RCE SaaS — Panel de Control Master</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #004b93;
      --primary-hover: #00366b;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --bg: #f8fafc;
      --card-bg: #ffffff;
      --border: #e2e8f0;
      --text-main: #0f172a;
      --text-muted: #64748b;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', -apple-system, sans-serif; }
    body { background-color: var(--bg); color: var(--text-main); min-height: 100vh; }
    
    /* Login Screen */
    #login-view {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 20px;
    }
    .login-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
      padding: 36px;
      width: 100%;
      max-width: 420px;
      text-align: center;
    }
    .login-card .logo { font-size: 32px; margin-bottom: 12px; }
    .login-card h2 { font-size: 20px; font-weight: 700; margin-bottom: 8px; color: var(--primary); }
    .login-card p { font-size: 13px; color: var(--text-muted); margin-bottom: 24px; }
    .form-group { text-align: left; margin-bottom: 18px; }
    .form-group label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 6px; }
    .form-control {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 14px;
      transition: border-color 0.15s;
    }
    .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,75,147,0.1); }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 10px 18px;
      font-size: 14px;
      font-weight: 600;
      border-radius: 8px;
      cursor: pointer;
      border: none;
      transition: all 0.15s;
    }
    .btn-primary { background: var(--primary); color: white; width: 100%; }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-success { background: var(--success); color: white; }
    .btn-sm { padding: 6px 12px; font-size: 12px; }
    .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text-main); }
    .btn-outline:hover { background: #f1f5f9; }
    
    /* Dashboard Screen */
    #dashboard-view { display: none; }
    header {
      background: white;
      border-bottom: 1px solid var(--border);
      padding: 16px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .brand { display: flex; align-items: center; gap: 12px; }
    .brand-title { font-size: 18px; font-weight: 700; color: var(--primary); }
    .badge-status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #ecfdf5;
      color: #065f46;
      font-size: 12px;
      padding: 4px 10px;
      border-radius: 20px;
      font-weight: 600;
    }
    .badge-status::before { content: ""; width: 8px; height: 8px; background: #10b981; border-radius: 50%; }

    main { max-width: 1240px; margin: 0 auto; padding: 32px 20px; }
    
    /* Stats Grid */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
      margin-bottom: 28px;
    }
    .stat-card {
      background: white;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .stat-title { font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px; }
    .stat-value { font-size: 28px; font-weight: 700; color: var(--text-main); }
    .stat-sub { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

    /* Controls Bar */
    .controls-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    .search-input { width: 320px; }

    /* Table */
    .table-container {
      background: white;
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow-x: auto;
      box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; }
    th {
      background: #f8fafc;
      padding: 12px 16px;
      font-weight: 600;
      color: var(--text-muted);
      border-bottom: 1px solid var(--border);
      text-transform: uppercase;
      font-size: 11px;
      letter-spacing: 0.5px;
    }
    td { padding: 14px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fdfdfe; }

    .key-box {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #f1f5f9;
      padding: 4px 8px;
      border-radius: 6px;
      font-family: monospace;
      font-size: 12px;
      color: #334155;
    }
    .copy-btn {
      background: white;
      border: 1px solid #cbd5e1;
      padding: 2px 6px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 11px;
      font-weight: 600;
    }
    .copy-btn:hover { background: #e2e8f0; }

    .plan-tag {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
    }
    .plan-starter { background: #e0f2fe; color: #0369a1; }
    .plan-pro { background: #fef3c7; color: #b45309; }
    .plan-enterprise { background: #f3e8ff; color: #7e22ce; }

    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 600;
    }
    .status-active { background: #dcfce7; color: #15803d; }
    .status-suspended { background: #fee2e2; color: #b91c1c; }

    /* Modal */
    .modal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(2px);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      z-index: 100;
    }
    .modal-card {
      background: white;
      border-radius: 12px;
      width: 100%;
      max-width: 540px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
      overflow: hidden;
    }
    .modal-header {
      padding: 18px 24px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .modal-header h3 { font-size: 16px; font-weight: 700; }
    .modal-body { padding: 24px; max-height: 80vh; overflow-y: auto; }
    .modal-footer {
      padding: 16px 24px;
      border-top: 1px solid var(--border);
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      background: #f8fafc;
    }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  </style>
</head>
<body>

  <!-- 1. VISTA DE LOGIN -->
  <div id="login-view">
    <div class="login-card">
      <div class="logo">🇨🇱</div>
      <h2>SENCE RCE SaaS</h2>
      <p>Panel de Super-Administración Master</p>
      <form id="login-form">
        <div class="form-group">
          <label for="master_key_input">Master Admin Key</label>
          <input type="password" id="master_key_input" class="form-control" placeholder="••••••••••••••••" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary" id="btn-login-submit">Ingresar al Panel</button>
        <div id="login-error" style="color: var(--danger); font-size: 12px; margin-top: 14px; display: none;"></div>
      </form>
    </div>
  </div>

  <!-- 2. VISTA DE DASHBOARD -->
  <div id="dashboard-view">
    <header>
      <div class="brand">
        <span style="font-size: 24px;">🇨🇱</span>
        <div>
          <div class="brand-title">SENCE RCE SaaS Master Panel</div>
          <small style="color: var(--text-muted); font-size: 11px;">Gestión Multi-Tenant de OTECs & Planes</small>
        </div>
      </div>
      <div style="display: flex; align-items: center; gap: 16px;">
        <span class="badge-status">Cloud Online (Railway + Supabase)</span>
        <button class="btn btn-outline btn-sm" onclick="logout()">Cerrar Sesión</button>
      </div>
    </header>

    <main>
      <!-- Métricas -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-title">OTECs Activas</div>
          <div class="stat-value" id="stat-active-otecs">-</div>
          <div class="stat-sub" id="stat-total-otecs">Total: -</div>
        </div>
        <div class="stat-card">
          <div class="stat-title">Cursos Sincronizados</div>
          <div class="stat-value" id="stat-total-courses">-</div>
          <div class="stat-sub">En todas las plataformas LMS</div>
        </div>
        <div class="stat-card">
          <div class="stat-title">Sesiones Asistencia (Mes)</div>
          <div class="stat-value" id="stat-month-sessions">-</div>
          <div class="stat-sub">Registros ante SENCE Clave Única</div>
        </div>
      </div>

      <!-- Barra de Controles -->
      <div class="controls-bar">
        <input type="text" id="search-input" class="form-control search-input" placeholder="🔍 Buscar por nombre o RUT..." oninput="filterTable()">
        <button class="btn btn-primary" style="width: auto;" onclick="openCreateModal()">+ Crear Nueva OTEC</button>
      </div>

      <!-- Tabla de OTECs -->
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>OTEC / Cliente</th>
              <th>RUT</th>
              <th>Contacto</th>
              <th>Plan</th>
              <th>API Key (WordPress)</th>
              <th>Estado</th>
              <th>Actividad (Mes)</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody id="otecs-tbody">
            <tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">Cargando OTECs...</td></tr>
          </tbody>
        </table>
      </div>
    </main>
  </div>

  <!-- 3. MODAL CREAR OTEC -->
  <div class="modal-overlay" id="create-modal">
    <div class="modal-card">
      <div class="modal-header">
        <h3>Dar de Alta Nueva OTEC</h3>
        <button class="btn-outline btn-sm" onclick="closeCreateModal()" style="border: none; font-size: 16px;">✕</button>
      </div>
      <form id="create-otec-form" onsubmit="handleCreateOtec(event)">
        <div class="modal-body">
          <div class="form-group">
            <label>Nombre de la OTEC / Razón Social *</label>
            <input type="text" id="new_nombre" class="form-control" placeholder="Ej: Capacitaciones Austral SpA" required>
          </div>
          <div class="grid-2">
            <div class="form-group">
              <label>RUT OTEC (sin puntos) *</label>
              <input type="text" id="new_rut" class="form-control" placeholder="76123456-7" required>
            </div>
            <div class="form-group">
              <label>Plan SaaS *</label>
              <select id="new_plan" class="form-control">
                <option value="starter">Starter (5 cursos, 500 ses.)</option>
                <option value="pro">Pro (20 cursos, 2000 ses.)</option>
                <option value="enterprise">Enterprise (Ilimitado)</option>
              </select>
            </div>
          </div>
          <div class="grid-2">
            <div class="form-group">
              <label>Email de Contacto *</label>
              <input type="email" id="new_email" class="form-control" placeholder="admin@otec.cl" required>
            </div>
            <div class="form-group">
              <label>Teléfono</label>
              <input type="text" id="new_telefono" class="form-control" placeholder="+56 9 1234 5678">
            </div>
          </div>
          <div class="form-group">
            <label>Token SENCE RTS (UUID 36 chars)</label>
            <input type="text" id="new_token" class="form-control" placeholder="Opcional por ahora">
          </div>
          <div class="form-group">
            <label>API Key Personalizada (dejar vacío para auto-generar)</label>
            <input type="text" id="new_api_key" class="form-control" placeholder="sk-live-... (auto)">
          </div>
          <div class="form-group" style="display: flex; align-items: center; gap: 8px;">
            <input type="checkbox" id="new_test_env" checked>
            <label for="new_test_env" style="margin: 0; text-transform: none; font-size: 13px; font-weight: 500;">Habilitar Modo Simulador / Test inicialmente</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline" onclick="closeCreateModal()">Cancelar</button>
          <button type="submit" class="btn btn-primary" style="width: auto;" id="btn-save-otec">Guardar y Generar Clave</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    let currentMasterKey = sessionStorage.getItem('sence_master_key') || '';
    let allOtecs = [];

    // Al cargar
    window.addEventListener('DOMContentLoaded', () => {
      if (currentMasterKey) {
        verifySession();
      } else {
        showLogin();
      }
    });

    document.getElementById('login-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const key = document.getElementById('master_key_input').value.trim();
      const errBox = document.getElementById('login-error');
      errBox.style.display = 'none';

      try {
        const res = await fetch('/admin/api/login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ master_key: key })
        });
        const data = await res.json();
        if (res.ok && data.success) {
          currentMasterKey = key;
          sessionStorage.setItem('sence_master_key', key);
          showDashboard();
          loadData();
        } else {
          errBox.textContent = data.error || 'Error de autenticación';
          errBox.style.display = 'block';
        }
      } catch (err) {
        errBox.textContent = 'Error al comunicarse con el servidor.';
        errBox.style.display = 'block';
      }
    });

    function showLogin() {
      document.getElementById('login-view').style.display = 'flex';
      document.getElementById('dashboard-view').style.display = 'none';
    }

    function showDashboard() {
      document.getElementById('login-view').style.display = 'none';
      document.getElementById('dashboard-view').style.display = 'block';
    }

    function logout() {
      sessionStorage.removeItem('sence_master_key');
      currentMasterKey = '';
      showLogin();
    }

    async function verifySession() {
      try {
        const res = await fetch('/admin/api/otecs', {
          headers: { 'x-master-key': currentMasterKey }
        });
        if (res.ok) {
          showDashboard();
          loadData();
        } else {
          logout();
        }
      } catch (e) {
        logout();
      }
    }

    async function loadData() {
      try {
        const res = await fetch('/admin/api/otecs', {
          headers: { 'x-master-key': currentMasterKey }
        });
        if (!res.ok) {
          if (res.status === 401) logout();
          return;
        }
        const data = await res.json();
        allOtecs = data.otecs || [];

        // Actualizar estadísticas
        document.getElementById('stat-active-otecs').textContent = data.stats.active_otecs;
        document.getElementById('stat-total-otecs').textContent = 'Total registradas: ' + data.stats.total_otecs;
        document.getElementById('stat-total-courses').textContent = data.stats.total_courses;
        document.getElementById('stat-month-sessions').textContent = data.stats.total_sessions_month;

        renderTable(allOtecs);
      } catch (err) {
        console.error('Error cargando datos:', err);
      }
    }

    function renderTable(otecs) {
      const tbody = document.getElementById('otecs-tbody');
      if (!otecs || otecs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">No hay OTECs registradas. ¡Crea la primera con el botón superior!</td></tr>';
        return;
      }

      tbody.innerHTML = otecs.map(o => \`
        <tr>
          <td>
            <strong>\${escapeHtml(o.nombre)}</strong>
            \${o.notes ? \`<br><small style="color:var(--text-muted)">\${escapeHtml(o.notes)}</small>\` : ''}
          </td>
          <td><code>\${escapeHtml(o.rut_otec)}</code></td>
          <td>
            \${escapeHtml(o.email)}<br>
            <small style="color:var(--text-muted)">\${o.telefono ? escapeHtml(o.telefono) : '-'}</small>
          </td>
          <td>
            <select class="form-control" style="padding: 4px 8px; font-size: 11px; font-weight: 700;" onchange="changePlan('\${o.id}', this.value)">
              <option value="starter" \${o.plan === 'starter' ? 'selected' : ''}>STARTER</option>
              <option value="pro" \${o.plan === 'pro' ? 'selected' : ''}>PRO</option>
              <option value="enterprise" \${o.plan === 'enterprise' ? 'selected' : ''}>ENTERPRISE</option>
            </select>
          </td>
          <td>
            <div class="key-box">
              <span>\${escapeHtml(o.api_key.substring(0, 10))}...</span>
              <button class="copy-btn" onclick="copyToClipboard('\${escapeHtml(o.api_key)}', this)">📋 Copiar</button>
              <button class="copy-btn" title="Regenerar clave" onclick="regenerateKey('\${o.id}')">🔄</button>
            </div>
          </td>
          <td>
            <span class="status-pill \${o.is_active ? 'status-active' : 'status-suspended'}">
              \${o.is_active ? '● Activa' : '○ Suspendida'}
            </span>
          </td>
          <td>
            <span>\${o.courses_count} cursos</span><br>
            <small style="color:var(--text-muted)">\${o.sessions_this_month} ses. este mes</small>
          </td>
          <td>
            <div style="display: flex; gap: 6px;">
              <button class="btn btn-outline btn-sm" onclick="toggleOtec('\${o.id}')">
                \${o.is_active ? 'Suspender' : 'Activar'}
              </button>
              <button class="btn btn-outline btn-sm" style="color: var(--danger);" onclick="deleteOtec('\${o.id}', '\${escapeHtml(o.nombre)}')">
                🗑️
              </button>
            </div>
          </td>
        </tr>
      \`).join('');
    }

    function filterTable() {
      const q = document.getElementById('search-input').value.toLowerCase();
      const filtered = allOtecs.filter(o => 
        o.nombre.toLowerCase().includes(q) || 
        o.rut_otec.toLowerCase().includes(q) ||
        o.email.toLowerCase().includes(q)
      );
      renderTable(filtered);
    }

    function copyToClipboard(text, btn) {
      navigator.clipboard.writeText(text).then(() => {
        const originalText = btn.textContent;
        btn.textContent = '✓ Copiada';
        btn.style.color = '#10b981';
        setTimeout(() => {
          btn.textContent = originalText;
          btn.style.color = '';
        }, 1500);
      });
    }

    async function toggleOtec(id) {
      try {
        const res = await fetch(\`/admin/api/otecs/\${id}/toggle\`, {
          method: 'PATCH',
          headers: { 'x-master-key': currentMasterKey }
        });
        if (res.ok) loadData();
      } catch (e) {
        alert('Error al cambiar estado');
      }
    }

    async function changePlan(id, newPlan) {
      try {
        const res = await fetch(\`/admin/api/otecs/\${id}/plan\`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json', 'x-master-key': currentMasterKey },
          body: JSON.stringify({ plan: newPlan })
        });
        if (res.ok) loadData();
      } catch (e) {
        alert('Error al actualizar plan');
      }
    }

    async function regenerateKey(id) {
      if (!confirm('¿Seguro que deseas regenerar la API Key? La clave actual dejará de funcionar en WordPress.')) return;
      try {
        const res = await fetch(\`/admin/api/otecs/\${id}/regenerate-key\`, {
          method: 'POST',
          headers: { 'x-master-key': currentMasterKey }
        });
        if (res.ok) {
          const data = await res.json();
          alert('Nueva API Key generada: ' + data.api_key);
          loadData();
        }
      } catch (e) {
        alert('Error al regenerar clave');
      }
    }

    async function deleteOtec(id, nombre) {
      if (!confirm(\`¿Eliminar la OTEC "\${nombre}"? Se borrarán sus cursos y sesiones asociadas.\`)) return;
      try {
        const res = await fetch(\`/admin/api/otecs/\${id}\`, {
          method: 'DELETE',
          headers: { 'x-master-key': currentMasterKey }
        });
        if (res.ok) loadData();
      } catch (e) {
        alert('Error al eliminar');
      }
    }

    function openCreateModal() {
      document.getElementById('create-modal').style.display = 'flex';
      document.getElementById('create-otec-form').reset();
    }

    function closeCreateModal() {
      document.getElementById('create-modal').style.display = 'none';
    }

    async function handleCreateOtec(e) {
      e.preventDefault();
      const btn = document.getElementById('btn-save-otec');
      btn.disabled = true;
      btn.textContent = 'Guardando...';

      const payload = {
        nombre: document.getElementById('new_nombre').value,
        rut_otec: document.getElementById('new_rut').value,
        plan: document.getElementById('new_plan').value,
        email: document.getElementById('new_email').value,
        telefono: document.getElementById('new_telefono').value,
        token_sence: document.getElementById('new_token').value,
        api_key: document.getElementById('new_api_key').value,
        test_env: document.getElementById('new_test_env').checked
      };

      try {
        const res = await fetch('/admin/api/otecs', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'x-master-key': currentMasterKey },
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (res.ok && data.success) {
          closeCreateModal();
          loadData();
          alert('¡OTEC creada con éxito!\\n\\nAPI Key generada:\\n' + data.otec.api_key + '\\n\\nCópiala para ingresarla en el WordPress del cliente.');
        } else {
          alert('Error: ' + (data.error || 'No se pudo crear la OTEC'));
        }
      } catch (err) {
        alert('Error de conexión al crear OTEC');
      } finally {
        btn.disabled = false;
        btn.textContent = 'Guardar y Generar Clave';
      }
    }

    function escapeHtml(text) {
      if (!text) return '';
      return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }
  </script>
</body>
</html>`);
});

module.exports = router;
