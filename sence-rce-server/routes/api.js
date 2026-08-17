const express = require('express');
const router = express.Router();
const { supabase } = require('../lib/supabase');
const { requireApiKey } = require('../middleware/auth');
const { PLANS, checkPlanLimit, checkCoursesLimit } = require('../lib/plans');

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

    const courseConfig = {
      codigo_sence: course ? course.codigo_sence : '',
      codigo_curso: course ? course.codigo_curso : '',
      linea_capacitacion: course ? course.linea_capacitacion : 3,
      solicitar_cierre: course ? course.solicitar_cierre : false,
      asistencia_obligatoria: course ? course.asistencia_obligatoria : true
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
      solicitar_cierre
    } = req.body;

    if (!wp_course_id) {
      return res.status(400).json({ error: 'wp_course_id es requerido' });
    }

    // Verificar límite de cursos según plan si es inserción
    const { data: existing } = await supabase
      .from('courses')
      .select('id')
      .eq('otec_id', otec.id)
      .eq('wp_course_id', wp_course_id)
      .single();

    if (!existing) {
      const courseCheck = await checkCoursesLimit(otec);
      if (!courseCheck.allowed) {
        return res.status(403).json({
          error: courseCheck.reason,
          code: 'PLAN_LIMIT_REACHED'
        });
      }
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
      is_active: true
    };

    const { data, error } = await supabase
      .from('courses')
      .upsert(payload, { onConflict: 'otec_id,wp_course_id,wp_site_url' })
      .select()
      .single();

    if (error) {
      console.error('Error al hacer upsert de curso:', error);
      return res.status(500).json({ error: 'No se pudo guardar la configuración del curso' });
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
      query = query.eq('course_id', wpCourseId);
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
 * GET /api/sessions/export-csv
 * Descarga directa de archivo CSV con las asistencias
 */
router.get('/sessions/export-csv', async (req, res) => {
  try {
    const otec = req.otec;
    const { data: sessions, error } = await supabase
      .from('session_summary')
      .select('*')
      .eq('otec_id', otec.id)
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

    for (const s of sessions || []) {
      const row = [
        s.session_id,
        `"${(s.nombre_curso || '').replace(/"/g, '""')}"`,
        `"${s.codigo_sence || ''}"`,
        `"${s.codigo_curso || ''}"`,
        `"${s.run_alumno || ''}"`,
        `"${s.id_sesion_alumno || ''}"`,
        `"${s.id_sesion_sence || ''}"`,
        `"${s.session_opened_at || ''}"`,
        `"${s.session_closed_at || ''}"`,
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
    const planType = otec.plan || 'free';
    const planDef = PLANS[planType] || PLANS.free;

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

    const sessionsUsed = monthSessions || 0;
    const sessionsLimit = planDef.max_sessions_month;
    const percentUsed = sessionsLimit > 0 ? Math.min(100, Math.round((sessionsUsed / sessionsLimit) * 100)) : 0;

    res.json({
      total_sessions: totalSessions || 0,
      active_sessions: activeSessions || 0,
      sessions_this_month: sessionsUsed,
      total_courses: totalCourses || 0,
      plan: {
        id: planDef.id,
        name: planDef.name,
        max_courses: planDef.max_courses,
        max_sessions_month: planDef.max_sessions_month,
        price_clp: planDef.price_clp
      },
      usage: {
        sessions_used: sessionsUsed,
        sessions_limit: sessionsLimit,
        percent: percentUsed
      }
    });
  } catch (error) {
    console.error('Error en /api/stats:', error);
    res.status(500).json({ error: 'Error al obtener estadísticas' });
  }
});

/**
 * GET /api/plan
 * Información detallada del plan contratado y catálogo de planes
 */
router.get('/plan', async (req, res) => {
  try {
    const otec = req.otec;
    const planType = otec.plan || 'free';
    const planDef = PLANS[planType] || PLANS.free;

    const planCheck = await checkPlanLimit(otec);
    const courseCheck = await checkCoursesLimit(otec);

    res.json({
      current_plan: {
        ...planDef,
        expires_at: otec.plan_expires_at,
        is_active: otec.is_active
      },
      usage: {
        sessions: {
          current: planCheck.current,
          limit: planCheck.limit,
          allowed: planCheck.allowed
        },
        courses: {
          current: courseCheck.current,
          limit: courseCheck.limit,
          allowed: courseCheck.allowed
        }
      },
      all_plans: Object.values(PLANS)
    });
  } catch (error) {
    console.error('Error en /api/plan:', error);
    res.status(500).json({ error: 'Error al obtener plan' });
  }
});

module.exports = router;
