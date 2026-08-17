require('dotenv').config();
const express = require('express');
const helmet = require('helmet');
const cors = require('cors');
const morgan = require('morgan');
const rateLimit = require('express-rate-limit');

const apiRouter = require('./routes/api');
const callbacksRouter = require('./routes/callbacks');
const mockRouter = require('./routes/mock');

const app = express();
const PORT = process.env.PORT || 3000;

// 1. Seguridad Básica con Helmet (ajustando CSP para renderizado de mock)
app.use(
  helmet({
    contentSecurityPolicy: false // Permitir formularios POST directos de SENCE / Mock
  })
);

// 2. CORS dinámico
const allowedOrigins = process.env.ALLOWED_ORIGINS
  ? process.env.ALLOWED_ORIGINS.split(',').map(o => o.trim())
  : ['*'];

app.use(
  cors({
    origin: (origin, callback) => {
      // Permitir solicitudes sin origin (como callbacks de servidores, curl, o navegadores en POST)
      if (!origin || allowedOrigins.includes('*') || allowedOrigins.includes(origin)) {
        return callback(null, true);
      }
      return callback(null, true); // En desarrollo o testing permitimos flexibilidad
    },
    credentials: true
  })
);

// 3. Logger de peticiones
if (process.env.NODE_ENV !== 'test') {
  app.use(morgan('combined'));
}

// 4. Rate Limiters
const apiLimiter = rateLimit({
  windowMs: 15 * 60 * 1000, // 15 minutos
  max: 200, // límite de peticiones por IP
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'Demasiadas solicitudes a la API. Intente nuevamente en unos minutos.' }
});

const callbackLimiter = rateLimit({
  windowMs: 1 * 60 * 1000, // 1 minuto
  max: 60, // SENCE puede enviar múltiples peticiones concurrentes
  standardHeaders: true,
  legacyHeaders: false
});

// 5. Body Parsers — CRÍTICO: SENCE envía application/x-www-form-urlencoded
app.use(express.urlencoded({ extended: true, limit: '10mb' }));
app.use(express.json({ limit: '10mb' }));

// 6. Endpoint de Health Check
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    service: 'sence-rce-cloud-backend',
    version: '1.0.0',
    timestamp: new Date().toISOString()
  });
});

// 7. Montar Enrutadores
app.use('/api', apiLimiter, apiRouter);
app.use('/callback', callbackLimiter, callbacksRouter);
app.use('/mock', mockRouter);

// 8. Manejador 404
app.use((req, res) => {
  res.status(404).json({
    error: 'Ruta no encontrada',
    path: req.originalUrl
  });
});

// 9. Manejador Global de Errores
app.use((err, req, res, next) => {
  console.error('[Global Error]', err);
  res.status(500).json({
    error: 'Error interno del servidor',
    message: process.env.NODE_ENV === 'development' ? err.message : undefined
  });
});

// 10. Iniciar Servidor
if (process.env.NODE_ENV !== 'test') {
  app.listen(PORT, () => {
    console.log(`=======================================================`);
    console.log(`🚀 SENCE RCE SaaS Server corriendo en el puerto ${PORT}`);
    console.log(`📍 Health Check: http://localhost:${PORT}/health`);
    console.log(`📍 Simulador Mock: http://localhost:${PORT}/mock`);
    console.log(`=======================================================`);
  });
}

module.exports = app;
