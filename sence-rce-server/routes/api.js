const express = require('express');
const router = express.Router();
const { supabase } = require('../lib/supabase');
const { requireApiKey } = require('../middleware/auth');

// Todas las rutas de esta API requieren API Key válida
router.use(requireApiKey);

/**
 * GET /api/session/status
 * Consulta si un usuario/alumno tiene sesión activa en un curso
 */
router.get('/session/status', async (req, res) => {
  try {
    const otec = req.otec;
    const wpCourseId = req.query.wp_course_id ? parseInt(req.query.wp_course_id, 10) : null;
    const wpUserId = req.query.wp_user_id ? parseInt(req.query.wp_user_id, 10) : null;
    const runAlumno = req.query.run_alumno || null;

    if (!wpCourseId) {
      return res.status(400).json({ error: 'Parámetro wp_course_id requerido' });
    }

    // 1. Obtener configuración del curso
    const { data: course } = await supabase
      .from('courses')
      .select('*')
      .eq('otec_id', otec.id)
      .eq('wp_course_id', wpCourseId)
      .single();

    // Fecha actual en zona horaria de Chile (YYYY-MM-DD)
    const todayCl = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Santiago' }).format(new Date());
    const fechaInicio = course && course.fecha_inicio ? course.fecha_inicio : null;
    const fechaTermino = course && course.fecha_termino ? course.fecha_termino : null;
    let accessState = 'open';
    if (fechaInicio && todayCl < fechaInicio) accessState = 'not_started';
    else if (fechaTermino && todayCl > fechaTermino) accessState = 'ended';

    const courseConfig = {
      codigo_sence: course ? course.codigo_sence : '',
      codigo_curso: course ? course.codigo_curso : '',
      linea_capacitacion: course ? course.linea_capacitacion : 3,
      solicitar_cierre: course ? course.solicitar_cierre : false,
      asistencia_obligatoria: course ? course.asistencia_obligatoria : true,
      fecha_inicio: fechaInicio,
      fecha_termino: fechaTermino,
      access_state: accessState
    };

    // 2. Buscar sesión activa
    let sessionQuery = supabase
      .from('sessions')
      .select('*')
      .eq('otec_id', otec.id)
      .eq('is_active', true);

    if (course) {
      sessionQuery = sessionQuery.eq('course_id', course.id);
    }

    if (runAlumno) {
      sessionQuery = sessionQuery.eq('run_alumno', runAlumno);
    }

    const { data: sessions } = await sessionQuery.order('session_opened_at', { ascending: false }).limit(1);
    const activeSession = sessions && sessions.length > 0 ? sessions[0] : null;

    if (activeSession) {
      const openedAt = new Date(activeSession.session_opened_at).getTime();
      const elapsedSeconds = Math.max(0, Math.floor((Date.now() - openedAt) / 1000));

      return res.json({
        has_session: true,
        session: {
          id: activeSession.id,
          id_sesion_alumno: activeSession.id_sesion_alumno,
          id_sesion_sence: activeSession.id_sesion_sence,
          run_alumno: activeSession.run_alumno,
          session_opened_at: activeSession.session_opened_at,
          elapsed_seconds: elapsedSeconds
        },
        course_config: courseConfig
      });
    }

    return res.json({
      has_session: false,
      session: null,
      course_config: courseConfig
    });
  } catch (error) {
    console.error('Error en /api/session/status:', error);
    res.status(500).json({ error: 'Error al consultar estado de sesión' });
  }
});

/**
 * GET /api/course/config
 * Obtiene la configuración SENCE de un curso en particular
 */
router.get('/course/config', async (req, res) => {
  try {
    const otec = req.otec;
    const wpCourseId = parseInt(req.query.wp_course_id, 10);

    if (!wpCourseId) {
      return res.status(400).json({ error: 'wp_course_id requerido' });
    }

    const { data: course, error } = await supabase
      .from('courses')
      .select('*')
      .eq('otec_id', otec.id)
      .eq('wp_course_id', wpCourseId)
      .single();

    if (error || !course) {
      return res.json({});
    }

    res.json(course);
  } catch (error) {
    console.error('Error en /api/course/config:', error);
    res.status(500).json({ error: 'Error al obtener configuración de curso' });
  }
});

/**
 * POST /api/course/upsert
 * Sincroniza o actualiza la configuración de un curso desde WordPress
 */
router.post('/course/upsert', async (req, res) => {
  try {
    const otec = req.otec;
    const {
      wp_course_id,
      wp_site_url,
      nombre_curso,
      codigo_sence,
      codigo_curso,
      linea_capacitacion,
      asistencia_obligatoria,
      solicitar_cierre,
      fecha_inicio,
      fecha_termino,
      email_respaldo
    } = req.body;

    if (!wp_course_id) {
      return res.status(400).json({ error: 'wp_course_id es requerido' });
    }

    const toDate = (v) => (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : null);
    const fechaInicio = toDate(fecha_inicio);
    const fechaTermino = toDate(fecha_termino);
    if (fechaInicio && fechaTermino && fechaTermino < fechaInicio) {
      return res.status(400).json({ error: 'La fecha de término no puede ser anterior a la fecha de inicio' });
    }

    const payload = {
      otec_id: otec.id,
      wp_course_id: parseInt(wp_course_id, 10),
      wp_site_url: wp_site_url || '',
      nombre_curso: nombre_curso || '',
      codigo_sence: codigo_sence || '',
      codigo_curso: codigo_curso || '',
      linea_capacitacion: linea_capacitacion ? parseInt(linea_capacitacion, 10) : 3,
      asistencia_obligatoria: asistencia_obligatoria === undefined ? true : Boolean(asistencia_obligatoria),
      solicitar_cierre: Boolean(solicitar_cierre),
      fecha_inicio: fechaInicio,
      fecha_termino: fechaTermino,
      email_respaldo: email_respaldo ? String(email_respaldo).trim() : null,
      is_active: true
    };

    let { data, error } = await supabase
      .from('courses')
      .upsert(payload, { onConflict: 'otec_id,wp_course_id,wp_site_url' })
      .select()
      .single();

    if (error) {
      console.error('Error al hacer upsert de curso:', error);

      // Si el error es por falta de columnas opcionales en Supabase
      const isMissingOptionalColumns =
        (error.message && (error.message.includes('fecha_inicio') || error.message.includes('fecha_termino') || error.message.includes('email_respaldo'))) ||
        error.code === 'PGRST204';

      if (isMissingOptionalColumns) {
        console.warn('Columnas opcionales no existen en Supabase. Reintentando guardar sin ellas...');
        const fallbackPayload = { ...payload };
        delete fallbackPayload.fecha_inicio;
        delete fallbackPayload.fecha_termino;
        delete fallbackPayload.email_respaldo;

        const retry = await supabase
          .from('courses')
          .upsert(fallbackPayload, { onConflict: 'otec_id,wp_course_id,wp_site_url' })
          .select()
          .single();

        if (!retry.error) {
          return res.json({
            success: true,
            course: retry.data,
            warning: 'La configuración básica se guardó, pero para habilitar fechas y email de respaldo debes ejecutar en Supabase SQL Editor: ALTER TABLE courses ADD COLUMN IF NOT EXISTS fecha_inicio DATE; ALTER TABLE courses ADD COLUMN IF NOT EXISTS fecha_termino DATE; ALTER TABLE courses ADD COLUMN IF NOT EXISTS email_respaldo TEXT;'
          });
        }
      }

      const errMsg = error.message ? `No se pudo guardar en Supabase: ${error.message}` : 'No se pudo guardar la configuración del curso';
      return res.status(500).json({ error: errMsg });
    }

    res.json({ success: true, course: data });
  } catch (error) {
    console.error('Error en /api/course/upsert:', error);
    res.status(500).json({ error: 'Error interno en upsert' });
  }
});

/**
 * GET /api/sessions
 * Listado paginado y filtrable de sesiones de asistencia
 */
router.get('/sessions', async (req, res) => {
  try {
    const otec = req.otec;
    const limit = Math.min(parseInt(req.query.limit, 10) || 50, 200);
    const offset = parseInt(req.query.offset, 10) || 0;
    const wpCourseId = req.query.wp_course_id ? parseInt(req.query.wp_course_id, 10) : null;
    const dateFrom = req.query.date_from;
    const dateTo = req.query.date_to;
    const searchRun = req.query.run_alumno;

    let query = supabase
      .from('session_summary')
      .select('*', { count: 'exact' })
      .eq('otec_id', otec.id);

    if (wpCourseId) {
      const { data: matchedCourses } = await supabase
        .from('courses')
        .select('id, codigo_curso')
        .eq('otec_id', otec.id)
        .eq('wp_course_id', wpCourseId);

      const targetCourseIds = [wpCourseId];
      let matchCodCurso = null;
      if (matchedCourses && matchedCourses.length > 0) {
        matchedCourses.forEach(c => {
          if (!targetCourseIds.includes(c.id)) {
            targetCourseIds.push(c.id);
          }
          if (c.codigo_curso) {
            matchCodCurso = c.codigo_curso;
          }
        });
      }

      if (matchCodCurso) {
        query = query.or(`course_id.in.(${targetCourseIds.join(',')}),codigo_curso.eq.${matchCodCurso}`);
      } else {
        query = query.in('course_id', targetCourseIds);
      }
    }

    if (searchRun) {
      query = query.ilike('run_alumno', `%${searchRun}%`);
    }

    if (dateFrom) {
      query = query.gte('session_opened_at', dateFrom);
    }

    if (dateTo) {
      query = query.lte('session_opened_at', dateTo);
    }

    // Auto-conciliación de sesiones huérfanas de más de 4 horas (tope reglamentario SENCE)
    try {
      const fourHoursAgo = new Date(Date.now() - 4 * 60 * 60 * 1000).toISOString();
      const { data: expiredSessions } = await supabase
        .from('sessions')
        .select('id, session_opened_at')
        .eq('otec_id', otec.id)
        .eq('is_active', true)
        .lt('session_opened_at', fourHoursAgo);

      if (expiredSessions && expiredSessions.length > 0) {
        for (const exp of expiredSessions) {
          await supabase
            .from('sessions')
            .update({
              session_closed_at: new Date().toISOString(),
              fecha_hora_cierre: new Date().toISOString().replace('T', ' ').substring(0, 19),
              tiempo_sesion_seg: 14400, // 4 horas tope SENCE
              is_active: false
            })
            .eq('id', exp.id);
        }
      }
    } catch (e) {
      console.warn('Advertencia en limpieza de sesiones huérfanas:', e);
    }

    const { data: sessions, count, error } = await query
      .order('session_opened_at', { ascending: false })
      .range(offset, offset + limit - 1);

    if (error) {
      console.error('Error al consultar sesiones:', error);
      return res.status(500).json({ error: 'Error al consultar sesiones' });
    }

    res.json({
      sessions: sessions || [],
      total: count || 0,
      limit,
      offset
    });
  } catch (error) {
    console.error('Error en /api/sessions:', error);
    res.status(500).json({ error: 'Error interno de sesiones' });
  }
});

/**
 * POST /api/sessions/close-manual
 * Permite al administrador cerrar manualmente una sesión que quedó abierta
 */
router.post('/sessions/close-manual', async (req, res) => {
  try {
    const otec = req.otec;
    const sessionId = req.body.session_id ? parseInt(req.body.session_id, 10) : null;

    if (!sessionId) {
      return res.status(400).json({ error: 'session_id requerido' });
    }

    const { data: session, error: findErr } = await supabase
      .from('sessions')
      .select('*')
      .eq('id', sessionId)
      .eq('otec_id', otec.id)
      .single();

    if (findErr || !session) {
      return res.status(404).json({ error: 'Sesión no encontrada o no pertenece a esta OTEC' });
    }

    if (!session.is_active) {
      return res.json({ success: true, message: 'La sesión ya estaba cerrada', session });
    }

    const now = new Date();
    const openedAt = new Date(session.session_opened_at).getTime();
    const elapsedSeconds = Math.min(Math.max(0, Math.floor((now.getTime() - openedAt) / 1000)), 14400);

    const { data: updated, error: updErr } = await supabase
      .from('sessions')
      .update({
        session_closed_at: now.toISOString(),
        fecha_hora_cierre: now.toISOString().replace('T', ' ').substring(0, 19),
        tiempo_sesion_seg: elapsedSeconds,
        is_active: false
      })
      .eq('id', session.id)
      .select()
      .single();

    if (updErr) {
      return res.status(500).json({ error: 'Error al actualizar sesión en base de datos' });
    }

    res.json({ success: true, message: 'Sesión cerrada exitosamente', session: updated });
  } catch (err) {
    console.error('Error en /api/sessions/close-manual:', err);
    res.status(500).json({ error: 'Error interno al cerrar sesión' });
  }
});

/**
 * POST /api/sessions/reset
 * Elimina todos los registros de asistencia de la OTEC (para reiniciar el libro digital en blanco)
 * Requiere confirmación estricta de seguridad.
 */
router.post('/sessions/reset', async (req, res) => {
  try {
    const otec = req.otec;
    const { confirmation_code } = req.body;

    if (confirmation_code !== 'CONFIRMAR_BORRADO_SENCE') {
      return res.status(400).json({
        error: 'Código de confirmación de seguridad inválido. Operación cancelada por protección de datos SENCE.'
      });
    }

    const { error } = await supabase
      .from('sessions')
      .delete()
      .eq('otec_id', otec.id);

    if (error) {
      console.error('Error al resetear sesiones:', error);
      return res.status(500).json({ error: 'Error al eliminar registros de asistencia: ' + error.message });
    }

    res.json({ success: true, message: 'Todos los registros de asistencia han sido eliminados correctamente.' });
  } catch (err) {
    console.error('Error en /api/sessions/reset:', err);
    res.status(500).json({ error: 'Error interno al resetear registros.' });
  }
});

/**
 * GET /api/sessions/export-csv
 * Descarga directa de archivo CSV con las asistencias
 */
router.get('/sessions/export-csv', async (req, res) => {
  try {
    const otec = req.otec;
    let query = supabase
      .from('session_summary')
      .select('*')
      .eq('otec_id', otec.id);

    if (req.query.wp_course_id) {
      const wpCourseId = parseInt(req.query.wp_course_id, 10);
      const { data: matchedCourses } = await supabase
        .from('courses')
        .select('id, codigo_curso')
        .eq('otec_id', otec.id)
        .eq('wp_course_id', wpCourseId);

      const targetCourseIds = [wpCourseId];
      let matchCodCurso = null;
      if (matchedCourses && matchedCourses.length > 0) {
        matchedCourses.forEach(c => {
          if (!targetCourseIds.includes(c.id)) {
            targetCourseIds.push(c.id);
          }
          if (c.codigo_curso) {
            matchCodCurso = c.codigo_curso;
          }
        });
      }

      if (matchCodCurso) {
        query = query.or(`course_id.in.(${targetCourseIds.join(',')}),codigo_curso.eq.${matchCodCurso}`);
      } else {
        query = query.in('course_id', targetCourseIds);
      }
    }

    const { data: sessions, error } = await query
      .order('session_opened_at', { ascending: false })
      .limit(5000);

    if (error) {
      return res.status(500).send('Error generando exportación');
    }

    const filename = `asistencia-sence-${otec.rut_otec}-${new Date().toISOString().split('T')[0]}.csv`;

    res.setHeader('Content-Type', 'text/csv; charset=utf-8');
    res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);

    // BOM para Excel en español
    res.write('\uFEFF');

    // Encabezados CSV
    const headers = [
      'ID',
      'Curso',
      'Cod SENCE',
      'Cod Curso',
      'RUN Alumno',
      'ID Sesión Alumno',
      'ID Sesión SENCE',
      'Fecha Inicio',
      'Fecha Cierre',
      'Duración (seg)',
      'Duración Formato',
      'Estado'
    ];
    res.write(headers.join(';') + '\r\n');

    function formatChileDate(isoDate) {
      if (!isoDate) return '';
      try {
        const d = new Date(isoDate);
        if (isNaN(d.getTime())) return isoDate;
        return d.toLocaleString('es-CL', {
          timeZone: 'America/Santiago',
          year: 'numeric',
          month: '2-digit',
          day: '2-digit',
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          hour12: false
        });
      } catch (e) {
        return isoDate;
      }
    }

    for (const s of sessions || []) {
      const row = [
        s.session_id,
        `"${(s.nombre_curso || '').replace(/"/g, '""')}"`,
        `"${s.codigo_sence || ''}"`,
        `"${s.codigo_curso || ''}"`,
        `"${s.run_alumno || ''}"`,
        `"${s.id_sesion_alumno || ''}"`,
        `"${s.id_sesion_sence || ''}"`,
        `"${formatChileDate(s.session_opened_at)}"`,
        `"${formatChileDate(s.session_closed_at)}"`,
        s.tiempo_sesion_seg || 0,
        `"${s.tiempo_sesion_formateado || ''}"`,
        `"${s.estado_sesion || ''}"`
      ];
      res.write(row.join(';') + '\r\n');
    }

    res.end();
  } catch (error) {
    console.error('Error exportando CSV:', error);
    res.status(500).send('Error exportando CSV');
  }
});

/**
 * GET /api/stats
 * Resumen de métricas y consumo para el panel de control
 */
router.get('/stats', async (req, res) => {
  try {
    const otec = req.otec;

    const now = new Date();
    const startOfMonth = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1)).toISOString();

    // Total de sesiones históricas
    const { count: totalSessions } = await supabase
      .from('sessions')
      .select('id', { count: 'exact', head: true })
      .eq('otec_id', otec.id);

    // Sesiones activas actualmente
    const { count: activeSessions } = await supabase
      .from('sessions')
      .select('id', { count: 'exact', head: true })
      .eq('otec_id', otec.id)
      .eq('is_active', true);

    // Sesiones en el mes actual
    const { count: monthSessions } = await supabase
      .from('sessions')
      .select('id', { count: 'exact', head: true })
      .eq('otec_id', otec.id)
      .gte('session_opened_at', startOfMonth);

    // Total de cursos
    const { count: totalCourses } = await supabase
      .from('courses')
      .select('id', { count: 'exact', head: true })
      .eq('otec_id', otec.id)
      .eq('is_active', true);

    res.json({
      total_sessions: totalSessions || 0,
      active_sessions: activeSessions || 0,
      sessions_this_month: monthSessions || 0,
      total_courses: totalCourses || 0
    });
  } catch (error) {
    console.error('Error en /api/stats:', error);
    res.status(500).json({ error: 'Error al obtener estadísticas' });
  }
});

module.exports = router;
