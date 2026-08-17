const express = require('express');
const router = express.Router();

/**
 * GET /mock
 * Página de bienvenida del Simulador SENCE
 */
router.get('/', (req, res) => {
  res.send(`
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <title>Simulador SENCE RCE — Ambiente de Pruebas</title>
      <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f4f6f9; color: #333; margin: 0; padding: 40px 20px; }
        .card { max-width: 650px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-top: 5px solid #003f87; }
        h1 { color: #003f87; font-size: 24px; margin-top: 0; }
        .badge { background: #e3f2fd; color: #0d47a1; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }
        ul { line-height: 1.8; }
        code { background: #eee; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
      </style>
    </head>
    <body>
      <div class="card">
        <h1>🇨🇱 Simulador SENCE RCE <span class="badge">TEST SERVER</span></h1>
        <p>Este servidor emula el comportamiento de los endpoints oficiales de SENCE para pruebas de integración y desarrollo sin tocar sistemas productivos.</p>
        <h3>Endpoints simulados disponibles:</h3>
        <ul>
          <li><code>POST /mock/rce/Registro/IniciarSesion</code></li>
          <li><code>POST /mock/rcetest/Registro/IniciarSesion</code></li>
          <li><code>POST /mock/rce/Registro/CerrarSesion</code></li>
          <li><code>POST /mock/rcetest/Registro/CerrarSesion</code></li>
        </ul>
        <p>Cuando un alumno inicia o cierra sesión en modo prueba, este simulador muestra la interfaz de Clave Única para autorizar el retorno con un clic.</p>
      </div>
    </body>
    </html>
  `);
});

/**
 * Handler para IniciarSesion (simulación)
 */
function handleMockIniciarSesion(req, res) {
  const {
    RutOtec,
    Token,
    CodSence,
    CodigoCurso,
    LineaCapacitacion,
    RunAlumno,
    IdSesionAlumno,
    UrlRetoma,
    UrlError
  } = req.body;

  const now = new Date();
  const fechaHora = now.toISOString().replace('T', ' ').substring(0, 19);
  const mockSenceId = `SENCE-MOCK-${Date.now()}`;

  res.send(`
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <title>SENCE — Autenticación Clave Única (Simulador)</title>
      <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #eef2f6; margin: 0; padding: 30px 15px; color: #2c3e50; }
        .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.12); overflow: hidden; }
        .header { background: #003f87; color: white; padding: 25px; text-align: center; }
        .header img { height: 40px; margin-bottom: 8px; }
        .header h2 { margin: 0; font-size: 20px; font-weight: 600; }
        .content { padding: 25px 30px; }
        .info-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 20px; font-size: 13px; line-height: 1.6; }
        .btn { display: block; width: 100%; padding: 14px; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; text-align: center; margin-bottom: 12px; text-decoration: none; box-sizing: border-box; }
        .btn-success { background: #003f87; color: white; }
        .btn-success:hover { background: #002d61; }
        .btn-danger { background: #e53e3e; color: white; }
        .btn-danger:hover { background: #c53030; }
        details { margin-top: 20px; font-size: 12px; color: #64748b; }
        summary { cursor: pointer; font-weight: bold; }
        pre { background: #1e293b; color: #f8fafc; padding: 12px; border-radius: 6px; overflow-x: auto; }
      </style>
    </head>
    <body>
      <div class="container">
        <div class="header">
          <h2>🇨🇱 Gobierno de Chile — SENCE RCE</h2>
          <p style="margin: 5px 0 0; opacity: 0.85; font-size: 14px;">Simulador de Autenticación con Clave Única</p>
        </div>
        <div class="content">
          <div class="info-box">
            <strong>Datos del Alumno y Curso:</strong><br>
            • <strong>RUN Alumno:</strong> ${RunAlumno || 'No especificado'}<br>
            • <strong>Código SENCE:</strong> ${CodSence || '(En blanco - Línea 1)'}<br>
            • <strong>Código Curso / ID Acción:</strong> ${CodigoCurso || 'N/A'}<br>
            • <strong>Línea Capacitación:</strong> ${LineaCapacitacion || '3'}
          </div>

          <!-- Formulario de ÉXITO -->
          <form method="POST" action="${UrlRetoma}">
            <input type="hidden" name="RunAlumno" value="${RunAlumno || ''}">
            <input type="hidden" name="IdSesionAlumno" value="${IdSesionAlumno || ''}">
            <input type="hidden" name="IdSesionSence" value="${mockSenceId}">
            <input type="hidden" name="FechaHora" value="${fechaHora}">
            <input type="hidden" name="ZonaHoraria" value="America/Santiago">
            <input type="hidden" name="LineaCapacitacion" value="${LineaCapacitacion || '3'}">
            <input type="hidden" name="CodSence" value="${CodSence || ''}">
            <input type="hidden" name="CodigoCurso" value="${CodigoCurso || ''}">

            <button type="submit" class="btn btn-success">
              ✅ Continuar con Clave Única (Simular Éxito)
            </button>
          </form>

          <!-- Formulario de ERROR -->
          <form method="POST" action="${UrlError}">
            <input type="hidden" name="RunAlumno" value="${RunAlumno || ''}">
            <input type="hidden" name="IdSesionAlumno" value="${IdSesionAlumno || ''}">
            <input type="hidden" name="IdSesionSence" value="${mockSenceId}">
            <input type="hidden" name="FechaHora" value="${fechaHora}">
            <input type="hidden" name="ZonaHoraria" value="America/Santiago">
            <input type="hidden" name="LineaCapacitacion" value="${LineaCapacitacion || '3'}">
            <input type="hidden" name="CodSence" value="${CodSence || ''}">
            <input type="hidden" name="CodigoCurso" value="${CodigoCurso || ''}">
            <input type="hidden" name="GlosaError" value="312">

            <button type="submit" class="btn btn-danger">
              ❌ Simular Error 312 (Fallo de Clave Única)
            </button>
          </form>

          <details>
            <summary>Ver parámetros POST recibidos desde el sitio OTEC</summary>
            <pre>${JSON.stringify(req.body, null, 2)}</pre>
          </details>
        </div>
      </div>
    </body>
    </html>
  `);
}

/**
 * Handler para CerrarSesion (simulación)
 */
function handleMockCerrarSesion(req, res) {
  const {
    RutOtec,
    Token,
    CodSence,
    CodigoCurso,
    LineaCapacitacion,
    RunAlumno,
    IdSesionAlumno,
    IdSesionSence,
    UrlRetoma,
    UrlError
  } = req.body;

  const now = new Date();
  const fechaHora = now.toISOString().replace('T', ' ').substring(0, 19);

  res.send(`
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <title>SENCE — Cierre de Sesión (Simulador)</title>
      <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #eef2f6; margin: 0; padding: 30px 15px; color: #2c3e50; }
        .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.12); overflow: hidden; }
        .header { background: #c0392b; color: white; padding: 25px; text-align: center; }
        .header h2 { margin: 0; font-size: 20px; font-weight: 600; }
        .content { padding: 25px 30px; }
        .info-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 20px; font-size: 13px; line-height: 1.6; }
        .btn { display: block; width: 100%; padding: 14px; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; text-align: center; margin-bottom: 12px; text-decoration: none; box-sizing: border-box; }
        .btn-success { background: #003f87; color: white; }
        .btn-success:hover { background: #002d61; }
        .btn-danger { background: #e53e3e; color: white; }
        .btn-danger:hover { background: #c53030; }
      </style>
    </head>
    <body>
      <div class="container">
        <div class="header">
          <h2>🇨🇱 SENCE RCE — Confirmación de Cierre</h2>
          <p style="margin: 5px 0 0; opacity: 0.85; font-size: 14px;">Simulador de Cierre de Sesión</p>
        </div>
        <div class="content">
          <div class="info-box">
            <strong>Confirmación de Cierre de Asistencia:</strong><br>
            • <strong>RUN:</strong> ${RunAlumno}<br>
            • <strong>ID Sesión SENCE:</strong> ${IdSesionSence || 'N/A'}<br>
            • <strong>ID Sesión Alumno:</strong> ${IdSesionAlumno || 'N/A'}
          </div>

          <!-- Éxito de Cierre -->
          <form method="POST" action="${UrlRetoma}">
            <input type="hidden" name="RunAlumno" value="${RunAlumno || ''}">
            <input type="hidden" name="IdSesionAlumno" value="${IdSesionAlumno || ''}">
            <input type="hidden" name="FechaHora" value="${fechaHora}">
            <input type="hidden" name="ZonaHoraria" value="America/Santiago">
            <input type="hidden" name="LineaCapacitacion" value="${LineaCapacitacion || '3'}">
            <input type="hidden" name="CodSence" value="${CodSence || ''}">
            <input type="hidden" name="CodigoCurso" value="${CodigoCurso || ''}">

            <button type="submit" class="btn btn-success">
              ✅ Confirmar Cierre de Asistencia
            </button>
          </form>

          <!-- Error de Cierre -->
          <form method="POST" action="${UrlError}">
            <input type="hidden" name="RunAlumno" value="${RunAlumno || ''}">
            <input type="hidden" name="IdSesionAlumno" value="${IdSesionAlumno || ''}">
            <input type="hidden" name="GlosaError" value="313">

            <button type="submit" class="btn btn-danger">
              ❌ Simular Error 313 (URL de Cierre Incorrecta)
            </button>
          </form>
        </div>
      </div>
    </body>
    </html>
  `);
}

// Rutas de simulación
router.post('/rce/Registro/IniciarSesion', handleMockIniciarSesion);
router.post('/rcetest/Registro/IniciarSesion', handleMockIniciarSesion);

router.post('/rce/Registro/CerrarSesion', handleMockCerrarSesion);
router.post('/rcetest/Registro/CerrarSesion', handleMockCerrarSesion);

module.exports = router;
