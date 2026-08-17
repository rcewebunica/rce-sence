# 🇨🇱 SENCE RCE — Plataforma Integral de Control de Asistencia e-Learning

Ecosistema completo para la integración con el **Registro de Control de e-Learning (RCE)** del **SENCE Chile** (Servicio Nacional de Capacitación y Empleo), conforme a la normativa oficial (**Manual de Integración v1.1.5**).

---

## 🏗️ Arquitectura del Repositorio

El proyecto incluye tanto la solución **SaaS Cloud (Multi-OTEC)** como el plugin **Standalone**:

```
.
├── ⚙️ sence-rce-server/         # Backend central Node.js / Express (Railway / Render / VPS)
│   ├── server.js               # Servidor API REST + Callbacks SENCE + Simulador Mock
│   ├── lib/                    # Cliente Supabase y gestión de Planes SaaS
│   ├── middleware/             # Autenticación con API Key por OTEC
│   └── routes/                 # /callback, /api, /mock
│
├── 🔌 sence-rce-client/         # Plugin WordPress SaaS (Thin Client para OTECs)
│   ├── sence-rce-client.php    # Plugin principal (v2.0.0)
│   ├── includes/               # Cliente API, Bloqueador de contenido, RUT Helper
│   └── assets/                 # Cronómetro en vivo, CSS responsivo y alertas SENCE
│
├── 📦 sence-rce-asistencia/     # Plugin WordPress Standalone (v1.1.0)
│   └── (Solución monolítica con base de datos local MySQL)
│
└── 🗄️ supabase/                 # Esquema y datos iniciales de PostgreSQL
    ├── schema.sql              # Tablas: otecs, courses, sessions, usage_log + RLS
    └── seed.sql                # Datos de prueba para OTECs y cursos
```

---

## 📋 Características Principales

- **Autenticación con Clave Única:** Integración directa con los portales de inicio y cierre de sesión de SENCE.
- **Multi-Tenant SaaS:** Cada OTEC gestiona sus cursos mediante su propia API Key con control de cuotas por plan.
- **Compatibilidad Tutor LMS:** Detección automática de cursos, lecciones y cuestionarios con bloqueo de contenido hasta registrar asistencia.
- **Resolución de Límites SENCE:**
  - `UrlRetoma` / `UrlError` resueltas mediante callbacks de alta velocidad en el servidor central (límite de 100 caracteres).
  - Normalización obligatoria de RUT/RUN sin puntos (`xxxxxxxx-x`, Módulo 11).
  - Manejo de Línea 1 (Programas Sociales / Becas Laborales) con `CodSence` en blanco.
  - Validación estricta de `CodSence` (10 dígitos) y `CodigoCurso` (mínimo 7 caracteres) para Líneas 3 y 6.
- **Cronómetro en Tiempo Real:** Visualización en vivo del tiempo de asistencia del alumno con alerta de advertencia en los últimos 10 minutos.
- **Simulador Mock SENCE:** Entorno de pruebas integrado para testear flujos sin necesidad de credenciales de producción.
- **Exportación CSV:** Descarga directa de asistencias en formato compatible con Excel y auditorías SENCE.

---

## 🚀 Guía de Despliegue Rápido

### 1. Base de Datos (Supabase)
1. Crea un proyecto en [Supabase](https://supabase.com).
2. Ve al **SQL Editor** y ejecuta el archivo `supabase/schema.sql`.

### 2. Backend Central (Railway)
1. Despliega la carpeta `sence-rce-server` en [Railway](https://railway.app).
2. Configura las variables de entorno requeridas:
   ```env
   SUPABASE_URL=https://tu-proyecto.supabase.co
   SUPABASE_SERVICE_KEY=tu_service_role_key
   PORT=3000
   ALLOWED_ORIGINS=*
   MASTER_ADMIN_KEY=clave_secreta_super_admin
   ```

### 3. WordPress (OTEC)
1. Instala el plugin `sence-rce-client` en tu instalación de WordPress con Tutor LMS.
2. Ve a **SENCE RCE ☁️ → Configuración**.
3. Ingresa la URL de tu servidor Railway y la API Key asignada.
4. Vincula tus cursos en **Cursos SENCE**.

---

## 📜 Licencia y Autor

Desarrollado por **Webunica Chile**  
Licencia: GPLv3 o posterior.
