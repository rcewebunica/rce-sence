const { supabase } = require('./supabase');

/**
 * Definición de planes SaaS para OTECs
 */
const PLANS = {
  free: {
    id: 'free',
    name: 'Gratuito',
    max_courses: 1,
    max_sessions_month: 50,
    price_clp: 0,
    description: 'Ideal para pruebas y validación inicial con SENCE.'
  },
  starter: {
    id: 'starter',
    name: 'Starter',
    max_courses: 5,
    max_sessions_month: 500,
    price_clp: 29900,
    description: 'Para OTECs pequeñas con cursos regulares.'
  },
  pro: {
    id: 'pro',
    name: 'Pro',
    max_courses: 20,
    max_sessions_month: 2000,
    price_clp: 79900,
    description: 'Para OTECs con alta demanda y múltiples cursos simultáneos.'
  },
  enterprise: {
    id: 'enterprise',
    name: 'Enterprise',
    max_courses: -1, // Ilimitado
    max_sessions_month: -1, // Ilimitado
    price_clp: -1,
    description: 'Capacidad ilimitada y soporte prioritario dedicado.'
  }
};

/**
 * Verifica si la OTEC ha excedido el límite mensual de sesiones de asistencia
 */
async function checkPlanLimit(otec) {
  const planType = otec.plan || 'free';
  const planDef = PLANS[planType] || PLANS.free;

  // Enterprise no tiene límite
  if (planDef.max_sessions_month === -1) {
    return { allowed: true, current: 0, limit: -1, plan: planDef.name };
  }

  // Obtener primer día del mes actual en UTC
  const now = new Date();
  const startOfMonth = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1)).toISOString();

  const { count, error } = await supabase
    .from('sessions')
    .select('id', { count: 'exact', head: true })
    .eq('otec_id', otec.id)
    .gte('session_opened_at', startOfMonth);

  if (error) {
    console.error('Error al verificar límite de plan:', error);
    // En caso de fallo de BD temporal, permitimos la sesión para no bloquear al alumno
    return { allowed: true, current: 0, limit: planDef.max_sessions_month, plan: planDef.name };
  }

  const currentUsage = count || 0;
  const isAllowed = currentUsage < planDef.max_sessions_month;

  return {
    allowed: isAllowed,
    current: currentUsage,
    limit: planDef.max_sessions_month,
    plan: planDef.name,
    reason: isAllowed ? null : `Límite mensual del plan ${planDef.name} alcanzado (${currentUsage}/${planDef.max_sessions_month} sesiones).`
  };
}

/**
 * Verifica si la OTEC puede registrar un nuevo curso según su plan
 */
async function checkCoursesLimit(otec) {
  const planType = otec.plan || 'free';
  const planDef = PLANS[planType] || PLANS.free;

  if (planDef.max_courses === -1) {
    return { allowed: true, current: 0, limit: -1, plan: planDef.name };
  }

  const { count, error } = await supabase
    .from('courses')
    .select('id', { count: 'exact', head: true })
    .eq('otec_id', otec.id)
    .eq('is_active', true);

  if (error) {
    console.error('Error al verificar límite de cursos:', error);
    return { allowed: true, current: 0, limit: planDef.max_courses, plan: planDef.name };
  }

  const currentCourses = count || 0;
  const isAllowed = currentCourses < planDef.max_courses;

  return {
    allowed: isAllowed,
    current: currentCourses,
    limit: planDef.max_courses,
    plan: planDef.name,
    reason: isAllowed ? null : `Límite de cursos activos alcanzado para el plan ${planDef.name} (${currentCourses}/${planDef.max_courses}).`
  };
}

module.exports = {
  PLANS,
  checkPlanLimit,
  checkCoursesLimit
};
