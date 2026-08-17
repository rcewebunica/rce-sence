=== SENCE RCE - Control Asistencia e-Learning ===
Contributors: studioo
Tags: sence, rce, asistencia, e-learning, tutor-lms, chile
Requires at least: 5.6
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Plugin de integración con el Registro de Control e-Learning (RCE) de SENCE Chile para WordPress + Tutor LMS.

== Description ==

Este plugin implementa el control de asistencia e-learning exigido por SENCE Chile (Servicio Nacional de Capacitación y Empleo). Permite que los alumnos registren su asistencia mediante el inicio de sesión con su Clave Única antes de acceder al contenido del curso.

**Características:**

* Integración directa con la API RCE de SENCE (Producción y Test)
* Compatible con Tutor LMS
* Bloqueo de contenido hasta registrar asistencia SENCE
* Inicio y cierre de sesión SENCE con timer
* Configuración global y por curso (Multi-OTEC)
* Soporte para líneas de capacitación 1, 3 y 6
* Grupo "Becarios" (alumnos exentos de SENCE)
* Reportes de asistencia con exportación CSV
* Dashboard con estadísticas en tiempo real
* Manejo completo de errores SENCE (códigos 100-310)
* Shortcode [sence_rce] para inserción manual

**Flujo de Funcionamiento:**

1. El alumno entra al curso en Tutor LMS
2. El plugin muestra "Iniciar Sesión SENCE" (bloquea el contenido)
3. SENCE valida las credenciales del alumno
4. SENCE redirige de vuelta al sitio con datos de sesión
5. El plugin registra la asistencia en la base de datos
6. El contenido del curso se desbloquea
7. (Opcional) Se muestra timer y botón "Cerrar Sesión SENCE"

**Requisitos:**

* WordPress 5.6+
* PHP 7.4+
* Tutor LMS (recomendado)
* Credenciales SENCE (RUT OTEC, Token)
* Los alumnos deben tener su RUT registrado en su perfil

== Installation ==

1. Suba la carpeta `sence-rce-asistencia` al directorio `/wp-content/plugins/`
2. Active el plugin desde el menú "Plugins" de WordPress
3. Vaya a "SENCE RCE > Configuración" y complete sus datos de OTEC
4. Configure cada curso en "SENCE RCE > Cursos SENCE"
5. Asegúrese de que los alumnos tengan su RUT cargado

== Changelog ==

= 1.1.0 =
* Corrección de seguridad: bloque DEBUG ahora solo visible para administradores con WP_DEBUG activo
* Token ya no se expone completo en modo debug (se enmascara con •)
* RutOtec ahora siempre se envía sin puntos a SENCE (formato xxxxxxxx-x según §3.2 del manual)
* validate_config() ahora verifica CodSence exactamente 10 chars en producción (Error 204)
* validate_config() ahora verifica CodigoCurso mínimo 7 chars en producción (Error 205)
* validate_config() ahora verifica Token exactamente 36 chars UUID en producción
* Diagnóstico corrige umbral UrlRetoma a 100 chars (límite real del manual SENCE §3.2)
* admin-courses.php: fallback de post type corregido de 'courses' a 'course'
* class-session-manager.php: close_session() ya no lee $_POST directamente
* class-activator.php: agrega UNIQUE KEY en id_sesion_alumno para evitar sesiones duplicadas
* CodSence enviado en blanco para Programas Sociales / Becas Laborales (Línea 1)
* Eliminado campo FechaHora del formulario de cierre de sesión

= 1.0.2 =
* Compatibilidad con Manual SENCE v1.1.6
* Soporte para FPT e-learning (Línea de Capacitación 6)
* Correcciones menores

= 1.0.1 =
* Actualización a Manual SENCE v1.1.5 (Clave Única)
* Cambio de textos y enlaces de ayuda a Portal Clave Única
* Agregados códigos de error 311, 312, 313
* Campo RUT en perfil de usuario y registro

= 1.0.0 =
* Versión inicial
* Integración completa con RCE de SENCE
* Soporte Tutor LMS
* Dashboard, reportes y exportación CSV
* Bloqueo de contenido con inicio/cierre de sesión
