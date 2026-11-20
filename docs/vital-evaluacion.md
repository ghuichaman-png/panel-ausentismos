# Evaluación del design system Vital para la app unida

Fecha: 19-11-2026 · Fuente: `Vital.zip` (33 archivos: README, `tokens.css`/`tokens.json`, `components/bundle.css` y `bundle.js`, la fuente Figtree con licencia OFL y guías de 7 componentes). Es la nueva referencia visual que reemplaza a `docs/referencia-estilo.png` y la que servirá de base para la guía de la Fase 3.

## 1. Qué es Vital

- **Tokens** de color en tema claro y **oscuro**:
  - lienzo `bg` `#f4f7f6`, `surface` blanco y `surface-sunken` para campos;
  - marca `brand` `#08766b` (verde azulado), coral `accent` usado una vez por bloque;
  - 5 tintes pastel por área clínica;
  - 4 estados con significado fijo: `success`, `warning`, `danger` e `info`.
- **Espaciado** de 4 px (4 a 96).
- **Radios** de 8, 14, 20 y 28 px, más la píldora.
- **Dos sombras suaves.**
- **Tipografía**: una sola familia, **Figtree** variable, con los archivos incluidos. La escala va de `display` 80 px a `caption` 13 px y el cuerpo es de 17 px. En el celular se baja un escalón.
- **Componentes**: Button, Badge, Card, MetricCard, TextField, SegmentedControl y NavBar. Hay **CSS puro** (`vt-*`) y versiones **React 18** opcionales.
- **Voz**: trato de tú, verbo primero en los botones, titulares en tipo oración, sin emojis, sin signos de exclamación y sin alarmar.

## 2. Encaje con lo decidido

| Tema | Encaje |
|---|---|
| Arquitectura recomendada (camino 2: PHP + JavaScript sin compilar) | **Bueno.** Los componentes son clases CSS que se usan sin React. `bundle.js` (React) **no hace falta** y no lo cargaría. |
| Hosting y CSP | **Bueno.** Las fuentes vienen en el paquete y se sirven desde el mismo dominio, así que se puede quitar Google Fonts y la CSP queda más estricta (`'self'`). Además funciona si la red del hospital bloquea Google. |
| Estilo descrito en el encargo (encabezado claro, "Pedir días" en píldora verde azulado, pestañas de texto) | **Muy parecido.** `brand` `#08766b` es casi igual a la `--marca` actual `#0e6b7c`. NavBar = barra con el nombre en texto, enlaces "Calendario · Mis solicitudes" y una acción primaria. |
| Accesibilidad | **Bueno.** Medí todos los pares de texto y todos superan 4,5:1 en claro y en oscuro (p. ej. `brand` sobre `bg` 5,11; `danger` sobre `danger-soft` 4,68; texto blanco sobre `brand` 5,51). Foco de 2 px en `focus`, con contraste de 5 o más. |
| Estados de solicitudes y de cupo | **Directo** (ver la §3). |

## 3. Cómo se traducen nuestros estados a Vital

| Estado en la app | Vital | Texto visible |
|---|---|---|
| Día libre | `success` / `success-soft` | "Libre" |
| Cupo parcial (p. ej. "1 de 2", por bloque AM/PM) | `warning` / `warning-soft` | "1 de 2" |
| Lleno | `danger` / `danger-soft` | "Lleno" |
| Pendiente | `info` / `info-soft` | "Pendiente" |
| Autorizada | `success` | "Autorizada" |
| Rechazada | `danger` | "Rechazada" |
| Anulada / vencida | `neutral` | "Anulada" / "Vencida" |
| Sobre cupo | `warning` | "Sobre cupo" |
| No hábil (fin de semana o feriado) | `surface-sunken` + `ink-muted` | "Feriado" o sin texto |
| Día de hoy | contorno `brand` (no `accent`: Vital lo reserva para un destacado por bloque) | — |

La regla de Vital "nunca solo color, siempre palabra" coincide con el anti-objetivo de SPEC §1.

## 4. Lo que Vital no trae y habrá que diseñar con sus tokens

La app necesita estos componentes y Vital no los tiene:
- **calendario mensual**, de ausencias y de turnos;
- **tabla** (bandeja de coordinación, nómina, horas extra);
- **select** y **autocompletado**;
- **checkbox** y **radio** (o usar SegmentedControl cuando son 2 a 5 opciones);
- **modal o diálogo**;
- **aviso** (alerta en línea);
- **toast**;
- **menú móvil** de la NavBar (Vital dice que esto le toca al consumidor);
- **paginación o filtros**;
- **PIN de 6 dígitos**;
- **íconos**: Vital recomienda Lucide, pero no lo incluye.

Los definiré en la guía de la Fase 3 usando solo tokens de Vital, con el prefijo `vt-` para no mezclar estilos.

## 5. Conflictos con nuestras reglas (propongo ajustes, que decides tú)

| # | Vital dice o hace | Problema | Propuesta |
|---|---|---|---|
| V1 | `Button` `md` mide unos 42 px de alto, `sm` unos 30 px, la opción de SegmentedControl unos 32 px y los enlaces de NavBar son texto de 13 px con 4 px de relleno. | Por debajo de los **44 px táctiles** que exige el SPEC y el mismo README de Vital. | En el celular, usar como mínimo `md` ajustado a 44 px, y `lg` para "Pedir días". Agrandar el área táctil de la opción segmentada y de los enlaces sin cambiar cómo se ven. |
| V2 | Escala tipográfica pensada para landing (`title-1` 56 px, `display` 80 px). | Es una app de uso diario, no una landing. | Usar desde `title-2` hacia abajo: título de página 28 a 40 px, celdas del calendario en `caption`/`callout`. |
| V3 | NavBar en "vidrio" translúcido sobre `bg` gris menta. | El encargo dice "encabezado blanco". | Usar `nav-glass` (se ve casi blanco) o `surface` sólido. Ver la pregunta 1. |
| V4 | Sombras suaves en las tarjetas. | La guía actual (SPEC §8) no usa sombras. | Seguir a Vital: `shadow-card` en tarjetas blancas y ninguna en tarjetas con tinte. |
| V5 | Tintes pastel "por área clínica" (respiratorio = `sky`, neurología = `lavender`, cardio = `peach`…). | Bien usados podrían identificar equipos, pero no alcanzan para 19 equipos y no deben confundirse con estados. | Usarlos solo como fondo de tarjetas de equipo o de unidad (p. ej. Respiratorio `sky`, Neurología `lavender`, Cardio `peach`), **nunca** en las celdas del calendario, que usan los colores de estado. Ver la pregunta 2. |
| V6 | El SegmentedControl es "para cambiar la vista de un mismo contenido". | — | Encaja con "Mi equipo · Todos · Turnantes" y "Mes · Semana" en el calendario, y con "Día completo · Mañana · Tarde". |
| V7 | Tema oscuro completo. | No estaba pedido. | Ver la pregunta 3. |
| V8 | "Eyebrows" y frases dobles en titulares ("**Lo más nuevo.** …"). | Estilo de landing. | Usarlos con moderación (p. ej. "**Pedir días.** Elige fecha y tipo."). |

## 6. Cómo se incorporaría (en la Fase 4, no ahora)

- Copiar a `public/assets/vital/`: `tokens.css`, `bundle.css` (solo el CSS), `fonts/` y `licenses/Figtree-OFL.txt`, tal como vienen y sin editarlos. Los ajustes van en un archivo propio (`app.css`) encima.
- `bundle.js`, `index.d.ts` y los `preview.html` no se suben.
- Reemplazar Outfit y DM Sans (Google Fonts) por Figtree local, y quitar `fonts.googleapis.com` de la CSP.
- La sección §8 del SPEC v2 pasa a llamarse "Guía visual (basada en Vital)" y se borran todas las menciones a Gerson 1.

## 7. Preguntas

1. **Encabezado:** ¿vidrio translúcido de Vital (casi blanco) o blanco sólido?
2. **Tintes por equipo:** ¿quieres que algunos equipos tengan su tinte (Respiratorio, Neurología, Cardio…) en las tarjetas y en la vista de coordinación? ¿O la app no usa tintes?
3. **Tema oscuro:** ¿se ofrece (automático según el celular) o solo tema claro por ahora?
4. **Ajustes V1 y V2** (44 px táctiles y escala reducida): ¿de acuerdo con apartarse de Vital en esos dos puntos?
