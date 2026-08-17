const express = require('express');
const router = express.Router();
const { supabase } = require('../lib/supabase');
const { checkPlanLimit } = require('../lib/plans');

/**
 * Helper para construir URL de redirección con parámetros limpios
 */
function buildRedirectUrl(baseUrl, params = {}) {
  try {
    const url = new URL(baseUrl);
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null) {
        url.searchParams.set(key, String(value));
      }
    }
    return url.toString();
  } catch (e) {
    // Si la URL es relativa o malformada
    const separator = baseUrl.includes('?') ? '&' : '?';
    const qs = new URLSearchParams(params).toString();
    return `${baseUrl}${separator}${qs}`;
  }
}

/**
 * POST /callback/open
 * Callback oficial invocado por SENCE al completar exitosamente (o con error) el Inicio de Sesión
 */
router.post('/open', async (req, res) => {
  try {
    // Parámetros pasados en la URL de Retoma inicial
    const otecApiKey = req.query.otec_api_key;
    const wpCourseId = req.query.sence_course_id ? parseInt(req.query.sence_course_id, 10) : null;
    const wpSiteUrl = req.query.site_url || '';
    const wpRetoma = req.query.wp_retoma || '/';

    // Parámetros enviados por SENCE en el cuerpo POST
    const {
      RunAlumno,
      IdSesionAlumno,
      IdSesionSence,
      FechaHora,
      ZonaHoraria,
      LineaCapacitacion,
      CodSence,
      CodigoCurso,
      GlosaError
    } = req.body;

    // 1. Manejo de error retornado directamente por SENCE
    if (GlosaError) {
      console.warn(`[SENCE Callback] Error en inicio de sesión. GlosaError: ${GlosaError}`);
      const redirectUrl = buildRedirectUrl(wpRetoma, {
        sence_error: GlosaError,
        id_sesion: IdSesionAlumno || ''
      });
      return res.redirect(302, redirectUrl);
    }

    // 2. Validar que tengamos la OTEC
    if (!otecApiKey) {
      console.error('[SENCE Callback] Falta otec_api_key en query param');
      return res.redirect(302, buildRedirectUrl(wpRetoma, { sence_error: 'MISSING_OTEC_KEY' }));
    }

    const { data: otec, error: otecErr } = await supabase
      .from('otecs')
      .select('*')
      .eq('api_key', otecApiKey)
      .eq('is_active', true)
      .single();

    if (otecErr || !otec) {
      console.error('[SENCE Callback] OTEC no encontrada o inactiva:', otecErr);
      return res.redirect(302, buildRedirectUrl(wpRetoma, { sence_error: 'INVALID_OTEC' }));
    }

    // 3. Validar límite mensual del plan
    const planCheck = await checkPlanLimit(otec);
    if (!planCheck.allowed) {
      console.warn(`[SENCE Callback] Límite de plan excedido para OTEC ${otec.nombre}: ${planCheck.reason}`);
      return res.redirect(302, buildRedirectUrl(wpRetoma, { sence_error: 'PLAN_LIMIT_EXCEEDED' }));
    }

    // 4. Buscar o registrar el curso
    let courseId = null;
    if (wpCourseId) {
      const { data: course } = await supabase
        .from('courses')
        .select('id')
        .eq('otec_id', otec.id)
        .eq('wp_course_id', wpCourseId)
        .single();

      if (course) {
        courseId = course.id;
      } else {
        // Auto-crear registro del curso si no existía previamente
        const { data: newCourse } = await supabase
          .from('courses')
          .insert({
            otec_id: otec.id,
            wp_course_id: wpCourseId,
            wp_site_url: wpSiteUrl,
            codigo_sence: CodSence || '',
            codigo_curso: CodigoCurso || '',
            linea_capacitacion: LineaCapacitacion ? parseInt(LineaCapacitacion, 10) : 3,
            is_active: true
          })
          .select('id')
          .single();

        if (newCourse) courseId = newCourse.id;
      }
    }

    // 5. Guardar registro de sesión en Supabase
    const { error: sessionErr } = await supabase
      .from('sessions')
      .insert({
        otec_id: otec.id,
        course_id: courseId,
        wp_user_id: 0, // El plugin de WP asocia la sesión al alumno logueado
        wp_site_url: wpSiteUrl,
        run_alumno: RunAlumno || '',
        cod_sence: CodSence || '',
        codigo_curso: CodigoCurso || '',
        id_sesion_alumno: IdSesionAlumno,
        id_sesion_sence: IdSesionSence || null,
        linea_capacitacion: LineaCapacitacion ? parseInt(LineaCapacitacion, 10) : 3,
        fecha_hora_inicio: FechaHora || new Date().toISOString(),
        zona_horaria: ZonaHoraria || 'America/Santiago',
        session_opened_at: new Date().toISOString(),
        is_active: true
      });

    if (sessionErr) {
      console.error('[SENCE Callback] Error al registrar sesión en Supabase:', sessionErr);
    }

    // 6. Registrar en usage_log para auditoría y facturación
    await supabase.from('usage_log').insert({
      otec_id: otec.id,
      event_type: 'session_open',
      metadata: {
        run_alumno: RunAlumno,
        id_sesion_alumno: IdSesionAlumno,
        id_sesion_sence: IdSesionSence,
        wp_course_id: wpCourseId
      }
    });

    // 7. Redireccionar de vuelta al campus WordPress
    const finalRedirect = buildRedirectUrl(wpRetoma, {
      sence_success: '1',
      run: RunAlumno || '',
      id_sesion: IdSesionAlumno || ''
    });

    return res.redirect(302, finalRedirect);
  } catch (error) {
    console.error('[SENCE Callback] Excepción inesperada en /open:', error);
    const wpRetoma = req.query.wp_retoma || '/';
    return res.redirect(302, buildRedirectUrl(wpRetoma, { sence_error: 'SERVER_ERROR' }));
  }
});

/**
 * POST /callback/close
 * Callback invocado por SENCE al registrar el Cierre de Sesión
 */
router.post('/close', async (req, res) => {
  try {
    const wpRetoma = req.query.wp_retoma || '/';
    const idSesionAlumnoQuery = req.query.id_sesion_alumno;

    const {
      RunAlumno,
      IdSesionAlumno,
      IdSesionSence,
      FechaHora,
      ZonaHoraria,
      GlosaError
    } = req.body;

    const targetSessionId = IdSesionAlumno || idSesionAlumnoQuery;

    if (GlosaError) {
      console.warn(`[SENCE Callback Close] Error en cierre: Glosa ${GlosaError}`);
      return res.redirect(302, buildRedirectUrl(wpRetoma, { sence_error: GlosaError }));
    }

    if (!targetSessionId) {
      console.error('[SENCE Callback Close] No se proporcionó IdSesionAlumno');
      return res.redirect(302, buildRedirectUrl(wpRetoma, { sence_error: 'MISSING_SESSION_ID' }));
    }

    // Buscar sesión activa en Supabase
    const { data: session, error: findErr } = await supabase
      .from('sessions')
      .select('*')
      .eq('id_sesion_alumno', targetSessionId)
      .eq('is_active', true)
      .single();

    if (findErr || !session) {
      console.warn(`[SENCE Callback Close] Sesión no encontrada o ya cerrada: ${targetSessionId}`);
      return res.redirect(302, buildRedirectUrl(wpRetoma, { sence_closed: '1', note: 'SESSION_ALREADY_CLOSED' }));
    }

    // Calcular duración en segundos
    const openedAt = new Date(session.session_opened_at).getTime();
    const closedAt = new Date().getTime();
    const elapsedSeconds = Math.max(0, Math.floor((closedAt - openedAt) / 1000));

    // Actualizar sesión en Supabase
    await supabase
      .from('sessions')
      .update({
        session_closed_at: new Date().toISOString(),
        fecha_hora_cierre: FechaHora || new Date().toISOString(),
        tiempo_sesion_seg: elapsedSeconds,
        is_active: false
      })
      .eq('id', session.id);

    // Registro de auditoría
    await supabase.from('usage_log').insert({
      otec_id: session.otec_id,
      event_type: 'session_close',
      metadata: {
        session_id: session.id,
        id_sesion_alumno: targetSessionId,
        tiempo_sesion_seg: elapsedSeconds
      }
    });

    const finalRedirect = buildRedirectUrl(wpRetoma, {
      sence_closed: '1',
      duracion_seg: elapsedSeconds
    });

    return res.redirect(302, finalRedirect);
  } catch (error) {
    console.error('[SENCE Callback Close] Error procesando cierre:', error);
    const wpRetoma = req.query.wp_retoma || '/';
    return res.redirect(302, buildRedirectUrl(wpRetoma, { sence_error: 'CLOSE_SERVER_ERROR' }));
  }
});

/**
 * POST /callback/error
 * Callback invocado por SENCE si ocurre un fallo durante la interacción en su plataforma
 */
router.post('/error', async (req, res) => {
  try {
    const wpRetoma = req.query.wp_retoma || '/';
    const { GlosaError, IdSesionAlumno } = req.body;

    console.warn(`[SENCE Callback Error] GlosaError recibido: ${GlosaError}, Sesión: ${IdSesionAlumno}`);

    if (IdSesionAlumno) {
      await supabase
        .from('sessions')
        .update({
          error_code: GlosaError || 'UNKNOWN_ERROR',
          is_active: false
        })
        .eq('id_sesion_alumno', IdSesionAlumno);
    }

    const redirectUrl = buildRedirectUrl(wpRetoma, {
      sence_error: GlosaError || '300'
    });

    return res.redirect(302, redirectUrl);
  } catch (error) {
    console.error('[SENCE Callback Error] Error:', error);
    const wpRetoma = req.query.wp_retoma || '/';
    return res.redirect(302, buildRedirectUrl(wpRetoma, { sence_error: '300' }));
  }
});

module.exports = router;
