const { supabase } = require('../lib/supabase');

/**
 * Middleware para requerir API Key de OTEC
 */
async function requireApiKey(req, res, next) {
  const apiKey = req.headers['x-api-key'] || req.query.api_key;

  if (!apiKey) {
    return res.status(401).json({
      error: 'Autenticación fallida: Se requiere el encabezado X-Api-Key o parámetro api_key.',
      code: 'MISSING_API_KEY'
    });
  }

  try {
    const { data: otec, error } = await supabase
      .from('otecs')
      .select('*')
      .eq('api_key', apiKey)
      .eq('is_active', true)
      .single();

    if (error || !otec) {
      return res.status(401).json({
        error: 'API Key inválida o la cuenta de la OTEC se encuentra suspendida.',
        code: 'INVALID_API_KEY'
      });
    }

    // Comprobar expiración del plan si existe
    if (otec.plan_expires_at && new Date(otec.plan_expires_at) < new Date()) {
      return res.status(403).json({
        error: 'El plan contratado ha expirado. Por favor renueve su suscripción.',
        code: 'PLAN_EXPIRED'
      });
    }

    req.otec = otec;
    next();
  } catch (err) {
    console.error('Error en middleware de autenticación:', err);
    return res.status(500).json({
      error: 'Error interno de validación de credenciales.',
      code: 'AUTH_SERVER_ERROR'
    });
  }
}

/**
 * Middleware para super-administrador
 */
function requireMasterKey(req, res, next) {
  const masterKey = req.headers['x-master-key'] || req.query.master_key;
  const expectedKey = process.env.MASTER_ADMIN_KEY;

  if (!expectedKey || masterKey !== expectedKey) {
    return res.status(403).json({
      error: 'Acceso denegado: Se requiere Master Key válida.',
      code: 'FORBIDDEN'
    });
  }

  next();
}

module.exports = {
  requireApiKey,
  requireMasterKey
};
